<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

// /legacy/index.php?entryPoint=wpsApi&wps_path={module}[/{id}] — authorised by X-Auth-Key inside the entry point.
$entry_point_registry['wpsApi'] = [
    'file' => 'custom/include/WPS/wps_api_entry_point.php',
    'auth' => false,
];
