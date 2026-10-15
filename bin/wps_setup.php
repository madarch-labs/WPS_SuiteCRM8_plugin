<?php
/**
 * CLI setup of the WPS integration package. Run it from the legacy root of the CRM as the web server user:
 * the CRM root for SuiteCRM 7, public/legacy for SuiteCRM 8. bin/install.sh and bin/uninstall.sh call it for you.
 *
 *   php wps_setup.php config                        write $sugar_config['wps'] to config_override.php from WPS_* env vars
 *   php wps_setup.php install <package-dir> [--force]
 *                                                   install / re-install the Module Loader package (same as Admin → Module Loader);
 *                                                   --force skips the CRM version check of the manifest
 *   php wps_setup.php all <package-dir> [--force]   config + install
 *   php wps_setup.php uninstall [--keep-config] [--remove-fields]
 *                                                   uninstall the package (same as Module Loader → Uninstall);
 *                                                   by default the config block is removed and the custom field with its data is kept
 *   php wps_setup.php status                        what is installed and whether Redis is reachable (exit code 1 if not installed)
 *
 * Env (config): WPS_ENABLED, WPS_REDIS_HOST, WPS_REDIS_PORT, WPS_REDIS_PASSWORD, WPS_REDIS_TIMEOUT, WPS_REDIS_STREAM,
 *               WPS_REDIS_STREAM_MAXLEN, WPS_EVENT_MODULES (comma list, empty = all), WPS_API_STATIC_KEY, WPS_API_USER_ID.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}
if (!is_file('include/entryPoint.php') || !is_file('config.php')) {
    fwrite(STDERR, "Run this script from the legacy root of an installed SuiteCRM (SuiteCRM 7: the CRM root, SuiteCRM 8: public/legacy)\n");
    exit(1);
}

const WPS_ID_NAME = 'wps_integration';

/** unpacked package copies in the temp dir; removed at exit, also after a fatal error */
$GLOBALS['wps_tmp_dirs'] = [];
register_shutdown_function(static function (): void {
    foreach ($GLOBALS['wps_tmp_dirs'] as $dir) {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
});

function wps_tmp_dir(string $prefix): string
{
    $dir = sys_get_temp_dir() . "/{$prefix}_" . getmypid();
    $GLOBALS['wps_tmp_dirs'][] = $dir;
    return $dir;
}

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn($a) => strncmp($a, '--', 2) === 0));
$positional = array_values(array_filter($args, static fn($a) => strncmp($a, '--', 2) !== 0));
$command = $positional[0] ?? '';
$packageDir = isset($positional[1]) ? rtrim($positional[1], '/') : '';

if (!in_array($command, ['config', 'install', 'all', 'uninstall', 'status'], true)) {
    fwrite(STDERR, "Usage: php wps_setup.php config | install <package-dir> [--force] | all <package-dir> [--force]"
        . " | uninstall [--keep-config] [--remove-fields] | status\n");
    exit(2);
}

require_once 'include/entryPoint.php';
require_once 'modules/Configurator/Configurator.php';
require_once 'ModuleInstall/ModuleInstaller.php';
require_once 'modules/Administration/UpgradeHistory.php';

$GLOBALS['current_user'] = BeanFactory::getBean('Users', '1');
$GLOBALS['current_language'] = $GLOBALS['sugar_config']['default_language'] ?? 'en_us';
$GLOBALS['app_list_strings'] = return_app_list_strings_language($GLOBALS['current_language']);
$GLOBALS['app_strings'] = return_application_language($GLOBALS['current_language']);
$GLOBALS['mod_strings'] = return_module_language($GLOBALS['current_language'], 'Administration');

function wps_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function wps_crm_version(): string
{
    global $suitecrm_version;
    if (empty($suitecrm_version) && is_file('suitecrm_version.php')) {
        include 'suitecrm_version.php';
    }
    return (string)($suitecrm_version ?? '');
}

