# Changelog — WPS Integration for SuiteCRM 8

## 1.2.0 — 2026-10-20

Контракт WPS 2.0 (задача WPS 0.2). Совместим с WPS, ожидающим контракт 1.0: старые запросы и поля события не изменились.

- `GET /api/v9/capabilities` — версия контракта, возможности, лимиты.
- Поиск: `filter[field][op]=value` (`eq neq gt gte lt lte like in nin null notnull`, AND; `nin` — «нет в списке», пустое значение подходит, возможность `filter_nin`), `sort` (до 2 полей, скрытый ключ `id`),
  keyset-курсор `page[after]` / `meta.next_cursor`, `page[size]` (≤ `max_page_size`), `fields`, `count=1` → `meta.total`.
  Старый синтаксис `filter[field]=value&size=N` работает как раньше.
- `DELETE /api/v9/{module}/{id}` — мягкое удаление (`mark_deleted`), событие `delete`.
- Связи: `GET|POST /api/v9/{module}/{id}/relationships/{link}`, `DELETE …/{related_id}`; поля связи (`accept_status`, `required`)
  читаются и пишутся в join-таблице; повторный `POST` идемпотентен и обновляет поля.
- События v2: `event_type` (`create` / `update` / `delete`), `before` (старые значения изменённых полей), `changed_fields`,
  `wps_event_version: 2`; хуки `before_save` и `after_delete`; изменения считаются после нормализации по типу поля
  (`1000.000000` = `1000`, `NULL` = `''`, порядок multienum); удаление через сохранение `deleted = 1` публикуется как `delete`.
- Событие `create` для записей, которым SugarBean назначает id до `before_save` (Opportunities): новая запись — та, у
  которой нет `fetched_row` и строки ещё нет в БД (раньше такая запись публиковалась как `update` без изменений).
- `email1` (основной адрес почты людей и компаний) читается, пишется и попадает в события как поле записи
  (адреса хранятся в отдельной таблице SuiteCRM).
- Запись: `null` в `attributes` очищает поле (раньше SugarBean пропускал такое поле при сохранении).
- Заголовки `X-WPS-Execution-Id` / `X-WPS-Process-Id` / `X-WPS-Depth` → `wps_origin` во всех событиях этого запроса.
- События связей `link` / `unlink` (`publish_relationship_events`, по умолчанию выключено).
- Новые настройки: `ignore_change_fields`, `publish_unchanged`, `publish_delete_events`, `publish_relationship_events`,
  `max_page_size`, `max_link_batch`, `max_in_values` (`WPS_*` в `config/wps.env.example`).

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
