<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'custom/include/WPS/WpsApi.php';

(new WpsApi())->handle();
