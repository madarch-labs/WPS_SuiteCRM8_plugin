<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsConfig.php';
require_once 'custom/include/WPS/WpsBeanSerializer.php';

/**
 * CRM API with the WPS contract (TS §9, version prefix /api/v9):
 *
 *   GET  /api/v9/modules                                  → {data: [{type: "modules", id, attributes: {name, label}}]}
 *   GET  /api/v9/{module}/{id}                            → {data: {type, id, attributes}} | 404
 *   GET  /api/v9/{module}?filter[field]=value&size=1      → {data: [resource, ...]} (oldest first)
 *   POST /api/v9/{module}       {data: {type, attributes}} → 201 {data: resource}
 *   PUT  /api/v9/{module}/{id}  {data: {type, id, attributes}} → 200 {data: resource} | 404
 *
 * Auth: header X-Auth-Key = api_static_key . gmdate("Y.m.d H") (the previous hour is accepted too).
 * Errors: {errors: [{status, title, detail}]} with 400 / 401 / 404 / 405 / 422 / 500.
 *
 * The path comes from ?wps_path= (rewrite /api/v9/(.*) → /legacy/index.php?entryPoint=wpsApi&wps_path=$1)
 * or from PATH_INFO. Query parameters are re-parsed from the raw QUERY_STRING: SuiteCRM escapes
 * $_GET values (securexss) during bootstrap, which would corrupt filter values.
 */
class WpsApi
{
    private const MAX_SIZE = 100;