function wps_configure(): void
{
    $modules = array_values(array_filter(array_map('trim', explode(',', wps_env('WPS_EVENT_MODULES', 'Leads,Contacts,Accounts,Opportunities,Cases,Calls,Tasks,Meetings'))), 'strlen'));
    $key = wps_env('WPS_API_STATIC_KEY');
    if ($key === '') {
        fwrite(STDERR, "WARNING: WPS_API_STATIC_KEY is empty: the /api/v9 endpoint will reject every request\n");
    }
    $configurator = new Configurator();
    $configurator->config['wps'] = [
        'enabled' => filter_var(wps_env('WPS_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'redis_host' => wps_env('WPS_REDIS_HOST', 'redis-stream'),
        'redis_port' => (int)wps_env('WPS_REDIS_PORT', '6379'),
        'redis_password' => wps_env('WPS_REDIS_PASSWORD'),
        'redis_timeout' => (float)wps_env('WPS_REDIS_TIMEOUT', '1.0'),
        'stream' => wps_env('WPS_REDIS_STREAM', 'crm_events'),
        'stream_maxlen' => (int)wps_env('WPS_REDIS_STREAM_MAXLEN', '100000'),
        'event_modules' => $modules,
        'api_static_key' => $key,
        'api_user_id' => wps_env('WPS_API_USER_ID', '1'),
        'api_readonly_modules' => ['Users', 'Employees'],
    ];
    $configurator->handleOverride();
    $GLOBALS['sugar_config']['wps'] = $configurator->config['wps'];
    echo "WPS config written to config_override.php (stream {$configurator->config['wps']['stream']} @ "
        . "{$configurator->config['wps']['redis_host']}:{$configurator->config['wps']['redis_port']}, modules: "
        . ($modules === [] ? 'all' : implode(',', $modules)) . ")\n";
}

function wps_unconfigure(): void
{
    $file = 'config_override.php';
    if (!is_file($file)) {
        return;
    }
    $lines = file($file);
    $kept = array_filter($lines, static fn($line) => !preg_match('/^\s*\$sugar_config\[\'wps\'\]/', $line));
    if (count($kept) !== count($lines)) {
        file_put_contents($file, implode('', $kept));
        echo "WPS config removed from config_override.php\n";
    }
}

function wps_upload_dir(): string
{
    return rtrim($GLOBALS['sugar_config']['upload_dir'] ?? 'upload/', '/') . '/upgrades/module';
}

function wps_installed(): ?UpgradeHistory
{
    // upgrade_history has no "deleted" column
    return (new UpgradeHistory())->retrieve_by_string_fields(['id_name' => WPS_ID_NAME, 'status' => 'installed'], true, false);
}

function wps_field_exists(array $field): bool
{
    $fieldId = $field['module'] . $field['name'] . (substr($field['name'], -2) === '_c' ? '' : '_c');
    $db = DBManagerFactory::getInstance();
    return (bool)$db->getOne('SELECT id FROM fields_meta_data WHERE deleted = 0 AND id = ' . $db->quoted($fieldId));
}

/**
 * ModuleInstaller derives its backup ("restore point") directory from the Module Loader request: the zip path behind
 * $_REQUEST['install_file']. Set it as Module Loader does, so backups go next to the zip and not to "-restore/" in the CRM root.
 */
/**
 * Runs a ModuleInstaller call and prints its own output (it echoes notes without a line break) on a separate line.
 */
function wps_mi(callable $call): void
{
    ob_start();
    try {
        $call();
    } finally {
        $out = trim(strip_tags((string)ob_get_clean()));
        if ($out !== '') {
            echo "ModuleInstaller: {$out}\n";
        }
    }
}

function wps_set_install_file(string $zipPath): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
    $hash = md5($zipPath);
    $_SESSION['file2Hash'][$hash] = $zipPath;
    $_REQUEST['install_file'] = $hash;
}

function wps_remove_previous_files(?UpgradeHistory $previous, array $installdefs): void
{
    if ($previous !== null && is_file($previous->filename)) {
        $work = wps_tmp_dir('wps_prev');
        $zip = new ZipArchive();
        if ($zip->open($previous->filename) === true) {
            $zip->extractTo($work);
            $zip->close();
            wps_set_install_file($previous->filename);
            $GLOBALS['mi_remove_tables'] = false;
            $installer = new ModuleInstaller();
            $installer->silent = true;
            wps_mi(static fn() => $installer->uninstall($work));
            echo "Removed the files of the installed version {$previous->version}\n";
            return;
        }
    }
    // no installed version (or its zip is gone), but the package directories exist: leftovers of a manual copy or of
    // an older installer. They belong to the package and are replaced by this installation.
    foreach ($installdefs['copy'] ?? [] as $copy) {
        if (is_dir($copy['to'])) {
            rmdir_recursive($copy['to']);
            echo "Removed leftover {$copy['to']}/ (" . ($previous ? "zip of version {$previous->version} not found" : 'package not registered') . ")\n";
        }
    }
}

function wps_install(string $packageDir, bool $force): void
{
    if ($packageDir === '' || !is_file("{$packageDir}/manifest.php")) {
        throw new RuntimeException("Package directory with manifest.php expected, got \"{$packageDir}\"");
    }
    $manifest = [];
    $installdefs = [];
    include "{$packageDir}/manifest.php";
    $version = $manifest['version'];

    // the CRM version check of Module Loader (ModuleInstaller itself does not check it)
    $crmVersion = wps_crm_version();
    $patterns = $manifest['acceptable_suitecrm_versions']['regex_matches'] ?? [];
    $accepted = $patterns === [];
    foreach ($patterns as $pattern) {
        $accepted = $accepted || preg_match("/{$pattern}/", $crmVersion) === 1;
    }
    if (!$accepted) {
        $message = "{$manifest['name']} {$version} does not support SuiteCRM {$crmVersion} (accepted: " . implode(', ', $patterns) . ')';
        if (!$force) {
            throw new RuntimeException($message . '; use --force to install anyway');
        }
        fwrite(STDERR, "WARNING: {$message}, installing because of --force\n");
    }

    // keep a zip copy in upload/upgrades/module, as Module Loader does (needed to uninstall from the UI)
    $uploadDir = wps_upload_dir();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $zipPath = "{$uploadDir}/" . WPS_ID_NAME . "-{$version}.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create {$zipPath}");
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($packageDir) + 1));
    }
    $zip->close();
    copy("{$packageDir}/manifest.php", "{$uploadDir}/" . WPS_ID_NAME . "-{$version}-manifest.php");

    // unpacked copy the installer works from (Module Loader unzips to cache/upload/upgrades/temp)
    $work = wps_tmp_dir('wps_pkg');
    $zip->open($zipPath);
    $zip->extractTo($work);
    $zip->close();

    $previous = wps_installed();
    $isUpgrade = $previous !== null;
    // like an upgrade in Module Loader: remove the files of the installed version first (custom field and config stay).
    // Otherwise ModuleInstaller backs the old files up as a "restore point" and puts them back on uninstall.
    wps_remove_previous_files($previous, $installdefs);

    wps_set_install_file($zipPath);
    $installer = new ModuleInstaller();
    $installer->silent = true;
    wps_mi(static fn() => $installer->install($work, $isUpgrade, $isUpgrade ? (string)$previous->version : ''));

    // a re-install of another version leaves the old zip behind
    if ($isUpgrade && $previous->filename !== $zipPath && is_file($previous->filename)) {
        @unlink($previous->filename);
        @unlink(preg_replace('/\.zip$/', '-manifest.php', $previous->filename));
    }
    $history = $isUpgrade ? $previous : new UpgradeHistory();
    $history->filename = $zipPath;
    $history->md5sum = md5_file($zipPath);
    $history->type = $manifest['type'];
    $history->version = $version;
    $history->status = 'installed';
    $history->name = $manifest['name'];
    $history->description = $manifest['description'];
    $history->id_name = WPS_ID_NAME;
    $history->manifest = base64_encode(serialize(['manifest' => $manifest, 'installdefs' => $installdefs, 'upgrade_manifest' => []]));
    $history->enabled = 1;
    $history->save();

    echo ($isUpgrade ? "Re-installed package {$manifest['name']} {$version} (was {$previous->version})" : "Installed package {$manifest['name']} {$version}")
        . " into SuiteCRM {$crmVersion}\n";
}

