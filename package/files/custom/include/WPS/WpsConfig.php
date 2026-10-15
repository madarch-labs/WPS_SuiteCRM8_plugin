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
 *   ]
 */
final class WpsConfig
{
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
