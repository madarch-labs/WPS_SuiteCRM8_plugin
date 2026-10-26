<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsApiError.php';
require_once 'custom/include/WPS/WpsBeanSerializer.php';

/**
 * Relationships of the WPS contract 2.0 (/api/v9/{module}/{id}/relationships/{link}):
 *
 *   GET    …/relationships/{link}?page[size]=&page[after]=   related records + relationship fields (meta.relationship)
 *   POST   …/relationships/{link}  {data: [{type, id, meta: {accept_status: …}}]}   link (idempotent, updates fields)
 *   DELETE …/relationships/{link}/{related_id}                                      unlink (idempotent)
 *
 * {link} is a link field of the module (vardef type "link"): Meetings.users, Meetings.contacts, Calls.contacts,
 * Accounts.contacts, SecurityGroups.users, Users.reportees … Relationship fields are the extra columns of the join table
 * of a many-to-many relationship (meetings_users.accept_status, required); they are read and written directly in the
 * join table, Link2 does not expose them reliably.
 */
final class WpsRelationships
{
    private const SYSTEM_COLUMNS = ['id', 'date_modified', 'deleted'];

    /** @var SugarBean */
    private $bean;
    /** @var string */
    private $link;
    /** @var Link2 */
    private $rel;

    public function __construct(SugarBean $bean, string $link)
    {
        $def = $bean->field_defs[$link] ?? null;
        if (!is_array($def) || ($def['type'] ?? '') !== 'link' || !$bean->load_relationship($link)) {
            throw new WpsApiError(422, 'Unknown link ' . $bean->module_dir . '.' . $link);
        }
        $this->bean = $bean;
        $this->link = $link;
        $this->rel = $bean->$link;
    }

    public function relatedModule(): string
    {
        return (string)$this->rel->getRelatedModuleName();
    }

    /**
     * Columns of the join table that can be set as relationship fields (empty for one-to-many links).
     *
     * @return list<string>
     */
    public function allowedFields(): array
    {
        $join = $this->joinDefinition();
        if ($join === null) {
            return [];
        }
        $columns = array_keys((array)DBManagerFactory::getInstance()->get_columns($join['table']));
        return array_values(array_diff($columns, array_merge(self::SYSTEM_COLUMNS, [$join['own_key'], $join['related_key']])));
    }

    /**
     * @return array{items: list<array{bean: SugarBean, fields: array<string, string>}>, next_offset: ?int}
     */
    public function list(int $size, int $offset): array
    {
        $ids = $this->relatedIds();
        sort($ids, SORT_STRING); // stable order for the offset cursor
        $page = array_slice($ids, $offset, $size);
        $fields = $this->relationshipFields($page);
        $items = [];
        foreach ($page as $id) {
            $related = WpsBeanSerializer::load($this->relatedModule(), $id);
            if ($related !== null) {
                $items[] = ['bean' => $related, 'fields' => $fields[$id] ?? []];
            }
        }
        return ['items' => $items, 'next_offset' => $offset + $size < count($ids) ? $offset + $size : null];
    }

    /**
     * @param list<array{id: string, fields: array<string, string>}> $items
     * @return array{linked: list<string>, updated: list<string>, not_found: list<string>}
     */
    public function add(array $items): array
    {
        $allowed = $this->allowedFields();
        foreach ($items as $item) {
            foreach (array_keys($item['fields']) as $field) {
                if (!in_array($field, $allowed, true)) {
                    throw new WpsApiError(422, "Field \"{$field}\" is not a relationship field of {$this->bean->module_dir}.{$this->link}"
                        . ($allowed !== [] ? ' (allowed: ' . implode(', ', $allowed) . ')' : ''));
                }
            }
        }
        $existing = array_flip($this->relatedIds());
        $result = ['linked' => [], 'updated' => [], 'not_found' => []];
        foreach ($items as $item) {
            $id = $item['id'];
            if (WpsBeanSerializer::load($this->relatedModule(), $id) === null) {
                $result['not_found'][] = $id;
                continue;
            }
            if (isset($existing[$id])) {
                if ($item['fields'] !== []) {
                    $this->updateFields($id, $item['fields']);
                }
                $result['updated'][] = $id;
                continue;
            }
            $this->rel->add([$id], $item['fields']);
            $result['linked'][] = $id;
        }
        return $result;
    }

