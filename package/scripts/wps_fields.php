<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * Custom fields of the WPS package. They are created by scripts/wps_post_execute.php instead of manifest "custom_fields":
 * ModuleInstaller runs "ALTER TABLE ... ADD" for manifest custom fields even when the field already exists, so
 * re-installing the package (Module Loader or CLI) after an uninstall that kept the field failed with "Duplicate column".
 * The functions are guarded: an upgrade loads this file of the old and of the new version in one request.
 */
if (!function_exists('wps_package_custom_fields')) {
    function wps_package_custom_fields(): array
    {
        return [
            [
                'name' => 'wps_processed',
                'label' => 'LBL_WPS_PROCESSED',
                'type' => 'bool',
                'module' => 'Leads',
                'default' => '0',
                'required' => false,
                'reportable' => true,
                'audited' => false,
                'importable' => 'true',
                'duplicate_merge' => false,
                'mass_update' => false,
                'help' => 'Set by WPS after the lead has been processed (protection against re-processing)',
                'comments' => 'WPS marker',
            ],
        ];
    }
}

if (!function_exists('wps_package_field_exists')) {
    /**
     * true if the field is registered in fields_meta_data or its column already exists in the _cstm table
     */
    function wps_package_field_exists(array $field): bool
    {
        $db = DBManagerFactory::getInstance();
        $name = substr($field['name'], -2) === '_c' ? $field['name'] : $field['name'] . '_c';
        if ($db->getOne('SELECT id FROM fields_meta_data WHERE deleted = 0 AND id = ' . $db->quoted($field['module'] . $name))) {
            return true;
        }
        $bean = BeanFactory::newBean($field['module']);
        $table = $bean ? $bean->get_custom_table_name() : '';
        if ($table !== '' && $db->tableExists($table)) {
            return array_key_exists($name, $db->get_columns($table));
        }
        return false;
    }
}
