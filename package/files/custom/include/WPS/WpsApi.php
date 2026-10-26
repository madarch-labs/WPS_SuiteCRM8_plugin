<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsConfig.php';
require_once 'custom/include/WPS/WpsApiError.php';
require_once 'custom/include/WPS/WpsBeanSerializer.php';
require_once 'custom/include/WPS/WpsQueryBuilder.php';
require_once 'custom/include/WPS/WpsRelationships.php';
require_once 'custom/include/WPS/WpsRequestContext.php';

/**
 * CRM API with the WPS contract 2.0 (prefix /api/v9; the contract is described in WPS doc/{ru,en}/04-operations/crm-integration.md):
 *
 *   GET    /api/v9/capabilities                          → {data: {contract_version, crm, features, limits}}
 *   GET    /api/v9/modules                               → {data: [{type: "modules", id, attributes: {name, label}}]}
 *   GET    /api/v9/{module}/{id}                         → {data: {type, id, attributes}} | 404
 *   GET    /api/v9/{module}?filter[f][op]=v&sort=&fields=&page[size]=&page[after]=&count=1
 *                                                        → {data: [resource, ...], meta: {size, count, next_cursor, total?}}
 *   POST   /api/v9/{module}       {data: {type, attributes}}      → 201 {data: resource}
 *   PUT    /api/v9/{module}/{id}  {data: {type, id, attributes}}  → 200 {data: resource} | 404
 *   DELETE /api/v9/{module}/{id}                         → 200 {meta: {deleted: true}} | 404 (mark_deleted, event "delete")
 *   GET    /api/v9/{module}/{id}/relationships/{link}    → related resources with meta.relationship (fields of the link)
 *   POST   /api/v9/{module}/{id}/relationships/{link}    {data: [{type, id, meta: {...}}]} → {meta: {linked, updated, not_found}}
 *   DELETE /api/v9/{module}/{id}/relationships/{link}/{related_id} → {meta: {removed: bool}}
 *
 * Auth: header X-Auth-Key = api_static_key . gmdate("Y.m.d H") (the previous hour is accepted too).
 * Headers X-WPS-Execution-Id / X-WPS-Process-Id / X-WPS-Depth mark saves made by WPS (wps_origin of the events).
 * Errors: {errors: [{status, title, detail}]} with 400 / 401 / 404 / 405 / 422 / 500.
 *
 * The path comes from ?wps_path= (rewrite /api/v9/(.*) → /legacy/index.php?entryPoint=wpsApi&wps_path=$1)
 * or from PATH_INFO. Query parameters are re-parsed from the raw QUERY_STRING: SuiteCRM escapes
 * $_GET values (securexss) during bootstrap, which would corrupt filter values.
 */
class WpsApi
{
    private const DEFAULT_SIZE = 20;

