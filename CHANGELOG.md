# Changelog — WPS Integration for SuiteCRM 8

## 1.1.0 — 2026-10-15

Первая версия отдельного пакета (раньше плагин был общим для SuiteCRM 7 и 8 и вшивался в Docker-образ стенда).
Поведение плагина (событие в стрим, API `/api/v9`, поле `Leads.wps_processed_c`) не изменилось.

- Пакет только для SuiteCRM 8.10 (legacy-слой `public/legacy`): `manifest.php` проверяет `acceptable_suitecrm_versions`.
- `bin/install.sh`: установка в CRM на хосте или в Docker-контейнере, запись параметров, rewrite в vhost (`--apache-conf`), `cache:clear`, самопроверка API.
- `bin/uninstall.sh`: удаление как в Module Loader; `--keep-config`, `--remove-fields`.
- `bin/build.sh`: zip для Admin → Module Loader.
- Исправлено: повторная установка поверх сохранённого поля `wps_processed_c` падала в Module Loader с «Duplicate column»
  — поле теперь создаётся скриптом `scripts/wps_post_execute.php`, только если его нет.
- Исправлено: `--remove-fields` / «Remove tables» не удаляли поле (SuiteCRM ищет его по имени без суффикса `_c`).
- Исправлено: после переустановки CLI-установщиком и последующего удаления файлы `custom/include/WPS` оставались
  (ModuleInstaller восстанавливал «restore point»). Переустановка теперь сначала удаляет файлы установленной версии.

## 1.0.0 — 2026-10-14

Плагин в стенде `.tehnical/suiteCRM7/plugin` репозитория WPS, общий для SuiteCRM 7 и 8.