    public function remove(string $relatedId): bool
    {
        if (!in_array($relatedId, $this->relatedIds(), true)) {
            return false;
        }
        $this->rel->delete($this->bean->id, $relatedId);
        return true;
    }

    /**
     * Ids of the related records, read directly: many-to-many from the join table, one-to-many by the key column.
     * Link2::get() builds a broken query for self-referencing one-to-many links (Users.reportees), so it is only the
     * fallback for relationship kinds without these definitions.
     *
     * @return list<string>
     */
    private function relatedIds(): array
    {
        $db = DBManagerFactory::getInstance();
        $join = $this->joinDefinition();
        $sql = null;
        if ($join !== null) {
            $sql = "SELECT {$join['related_key']} AS rid FROM {$join['table']} WHERE deleted = 0 AND {$join['own_key']} = " . $db->quoted($this->bean->id);
        } else {
            $object = $this->rel->getRelationshipObject();
            $def = is_object($object) ? (array)$object->def : [];
            if (!empty($def['rhs_table']) && !empty($def['rhs_key']) && ($def['lhs_key'] ?? 'id') === 'id') {
                if ($this->rel->getSide() === REL_LHS) {
                    $sql = "SELECT id AS rid FROM {$def['rhs_table']} WHERE deleted = 0 AND {$def['rhs_key']} = " . $db->quoted($this->bean->id);
                } else {
                    $key = $def['rhs_key'];
                    return !empty($this->bean->$key) ? [(string)$this->bean->$key] : [];
                }
            }
        }
        if ($sql === null) {
            return array_values(array_map('strval', (array)$this->rel->get(true)));
        }
        $result = $db->query($sql, true);
        $ids = [];
        while (($row = $db->fetchByAssoc($result, false)) !== false && $row !== null) {
            $ids[] = (string)$row['rid'];
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, string>> related id => relationship fields
     */
    private function relationshipFields(array $ids): array
    {
        $join = $this->joinDefinition();
        $fields = $this->allowedFields();
        if ($join === null || $fields === [] || $ids === []) {
            return [];
        }
        $db = DBManagerFactory::getInstance();
        $sql = 'SELECT ' . $join['related_key'] . ' AS rid, ' . implode(', ', $fields) . " FROM {$join['table']}"
            . " WHERE deleted = 0 AND {$join['own_key']} = " . $db->quoted($this->bean->id)
            . " AND {$join['related_key']} IN (" . implode(', ', array_map([$db, 'quoted'], $ids)) . ')';
        $result = $db->query($sql, true);
        $rows = [];
        while (($row = $db->fetchByAssoc($result, false)) !== false && $row !== null) {
            $rid = (string)$row['rid'];
            unset($row['rid']);
            $rows[$rid] = array_map(static function ($v): string {
                return $v === null ? '' : (string)$v;
            }, $row);
        }
        return $rows;
    }

    /**
     * @param array<string, string> $fields
     */
    private function updateFields(string $relatedId, array $fields): void
    {
        $join = $this->joinDefinition();
        if ($join === null) {
            return;
        }
        $db = DBManagerFactory::getInstance();
        $sets = [];
        foreach ($fields as $field => $value) {
            $sets[] = "{$field} = " . $db->quoted((string)$value);
        }
        $sets[] = 'date_modified = ' . $db->quoted(gmdate('Y-m-d H:i:s'));
        $db->query("UPDATE {$join['table']} SET " . implode(', ', $sets)
            . " WHERE deleted = 0 AND {$join['own_key']} = " . $db->quoted($this->bean->id)
            . " AND {$join['related_key']} = " . $db->quoted($relatedId), true);
    }

    /**
     * @return array{table: string, own_key: string, related_key: string}|null join table of a many-to-many relationship
     */
    private function joinDefinition(): ?array
    {
        $object = $this->rel->getRelationshipObject();
        // "def" is served by __get on some relationship classes: isset() would not see it
        $def = is_object($object) ? (array)$object->def : [];
        if (empty($def['join_table']) || empty($def['join_key_lhs']) || empty($def['join_key_rhs'])) {
            return null;
        }
        $lhs = $this->rel->getSide() === REL_LHS;
        return [
            'table' => (string)$def['join_table'],
            'own_key' => (string)($lhs ? $def['join_key_lhs'] : $def['join_key_rhs']),
            'related_key' => (string)($lhs ? $def['join_key_rhs'] : $def['join_key_lhs']),
        ];
    }
}