function wps_uninstall(bool $keepConfig, bool $removeFields): void
{
    $history = wps_installed();
    if ($history === null) {
        echo "Package " . WPS_ID_NAME . " is not installed\n";
    } else {
        if (!is_file($history->filename)) {
            throw new RuntimeException("Package zip {$history->filename} is missing: re-install the package first, then uninstall");
        }
        $work = wps_tmp_dir('wps_pkg');
        $zip = new ZipArchive();
        if ($zip->open($history->filename) !== true) {
            throw new RuntimeException("Cannot open {$history->filename}");
        }
        $zip->extractTo($work);
        $zip->close();

        // same as Module Loader → Uninstall: "remove tables" decides whether custom fields (and their data) are dropped
        $GLOBALS['mi_remove_tables'] = $removeFields;
        wps_set_install_file($history->filename);
        $installer = new ModuleInstaller();
        $installer->silent = true;
        wps_mi(static fn() => $installer->uninstall($work));

        $version = $history->version;
        @unlink($history->filename);
        @unlink(preg_replace('/\.zip$/', '-manifest.php', $history->filename));
        $history->delete();
        echo "Uninstalled package {$history->name} {$version}"
            . ($removeFields ? ' (custom field Leads.wps_processed_c and its data removed)' : ' (custom field Leads.wps_processed_c kept)') . "\n";
    }
    if (!$keepConfig) {
        wps_unconfigure();
    }
}

