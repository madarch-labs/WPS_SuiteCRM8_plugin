<?php
/**
 * WPS Integration for SuiteCRM 8 — Module Loader package.
 *
 *  - application-level logic hooks before_save / after_save / after_delete (+ relationship hooks, off by default) →
 *    XADD {stream} * data '{json}' into the WPS Redis Stream: events v2 with event_type, before, changed_fields, wps_origin;
 *  - custom entry point "wpsApi" → CRM API with the WPS contract 2.0 (JSON:API, X-Auth-Key): capabilities, modules,
 *      GET/POST/PUT/DELETE /api/v9/{module}[/{id}], search with filter operators / sort / cursor / fields / count,
 *      /api/v9/{module}/{id}/relationships/{link}[/{related_id}] with relationship fields
 *    (/api/v9/... is mapped to /legacy/index.php?entryPoint=wpsApi&wps_path=... by a web server rewrite rule placed before
 *    the Symfony front controller, config/apache-wps.conf). Installed into the legacy layer public/legacy, where
 *    SuiteCRM 8 runs logic hooks, entry points and the Extension framework of SuiteCRM 7.
 *  - custom field Leads.wps_processed_c — "Processed by WPS" marker set by WPS processes (scripts/wps_post_execute.php).
 *
 * Settings are read from $sugar_config['wps'] (config_override.php), see WpsConfig and bin/wps_setup.php.
 */

$manifest = [
    // the legacy core of SuiteCRM 7 and 8 reports $sugar_version 6.5.x; the real check is acceptable_suitecrm_versions
    'acceptable_sugar_versions' => ['regex_matches' => ['^6\\.5\\.']],
    'acceptable_suitecrm_versions' => ['regex_matches' => ['^8\\.10\\.']],
    'acceptable_sugar_flavors' => ['CE'],
    'readme' => '',
    'key' => 'wps',
    'author' => 'WPS',
    'description' => 'Workflow Processing Service integration for SuiteCRM 8: events v2 (create/update/delete with old values) to the WPS Redis Stream + /api/v9 endpoint (contract 2.0)',
    'icon' => '',
    'is_uninstallable' => true,
    'name' => 'WPS Integration for SuiteCRM 8',
    'published_date' => '2026-10-20 00:00:00',
    'type' => 'module',
    'version' => '1.2.0',
    'remove_tables' => 'prompt',
];

$installdefs = [
    'id' => 'wps_integration',
    'copy' => [
        ['from' => '<basepath>/files/custom/include/WPS', 'to' => 'custom/include/WPS'],
    ],
    'hookdefs' => [
        ['from' => '<basepath>/files/extension/wps_logic_hooks.php', 'to_module' => 'application'],
    ],
    'entrypoints' => [
        ['from' => '<basepath>/files/extension/wps_entry_point.php', 'to_module' => 'application'],
    ],
    'language' => [
        ['from' => '<basepath>/files/extension/language/Leads.en_us.wps.php', 'to_module' => 'Leads', 'language' => 'en_us'],
        ['from' => '<basepath>/files/extension/language/Leads.ru_RU.wps.php', 'to_module' => 'Leads', 'language' => 'ru_RU'],
    ],
    // the custom field Leads.wps_processed_c is created / removed by these scripts (idempotent, see scripts/wps_fields.php)
    'post_execute' => ['<basepath>/scripts/wps_post_execute.php'],
    'pre_uninstall' => ['<basepath>/scripts/wps_pre_uninstall.php'],
];