    public function handle(): void
    {
        try {
            if (!$this->authorized()) {
                $this->error(401, 'Invalid X-Auth-Key');
                return;
            }
            $GLOBALS['disable_date_format'] = true; // dates in/out in the DB format (UTC)
            $this->actAsApiUser();
            WpsRequestContext::setFromServer($_SERVER);

            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $segments = $this->pathSegments();
            $query = [];
            parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);

            if ($segments === ['capabilities']) {
                $method === 'GET' ? $this->capabilities() : $this->error(405, 'Method not allowed');
                return;
            }
            if ($segments === ['modules']) {
                $method === 'GET' ? $this->modules() : $this->error(405, 'Method not allowed');
                return;
            }
            $count = count($segments);
            if ($count < 1 || $count > 5 || ($count >= 3 && $segments[2] !== 'relationships') || $count === 3) {
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

            if ($count >= 4) {
                $this->relationships($method, $module, (string)$id, $segments[3], $segments[4] ?? null, $query);
            } elseif ($method === 'GET' && $id !== null) {
                $this->getById($module, $id);
            } elseif ($method === 'GET') {
                $this->find($bean, $module, $query);
            } elseif ($method === 'POST' && $id === null) {
                $this->create($bean, $module);
            } elseif ($method === 'PUT' && $id !== null) {
                $this->update($module, $id);
            } elseif ($method === 'DELETE' && $id !== null) {
                $this->delete($module, $id);
            } else {
                $this->error(405, 'Method not allowed');
            }
        } catch (WpsApiError $e) {
            $this->error($e->getStatus(), $e->getMessage());
        } catch (Throwable $e) {
            if (isset($GLOBALS['log'])) {
                $GLOBALS['log']->fatal('[WPS] API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
            $this->error(500, 'Internal error', $e->getMessage());
        }
    }

    private function capabilities(): void
    {
        $suitecrm_version = '';
        if (is_file('suitecrm_version.php')) {
            include 'suitecrm_version.php';
        }
        $this->respond(200, ['data' => [
            'contract_version' => WpsConfig::CONTRACT_VERSION,
            'crm' => ['name' => 'SuiteCRM', 'version' => (string)$suitecrm_version, 'plugin_version' => WpsConfig::PLUGIN_VERSION],
            'features' => ['filter_operators', 'filter_nin', 'sort', 'cursor_pagination', 'fields', 'count', 'delete', 'relationships',
                'relationship_fields', 'event_v2', 'delete_events', 'origin_headers'],
            'limits' => [
                'max_page_size' => (int)WpsConfig::get('max_page_size'),
                'max_link_batch' => (int)WpsConfig::get('max_link_batch'),
                'max_in_values' => (int)WpsConfig::get('max_in_values'),
            ],
        ]]);
    }

    private function getById(string $module, string $id): void
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
        $fields = WpsBeanSerializer::storedFields($bean);
        $size = $this->pageSize($query);
        $only = $this->fieldList($query, $fields, $module);
        $builder = (new WpsQueryBuilder($bean, $fields, (int)WpsConfig::get('max_in_values')))
            ->filter(isset($query['filter']) && is_array($query['filter']) ? $query['filter'] : [])
            ->sort(isset($query['sort']) ? (string)$query['sort'] : null);
        $page = $builder->page($size, isset($query['page']['after']) ? (string)$query['page']['after'] : null);

        $items = [];
        foreach ($page['ids'] as $id) {
            $full = WpsBeanSerializer::load($module, $id);
            if ($full !== null) {
                $items[] = $this->resource($module, $full, $only);
            }
        }
        $meta = ['size' => $size, 'count' => count($items), 'next_cursor' => $page['next_cursor']];
        if (!empty($query['count'])) {
            $meta['total'] = $builder->count();
        }
        $this->respond(200, ['data' => $items, 'meta' => $meta]);
    }

    private function create(SugarBean $bean, string $module): void
    {
        $this->assertWritable($module);
        $attributes = $this->bodyAttributes();
        if ($attributes === null) {
            return;
        }
        $ignored = WpsBeanSerializer::apply($bean, $attributes);
        $bean->save(false);
        $this->respondSaved(201, $module, (string)$bean->id, $ignored);
    }

    private function update(string $module, string $id): void
    {
        $this->assertWritable($module);
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

    private function delete(string $module, string $id): void
    {
        $this->assertWritable($module);
        $bean = WpsBeanSerializer::load($module, $id);
        if ($bean === null) {
            $this->error(404, "{$module} {$id} not found");
            return;
        }
        $bean->mark_deleted($id); // soft delete; after_delete publishes the "delete" event
        $this->respond(200, ['meta' => ['deleted' => true]]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function relationships(string $method, string $module, string $id, string $link, ?string $relatedId, array $query): void
    {
        $bean = WpsBeanSerializer::load($module, $id);
        if ($bean === null) {
            $this->error(404, "{$module} {$id} not found");
            return;
        }
        $relationships = new WpsRelationships($bean, $link);

        if ($method === 'GET' && $relatedId === null) {
            $size = $this->pageSize($query);
            $after = isset($query['page']['after']) ? (string)$query['page']['after'] : '';
            $offset = 0;
            if ($after !== '') {
                $decoded = json_decode((string)base64_decode(strtr($after, '-_', '+/'), true), true);
                if (!is_array($decoded) || !isset($decoded['o']) || ($decoded['l'] ?? '') !== $module . '.' . $link) {
                    throw new WpsApiError(422, 'Invalid cursor (it belongs to another query)');
                }
                $offset = (int)$decoded['o'];
            }
            $relatedModule = $relationships->relatedModule();
            $related = BeanFactory::newBean($relatedModule);
            $only = $related instanceof SugarBean ? $this->fieldList($query, WpsBeanSerializer::storedFields($related), $relatedModule) : null;
            $page = $relationships->list($size, $offset);
            $items = [];
            foreach ($page['items'] as $item) {
                $resource = $this->resource($relatedModule, $item['bean'], $only);
                $resource['meta'] = ['relationship' => (object)$item['fields']];
                $items[] = $resource;
            }
            $next = $page['next_offset'] === null ? null
                : rtrim(strtr(base64_encode((string)json_encode(['o' => $page['next_offset'], 'l' => $module . '.' . $link])), '+/', '-_'), '=');
            $this->respond(200, ['data' => $items, 'meta' => ['size' => $size, 'count' => count($items), 'next_cursor' => $next]]);
            return;
        }

        $this->assertWritable($module);
        if ($method === 'POST' && $relatedId === null) {
            $body = json_decode((string)file_get_contents('php://input'), true);
            $data = is_array($body) ? ($body['data'] ?? null) : null;
            if (is_array($data) && isset($data['id'])) {
                $data = [$data];
            }
            if (!is_array($data) || $data === [] || count($data) > (int)WpsConfig::get('max_link_batch')) {
                $this->error(400, 'Request body must be {"data": [{"type": ..., "id": ..., "meta": {...}}]} with 1..' . (int)WpsConfig::get('max_link_batch') . ' items');
                return;
            }
            $items = [];
            foreach ($data as $entry) {
                if (!is_array($entry) || !isset($entry['id']) || !is_scalar($entry['id']) || (string)$entry['id'] === '') {
                    $this->error(400, 'Every item needs an "id"');
                    return;
                }
                $fields = [];
                foreach ((array)($entry['meta'] ?? []) as $name => $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new WpsApiError(422, "Relationship field \"{$name}\" must be a string");
                    }
                    $fields[(string)$name] = $value === null ? '' : (string)$value;
                }
                $items[] = ['id' => (string)$entry['id'], 'fields' => $fields];
            }
            $this->respond(200, ['meta' => $relationships->add($items)]);
            return;
        }
        if ($method === 'DELETE' && $relatedId !== null) {
            $this->respond(200, ['meta' => ['removed' => $relationships->remove($relatedId)]]);
            return;
        }
        $this->error(405, 'Method not allowed');
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
     * @param array<string, mixed> $query
     */
    private function pageSize(array $query): int
    {
        $size = isset($query['page']['size']) ? (int)$query['page']['size'] : (isset($query['size']) ? (int)$query['size'] : self::DEFAULT_SIZE);
        return max(1, min((int)WpsConfig::get('max_page_size'), $size));
    }

    /**
     * "fields=a,b" → list of field names (id is always included) or null for all fields.
     *
     * @param array<string, mixed> $query
     * @param array<string, array<string, mixed>> $fields
     * @return list<string>|null
     */
    private function fieldList(array $query, array $fields, string $module): ?array
    {
        if (!isset($query['fields']) || !is_string($query['fields']) || trim($query['fields']) === '') {
            return null;
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $query['fields'])), 'strlen'));
        foreach ($list as $field) {
            if (!isset($fields[$field])) {
                throw new WpsApiError(422, "Unknown field {$module}.{$field}");
            }
        }
        return $list;
    }

    private function assertWritable(string $module): void
    {
        if (in_array($module, WpsConfig::getList('api_readonly_modules'), true)) {
            throw new WpsApiError(405, "Module {$module} is read-only for the WPS API");
        }
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
     * @param list<string>|null $only
     * @return array{type: string, id: string, attributes: object}
     */
    private function resource(string $module, SugarBean $bean, ?array $only = null): array
    {
        $attributes = WpsBeanSerializer::toArray($bean, $only);
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
