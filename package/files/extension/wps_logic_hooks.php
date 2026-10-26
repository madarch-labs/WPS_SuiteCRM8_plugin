<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

// Application-level hooks: called for every bean of every module; the module filter and the switches are in WpsConfig.
$hook_version = 1;
$hook_array['before_save'][] = [
    1000,
    'WPS: remember the stored row before the save (event "before" / "changed_fields")',
    'custom/include/WPS/WpsEventHook.php',
    'WpsEventHook',
    'beforeSave',
];
$hook_array['after_save'][] = [
    1000,
    'WPS: publish the saved record to the WPS Redis Stream',
    'custom/include/WPS/WpsEventHook.php',
    'WpsEventHook',
    'afterSave',
];
$hook_array['after_delete'][] = [
    1000,
    'WPS: publish the deleted record to the WPS Redis Stream',
    'custom/include/WPS/WpsEventHook.php',
    'WpsEventHook',
    'afterDelete',
];
$hook_array['after_relationship_add'][] = [
    1000,
    'WPS: publish "link" events (publish_relationship_events)',
    'custom/include/WPS/WpsEventHook.php',
    'WpsEventHook',
    'afterRelationshipAdd',
];
$hook_array['after_relationship_delete'][] = [
    1000,
    'WPS: publish "unlink" events (publish_relationship_events)',
    'custom/include/WPS/WpsEventHook.php',
    'WpsEventHook',
    'afterRelationshipDelete',
];
