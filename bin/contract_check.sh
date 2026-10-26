#!/usr/bin/env bash
# Contract 2.0 check against a CRM plugin: ./contract_check.sh <base url> <static key> <redis container>
BASE=$1; KEY=$2; REDIS=$3; RUN=$(date +%s)
k() { echo "X-Auth-Key: ${KEY}$(date -u '+%Y.%m.%d %H')"; }
api() { curl -s -g -X "$1" "$BASE$2" -H "$(k)" -H 'Content-Type: application/json' "${@:3}"; }
ok() { if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1: got [$2] expected [$3]"; FAILS=$((FAILS+1)); fi; }
last_event() { docker exec $REDIS redis-cli --raw XREVRANGE crm_events + - COUNT 30 | grep -m1 "\"id\":\"$1\".*\"event_type\":\"$2\""; }
FAILS=0
echo "[capabilities]"
C=$(api GET /capabilities); ok "contract 2.0" "$(echo "$C" | jq -r .data.contract_version)" "2.0"
ok "features include relationships" "$(echo "$C" | jq -r '.data.features | index("relationships") != null')" "true"
echo "[search]"
for a in 1000 2500 4000; do api POST /Opportunities -d "{\"data\":{\"type\":\"Opportunities\",\"attributes\":{\"name\":\"ct-$RUN-$a\",\"amount\":\"$a\",\"date_closed\":\"2026-12-31\",\"sales_stage\":\"Prospecting\"}}}" >/dev/null; done
api PUT /Opportunities/$(api GET "/Opportunities?filter[name][eq]=ct-$RUN-2500" | jq -r '.data[0].id') -d '{"data":{"type":"Opportunities","attributes":{"sales_stage":"Qualification"}}}' >/dev/null
R=$(api GET "/Opportunities?filter[name][like]=ct-$RUN-%25&filter[amount][gte]=2000&sort=-amount&fields=name,amount&count=1")
ok "gte + like + sort -amount" "$(echo "$R" | jq -r '[.data[].attributes.amount] | join(",")')" "4000,2500"
ok "fields: only name, amount" "$(echo "$R" | jq -r '.data[0].attributes | keys | join(",")')" "amount,name"
ok "count=1 → total" "$(echo "$R" | jq -r .meta.total)" "2"
R=$(api GET "/Opportunities?filter[name][like]=ct-$RUN-%25&filter[sales_stage][in][]=Qualification&filter[sales_stage][in][]=Closed%20Won")
ok "in" "$(echo "$R" | jq -r '[.data[].attributes.name] | join(",")')" "ct-$RUN-2500"
R=$(api GET "/Opportunities?filter[name][like]=ct-$RUN-%25&filter[sales_stage][nin][]=Qualification&filter[sales_stage][nin][]=Closed%20Won&sort=amount")
ok "nin" "$(echo "$R" | jq -r '[.data[].attributes.amount] | join(",")')" "1000,4000"
P1=$(api GET "/Opportunities?filter[name][like]=ct-$RUN-%25&sort=amount&page[size]=2"); CUR=$(echo "$P1" | jq -r .meta.next_cursor)
P2=$(api GET "/Opportunities?filter[name][like]=ct-$RUN-%25&sort=amount&page[size]=2&page[after]=$CUR"); echo "$P2" | jq -e .meta >/dev/null 2>&1 || echo "    P2 raw: $(echo "$P2" | head -c 300)"
ok "cursor: page 1" "$(echo "$P1" | jq -r '[.data[].attributes.amount] | join(",")')" "1000,2500"
ok "cursor: page 2, last" "$(echo "$P2" | jq -r '([.data[].attributes.amount] | join(",")) + " " + (.meta.next_cursor|tostring)')" "4000 null"
ok "foreign cursor → 422" "$(api GET "/Opportunities?filter[name][like]=x%25&page[after]=$CUR" | jq -r '.errors[0].status')" "422"
ok "unknown operator → 422" "$(api GET "/Opportunities?filter[amount][between]=1" | jq -r '.errors[0].status')" "422"
ok "unknown field → 422" "$(api GET "/Opportunities?filter[nofield][eq]=1" | jq -r '.errors[0].status')" "422"
ok "old syntax still works" "$(api GET "/Opportunities?filter[name]=ct-$RUN-1000&size=1" | jq -r '.data[0].attributes.amount')" "1000"
echo "[events v2]"
ID=$(api POST /Leads -d "{\"data\":{\"type\":\"Leads\",\"attributes\":{\"last_name\":\"ct-$RUN\",\"status\":\"New\"}}}" | jq -r .data.id)
E=$(last_event $ID create); ok "create event" "$(echo "$E" | jq -r '.event_type + " " + (.before|tostring) + " " + (.changed_fields|index("last_name") != null|tostring)')" "create {} true"
api PUT /Leads/$ID -H "X-WPS-Execution-Id: 11111111-2222-3333-4444-555555555555" -H "X-WPS-Process-Id: 77" -H "X-WPS-Depth: 1" -d '{"data":{"type":"Leads","attributes":{"status":"Assigned","last_name":"'"ct-$RUN"'"}}}' >/dev/null
E=$(last_event $ID update); ok "update: before only changed" "$(echo "$E" | jq -c '[.before, .changed_fields]')" '[{"status":"New"},["status"]]'
ok "wps_origin from X-WPS-* headers" "$(echo "$E" | jq -c '.wps_origin')" '{"execution_id":"11111111-2222-3333-4444-555555555555","process_id":77,"depth":1}'
OID=$(api GET "/Opportunities?filter[name][eq]=ct-$RUN-1000" | jq -r '.data[0].id')
api PUT /Opportunities/$OID -d '{"data":{"type":"Opportunities","attributes":{"amount":"1000.00"}}}' >/dev/null
E=$(last_event $OID update); ok "1000 → 1000.00 is not a change" "$(echo "$E" | jq -c '.changed_fields')" "[]"
echo "[delete]"
ok "DELETE → deleted" "$(api DELETE /Leads/$ID | jq -r .meta.deleted)" "true"
E=$(last_event $ID delete); ok "delete event" "$(echo "$E" | jq -r '.event_type + " " + .deleted')" "delete 1"
ok "second DELETE → 404" "$(api DELETE /Leads/$ID | jq -r '.errors[0].status')" "404"
echo "[relationships]"
MID=$(api POST /Meetings -d "{\"data\":{\"type\":\"Meetings\",\"attributes\":{\"name\":\"ct-$RUN\",\"date_start\":\"2026-12-01 10:00:00\",\"duration_hours\":\"1\",\"duration_minutes\":\"0\",\"status\":\"Planned\"}}}" | jq -r .data.id)
L=$(api POST /Meetings/$MID/relationships/users -d '{"data":[{"type":"Users","id":"1","meta":{"accept_status":"accept"}},{"type":"Users","id":"no-such-user"}]}')
ok "link users" "$(echo "$L" | jq -c '.meta')" '{"linked":["1"],"updated":[],"not_found":["no-such-user"]}'
G=$(api GET "/Meetings/$MID/relationships/users?fields=user_name")
ok "list with relationship fields" "$(echo "$G" | jq -r '.data[0].id + " " + .data[0].attributes.user_name + " " + .data[0].meta.relationship.accept_status')" "1 admin accept"
ok "link again updates the field" "$(api POST /Meetings/$MID/relationships/users -d '{"data":[{"type":"Users","id":"1","meta":{"accept_status":"decline"}}]}' | jq -c .meta.updated)" '["1"]'
ok "field updated" "$(api GET /Meetings/$MID/relationships/users | jq -r '.data[0].meta.relationship.accept_status')" "decline"
ok "unknown relationship field → 422" "$(api POST /Meetings/$MID/relationships/users -d '{"data":[{"type":"Users","id":"1","meta":{"color":"red"}}]}' | jq -r '.errors[0].status')" "422"
ok "unknown link → 422" "$(api GET /Meetings/$MID/relationships/nolink | jq -r '.errors[0].status')" "422"
ok "unlink" "$(api DELETE /Meetings/$MID/relationships/users/1 | jq -r .meta.removed)" "true"
ok "unlink again (idempotent)" "$(api DELETE /Meetings/$MID/relationships/users/1 | jq -r .meta.removed)" "false"
ok "read-only Users: GET relationships allowed" "$(api GET /Users/1/relationships/reportees | jq -r '.meta.count|type')" "number"
ok "read-only Users: DELETE → 405" "$(api DELETE /Users/1 | jq -r '.errors[0].status')" "405"
echo; [ $FAILS = 0 ] && echo "ALL OK" || echo "FAILURES: $FAILS"