function wps_status(): int
{
    $history = wps_installed();
    echo 'SuiteCRM:        ' . wps_crm_version() . "\n";
    echo 'Package:         ' . ($history ? "{$history->name} {$history->version} (installed)" : 'not installed') . "\n";
    $hooks = @file_get_contents('custom/application/Ext/LogicHooks/logichooks.ext.php') ?: '';
    echo 'after_save hook: ' . (strpos($hooks, 'WpsEventHook') !== false ? 'registered' : 'missing') . "\n";
    $entryPoints = @file_get_contents('custom/application/Ext/EntryPointRegistry/entry_point_registry.ext.php') ?: '';
    echo 'entry point:     ' . (strpos($entryPoints, 'wpsApi') !== false ? 'wpsApi registered' : 'missing') . "\n";
    echo 'custom field:    Leads.wps_processed_c ' . (wps_field_exists(['module' => 'Leads', 'name' => 'wps_processed']) ? 'exists' : 'missing') . "\n";
    $config = $GLOBALS['sugar_config']['wps'] ?? null;
    if (!is_array($config)) {
        echo "config:          \$sugar_config['wps'] is not set (run: wps_setup.php config)\n";
        return $history ? 0 : 1;
    }
    $modules = $config['event_modules'] ?? [];
    echo 'config:          enabled=' . var_export((bool)($config['enabled'] ?? true), true)
        . ", stream {$config['stream']} @ {$config['redis_host']}:{$config['redis_port']}, modules: "
        . (empty($modules) ? 'all' : implode(',', (array)$modules))
        . ', api key ' . (($config['api_static_key'] ?? '') === '' ? 'EMPTY' : 'set') . "\n";
    if (is_file('custom/include/WPS/WpsRedisClient.php')) {
        require_once 'custom/include/WPS/WpsRedisClient.php';
        try {
            $client = new WpsRedisClient((string)$config['redis_host'], (int)$config['redis_port'], (string)($config['redis_password'] ?? ''), (float)($config['redis_timeout'] ?? 1.0));
            $length = $client->command(['XLEN', (string)$config['stream']]);
            echo "redis:           reachable, stream length {$length}\n";
        } catch (Throwable $e) {
            echo 'redis:           NOT reachable: ' . $e->getMessage() . " (records are still saved; events are lost until Redis is reachable)\n";
        }
    }
    return $history ? 0 : 1;
}

try {
    switch ($command) {
        case 'config':
            wps_configure();
            break;
        case 'install':
            wps_install($packageDir, in_array('--force', $flags, true));
            break;
        case 'all':
            wps_configure();
            wps_install($packageDir, in_array('--force', $flags, true));
            break;
        case 'uninstall':
            wps_uninstall(in_array('--keep-config', $flags, true), in_array('--remove-fields', $flags, true));
            break;
        case 'status':
            exit(wps_status());
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'WPS setup failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
