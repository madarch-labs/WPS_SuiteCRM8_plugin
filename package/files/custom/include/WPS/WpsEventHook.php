<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsConfig.php';
require_once 'custom/include/WPS/WpsRedisClient.php';
require_once 'custom/include/WPS/WpsBeanSerializer.php';

/**
 * after_save → XADD {stream} MAXLEN ~ N * data '{json}' — the format read by WPS EventProcessorService:
 * a JSON object with "id", "module" / "object_type" and all stored fields of the record.
 *
 * A Redis failure never breaks saving the record: it is written to logs/legacy/suitecrm.log ([WPS] prefix) and
 * the PHP error log, and the save goes on.
 */
class WpsEventHook
{
    /** @var WpsRedisClient|null connection reused within one request (a request may save many beans) */
    private static $client;

    /**
     * @param SugarBean $bean
     * @param string $event
     * @param array $arguments
     */
    public function afterSave($bean, $event, $arguments): void
    {
        try {
            if (!WpsConfig::enabled() || !($bean instanceof SugarBean) || empty($bean->id)) {
                return;
            }
            $module = (string)($bean->module_dir ?: $bean->module_name);
            if ($module === '' || !WpsConfig::isEventModule($module)) {
                return;
            }
            // the bean in memory holds values after cleanBean() (HTML-encoded) and, for UI saves, dates in
            // the user's format — publish the record as stored instead (the same values the API returns)
            $stored = WpsBeanSerializer::load($module, (string)$bean->id);
            $payload = ['id' => (string)$bean->id, 'module' => $module, 'object_type' => $module]
                + WpsBeanSerializer::toArray($stored ?? $bean);
            $payload['wps_event'] = 'after_save';
            // SuiteCRM 7 calls after_save without arguments: an update is a bean loaded from the DB before saving
            $isUpdate = isset($arguments['isUpdate']) ? !empty($arguments['isUpdate']) : !empty($bean->fetched_row);
            $payload['wps_is_update'] = $isUpdate ? '1' : '0';
            $payload['wps_user_id'] = isset($GLOBALS['current_user']->id) ? (string)$GLOBALS['current_user']->id : '';
            $payload['wps_published_at'] = gmdate('Y-m-d\TH:i:s\Z');

            $messageId = self::publish($payload);
            self::log('debug', "published {$module} {$bean->id} as {$messageId}");
        } catch (Throwable $e) {
            self::$client = null;
            self::log('fatal', 'publish failed for ' . get_class($bean) . ' ' . ($bean->id ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function publish(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        $args = ['XADD', (string)WpsConfig::get('stream')];
        $maxLen = (int)WpsConfig::get('stream_maxlen');
        if ($maxLen > 0) {
            array_push($args, 'MAXLEN', '~', $maxLen);
        }
        array_push($args, '*', 'data', $json);

        $reused = self::$client !== null;
        try {
            $id = self::client()->command($args);
        } catch (RuntimeException $e) {
            self::$client = null;
            if (!$reused) {
                throw $e;
            }
            // a reused connection may have been closed by the server: reconnect once
            $id = self::client()->command($args);
        }
        return (string)$id;
    }

    private static function client(): WpsRedisClient
    {
        if (self::$client === null) {
            self::$client = new WpsRedisClient(
                (string)WpsConfig::get('redis_host'),
                (int)WpsConfig::get('redis_port'),
                (string)WpsConfig::get('redis_password'),
                (float)WpsConfig::get('redis_timeout')
            );
        }
        return self::$client;
    }

    private static function log(string $level, string $message): void
    {
        if (isset($GLOBALS['log'])) {
            $GLOBALS['log']->$level('[WPS] ' . $message);
        }
        if ($level === 'fatal') {
            error_log('[WPS] ' . $message);
        }
    }
}
