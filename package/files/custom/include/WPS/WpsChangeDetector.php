<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * Which fields changed in a save: old and new stored values are compared after normalization by the vardef type,
 * so that format differences are not reported as changes ("1000.000000" vs "1000", NULL vs "", the order of
 * multienum values). Strings are compared as is (case and spaces matter).
 */
final class WpsChangeDetector
{
    private const NUMERIC_TYPES = ['int', 'integer', 'decimal', 'float', 'double', 'currency', 'long', 'ulong', 'tinyint', 'uint'];
    private const BOOL_TYPES = ['bool', 'boolean'];
    private const DATETIME_TYPES = ['datetime', 'datetimecombo'];

    /**
     * Old values of the changed fields.
     *
     * @param array<string, mixed> $old stored values before the save
     * @param array<string, mixed> $new stored values after the save
     * @param array<string, array<string, mixed>> $fieldDefs
     * @param list<string> $ignore fields never reported (date_modified, ...)
     * @return array<string, string|null>
     */
    public static function before(array $old, array $new, array $fieldDefs, array $ignore): array
    {
        $before = [];
        foreach ($new as $field => $value) {
            if ($field === 'id' || in_array($field, $ignore, true) || !array_key_exists($field, $old)) {
                continue;
            }
            $type = (string)($fieldDefs[$field]['type'] ?? '');
            if (self::normalize($old[$field], $type) !== self::normalize($value, $type)) {
                $before[$field] = $old[$field] === null ? null : (string)$old[$field];
            }
        }
        return $before;
    }

    /**
     * Fields set on a new record (not empty, not ignored).
     *
     * @param array<string, mixed> $new
     * @param list<string> $ignore
     * @return list<string>
     */
    public static function filled(array $new, array $ignore): array
    {
        $fields = [];
        foreach ($new as $field => $value) {
            if ($field !== 'id' && !in_array($field, $ignore, true) && $value !== null && $value !== '') {
                $fields[] = (string)$field;
            }
        }
        return $fields;
    }

    /**
     * @param mixed $value
     */
    public static function normalize($value, string $type): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        $value = (string)$value;
        if (in_array($type, self::NUMERIC_TYPES, true) && is_numeric(trim($value))) {
            return self::canonicalNumber(trim($value));
        }
        if (in_array($type, self::BOOL_TYPES, true)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        }
        if (in_array($type, self::DATETIME_TYPES, true) || $type === 'date') {
            return in_array($value, ['0000-00-00', '0000-00-00 00:00:00'], true) ? '' : substr(str_replace('T', ' ', $value), 0, $type === 'date' ? 10 : 19);
        }
        if ($type === 'multienum') {
            $items = array_filter(explode('^,^', trim($value, '^')), 'strlen');
            sort($items, SORT_STRING);
            return implode(',', $items);
        }
        return $value;
    }

    private static function canonicalNumber(string $number): string
    {
        $negative = strpos($number, '-') === 0;
        $number = ltrim($number, '+-');
        if (stripos($number, 'e') !== false) {
            $number = rtrim(rtrim(sprintf('%.10F', (float)$number), '0'), '.');
        }
        $parts = explode('.', $number, 2);
        $int = ltrim($parts[0], '0');
        $frac = rtrim($parts[1] ?? '', '0');
        $result = ($int === '' ? '0' : $int) . ($frac !== '' ? '.' . $frac : '');
        return $negative && $result !== '0' ? '-' . $result : $result;
    }
}
