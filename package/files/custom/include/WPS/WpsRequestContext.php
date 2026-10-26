<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * Origin of the current HTTP request when it comes from WPS (headers X-WPS-Execution-Id, X-WPS-Process-Id,
 * X-WPS-Depth). Every event published while this request runs — including cascade saves of other beans made by
 * logic hooks — carries it as "wps_origin" (protection against process cascades, WPS md/tasks_list/7.5-limits.md).
 */
final class WpsRequestContext
{
    /** @var array{execution_id: string, process_id: int|null, depth: int}|null */
    private static $origin;

    /**
     * @param array<string, mixed> $server $_SERVER
     */
    public static function setFromServer(array $server): void
    {
        $executionId = (string)($server['HTTP_X_WPS_EXECUTION_ID'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $executionId)) {
            self::$origin = null;
            return;
        }
        $processId = (string)($server['HTTP_X_WPS_PROCESS_ID'] ?? '');
        $depth = (string)($server['HTTP_X_WPS_DEPTH'] ?? '0');
        self::$origin = [
            'execution_id' => strtolower($executionId),
            'process_id' => ctype_digit($processId) ? (int)$processId : null,
            'depth' => ctype_digit($depth) ? (int)$depth : 0,
        ];
    }

    /**
     * @return array{execution_id: string, process_id: int|null, depth: int}|null
     */
    public static function origin(): ?array
    {
        return self::$origin;
    }
}
