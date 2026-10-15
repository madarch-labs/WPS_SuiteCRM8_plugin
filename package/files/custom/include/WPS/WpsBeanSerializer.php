<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * Bean ↔ flat array of field values (strings / null), shared by the after_save hook and the API.
 * Only stored fields are exposed: table columns and custom (_cstm) fields; links, non-db/relate fields
 * and secrets (passwords, hashes) are skipped.
 */
final class WpsBeanSerializer
{
    private const SECRET_FIELDS = ['user_hash', 'system_generated_password', 'authenticate_id', 'password', 'mail_smtppass'];
    private const DATETIME_TYPES = ['datetime', 'datetimecombo'];

    /**
     * Reads a record as stored: raw DB values (no HTML encoding) and dates in the DB format (UTC),
     * independent of the current user's preferences. Null when the record does not exist / is deleted.
     */
    public static function load(string $module, string $id): ?SugarBean
    {
        $bean = BeanFactory::newBean($module);
        if (!$bean instanceof SugarBean) {
            return null;
        }
        $previous = $GLOBALS['disable_date_format'] ?? null;
        $GLOBALS['disable_date_format'] = true;
        try {
            $found = $bean->retrieve($id, false);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['disable_date_format']);
            } else {
                $GLOBALS['disable_date_format'] = $previous;
            }
        }
        return $found !== null && !empty($bean->id) ? $bean : null;
    }

    /**
     * @return array<string, mixed> field => value (id is included)
     */
    public static function toArray(SugarBean $bean): array
    {
        $result = [];
        foreach (self::storedFields($bean) as $name => $def) {
            $value = isset($bean->$name) ? $bean->$name : null;
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $value = (string)$value;
            } elseif ($value !== null) {
                continue;
            }
            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * Applies attributes to a bean. Values of unknown / read-only fields are not set and returned.
     *
     * @param array<string, mixed> $attributes
     * @return string[] ignored attribute names
     */
    public static function apply(SugarBean $bean, array $attributes): array
    {
        $fields = self::storedFields($bean);
        $ignored = [];
        foreach ($attributes as $name => $value) {
            $name = (string)$name;
            if (!isset($fields[$name]) || in_array($name, ['id', 'deleted', 'date_entered', 'date_modified', 'created_by'], true)) {
                $ignored[] = $name;
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                $ignored[] = $name;
                continue;
            }
            $type = $fields[$name]['type'] ?? '';
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if ($value !== null && $value !== '' && in_array($type, self::DATETIME_TYPES, true)) {
                $value = self::toDbDateTime((string)$value);
            } elseif ($value !== null && $value !== '' && $type === 'date') {
                $value = self::toDbDate((string)$value);
            }
            $bean->$name = $value === null ? null : (string)$value;
        }
        return $ignored;
    }

    /**
     * Field definitions of stored fields: name => vardef.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function storedFields(SugarBean $bean): array
    {
        $fields = [];
        foreach ((array)$bean->field_defs as $name => $def) {
            $type = $def['type'] ?? '';
            $source = $def['source'] ?? 'db';
            if ($source !== 'db' && $source !== 'custom_fields') {
                continue;
            }
            if (in_array($type, ['link', 'password'], true) || in_array($name, self::SECRET_FIELDS, true)) {
                continue;
            }
            $fields[$name] = $def;
        }
        return $fields;
    }

    /**
     * "2026-10-14 10:00:00" (UTC, the CRM DB format), ISO 8601 with an offset, or a timestamp → "Y-m-d H:i:s" UTC.
     */
    public static function toDbDateTime(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            $date = new DateTime($value, new DateTimeZone('UTC'));
        } elseif (ctype_digit($value)) {
            $date = new DateTime('@' . $value);
        } else {
            $date = new DateTime($value, new DateTimeZone('UTC'));
        }
        $date->setTimezone(new DateTimeZone('UTC'));
        return $date->format('Y-m-d H:i:s');
    }

    public static function toDbDate(string $value): string
    {
        return (new DateTime($value, new DateTimeZone('UTC')))->format('Y-m-d');
    }
}
