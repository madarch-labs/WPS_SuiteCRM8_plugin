<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsConfig.php';
require_once 'custom/include/WPS/WpsRedisClient.php';
require_once 'custom/include/WPS/WpsBeanSerializer.php';
require_once 'custom/include/WPS/WpsChangeDetector.php';
require_once 'custom/include/WPS/WpsRequestContext.php';

/**
 * Events of the WPS contract 2.0: XADD {stream} MAXLEN ~ N * data '{json}' — a JSON object with "id",
 * "module" / "object_type", all stored fields of the record (new values) and:
 *
 *   event_type        create | update | delete (link | unlink when publish_relationship_events is on)
 *   before            old values of the changed fields ({} for create / delete)
 *   changed_fields    changed fields (all filled fields for create; ignore_change_fields are never listed)
 *   wps_origin        {execution_id, process_id, depth} when the save was made by a WPS request (X-WPS-* headers)
 *   wps_event_version 2; wps_event, wps_is_update, wps_user_id, wps_published_at — kept for compatibility
 *
 * before_save remembers whether the record is new and its stored row before the save; after_save compares it with the
 * row re-read from the DB. A Redis failure never breaks saving the record: it is written to logs/legacy/suitecrm.log
 * ([WPS] prefix) and the PHP error log, and the save goes on.
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
    public function beforeSave($bean, $event, $arguments): void
    {
        try {
            if (!WpsConfig::enabled() || !($bean instanceof SugarBean)) {
                return;
            }
            $module = self::module($bean);
            if ($module === '' || !WpsConfig::isEventModule($module)) {
                return;
            }
            // SugarBean::save() assigns the id of a new record before before_save, so an id does not mean
            // "stored": a record is new when it has no fetched_row and its row is not in the database yet
            $isNew = empty($bean->id) || !empty($bean->new_with_id);
            $before = [];
            if (!$isNew && isset($bean->wps_last_published_row) && is_array($bean->wps_last_published_row)) {
                // the same object saved again in this request: compare with what was published last time
                $before = $bean->wps_last_published_row;
            } elseif (!$isNew && !empty($bean->fetched_row) && is_array($bean->fetched_row)) {
                $before = $bean->fetched_row;
            } elseif (!$isNew) {
                // no retrieve(): read the stored row; none means the record is being created
                $stored = WpsBeanSerializer::load($module, (string)$bean->id, true);
                $isNew = $stored === null;
                $before = $stored !== null ? WpsBeanSerializer::toArray($stored) : [];
            }
            if (!$isNew && !array_key_exists('email1', $before) && WpsBeanSerializer::hasEmail($bean)) {
                // fetched_row has no e-mail: the stored primary address is still the old one at before_save
                $before['email1'] = WpsBeanSerializer::primaryEmail($bean);
            }
            $bean->wps_is_new = $isNew;
            $bean->wps_before_row = $before;
        } catch (Throwable $e) {
            self::log('fatal', 'before_save failed for ' . get_class($bean) . ': ' . $e->getMessage());
        }
    }

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
            $module = self::module($bean);
            if ($module === '' || !WpsConfig::isEventModule($module)) {
                return;
            }
            // the bean in memory holds values after cleanBean() (HTML-encoded) and, for UI saves, dates in
            // the user's format — publish the record as stored instead (the same values the API returns)
            $stored = WpsBeanSerializer::load($module, (string)$bean->id, true);
            $values = WpsBeanSerializer::toArray($stored ?? $bean);

            $isNew = isset($bean->wps_is_new) ? (bool)$bean->wps_is_new : empty($bean->fetched_row);
            $old = isset($bean->wps_before_row) && is_array($bean->wps_before_row) ? $bean->wps_before_row : [];
            $ignore = WpsConfig::getList('ignore_change_fields');
            if ($isNew) {
                $eventType = 'create';
                $before = [];
                $changed = WpsChangeDetector::filled($values, $ignore);
            } else {
                $wasDeleted = (string)($old['deleted'] ?? '0') === '1';
                $eventType = !$wasDeleted && (string)($values['deleted'] ?? '0') === '1' ? 'delete' : 'update';
                $before = $eventType === 'update' ? WpsChangeDetector::before($old, $values, (array)$bean->field_defs, $ignore) : [];
                $changed = array_keys($before);
            }
            unset($bean->wps_is_new, $bean->wps_before_row);
            $bean->wps_last_published_row = $values;
            if ($eventType === 'update' && $changed === [] && !WpsConfig::flag('publish_unchanged')) {
                return;
            }

            $payload = self::envelope($module, (string)$bean->id, $values, $eventType, $before, $changed);
            $payload['wps_event'] = 'after_save';
            $payload['wps_is_update'] = $isNew ? '0' : '1';
            $messageId = self::publish($payload);
            self::log('debug', "published {$eventType} {$module} {$bean->id} (" . count($changed) . " changed) as {$messageId}");
        } catch (Throwable $e) {
            self::$client = null;
            self::log('fatal', 'publish failed for ' . get_class($bean) . ' ' . ($bean->id ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * mark_deleted() → event "delete" with the record as it was stored (deleted = "1").
     *
     * @param SugarBean $bean
     * @param string $event
     * @param array $arguments ['id' => …]
     */
    public function afterDelete($bean, $event, $arguments): void
    {
        try {
            if (!WpsConfig::enabled() || !WpsConfig::flag('publish_delete_events') || !($bean instanceof SugarBean)) {
                return;
            }
            $module = self::module($bean);
            $id = (string)($arguments['id'] ?? $bean->id ?? '');
            if ($module === '' || $id === '' || !WpsConfig::isEventModule($module)) {
                return;
            }
            $stored = WpsBeanSerializer::load($module, $id, true);
            $values = $stored !== null ? WpsBeanSerializer::toArray($stored) : ['id' => $id];
            $values['deleted'] = '1';
            $payload = self::envelope($module, $id, $values, 'delete', [], []);
            $payload['wps_event'] = 'after_delete';
            $payload['wps_is_update'] = '1';
            self::publish($payload);
        } catch (Throwable $e) {
            self::$client = null;
            self::log('fatal', 'publish delete failed for ' . get_class($bean) . ': ' . $e->getMessage());
        }
    }

    /**
     * @param SugarBean $bean
     * @param string $event
     * @param array $arguments
     */
    public function afterRelationshipAdd($bean, $event, $arguments): void
    {
        $this->relationshipEvent($bean, 'link', (array)$arguments);
    }

    /**
     * @param SugarBean $bean
     * @param string $event
     * @param array $arguments
     */
    public function afterRelationshipDelete($bean, $event, $arguments): void
    {
        $this->relationshipEvent($bean, 'unlink', (array)$arguments);
    }

    /**
     * Relationship events (off by default, publish_relationship_events): who was linked to what.
     *
     * @param mixed $bean
     * @param array<string, mixed> $arguments
     */
    private function relationshipEvent($bean, string $type, array $arguments): void
    {
        try {
            if (!WpsConfig::enabled() || !WpsConfig::flag('publish_relationship_events') || !($bean instanceof SugarBean) || empty($bean->id)) {
                return;
            }
            $module = self::module($bean);
            if ($module === '' || !WpsConfig::isEventModule($module)) {
                return;
            }
            $payload = self::envelope($module, (string)$bean->id, ['id' => (string)$bean->id], $type, [], []);
            $payload['link'] = (string)($arguments['link'] ?? '');
            $payload['related_module'] = (string)($arguments['related_module'] ?? '');
            $payload['related_id'] = (string)($arguments['related_id'] ?? '');
            $payload['wps_event'] = 'after_relationship_' . ($type === 'link' ? 'add' : 'delete');
            self::publish($payload);
        } catch (Throwable $e) {
            self::$client = null;
            self::log('fatal', "publish {$type} failed: " . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $before
     * @param list<string> $changed
     * @return array<string, mixed>
     */
    private static function envelope(string $module, string $id, array $values, string $eventType, array $before, array $changed): array
    {
        $payload = ['id' => $id, 'module' => $module, 'object_type' => $module] + $values;
        $payload['event_type'] = $eventType;
        $payload['before'] = (object)$before;
        $payload['changed_fields'] = array_values($changed);
        $payload['wps_event_version'] = 2;
        $payload['wps_user_id'] = isset($GLOBALS['current_user']->id) ? (string)$GLOBALS['current_user']->id : '';
        $payload['wps_published_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $origin = WpsRequestContext::origin();
        if ($origin !== null) {
            $payload['wps_origin'] = $origin;
        }
        return $payload;
    }

    private static function module(SugarBean $bean): string
    {
        return (string)($bean->module_dir ?: $bean->module_name);
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
