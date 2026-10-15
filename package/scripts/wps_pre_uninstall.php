<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

// Included by ModuleInstaller::pre_uninstall() ($this is the ModuleInstaller). The custom fields and their data are
// removed only when the uninstall is asked to remove tables (Module Loader: "Remove tables", CLI: --remove-fields).
require_once __DIR__ . '/wps_fields.php';

if (!empty($GLOBALS['mi_remove_tables'])) {
    $wpsFields = [];
    foreach (array_filter(wps_package_custom_fields(), 'wps_package_field_exists') as $wpsField) {
        // DynamicField::deleteField() uses the name as is: it needs the real field name with the "_c" suffix
        // (with "wps_processed" it would look for "Leadswps_processed" and drop a column that does not exist)
        $wpsField['name'] = substr($wpsField['name'], -2) === '_c' ? $wpsField['name'] : $wpsField['name'] . '_c';
        $wpsFields[] = $wpsField;
    }
    if ($wpsFields !== []) {
        $this->uninstall_custom_fields($wpsFields);
    }
}
