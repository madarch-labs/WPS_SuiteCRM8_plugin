<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * WPS integration settings: $sugar_config['wps'] (config_override.php). Nothing is hardcoded in the code:
 *
 *   'wps' => [
 *     'enabled'          => true,
 *     'redis_host'       => 'redis-stream',
 *     'redis_port'       => 6379,
 *     'redis_password'   => '',
 *     'redis_timeout'    => 1.0,               // seconds, connect + read
 *     'stream'           => 'crm_events',
 *     'stream_maxlen'    => 100000,            // XADD MAXLEN ~ (0 = unlimited)
 *     'event_modules'    => ['Leads', ...],    // modules whose after_save is published ([] = all)
 *     'api_static_key'   => '...',             // X-Auth-Key = key . gmdate('Y.m.d H')
 *     'api_user_id'      => '1',               // user the API acts as (created_by / modified_user_id)
 *     'api_readonly_modules' => ['Users', 'Employees'],
 *     // contract 2.0
 *     'ignore_change_fields' => ['date_modified', ...], // never reported as changed (event "before" / "changed_fields")
 *     'publish_unchanged'    => true,              // publish "update" events without changed fields
 *     'publish_delete_events' => true,             // after_delete → event "delete"
 *     'publish_relationship_events' => false,      // after_relationship_add/delete → events "link" / "unlink"
 *     'max_page_size' => 100, 'max_link_batch' => 100, 'max_in_values' => 100,
 *   ]
 */
final class WpsConfig
{
    public const PLUGIN_VERSION = '1.2.0';
    public const CONTRACT_VERSION = '2.0';

    private const DEFAULTS = [
        'enabled' => true,
        'redis_host' => '127.0.0.1',
        'redis_port' => 6379,
        'redis_password' => '',
        'redis_timeout' => 1.0,
        'stream' => 'crm_events',
        'stream_maxlen' => 100000,
        'event_modules' => [],
        'api_static_key' => '',
        'api_user_id' => '1',
        'api_readonly_modules' => ['Users', 'Employees'],
        // contract 2.0: events v2, search and relationship limits
        'ignore_change_fields' => ['date_modified', 'modified_user_id', 'date_entered', 'created_by', 'deleted'],
        'publish_unchanged' => true,
        'publish_delete_events' => true,
        'publish_relationship_events' => false,
        'max_page_size' => 100,
        'max_link_batch' => 100,
        'max_in_values' => 100,
    ];

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        $config = isset($GLOBALS['sugar_config']['wps']) && is_array($GLOBALS['sugar_config']['wps'])
            ? $GLOBALS['sugar_config']['wps']
            : [];
        return array_key_exists($key, $config) ? $config[$key] : (self::DEFAULTS[$key] ?? null);
    }

    /**
     * @return string[]
     */
    public static function getList(string $key): array
    {
        $value = self::get($key);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', array_map('strval', $value)), 'strlen'));
    }

    public static function flag(string $key): bool
    {
        return filter_var(self::get($key), FILTER_VALIDATE_BOOLEAN);
    }

    public static function enabled(): bool
    {
        return filter_var(self::get('enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function isEventModule(string $module): bool
    {
        $modules = self::getList('event_modules');
        return $modules === [] || in_array($module, $modules, true);
    }
}
