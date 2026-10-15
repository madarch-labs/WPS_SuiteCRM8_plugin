<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

// Included by ModuleInstaller::post_execute() ($this is the ModuleInstaller), for Module Loader and the CLI alike.
require_once __DIR__ . '/wps_fields.php';

$wpsMissingFields = [];
foreach (wps_package_custom_fields() as $wpsField) {
    if (wps_package_field_exists($wpsField)) {
        $GLOBALS['log']->info("[WPS] custom field {$wpsField['module']}.{$wpsField['name']}_c already exists, kept as is");
    } else {
        $wpsMissingFields[] = $wpsField;
    }
}
if ($wpsMissingFields !== []) {
    $this->install_custom_fields($wpsMissingFields);
}
