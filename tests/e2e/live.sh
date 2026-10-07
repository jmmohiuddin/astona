#!/usr/bin/env bash
# Live-class join e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/live.sh
# Creates a throwaway student, batch and live classes through the wpcli container and deletes them on exit.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
API="$BASE_URL/wp-json/cc/v1"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
JAR="$TMP/jar"

SUFFIX="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
PHONE="016$SUFFIX"
LOGIN="88$PHONE"
PW="Live-Pass-$RANDOM$RANDOM"
MEET_URL="https://meet.google.com/zze-$SUFFIX-abc"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

cleanup() {
	wp eval "global \$wpdb; \$p = \$wpdb->prefix; \$u = get_user_by('login', '$LOGIN'); \$b = (int) \$wpdb->get_var(\"SELECT id FROM {\$p}cc_batches WHERE name = 'ZZ-E2E-LIVE $SUFFIX'\");
if (\$b) { \$ids = \$wpdb->get_col(\$wpdb->prepare(\"SELECT id FROM {\$p}cc_live_classes WHERE batch_id = %d\", \$b)); foreach (\$ids as \$i) { \$wpdb->delete(\$p.'cc_join_log', ['live_class_id' => \$i]); \$wpdb->delete(\$p.'cc_audit_log', ['entity_type' => 'live_class', 'entity_id' => \$i]); } \$wpdb->delete(\$p.'cc_live_classes', ['batch_id' => \$b]); \$wpdb->delete(\$p.'cc_enrollments', ['batch_id' => \$b]); \$wpdb->delete(\$p.'cc_batches', ['id' => \$b]); }
if (\$u) { require_once ABSPATH.'wp-admin/includes/user.php'; \$wpdb->delete(\$p.'cc_students', ['user_id'=>\$u->ID]); wp_delete_user(\$u->ID); }" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
json()  { python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1],""))' "$1" 2>/dev/null; }

# join ID [extra curl args...] -> BODY, STATUS, HEADERS
join() {
	local id="$1"; shift
	STATUS="$(curl -sS -m 20 -D "$TMP/headers" -o "$TMP/body" -w '%{http_code}' -X POST "$API/live-classes/$id/join" "$@")" || STATUS=000
	BODY="$(cat "$TMP/body")"
	HEADERS="$(tr -d '\r' < "$TMP/headers" | tr 'A-Z' 'a-z')"
}

echo "Live e2e: $BASE_URL (student $PHONE)"

wp eval "
if (!get_role('cc_student')) { add_role('cc_student', 'Student', ['read' => true]); }
global \$wpdb; \$p = \$wpdb->prefix; \$now = time(); \$f = 'Y-m-d H:i:s';
\$uid = wp_insert_user(['user_login' => '$LOGIN', 'user_pass' => '$PW', 'user_email' => '$LOGIN@students.invalid', 'role' => 'cc_student']);
\$wpdb->insert(\$p.'cc_students', ['user_id' => \$uid, 'full_name' => 'E2E Live', 'must_change_pw' => 0, 'created_at' => gmdate(\$f)]);
\$wpdb->insert(\$p.'cc_batches', ['course_id' => 0, 'name' => 'ZZ-E2E-LIVE $SUFFIX', 'start_date' => gmdate('Y-m-d'), 'created_at' => gmdate(\$f), 'updated_at' => gmdate(\$f)]);
\$b = (int) \$wpdb->insert_id;
\$wpdb->insert(\$p.'cc_enrollments', ['user_id' => \$uid, 'batch_id' => \$b, 'application_id' => random_int(900000000, 2000000000), 'status' => 'active', 'enrolled_at' => gmdate(\$f)]);
\$mk = function (\$s, \$e, \$url) use (\$b) { return CC_Live_Repository::create(['batch_id' => \$b, 'title' => 'ZZ e2e', 'starts_at' => gmdate('Y-m-d H:i:s', \$s), 'ends_at' => gmdate('Y-m-d H:i:s', \$e), 'provider' => 'meet', 'meeting_url' => \$url], 1); };
echo \$mk(\$now - 300, \$now + 3300, '$MEET_URL'), ' ', \$mk(\$now + 7200, \$now + 10800, '$MEET_URL'), ' ', \$mk(\$now - 7200, \$now - 3600, '$MEET_URL');
" > "$TMP/ids"
read -r OPEN_ID FUTURE_ID ENDED_ID < "$TMP/ids"
if [ -n "${ENDED_ID:-}" ]; then ok "fixtures created (open $OPEN_ID, future $FUTURE_ID, ended $ENDED_ID)"; else fail "fixtures not created (is docker compose up?)"; exit 1; fi

echo "Anonymous"
join "$OPEN_ID"
case "$STATUS" in 401|403) ok "anonymous refused ($STATUS)";; *) fail "anonymous refused (got $STATUS)";; esac
echo "$BODY" | grep -q 'meet.google.com' && fail "anonymous body leaks the url" || ok "anonymous body has no url"
echo "$HEADERS" | grep -q 'cache-control:.*no-store' && ok "anonymous refusal no-store" || fail "anonymous refusal no-store"

