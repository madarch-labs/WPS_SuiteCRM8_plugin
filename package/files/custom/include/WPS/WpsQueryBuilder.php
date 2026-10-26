<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsApiError.php';

/**
 * Search of the WPS contract 2.0 (GET /api/v9/{module}): filter with operators, sort, keyset cursor, count.
 *
 *   filter[f]=v                 the same as filter[f][eq]=v (contract 1.0)
 *   filter[f][op]=v             eq neq gt gte lt lte like in nin null notnull; several operators per field; all joined by AND
 *   sort=-date_closed,amount    up to 2 fields ("-" = descending), "id" is always the last (hidden) key
 *   page[size]=N (size=N)       1..max_page_size, default 20
 *   page[after]=<cursor>        opaque cursor from meta.next_cursor of the previous page
 *   count=1                     meta.total
 *
 * Values are compared as stored in the DB (datetime: UTC "Y-m-d H:i:s", date "Y-m-d", bool "1"/"0", decimal with a dot);
 * WPS casts them to this format. A plain SQL query is used instead of get_list(): the cursor needs full control of
 * ORDER BY and of the keyset condition. Column names come only from the stored fields of the bean.
 */
final class WpsQueryBuilder
{
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'nin', 'null', 'notnull'];
    private const NUMERIC_TYPES = ['int', 'integer', 'decimal', 'float', 'double', 'currency', 'long', 'ulong', 'tinyint', 'uint'];

    /** @var SugarBean */
    private $bean;
    /** @var array<string, array<string, mixed>> */
    private $fields;
    /** @var DBManager */
    private $db;
    /** @var list<array{field: string, op: string, value: mixed}> */
    private $conditions = [];
    /** @var list<array{field: string, desc: bool}> */
    private $sort = [];
    /** @var int */
    private $maxIn;

    /**
     * @param array<string, array<string, mixed>> $fields stored fields of the bean (WpsBeanSerializer::storedFields)
     */
    public function __construct(SugarBean $bean, array $fields, int $maxIn = 100)
    {
        $this->bean = $bean;
        $this->fields = $fields;
        $this->db = DBManagerFactory::getInstance();
        $this->maxIn = $maxIn;
    }

    /**
     * @param array<string, mixed> $filter the "filter" query parameter
     */
    public function filter(array $filter): self
    {
        foreach ($filter as $field => $spec) {
            $field = (string)$field;
            if (!isset($this->fields[$field])) {
                throw new WpsApiError(422, "Unknown filter field {$this->moduleName()}.{$field}");
            }
            if (!is_array($spec)) {
                $this->conditions[] = ['field' => $field, 'op' => 'eq', 'value' => (string)$spec];
                continue;
            }
            foreach ($spec as $op => $value) {
                $op = (string)$op;
                if (!in_array($op, self::OPERATORS, true)) {
                    throw new WpsApiError(422, "Unknown filter operator \"{$op}\" (allowed: " . implode(', ', self::OPERATORS) . ')');
                }
                if ($op === 'in' || $op === 'nin') {
                    $values = is_array($value) ? array_values($value) : explode(',', (string)$value);
                    if ($values === [] || count($values) > $this->maxIn) {
                        throw new WpsApiError(422, "Operator \"{$op}\" needs 1..{$this->maxIn} values");
                    }
                    $this->conditions[] = ['field' => $field, 'op' => $op, 'value' => array_map('strval', $values)];
                    continue;
                }
                if (is_array($value)) {
                    throw new WpsApiError(422, "Operator \"{$op}\" takes one value ({$field})");
                }
                $this->conditions[] = ['field' => $field, 'op' => $op, 'value' => (string)$value];
            }
        }
        return $this;
    }

    public function sort(?string $sort): self
    {
        $this->sort = [];
        $parts = $sort === null || trim($sort) === '' ? [] : array_map('trim', explode(',', $sort));
        if (count($parts) > 2) {
            throw new WpsApiError(422, 'Sort by up to 2 fields');
        }
        foreach ($parts as $part) {
            $desc = strpos($part, '-') === 0;
            $field = ltrim($part, '-+');
            if (!isset($this->fields[$field])) {
                throw new WpsApiError(422, "Unknown sort field {$this->moduleName()}.{$field}");
            }
            if ($field !== 'id') {
                $this->sort[] = ['field' => $field, 'desc' => $desc];
            }
        }
        if ($this->sort === [] && $parts === [] && isset($this->fields['date_entered'])) {
            $this->sort[] = ['field' => 'date_entered', 'desc' => false];
        }
        return $this;
    }

    /**
     * One page: ids in order + the cursor of the next page (null when this is the last one).
     *
     * @return array{ids: list<string>, next_cursor: ?string}
     */
    public function page(int $size, ?string $cursor): array
    {
        $keys = $this->keys();
        $where = $this->whereSql();
        if ($cursor !== null && $cursor !== '') {
            $where[] = $this->cursorSql($this->decodeCursor($cursor), $keys);
        }
        $select = [];
        foreach ($keys as $i => $key) {
            $select[] = $this->orderExpression($key['field']) . " AS wps_k{$i}";
        }
        $order = [];
        foreach ($keys as $i => $key) {
            $order[] = "wps_k{$i} " . ($key['desc'] ? 'DESC' : 'ASC');
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' ' . $this->fromSql() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . implode(', ', $order);
        $result = $this->db->limitQuery($sql, 0, $size + 1, true);
        $rows = [];
        while (($row = $this->db->fetchByAssoc($result, false)) !== false && $row !== null) {
            $rows[] = $row;
        }
        $next = null;
        if (count($rows) > $size) {
            $rows = array_slice($rows, 0, $size);
            $last = end($rows);
            $values = [];
            foreach (array_keys($keys) as $i) {
                $values[] = (string)$last["wps_k{$i}"];
            }
            $next = $this->encodeCursor($values);
        }
        $idIndex = count($keys) - 1;
        return ['ids' => array_map(static function (array $r) use ($idIndex): string {
            return (string)$r["wps_k{$idIndex}"];
        }, $rows), 'next_cursor' => $next];
    }

    public function count(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS c ' . $this->fromSql() . ' WHERE ' . implode(' AND ', $this->whereSql()), true);
        $row = $this->db->fetchByAssoc($result, false);
        return (int)($row['c'] ?? 0);
    }

    /**
     * @return list<array{field: string, desc: bool}> sort keys + id (direction of the last key)
     */
    private function keys(): array
    {
        $keys = $this->sort;
        $keys[] = ['field' => 'id', 'desc' => $keys !== [] ? end($keys)['desc'] : false];
        return $keys;
    }

    private function fromSql(): string
    {
        $table = $this->bean->table_name;
        $sql = "FROM {$table}";
        if ($this->usesCustomTable()) {
            $custom = $this->bean->get_custom_table_name();
            $sql .= " LEFT JOIN {$custom} ON {$custom}.id_c = {$table}.id";
        }
        return $sql;
    }

    private function usesCustomTable(): bool
    {
        foreach (array_merge(array_column($this->conditions, 'field'), array_column($this->sort, 'field')) as $field) {
            if (($this->fields[$field]['source'] ?? 'db') === 'custom_fields') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function whereSql(): array
    {
        $where = ["{$this->bean->table_name}.deleted = 0"];
        foreach ($this->conditions as $c) {
            $column = $this->column($c['field']);
            $value = $c['value'];
            switch ($c['op']) {
                case 'eq':
                    $where[] = $value === '' ? "({$column} = '' OR {$column} IS NULL)" : "{$column} = " . $this->db->quoted($value);
                    break;
                case 'neq':
                    $where[] = $value === '' ? "({$column} <> '' AND {$column} IS NOT NULL)" : "({$column} <> " . $this->db->quoted($value) . " OR {$column} IS NULL)";
                    break;
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                    $sqlOp = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$c['op']];
                    $where[] = "{$column} {$sqlOp} " . $this->literal($c['field'], $value);
                    break;
                case 'like':
                    $where[] = "{$column} LIKE " . $this->db->quoted($value);
                    break;
                case 'in':
                    $where[] = "{$column} IN (" . implode(', ', array_map([$this->db, 'quoted'], $value)) . ')';
                    break;
                case 'nin':
                    $where[] = "({$column} NOT IN (" . implode(', ', array_map([$this->db, 'quoted'], $value)) . ") OR {$column} IS NULL)";
                    break;
                case 'null':
                    $where[] = "({$column} IS NULL OR {$column} = '')";
                    break;
                case 'notnull':
                    $where[] = "({$column} IS NOT NULL AND {$column} <> '')";
                    break;
            }
        }
        return $where;
    }

    /**
     * Keyset condition (k1, k2, id) > (v1, v2, id) for ascending keys, "<" for descending ones.
     *
     * @param list<string> $values
     * @param list<array{field: string, desc: bool}> $keys
     */
    private function cursorSql(array $values, array $keys): string
    {
        if (count($values) !== count($keys)) {
            throw new WpsApiError(422, 'The cursor does not belong to this query');
        }
        $sql = '';
        for ($i = count($keys) - 1; $i >= 0; $i--) {
            $expr = $this->orderExpression($keys[$i]['field']);
            $literal = $this->literal($keys[$i]['field'], $values[$i]);
            $cmp = $keys[$i]['desc'] ? '<' : '>';
            $sql = $sql === '' ? "{$expr} {$cmp} {$literal}" : "({$expr} {$cmp} {$literal} OR ({$expr} = {$literal} AND {$sql}))";
        }
        return $sql;
    }

    /**
     * NULLs are sorted as the lowest value so that the keyset comparison stays total.
     */
    private function orderExpression(string $field): string
    {
        $column = $this->column($field);
        if ($field === 'id') {
            return $column;
        }
        return $this->isNumeric($field) ? "COALESCE({$column}, -999999999999999)" : "COALESCE({$column}, '')";
    }

    private function literal(string $field, string $value): string
    {
        if ($this->isNumeric($field)) {
            if (!is_numeric($value)) {
                throw new WpsApiError(422, "Value \"{$value}\" of {$field} must be a number");
            }
            return preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : $this->db->quoted($value);
        }
        return $this->db->quoted($value);
    }

    private function isNumeric(string $field): bool
    {
        return in_array($this->fields[$field]['type'] ?? '', self::NUMERIC_TYPES, true);
    }

    private function column(string $field): string
    {
        $table = ($this->fields[$field]['source'] ?? 'db') === 'custom_fields' ? $this->bean->get_custom_table_name() : $this->bean->table_name;
        return "{$table}.{$field}";
    }

    /**
     * @param list<string> $values
     */
    private function encodeCursor(array $values): string
    {
        $json = json_encode(['v' => $values, 's' => $this->signature()]);
        return rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
    }

    /**
     * @return list<string>
     */
    private function decodeCursor(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['v'], $data['s']) || !is_array($data['v']) || $data['s'] !== $this->signature()) {
            throw new WpsApiError(422, 'Invalid cursor (it belongs to another query)');
        }
        return array_map('strval', $data['v']);
    }

    private function signature(): string
    {
        return substr(sha1((string)json_encode([$this->bean->module_dir, $this->conditions, $this->sort])), 0, 16);
    }

    private function moduleName(): string
    {
        return (string)($this->bean->module_dir ?: $this->bean->module_name);
    }
}
