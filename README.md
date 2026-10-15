# WPS Integration for SuiteCRM 8

**[English](#english)** · **[Русский](#russian)**

---

<a id="english"></a>
# English

An integration plugin connecting SuiteCRM 8 with **Workflow Processing Service (WPS)** — a business process
automation service. The package is self-contained: it contains only CRM code, installers, and documentation.

**How it works in SuiteCRM 8.** SuiteCRM 8 has a legacy layer `public/legacy` — this is SuiteCRM 7.x
(`$suitecrm_legacy`), and it supports logic hooks, custom entry points, the Extension framework, Module Loader, and
custom fields. The plugin is installed there. A logic hook also fires when saving from the new Angular interface:
GraphQL mutation `saveRecord` → `core/backend/Data/LegacyHandler/RecordHandler` → legacy `SugarBean::save()` →
`after_save`.

| | SuiteCRM 7 (package `suiteCRM7`) | SuiteCRM 8 (this package) |
|---|---|---|
| Install location | CRM root | `public/legacy` (CRM root = the directory containing `bin/console`) |
| rewrite `/api/v9/(.*)` | `/index.php?entryPoint=wpsApi&wps_path=$1` | `/legacy/index.php?entryPoint=wpsApi&wps_path=$1`, in the vhost before the Symfony front controller |
| after install | — | `php bin/console cache:clear` (Symfony caches legacy metadata) |
| `.htaccess` option | yes (`--htaccess`) | no: `public/.htaccess` is the Symfony front controller |
| Module Loader | Admin → Module Loader | Admin → Module Loader (legacy page inside an iframe at `#/administration/UpgradeWizard?view=module`) |
| logs | `suitecrm.log` | `logs/legacy/suitecrm.log`, `logs/prod/` |

- [1. Purpose and structure](#1-purpose-and-structure)
- [2. Compatibility](#2-compatibility)
- [3. Requirements](#3-requirements)
- [4. Installation](#4-installation)
- [5. Web server configuration](#5-web-server-configuration)
- [6. WPS environment variables](#6-wps-environment-variables)
- [7. Post-install verification](#7-post-install-verification)
- [8. Connecting WPS to this CRM](#8-connecting-wps-to-this-crm)
- [9. Upgrade](#9-upgrade)
- [10. Uninstall](#10-uninstall)
- [11. Troubleshooting](#11-troubleshooting)
- [12. Event and API contract](#12-event-and-api-contract)

## 1. Purpose and structure

The plugin connects the CRM and WPS in both directions:

| Direction | How it works |
|---|---|
| CRM → WPS | Application-level `after_save` logic hook (`WpsEventHook`) publishes the saved record to the WPS Redis Stream: `XADD crm_events MAXLEN ~ 100000 * data '{json}'`. The Redis client is written in pure PHP (`WpsRedisClient`); the phpredis extension is not required |
| WPS → CRM | Custom entry point `wpsApi` (`WpsApi`) serves the API with the WPS contract: `GET/POST/PUT /api/v9/{module}[/{id}]`, search `?filter[field]=value&size=1`, module dictionary `/api/v9/modules`. The `/api/v9/...` path is translated to the entry point by a web server rewrite rule |
| Marker | Field `Leads.wps_processed_c` ("Processed by WPS"), set by WPS processes |

The SuiteCRM core is not modified: files are installed into `custom/` via the Extension framework, the package is
visible in Admin → Module Loader and is removed from there.

```
SuiteCRM8/
├── README.md                 this file
├── CHANGELOG.md
├── VERSION                   package version (= manifest.php → version)
├── package/                  contents of the Module Loader zip (manifest.php at the root)
│   ├── manifest.php
│   ├── files/custom/include/WPS/   WpsApi, WpsEventHook, WpsRedisClient, WpsBeanSerializer, WpsConfig, entry point
│   ├── files/extension/            logic hook and entry point registration, field labels (en_us, ru_RU)
│   └── scripts/                    create / delete Leads.wps_processed_c (idempotent)
├── bin/
│   ├── install.sh            automatic install and reinstall
│   ├── uninstall.sh          removal
│   ├── build.sh              builds dist/wps_integration-<version>-suitecrm8.zip
│   └── wps_setup.php         CLI: config / install / uninstall / status (called from install.sh and uninstall.sh)
├── config/
│   ├── wps.env.example       all WPS_* parameters with descriptions
│   ├── apache-wps.conf       rewrite /api/v9 for VirtualHost
│   └── nginx-wps.conf        rewrite /api/v9 for nginx
└── dist/                     build.sh output
```

## 2. Compatibility

| | Version |
|---|---|
| SuiteCRM | 8.10.x (tested on 8.10.2, legacy layer 7.15.2). `manifest.php` accepts `^8\.10\.` |
| PHP | 8.2–8.4 (same as SuiteCRM 8.10; tested on 8.3) + `zip` extension |
| Database | MariaDB 10.6–11.8 / MySQL 8 (same as SuiteCRM 8.10; tested on MariaDB 11.4) |
| Web server | Apache 2.4 with `mod_rewrite` (tested); nginx — a snippet is provided, not tested |
| WPS | CRM API contract `/api/v9`, Redis Stream `crm_events` |

For SuiteCRM 7 there is a separate package `suiteCRM7`. To install this package into another 8.x at your own risk,
use `bin/install.sh --force` (Module Loader will refuse). Before that, make sure the legacy layer and Module Loader
are present in that version.

## 3. Requirements

- SuiteCRM 8 is installed (`'installer_locked' => true` is present in `public/legacy/config.php`).
- The WPS Redis Stream is reachable from the CRM (by default `redis-stream:6379`), and the CRM web server is
  reachable from WPS.
- For `bin/install.sh`: bash, PHP CLI of the same version as the CRM, root rights or the web server user
  (`www-data`) or `sudo`; for Docker mode — `docker` on the host.
- The web server user can write to `public/legacy/custom/`, `cache/`, `upload/`, `config_override.php`, and to the
  Symfony cache (`cache/` in the CRM root) — it is cleared by `bin/console cache:clear`.

## 4. Installation

First prepare the parameters (secrets end up neither in the package nor on the command line):

```bash
cp config/wps.env.example config/wps.env
# set WPS_API_STATIC_KEY (= CRM_API_STATIC_KEY in WPS) and the WPS Redis address
```

Parameters already set in the environment take precedence over the file. A different file is passed with
`--env-file`.

### 4.1. Script: SuiteCRM on this machine

1. Run from the package directory (as root, as `www-data`, or via `sudo`):

   ```bash
   bin/install.sh --crm-path /var/www/html --apache-conf /etc/apache2/sites-available/000-default.conf
   ```

2. The script, step by step:
   1. verifies this is an installed SuiteCRM 8 (`bin/console`, `public/legacy`), that PHP `zip` is present and
      write permissions are available;
   2. writes `$sugar_config['wps']` to `public/legacy/config_override.php`;
   3. installs the package via `ModuleInstaller` and registers it in Module Loader (`upgrade_history`), creates the
      `Leads.wps_processed_c` field if it does not exist yet;
   4. runs `php bin/console cache:clear`;
   5. adds the `/api/v9` rewrite to the specified VirtualHost between the `# BEGIN/END WPS integration` markers,
      checks the configuration (`apachectl -t`) and reloads Apache. Without `--apache-conf` the snippet is only
      printed (see [section 5](#5-web-server-configuration));
   6. prints the status and calls `GET /api/v9/modules` with a correct and an incorrect key: `200` and `401` are
      expected. The URL used for the check is `--site-url` (default `http://localhost`).
3. Exit code `0` — everything is installed and the API responds; `3` — the package is installed but the self-check
   failed.

All options: `bin/install.sh --help` (`--web-user`, `--skip-config`, `--skip-check`, `--force`).

### 4.2. Script: SuiteCRM in a Docker container

1. Run on the host where `docker` is available:

   ```bash
   bin/install.sh --docker <crm-container> --env-file config/wps.env \
       --apache-conf /etc/apache2/sites-available/000-default.conf
   ```

   `--crm-path` is the path inside the container (default `/var/www/html`), `--apache-conf` is also a path inside
   the container.
2. The script copies the package to the container's `/tmp` (`docker cp`), runs the same steps as `www-data`
   (`docker exec -u www-data`), and performs the self-check with curl inside the container. Temporary files are
   removed.
3. The plugin files live in the CRM directory and survive `docker compose restart`. The Apache configuration
   usually lives in the image: after recreating the container (`up --force-recreate`, a new image) the rule from
   `--apache-conf` will be gone. Therefore the rewrite is better placed into the image configuration (as in the
   `.tehnical/suiteCRM8` stand of the WPS repository). Recreating the CRM **volume** also removes the plugin —
   then the installation must be repeated.

### 4.3. Manually via Admin → Module Loader

1. Build the zip: `bin/build.sh` → `dist/wps_integration-1.1.0-suitecrm8.zip` (`manifest.php` at the archive root;
   requires `zip` or PHP CLI with `ext-zip`).
2. Log in to the CRM as an administrator, open **Admin → Module Loader** (user menu → Admin; the legacy page opens
   inside the new interface at `#/administration/UpgradeWizard?view=module`).
3. **Choose File** → select the zip → **Upload**.
4. In the "WPS Integration for SuiteCRM 8" row click **Install**, then **Commit**. Wait for "Module Installed
   Successfully".
5. Add the parameters to `public/legacy/config_override.php` (Module Loader does not write them). The easiest way,
   from the `public/legacy` directory:

   ```bash
   sudo -u www-data env $(grep -v '^#' /path/to/wps.env | xargs) php /path/to/SuiteCRM8/bin/wps_setup.php config
   ```

6. Clear the cache from the CRM root: `sudo -u www-data php bin/console cache:clear`.

   or manually — the keys are described in `package/files/custom/include/WPS/WpsConfig.php`.
7. Configure the web server ([section 5](#5-web-server-configuration)).

> **Warning.** In Module Loader, navigate through the Admin menu. A direct link to the legacy page
> `legacy/index.php?module=Administration&action=UpgradeWizard` without a Referer triggers SuiteCRM's XSRF
> protection.

## 5. Web server configuration

WPS builds URLs as `{CRM_API_BASE_URL}/{module}/{id}?filter...`, so the CRM must respond on the path `/api/v9/...`.
The entry point is called as `/legacy/index.php?entryPoint=wpsApi&wps_path=...`; a rewrite performs the translation.

**Apache, VirtualHost (recommended)** — `config/apache-wps.conf`, inside `<VirtualHost>`:

```apache
    # BEGIN WPS integration: /api/v9/{module}[/{id}] -> wpsApi entry point (query string is kept)
    RewriteEngine On
    RewriteRule ^/api/v9/(.*)$ /legacy/index.php?entryPoint=wpsApi&wps_path=$1 [QSA,PT,L]
    # END WPS integration
```

`mod_rewrite` is required (`a2enmod rewrite`), then `apachectl -t && apachectl -k graceful`. The rule must be in
the VirtualHost configuration, not in `public/.htaccess`: there it would fire after the rewrite to the Symfony front
controller (`public/index.php`) and the request would go to the new interface. This package has no `.htaccess`
variant.

**nginx** — `config/nginx-wps.conf`, in the `server { }` block before `location`:

```nginx
    rewrite ^/api/v9/(.*)$ /legacy/index.php?entryPoint=wpsApi&wps_path=$1 last;
```

This variant was not tested by the package author — verify it with the command from
[section 7](#7-post-install-verification).

If the CRM is served under a subpath (for example, `/crm`), add the prefix to both parts of the rule.

## 6. WPS environment variables

Written to `$sugar_config['wps']` (`config_override.php`) by `wps_setup.php config` (called by `install.sh`).

| Variable | `$sugar_config['wps']` key | Default | Description |
|---|---|---|---|
| `WPS_ENABLED` | `enabled` | `true` | `false` — events are not published (the API keeps working) |
| `WPS_REDIS_HOST` | `redis_host` | `redis-stream` | host of the WPS Redis Stream |
| `WPS_REDIS_PORT` | `redis_port` | `6379` | port |
| `WPS_REDIS_PASSWORD` | `redis_password` | empty | password (`AUTH`) |
| `WPS_REDIS_TIMEOUT` | `redis_timeout` | `1.0` | connection and read timeout, seconds: a record save does not wait longer when Redis is unavailable |
| `WPS_REDIS_STREAM` | `stream` | `crm_events` | stream name |
| `WPS_REDIS_STREAM_MAXLEN` | `stream_maxlen` | `100000` | `XADD MAXLEN ~` (`0` — unlimited) |
| `WPS_EVENT_MODULES` | `event_modules` | `Leads,Contacts,Accounts,Opportunities,Cases,Calls,Tasks,Meetings` | modules whose saves are published (empty — all) |
| `WPS_API_STATIC_KEY` | `api_static_key` | empty | API key: `X-Auth-Key = key . gmdate("Y.m.d H")`, the previous hour's key is also accepted. An empty key — the API responds 401 to everything |
| `WPS_API_USER_ID` | `api_user_id` | `1` | the CRM user the API acts as (`created_by`, `modified_user_id`) |
| — | `api_readonly_modules` | `Users, Employees` | modules that cannot be written to (405) |

To change parameters without reinstalling: `bin/install.sh … --skip-check` or `wps_setup.php config` from
`public/legacy`.

## 7. Post-install verification

```bash
# 1. status: package, hook, entry point, field, parameters, Redis availability (from public/legacy, as www-data)
sudo -u www-data php /path/to/SuiteCRM8/bin/wps_setup.php status

# 2. API with the key (the key changes every hour, time is UTC)
KEY="${WPS_API_STATIC_KEY}$(date -u '+%Y.%m.%d %H')"
curl -s -H "X-Auth-Key: $KEY" http://<crm>/api/v9/modules | head -c 200            # 200 and {"data":[{"type":"modules",...
curl -s -o /dev/null -w '%{http_code}\n' -H 'X-Auth-Key: wrong' http://<crm>/api/v9/modules   # 401
curl -s -g -H "X-Auth-Key: $KEY" "http://<crm>/api/v9/Leads?filter[status]=New&size=1"   # field search (-g: brackets are not a glob)

# 3. event: save any lead in the CRM and look at the tail of the stream in the WPS Redis
redis-cli -h <redis-wps> XREVRANGE crm_events + - COUNT 1
```

## 8. Connecting WPS to this CRM

In the WPS backend `.env`:

```bash
CRM_API_BASE_URL=http://<crm>/api/v9      # the path must end with /api/v9
CRM_API_STATIC_KEY=<the same WPS_API_STATIC_KEY>
MOCK_SERVICE_URL=                         # empty: the module dictionary is taken from the CRM
```

Restart the WPS backend and refresh the module dictionary (`POST /api/dictionaries/modules/refresh`).

## 9. Upgrade

Re-running `bin/install.sh` with a new package version is an upgrade: the script removes the files of the installed
version (as Module Loader does on upgrade), installs the new one and updates the Module Loader record. The
`wps_processed_c` field with its data and the parameters are preserved. The same run with the same version is a
reinstall (idempotent).

Via Module Loader: first **Uninstall** the old version (choose "Do not remove tables" to keep the field), then
install the new one as in [4.3](#43-manually-via-admin--module-loader).

## 10. Uninstall

```bash
bin/uninstall.sh --crm-path /var/www/html [--apache-conf <vhost>] [--keep-config] [--remove-fields]
bin/uninstall.sh --docker <container> [same options]
```

| What | Default | Option |
|---|---|---|
| `custom/include/WPS` files, logic hook, entry point, labels | removed | — |
| Module Loader record and zip | removed | — |
| `$sugar_config['wps']` block in `config_override.php` | removed | `--keep-config` — keep |
| `Leads.wps_processed_c` field **with data** | kept | `--remove-fields` — delete |
| rewrite in the vhost | kept | `--apache-conf <file>` — remove the block between the markers |

Via Module Loader: **Uninstall** in the package row; "Remove tables" also deletes the field with its data.

## 11. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `install.sh`: "is not the legacy root of SuiteCRM" | wrong `--crm-path` | specify the SuiteCRM 8 root (the directory with `bin/console`, not `public/legacy`) |
| "bin/console not found: is this SuiteCRM 7" | it is SuiteCRM 7 | use the `suiteCRM7` package |
| "does not support SuiteCRM x.y" | version outside `^8\.10\.` | update the CRM or `--force` at your own risk |
| after installing via Module Loader the field is not visible or the hook does not fire | possibly the Symfony cache holds stale legacy metadata (`install.sh` always clears it) | `php bin/console cache:clear` from the CRM root |
| self-check: `404` for both keys | no rewrite or the entry point is not registered | [section 5](#5-web-server-configuration); `wps_setup.php status` |
| self-check: `401` for both keys | empty or different key | `WPS_API_STATIC_KEY`; server clocks (UTC) |
| `/api/v9/...` serves the new interface HTML or redirects to `#/Login` | the rewrite did not fire, the request went into the Symfony front controller | the rule must be in the vhost, not in `public/.htaccess` |
| `POST/PUT` → "Bad data passed in" | body without `Content-Type: application/json` | WPS sends the header; add it in your own requests |
| saving a record is slow, `logs/legacy/suitecrm.log` shows `[WPS] publish failed` | the WPS Redis is unavailable | network / `WPS_REDIS_HOST`; the record is saved, the event is lost (no outbox); the timeout is `WPS_REDIS_TIMEOUT` |
| Module Loader: "Possible Cross Site Request Forgery" | a direct URL opened without a Referer | navigate via the Admin menu → Module Loader |
| Module Loader: "Duplicate column name 'wps_processed_c'" | installing version 1.0.0 (the field was in the `custom_fields` manifest) over a preserved field | use 1.1.0+: the field is created by the script only if missing |
| after uninstalling, `custom/include/WPS` files remain | installs with the old installer (1.0.0) reinstalled without removal and ModuleInstaller restored the "restore point" | `bin/install.sh` will remove the leftovers ("Removed leftover…"), then run `bin/uninstall.sh` |

Logs: `logs/legacy/suitecrm.log` (lines `[WPS]`), `logs/prod/` (Symfony), the Apache error log.

## 12. Event and API contract

**Event** (the `data` field of the stream message) — a JSON object: `id`, `module`, `object_type` and all stored
record fields, including `_cstm`, re-read from the DB (dates — DB format, UTC). Relations, non-db fields and secrets
(`user_hash` etc.) are not included. Service fields: `wps_event` (`after_save`), `wps_is_update` (`1`/`0`),
`wps_user_id`, `wps_published_at`.

**API** (`X-Auth-Key`, JSON:API):

| Request | Response |
|---|---|
| `GET /api/v9/modules` | `{data: [{type: "modules", id: "Leads", attributes: {name, label}}]}` |
| `GET /api/v9/{module}/{id}` | `{data: {type, id, attributes}}`, `404` if missing |
| `GET /api/v9/{module}?filter[f]=v&size=1` | `{data: [...], meta}`; filters are AND-ed, `size` ≤ 100, order `date_entered ASC`; unknown field → `422` |
| `POST /api/v9/{module}` | body `{data: {type, attributes}}` → `201 {data}` |
| `PUT /api/v9/{module}/{id}` | → `200 {data}`, `404` if missing |

Errors: `{errors: [{status, title, detail?}]}` — `400` (broken JSON), `401` (key), `404`, `405` (method or a record
in `Users`/`Employees`), `422`, `500`. Unknown and system attributes are not written; their list is in
`meta.ignored_attributes`. Dates are accepted as `Y-m-d H:i:s` (UTC) or ISO 8601.

---

<a id="russian"></a>
# Русский

Плагин интеграции SuiteCRM 8 с **Workflow Processing Service (WPS)** — сервисом автоматизации бизнес-процессов.
Пакет самодостаточен: в нём только код для CRM, установщики и документация.

**Как устроено в SuiteCRM 8.** В SuiteCRM 8 есть legacy-слой `public/legacy` — это SuiteCRM 7.x (`$suitecrm_legacy`),
и в нём работают logic hooks, custom entry points, Extension-фреймворк, Module Loader и custom fields. Плагин ставится
туда. Logic hook срабатывает и при сохранении из нового Angular-интерфейса: GraphQL-мутация `saveRecord` →
`core/backend/Data/LegacyHandler/RecordHandler` → legacy `SugarBean::save()` → `after_save`.

| | SuiteCRM 7 (пакет `suiteCRM7`) | SuiteCRM 8 (этот пакет) |
|---|---|---|
| Куда ставится | корень CRM | `public/legacy` (корень CRM = каталог с `bin/console`) |
| rewrite `/api/v9/(.*)` | `/index.php?entryPoint=wpsApi&wps_path=$1` | `/legacy/index.php?entryPoint=wpsApi&wps_path=$1`, в vhost до front controller Symfony |
| после установки | — | `php bin/console cache:clear` (Symfony кэширует legacy-метаданные) |
| `.htaccess`-вариант | есть (`--htaccess`) | нет: `public/.htaccess` — это front controller Symfony |
| Module Loader | Admin → Module Loader | Admin → Module Loader (legacy-страница во фрейме `#/administration/UpgradeWizard?view=module`) |
| логи | `suitecrm.log` | `logs/legacy/suitecrm.log`, `logs/prod/` |

- [1. Назначение и состав](#1-назначение-и-состав)
- [2. Совместимость](#2-совместимость)
- [3. Требования](#3-требования)
- [4. Установка](#4-установка)
- [5. Настройка веб-сервера](#5-настройка-веб-сервера)
- [6. Параметры WPS_*](#6-параметры-wps_)
- [7. Проверка после установки](#7-проверка-после-установки)
- [8. Подключение WPS к этой CRM](#8-подключение-wps-к-этой-crm)
- [9. Обновление](#9-обновление)
- [10. Удаление](#10-удаление)
- [11. Диагностика](#11-диагностика)
- [12. Контракт события и API](#12-контракт-события-и-api)

## 1. Назначение и состав

Плагин связывает CRM и WPS в обе стороны:

| Направление | Как сделано |
|---|---|
| CRM → WPS | logic hook `after_save` уровня приложения (`WpsEventHook`) публикует сохранённую запись в Redis Stream WPS: `XADD crm_events MAXLEN ~ 100000 * data '{json}'`. Клиент Redis написан на чистом PHP (`WpsRedisClient`), расширение phpredis не нужно |
| WPS → CRM | custom entry point `wpsApi` (`WpsApi`) отдаёт API с контрактом WPS: `GET/POST/PUT /api/v9/{module}[/{id}]`, поиск `?filter[field]=value&size=1`, справочник `/api/v9/modules`. Путь `/api/v9/...` переводится на entry point правилом rewrite веб-сервера |
| Маркер | поле `Leads.wps_processed_c` («Обработан WPS»), его ставят процессы WPS |

Ядро SuiteCRM не изменяется: файлы ставятся в `custom/` через Extension-фреймворк, пакет виден в
Admin → Module Loader и удаляется оттуда.

```
SuiteCRM8/
├── README.md                 этот файл
├── CHANGELOG.md
├── VERSION                   версия пакета (= manifest.php → version)
├── package/                  содержимое zip для Module Loader (manifest.php в корне)
│   ├── manifest.php
│   ├── files/custom/include/WPS/   WpsApi, WpsEventHook, WpsRedisClient, WpsBeanSerializer, WpsConfig, entry point
│   ├── files/extension/            регистрация logic hook и entry point, подписи поля (en_us, ru_RU)
│   └── scripts/                    создание / удаление поля Leads.wps_processed_c (идемпотентно)
├── bin/
│   ├── install.sh            автоматическая установка и переустановка
│   ├── uninstall.sh          удаление
│   ├── build.sh              сборка dist/wps_integration-<версия>-suitecrm8.zip
│   └── wps_setup.php         CLI: config / install / uninstall / status (вызывается из install.sh и uninstall.sh)
├── config/
│   ├── wps.env.example       все параметры WPS_* с описанием
│   ├── apache-wps.conf       rewrite /api/v9 для VirtualHost
│   └── nginx-wps.conf        rewrite /api/v9 для nginx
└── dist/                     результат build.sh
```

## 2. Совместимость

| | Версия |
|---|---|
| SuiteCRM | 8.10.x (проверено на 8.10.2, legacy-слой 7.15.2). `manifest.php` принимает `^8\.10\.` |
| PHP | 8.2–8.4 (как у SuiteCRM 8.10; проверено на 8.3) + расширение `zip` |
| БД | MariaDB 10.6–11.8 / MySQL 8 (как у SuiteCRM 8.10; проверено на MariaDB 11.4) |
| Веб-сервер | Apache 2.4 с `mod_rewrite` (проверено); nginx — фрагмент приложен, не проверялся |
| WPS | контракт CRM API `/api/v9`, Redis Stream `crm_events` |

Для SuiteCRM 7 есть отдельный пакет `suiteCRM7`. Чтобы поставить этот пакет в другую 8.x на свой риск, используйте
`bin/install.sh --force` (Module Loader откажет). Перед этим проверьте, что в этой версии legacy-слой и Module Loader на месте.

## 3. Требования

- SuiteCRM 8 установлена (в `public/legacy/config.php` есть `'installer_locked' => true`).
- Из CRM доступен Redis Stream WPS (по умолчанию `redis-stream:6379`), а из WPS — веб-сервер CRM.
- Для `bin/install.sh`: bash, PHP CLI той же версии, что у CRM, права root, пользователя веб-сервера (`www-data`)
  или `sudo`; для режима Docker — `docker` на хосте.
- Пользователь веб-сервера может писать в `public/legacy/custom/`, `cache/`, `upload/`, `config_override.php` и в
  Symfony-кэш (`cache/` в корне CRM) — его чистит `bin/console cache:clear`.

## 4. Установка

Сначала подготовьте параметры (секреты не попадают ни в пакет, ни в командную строку):

```bash
cp config/wps.env.example config/wps.env
# задайте WPS_API_STATIC_KEY (= CRM_API_STATIC_KEY в WPS) и адрес Redis WPS
```

Параметры, уже заданные в окружении, важнее файла. Другой файл передаётся флагом `--env-file`.

### 4.1. Скриптом: SuiteCRM на этой машине

1. Выполните из каталога пакета (от root, от `www-data` или через `sudo`):

   ```bash
   bin/install.sh --crm-path /var/www/html --apache-conf /etc/apache2/sites-available/000-default.conf
   ```

2. Скрипт по шагам:
   1. проверяет, что это установленная SuiteCRM 8 (`bin/console`, `public/legacy`), есть PHP `zip` и права на запись;
   2. пишет `$sugar_config['wps']` в `public/legacy/config_override.php`;
   3. ставит пакет через `ModuleInstaller` и регистрирует его в Module Loader (`upgrade_history`), создаёт поле
      `Leads.wps_processed_c`, если его ещё нет;
   4. выполняет `php bin/console cache:clear`;
   5. добавляет rewrite `/api/v9` в указанный VirtualHost между маркерами `# BEGIN/END WPS integration`, проверяет
      конфигурацию (`apachectl -t`) и перезагружает Apache. Без `--apache-conf` фрагмент только печатается
      (см. [раздел 5](#5-настройка-веб-сервера));
   6. печатает статус и вызывает `GET /api/v9/modules` с правильным и неправильным ключом: ожидается `200` и `401`.
      Адрес для проверки — `--site-url` (по умолчанию `http://localhost`).
3. Код выхода `0` — всё установлено и API отвечает; `3` — пакет установлен, но самопроверка не прошла.

Все опции: `bin/install.sh --help` (`--web-user`, `--skip-config`, `--skip-check`, `--force`).

### 4.2. Скриптом: SuiteCRM в Docker-контейнере

1. Выполните на хосте, где есть `docker`:

   ```bash
   bin/install.sh --docker <контейнер-с-crm> --env-file config/wps.env \
       --apache-conf /etc/apache2/sites-available/000-default.conf
   ```

   `--crm-path` — путь внутри контейнера (по умолчанию `/var/www/html`), `--apache-conf` — тоже путь внутри контейнера.
2. Скрипт копирует пакет в `/tmp` контейнера (`docker cp`), выполняет те же шаги от `www-data`
   (`docker exec -u www-data`), а самопроверку — curl внутри контейнера. Временные файлы удаляются.
3. Файлы плагина лежат в каталоге CRM и переживают `docker compose restart`. Конфигурация Apache обычно живёт в образе:
   после пересоздания контейнера (`up --force-recreate`, новый образ) правило из `--apache-conf` пропадёт. Поэтому
   rewrite лучше положить в конфигурацию образа (как в стенде `.tehnical/suiteCRM8` репозитория WPS). Пересоздание
   **тома** CRM удаляет и плагин — тогда установку нужно повторить.

### 4.3. Вручную через Admin → Module Loader

1. Соберите zip: `bin/build.sh` → `dist/wps_integration-1.1.0-suitecrm8.zip` (`manifest.php` в корне архива;
   нужен `zip` или PHP CLI с `ext-zip`).
2. Войдите в CRM администратором, откройте **Admin → Module Loader** (меню пользователя → Admin; страница legacy
   открывается внутри нового интерфейса по адресу `#/administration/UpgradeWizard?view=module`).
3. **Choose File** → выберите zip → **Upload**.
4. В строке «WPS Integration for SuiteCRM 8» нажмите **Install**, затем **Commit**. Дождитесь «Module Installed
   Successfully».
5. Добавьте параметры в `public/legacy/config_override.php` (Module Loader их не пишет). Удобнее всего так, из
   каталога `public/legacy`:

   ```bash
   sudo -u www-data env $(grep -v '^#' /path/to/wps.env | xargs) php /path/to/SuiteCRM8/bin/wps_setup.php config
   ```

6. Очистите кэш из корня CRM: `sudo -u www-data php bin/console cache:clear`.

   или вручную — ключи описаны в `package/files/custom/include/WPS/WpsConfig.php`.
7. Настройте веб-сервер ([раздел 5](#5-настройка-веб-сервера)).

> **Внимание.** В Module Loader переходите через меню Admin. Прямая ссылка на legacy-страницу
> `legacy/index.php?module=Administration&action=UpgradeWizard` без Referer вызывает защиту SuiteCRM от XSRF.

## 5. Настройка веб-сервера

WPS собирает URL как `{CRM_API_BASE_URL}/{module}/{id}?filter...`, поэтому CRM должна отвечать на путь `/api/v9/...`.
Entry point вызывается как `/legacy/index.php?entryPoint=wpsApi&wps_path=...`; перевод делает rewrite.

**Apache, VirtualHost (рекомендуется)** — `config/apache-wps.conf`, внутрь `<VirtualHost>`:

```apache
    # BEGIN WPS integration: /api/v9/{module}[/{id}] -> wpsApi entry point (query string is kept)
    RewriteEngine On
    RewriteRule ^/api/v9/(.*)$ /legacy/index.php?entryPoint=wpsApi&wps_path=$1 [QSA,PT,L]
    # END WPS integration
```

Нужен `mod_rewrite` (`a2enmod rewrite`), затем `apachectl -t && apachectl -k graceful`. Правило должно стоять в
конфигурации VirtualHost, а не в `public/.htaccess`: там оно сработало бы после rewrite на front controller Symfony
(`public/index.php`), и запрос ушёл бы в новый интерфейс. Варианта с `.htaccess` у этого пакета нет.

**nginx** — `config/nginx-wps.conf`, в блок `server { }` перед `location`:

```nginx
    rewrite ^/api/v9/(.*)$ /legacy/index.php?entryPoint=wpsApi&wps_path=$1 last;
```

Этот вариант не проверялся автором пакета — проверьте командой из [раздела 7](#7-проверка-после-установки).

Если CRM открыта по подпути (например, `/crm`), добавьте префикс в обе части правила.

## 6. Параметры WPS_*

Записываются в `$sugar_config['wps']` (`config_override.php`) командой `wps_setup.php config` (её вызывает `install.sh`).

| Переменная | Ключ `$sugar_config['wps']` | По умолчанию | Описание |
|---|---|---|---|
| `WPS_ENABLED` | `enabled` | `true` | `false` — события не публикуются (API продолжает работать) |
| `WPS_REDIS_HOST` | `redis_host` | `redis-stream` | хост Redis Stream WPS |
| `WPS_REDIS_PORT` | `redis_port` | `6379` | порт |
| `WPS_REDIS_PASSWORD` | `redis_password` | пусто | пароль (`AUTH`) |
| `WPS_REDIS_TIMEOUT` | `redis_timeout` | `1.0` | таймаут подключения и чтения, секунды: дольше сохранение записи при недоступном Redis не ждёт |
| `WPS_REDIS_STREAM` | `stream` | `crm_events` | имя стрима |
| `WPS_REDIS_STREAM_MAXLEN` | `stream_maxlen` | `100000` | `XADD MAXLEN ~` (0 — без ограничения) |
| `WPS_EVENT_MODULES` | `event_modules` | `Leads,Contacts,Accounts,Opportunities,Cases,Calls,Tasks,Meetings` | модули, чьи сохранения публикуются (пусто — все) |
| `WPS_API_STATIC_KEY` | `api_static_key` | пусто | ключ API: `X-Auth-Key = ключ . gmdate("Y.m.d H")`, принимается и ключ прошлого часа. Пустой ключ — API отвечает 401 на всё |
| `WPS_API_USER_ID` | `api_user_id` | `1` | пользователь CRM, от имени которого работает API (`created_by`, `modified_user_id`) |
| — | `api_readonly_modules` | `Users, Employees` | модули, запись в которые запрещена (405) |

Изменить параметры без переустановки: `bin/install.sh … --skip-check` или `wps_setup.php config` из `public/legacy`.

## 7. Проверка после установки

```bash
# 1. статус: пакет, хук, entry point, поле, параметры, доступность Redis (из public/legacy, от www-data)
sudo -u www-data php /path/to/SuiteCRM8/bin/wps_setup.php status

# 2. API с ключом (ключ меняется каждый час, время — UTC)
KEY="${WPS_API_STATIC_KEY}$(date -u '+%Y.%m.%d %H')"
curl -s -H "X-Auth-Key: $KEY" http://<crm>/api/v9/modules | head -c 200            # 200 и {"data":[{"type":"modules",...
curl -s -o /dev/null -w '%{http_code}\n' -H 'X-Auth-Key: wrong' http://<crm>/api/v9/modules   # 401
curl -s -g -H "X-Auth-Key: $KEY" "http://<crm>/api/v9/Leads?filter[status]=New&size=1"   # поиск по полю (-g: скобки не шаблон)

# 3. событие: сохраните любой лид в CRM и посмотрите хвост стрима в Redis WPS
redis-cli -h <redis-wps> XREVRANGE crm_events + - COUNT 1
```

## 8. Подключение WPS к этой CRM

В `.env` WPS (backend):

```bash
CRM_API_BASE_URL=http://<crm>/api/v9      # путь должен заканчиваться на /api/v9
CRM_API_STATIC_KEY=<тот же WPS_API_STATIC_KEY>
MOCK_SERVICE_URL=                         # пусто: справочник модулей берётся из CRM
```

Перезапустите backend WPS и обновите справочник модулей (`POST /api/dictionaries/modules/refresh`).

## 9. Обновление

Повторный запуск `bin/install.sh` с новой версией пакета — это обновление: скрипт удаляет файлы установленной версии
(как Module Loader при обновлении), ставит новую и обновляет запись в Module Loader. Поле `wps_processed_c` с данными и
параметры сохраняются. Тот же запуск с той же версией — переустановка (идемпотентно).

Через Module Loader: сначала **Uninstall** старой версии (выберите «Do not remove tables», чтобы сохранить поле),
затем установка новой, как в [4.3](#43-вручную-через-admin--module-loader).

## 10. Удаление

```bash
bin/uninstall.sh --crm-path /var/www/html [--apache-conf <vhost>] [--keep-config] [--remove-fields]
bin/uninstall.sh --docker <контейнер> [те же опции]
```

| Что | По умолчанию | Опция |
|---|---|---|
| файлы `custom/include/WPS`, logic hook, entry point, подписи | удаляются | — |
| запись и zip в Module Loader | удаляются | — |
| блок `$sugar_config['wps']` в `config_override.php` | удаляется | `--keep-config` — оставить |
| поле `Leads.wps_processed_c` **с данными** | остаётся | `--remove-fields` — удалить |
| rewrite в vhost | остаётся | `--apache-conf <файл>` — удалить блок между маркерами |

Через Module Loader: **Uninstall** в строке пакета; «Remove tables» удаляет и поле с данными.

## 11. Диагностика

| Симптом | Причина | Решение |
|---|---|---|
| `install.sh`: «is not the legacy root of SuiteCRM» | неверный `--crm-path` | укажите корень SuiteCRM 8 (каталог с `bin/console`, не `public/legacy`) |
| «bin/console not found: is this SuiteCRM 8?» | это SuiteCRM 7 | используйте пакет `suiteCRM7` |
| «does not support SuiteCRM x.y» | версия вне `^8\.10\.` | обновите CRM или `--force` на свой риск |
| после установки через Module Loader не видно поля или хук не срабатывает | возможно, Symfony-кэш хранит старые legacy-метаданные (`install.sh` чистит его всегда) | `php bin/console cache:clear` из корня CRM |
| самопроверка: `404` на оба ключа | нет rewrite или entry point не зарегистрирован | [раздел 5](#5-настройка-веб-сервера); `wps_setup.php status` |
| самопроверка: `401` на оба ключа | пустой или другой ключ | `WPS_API_STATIC_KEY`; часы сервера (UTC) |
| `/api/v9/...` отдаёт HTML нового интерфейса или редирект на `#/Login` | rewrite не сработал, запрос ушёл во front controller Symfony | правило должно быть в vhost, не в `public/.htaccess` |
| `POST/PUT` → «Bad data passed in» | тело без `Content-Type: application/json` | WPS отправляет заголовок; в своих запросах добавьте его |
| сохранение записи медленное, в `logs/legacy/suitecrm.log` `[WPS] publish failed` | Redis WPS недоступен | сеть / `WPS_REDIS_HOST`; запись сохраняется, событие теряется (outbox нет); таймаут — `WPS_REDIS_TIMEOUT` |
| Module Loader: «Possible Cross Site Request Forgery» | открыт прямой URL без Referer | заходите через меню Admin → Module Loader |
| Module Loader: «Duplicate column name 'wps_processed_c'» | установка версии 1.0.0 (поле было в `custom_fields` manifest) поверх сохранённого поля | используйте 1.1.0+: поле создаётся скриптом только если его нет |
| после удаления остались файлы `custom/include/WPS` | установки старым установщиком (1.0.0) переустанавливались без удаления, и ModuleInstaller восстановил «restore point» | `bin/install.sh` удалит остатки («Removed leftover…»), затем `bin/uninstall.sh` |

Логи: `logs/legacy/suitecrm.log` (строки `[WPS]`), `logs/prod/` (Symfony), лог ошибок Apache.

## 12. Контракт события и API

**Событие** (поле `data` сообщения стрима) — JSON-объект: `id`, `module`, `object_type` и все хранимые поля записи,
включая `_cstm`, перечитанные из БД (даты — формат БД, UTC). Связи, non-db поля и секреты (`user_hash` и т. п.)
не попадают. Служебные поля: `wps_event` (`after_save`), `wps_is_update` (`1`/`0`), `wps_user_id`, `wps_published_at`.

**API** (`X-Auth-Key`, JSON:API):

| Запрос | Ответ |
|---|---|
| `GET /api/v9/modules` | `{data: [{type: "modules", id: "Leads", attributes: {name, label}}]}` |
| `GET /api/v9/{module}/{id}` | `{data: {type, id, attributes}}`, `404` если нет |
| `GET /api/v9/{module}?filter[f]=v&size=1` | `{data: [...], meta}`; фильтры по И, `size` ≤ 100, порядок `date_entered ASC`; неизвестное поле → `422` |
| `POST /api/v9/{module}` | тело `{data: {type, attributes}}` → `201 {data}` |
| `PUT /api/v9/{module}/{id}` | → `200 {data}`, `404` если нет |

Ошибки: `{errors: [{status, title, detail?}]}` — `400` (битый JSON), `401` (ключ), `404`, `405` (метод или запись в
`Users`/`Employees`), `422`, `500`. Неизвестные и системные атрибуты не записываются, их список — в
`meta.ignored_attributes`. Даты принимаются как `Y-m-d H:i:s` (UTC) или ISO 8601.