echo "Student login"
STATUS="$(curl -sS -m 20 -o "$TMP/body" -w '%{http_code}' -X POST "$API/auth/login" -H 'Content-Type: application/json' -c "$JAR" -d "{\"phone\":\"$PHONE\",\"password\":\"$PW\"}")"
check "login -> 200" "$STATUS" "200"
NONCE="$(curl -sS -m 20 -b "$JAR" "$BASE_URL/wp-admin/admin-ajax.php?action=rest-nonce")"
if [ -n "$NONCE" ]; then ok "REST nonce obtained"; else fail "REST nonce obtained"; fi

echo "Join"
join "$OPEN_ID" -b "$JAR"
case "$STATUS" in 401|403) ok "join without nonce refused ($STATUS)";; *) fail "join without nonce refused (got $STATUS)";; esac
echo "$BODY" | grep -q 'meet.google.com' && fail "nonce-less refusal leaks the url" || ok "nonce-less refusal has no url"
join "$OPEN_ID" -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "join inside window -> 200" "$STATUS" "200"
check "response carries the url" "$(echo "$BODY" | json url)" "$MEET_URL"
echo "$HEADERS" | grep -q 'cache-control:.*private' && echo "$HEADERS" | grep -q 'cache-control:.*no-store' && ok "join response private, no-store" || fail "join response private, no-store"
join "$FUTURE_ID" -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "future class -> 409" "$STATUS" "409"
echo "$BODY" | grep -q 'meet.google.com' && fail "409 leaks the url" || ok "409 has no url"
join "$ENDED_ID" -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "ended class -> 409" "$STATUS" "409"
join 999999999 -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "unknown class -> 404" "$STATUS" "404"

echo "Deactivated enrollment"
wp eval "global \$wpdb; \$wpdb->update(\$wpdb->prefix.'cc_enrollments', ['status' => 'deactivated'], ['user_id' => get_user_by('login', '$LOGIN')->ID]);" >/dev/null
join "$OPEN_ID" -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "deactivated student -> 403" "$STATUS" "403"
check "denial code" "$(echo "$BODY" | json code)" "not_enrolled"
echo "$BODY" | grep -q 'meet.google.com' && fail "403 leaks the url" || ok "403 has no url"

echo "Student pages never contain the url"
code="$(curl -sS -m 20 -o "$TMP/page" -w '%{http_code}' -b "$JAR" "$BASE_URL/student/")"
if [ "$code" = "200" ] && grep -q 'meet.google.com' "$TMP/page"; then fail "dashboard HTML contains the url"; else ok "dashboard HTML has no url"; fi

echo
echo "$((PASS + FAIL)) checks, $FAIL failures"
[ "$FAIL" -eq 0 ]