    public function handle(): void
    {
        try {
            if (!$this->authorized()) {
                $this->error(401, 'Invalid X-Auth-Key');
                return;
            }
            $GLOBALS['disable_date_format'] = true; // dates in/out in the DB format (UTC)
            $this->actAsApiUser();

            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $segments = $this->pathSegments();
            $query = [];
            parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);

            if ($segments === ['modules']) {
                $method === 'GET' ? $this->modules() : $this->error(405, 'Method not allowed');
                return;
            }
            if (count($segments) < 1 || count($segments) > 2) {
                $this->error(404, 'Route not found');
                return;
            }
            $module = $segments[0];
            $id = $segments[1] ?? null;
            $bean = $this->newBean($module);
            if ($bean === null) {
                $this->error(404, "Unknown module {$module}");
                return;
            }

            if ($method === 'GET' && $id !== null) {
                $this->getById($bean, $module, $id);
            } elseif ($method === 'GET') {
                $this->find($bean, $module, $query);
            } elseif ($method === 'POST' && $id === null) {
                $this->create($bean, $module);
            } elseif ($method === 'PUT' && $id !== null) {
                $this->update($bean, $module, $id);
            } else {
                $this->error(405, 'Method not allowed');
            }
        } catch (Throwable $e) {
            if (isset($GLOBALS['log'])) {
                $GLOBALS['log']->fatal('[WPS] API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
            $this->error(500, 'Internal error', $e->getMessage());
        }
    }

    private function getById(SugarBean $bean, string $module, string $id): void
    {
        $found = WpsBeanSerializer::load($module, $id);
        if ($found === null) {
            $this->error(404, "{$module} {$id} not found");
            return;
        }
        $this->respond(200, ['data' => $this->resource($module, $found)]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function find(SugarBean $bean, string $module, array $query): void
    {
        $filter = isset($query['filter']) && is_array($query['filter']) ? $query['filter'] : [];
        $size = isset($query['size']) ? (int)$query['size'] : 20;
        $size = max(1, min(self::MAX_SIZE, $size));

        $fields = WpsBeanSerializer::storedFields($bean);
        $db = DBManagerFactory::getInstance();
        $where = [];
        foreach ($filter as $field => $value) {
            $field = (string)$field;
            if (!isset($fields[$field]) || is_array($value)) {
                $this->error(422, "Unknown filter field {$module}.{$field}");
                return;
            }
            $table = ($fields[$field]['source'] ?? 'db') === 'custom_fields'
                ? $bean->get_custom_table_name()
                : $bean->table_name;
            $column = "{$table}.{$field}";
            $value = (string)$value;
            $where[] = $value === ''
                ? "({$column} = '' OR {$column} IS NULL)"
                : "{$column} = " . $db->quoted($value);
        }
        $orderBy = isset($fields['date_entered']) ? "{$bean->table_name}.date_entered ASC" : "{$bean->table_name}.id ASC";
        $list = $bean->get_list($orderBy, implode(' AND ', $where), 0, $size, $size);

        $items = [];
        foreach ((array)($list['list'] ?? []) as $row) {
            $full = WpsBeanSerializer::load($module, (string)$row->id);
            if ($full !== null) {
                $items[] = $this->resource($module, $full);
            }
            if (count($items) >= $size) {
                break;
            }
        }
        $this->respond(200, ['data' => $items, 'meta' => ['size' => $size, 'count' => count($items)]]);
    }

    private function create(SugarBean $bean, string $module): void
    {
        if ($this->isReadonly($module)) {
            $this->error(405, "Module {$module} is read-only for the WPS API");
            return;
        }
        $attributes = $this->bodyAttributes();
        if ($attributes === null) {
            return;
        }
        $ignored = WpsBeanSerializer::apply($bean, $attributes);
        $bean->save(false);
        $this->respondSaved(201, $module, (string)$bean->id, $ignored);
    }

    private function update(SugarBean $bean, string $module, string $id): void
    {
        if ($this->isReadonly($module)) {
            $this->error(405, "Module {$module} is read-only for the WPS API");
            return;
        }
        $bean = WpsBeanSerializer::load($module, $id);
        if ($bean === null) {
            $this->error(404, "{$module} {$id} not found");
            return;
        }
        $attributes = $this->bodyAttributes();
        if ($attributes === null) {
            return;
        }
        $ignored = WpsBeanSerializer::apply($bean, $attributes);
        $bean->save(false);
        $this->respondSaved(200, $module, $id, $ignored);
    }

    /**
     * @param string[] $ignored
     */
    private function respondSaved(int $status, string $module, string $id, array $ignored): void
    {
        // re-read: the response shows what is actually stored (defaults, calculated fields)
        $fresh = WpsBeanSerializer::load($module, $id);
        if ($fresh === null) {
            throw new RuntimeException("{$module} {$id} cannot be read back after save");
        }
        $body = ['data' => $this->resource($module, $fresh)];
        if ($ignored !== []) {
            $body['meta'] = ['ignored_attributes' => $ignored];
        }
        $this->respond($status, $body);
    }

    private function modules(): void
    {
        global $moduleList, $app_list_strings;
        $labels = isset($app_list_strings['moduleList']) && is_array($app_list_strings['moduleList'])
            ? $app_list_strings['moduleList']
            : [];
        $items = [];
        foreach ((array)$moduleList as $module) {
            $items[] = [
                'type' => 'modules',
                'id' => $module,
                'attributes' => ['name' => $module, 'label' => (string)($labels[$module] ?? $module)],
            ];
        }
        $this->respond(200, ['data' => $items]);
    }

    /**
     * @return array<string, mixed>|null attributes, or null when an error response has been sent
     */
    private function bodyAttributes(): ?array
    {
        $raw = (string)file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->error(400, 'Request body must be a JSON object');
            return null;
        }
        $attributes = $body['data']['attributes'] ?? null;
        if (!is_array($attributes)) {
            $this->error(400, 'Request body must be {"data": {"type": ..., "attributes": {...}}}');
            return null;
        }
        return $attributes;
    }

    /**
     * @return array{type: string, id: string, attributes: object}
     */
    private function resource(string $module, SugarBean $bean): array
    {
        $attributes = WpsBeanSerializer::toArray($bean);
        unset($attributes['id']);
        return ['type' => $module, 'id' => (string)$bean->id, 'attributes' => (object)$attributes];
    }

    private function newBean(string $module): ?SugarBean
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $module) || !isset($GLOBALS['beanList'][$module])) {
            return null;
        }
        $bean = BeanFactory::newBean($module);
        return $bean instanceof SugarBean ? $bean : null;
    }

    private function isReadonly(string $module): bool
    {
        return in_array($module, WpsConfig::getList('api_readonly_modules'), true);
    }

    /**
     * @return string[]
     */
    private function pathSegments(): array
    {
        $path = '';
        if (preg_match('/(?:^|&)wps_path=([^&]*)/', (string)($_SERVER['QUERY_STRING'] ?? ''), $m)) {
            $path = rawurldecode($m[1]);
        } elseif (!empty($_SERVER['PATH_INFO'])) {
            $path = (string)$_SERVER['PATH_INFO'];
        }
        $path = preg_replace('#^/*(api/v\d+/+)?#', '', $path);
        return array_values(array_filter(explode('/', (string)$path), 'strlen'));
    }

    private function authorized(): bool
    {
        $key = (string)WpsConfig::get('api_static_key');
        $given = (string)($_SERVER['HTTP_X_AUTH_KEY'] ?? '');
        if ($key === '' || $given === '') {
            return false;
        }
        // the previous hour is accepted too: clock skew / a request sent right at the hour boundary
        return hash_equals($key . gmdate('Y.m.d H'), $given)
            || hash_equals($key . gmdate('Y.m.d H', time() - 3600), $given);
    }

    private function actAsApiUser(): void
    {
        $user = BeanFactory::getBean('Users', (string)WpsConfig::get('api_user_id'));
        if (!$user instanceof User || empty($user->id)) {
            throw new RuntimeException('WPS API user ' . WpsConfig::get('api_user_id') . ' not found');
        }
        $GLOBALS['current_user'] = $user;
    }

    private function error(int $status, string $title, ?string $detail = null): void
    {
        $error = ['status' => (string)$status, 'title' => $title];
        if ($detail !== null) {
            $error['detail'] = $detail;
        }
        $this->respond($status, ['errors' => [$error]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
