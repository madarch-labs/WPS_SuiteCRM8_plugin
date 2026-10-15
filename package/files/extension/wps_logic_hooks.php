<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

// Application-level hook: called for every bean of every module; the module filter is in WpsConfig.
$hook_version = 1;
$hook_array['after_save'][] = [
    1000,
    'WPS: publish the saved record to the WPS Redis Stream',
    'custom/include/WPS/WpsEventHook.php',
    'WpsEventHook',
    'afterSave',
];
