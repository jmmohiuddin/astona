#!/usr/bin/env bash
# Student auth e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/auth.sh
# Creates a throwaway student through the wpcli container and deletes it on exit.
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
PHONE="013$SUFFIX"
LOGIN="88$PHONE"
TEMP_PW="Temp-Pass-$RANDOM$RANDOM"
NEW_PW="Brand-New-$RANDOM$RANDOM"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

cleanup() {
	wp eval "global \$wpdb; \$u = get_user_by('login', '$LOGIN'); if (\$u) { require_once ABSPATH.'wp-admin/includes/user.php'; \$wpdb->delete(\$wpdb->prefix.'cc_students', ['user_id'=>\$u->ID]); wp_delete_user(\$u->ID); }" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
json()  { python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1],""))' "$1" 2>/dev/null; }
refused() { [ "$2" = "401" ] || [ "$2" = "403" ] && ok "$1 ($2)" || fail "$1 (got $2)"; }

# post PATH JSON [extra curl args...] -> BODY, STATUS, HEADERS
post() {
	local path="$1" data="$2"; shift 2
	STATUS="$(curl -sS -m 20 -D "$TMP/headers" -o "$TMP/body" -w '%{http_code}' -X POST "$API$path" \
		-H 'Content-Type: application/json' -d "$data" "$@")" || STATUS=000
	BODY="$(cat "$TMP/body")"
	HEADERS="$(tr -d '\r' < "$TMP/headers" | tr 'A-Z' 'a-z')"
}

echo "Auth e2e: $BASE_URL (student $PHONE)"

wp eval "
if (!get_role('cc_student')) { add_role('cc_student', 'Student', ['read' => true]); }
\$id = wp_insert_user(['user_login' => '$LOGIN', 'user_pass' => '$TEMP_PW', 'user_email' => '$LOGIN@students.invalid', 'role' => 'cc_student']);
global \$wpdb;
\$wpdb->insert(\$wpdb->prefix.'cc_students', ['user_id' => \$id, 'full_name' => 'E2E Student', 'must_change_pw' => 1, 'temp_pw_expires' => gmdate('Y-m-d H:i:s', time() + 3600), 'created_at' => gmdate('Y-m-d H:i:s')]);
echo \$id;
" > "$TMP/uid"
if [ -s "$TMP/uid" ]; then ok "fixture student created"; else fail "fixture student not created (is docker compose up?)"; exit 1; fi

echo "Login page"
code="$(curl -sS -m 20 -D "$TMP/h" -o "$TMP/page" -w '%{http_code}' "$BASE_URL/student/login/")"
check "login page 200" "$code" "200"
grep -qi 'cache-control:.*no-store' "$TMP/h" && ok "login page no-store" || fail "login page no-store"
grep -q 'for="sl-phone"' "$TMP/page" && ok "phone field has a label" || fail "phone field label"

echo "Uniform login errors"
post /auth/login "{\"phone\":\"01399999999\",\"password\":\"nope-nope-nope\"}"
UNKNOWN_BODY="$BODY"; check "unknown phone -> 401" "$STATUS" "401"
post /auth/login "{\"phone\":\"$PHONE\",\"password\":\"nope-nope-nope\"}"
check "wrong password -> 401" "$STATUS" "401"
check "unknown phone and wrong password bodies identical" "$BODY" "$UNKNOWN_BODY"
echo "$HEADERS" | grep -q 'cache-control:.*no-store' && ok "error response no-store" || fail "error response no-store"
check "message" "$(echo "$BODY" | json message)" "Invalid phone or password"

echo "OTP request is uniform"
post /auth/otp/request "{\"phone\":\"$PHONE\"}"; KNOWN_BODY="$BODY"; check "registered -> 200" "$STATUS" "200"
post /auth/otp/request "{\"phone\":\"01388888888\"}"; check "unregistered -> 200" "$STATUS" "200"
check "bodies identical" "$BODY" "$KNOWN_BODY"
post /auth/otp/verify "{\"phone\":\"$PHONE\",\"code\":\"000000\"}"; check "wrong code -> 401" "$STATUS" "401"

echo "Password login + forced change"
post /auth/login "{\"phone\":\"$PHONE\",\"password\":\"$TEMP_PW\"}" -c "$JAR"
check "temp password login -> 200" "$STATUS" "200"
check "redirect to forced change" "$(echo "$BODY" | json redirect)" "/student/profile/?change=1"
grep -q wordpress_logged_in "$JAR" && ok "auth cookie set" || fail "auth cookie set"

NONCE="$(curl -sS -m 20 -b "$JAR" "$BASE_URL/wp-admin/admin-ajax.php?action=rest-nonce")"
if [ -n "$NONCE" ]; then ok "REST nonce obtained"; else fail "REST nonce obtained"; fi

post /me/password "{\"current_password\":\"$TEMP_PW\",\"new_password\":\"$NEW_PW\"}" -b "$JAR"
refused "password change without nonce refused" "$STATUS"
post /me/password "{\"current_password\":\"$TEMP_PW\",\"new_password\":\"short\"}" -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "short password -> 422" "$STATUS" "422"
check "short password gets the friendly message" "$(echo "$BODY" | json message)" "Your new password must be at least 10 characters."
post /me/password "{\"current_password\":\"wrong-wrong-wrong\",\"new_password\":\"$NEW_PW\"}" -b "$JAR" -H "X-WP-Nonce: $NONCE"
check "wrong current password -> 403" "$STATUS" "403"
post /me/password "{\"current_password\":\"$TEMP_PW\",\"new_password\":\"$NEW_PW\"}" -b "$JAR" -c "$JAR" -H "X-WP-Nonce: $NONCE"
check "password change -> 200" "$STATUS" "200"
echo "$HEADERS" | grep -q 'cache-control:.*no-store' && ok "password change no-store" || fail "password change no-store"
FRESH_NONCE="$(echo "$BODY" | json nonce)"
if [ -n "$FRESH_NONCE" ] && [ "$FRESH_NONCE" != "$NONCE" ]; then ok "password change returns a fresh nonce"; else fail "password change returns a fresh nonce"; fi
post /auth/logout "{}" -b "$JAR" -H "X-WP-Nonce: $NONCE"
refused "stale nonce refused after password change" "$STATUS"
echo "$HEADERS" | grep -q 'cache-control:.*no-store' && ok "403 from core nonce check is no-store" || fail "403 from core nonce check is no-store"
post /me/password "{\"current_password\":\"$NEW_PW\",\"new_password\":\"short\"}" -b "$JAR" -H "X-WP-Nonce: $FRESH_NONCE"
check "fresh nonce accepted by the next request" "$STATUS" "422"
curl -sS -m 20 -D "$TMP/h" -o /dev/null "$API/me/profile"
grep -qi 'cache-control:.*no-store' "$TMP/h" && ok "anonymous /me/ error is no-store" || fail "anonymous /me/ error is no-store"

post /auth/login "{\"phone\":\"$PHONE\",\"password\":\"$TEMP_PW\"}"
check "old password no longer works" "$STATUS" "401"
post /auth/login "{\"phone\":\"$PHONE\",\"password\":\"$NEW_PW\"}" -c "$JAR"
check "new password -> 200" "$STATUS" "200"
check "redirect is dashboard" "$(echo "$BODY" | json redirect)" "/student/"

echo "Portal + admin gating"
code="$(curl -sS -m 20 -D "$TMP/h" -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE_URL/student/")"
check "dashboard 200 when logged in" "$code" "200"
grep -qi 'cache-control:.*no-store' "$TMP/h" && ok "dashboard no-store" || fail "dashboard no-store"
code="$(curl -sS -m 20 -o /dev/null -w '%{http_code}' "$BASE_URL/student/")"
check "dashboard redirects anonymous" "$code" "302"
code="$(curl -sS -m 20 -o /dev/null -w '%{http_code} %{redirect_url}' -b "$JAR" "$BASE_URL/wp-admin/")"
case "$code" in 302*student*) ok "wp-admin blocked for student ($code)";; *) fail "wp-admin blocked for student ($code)";; esac

echo "Logout"
NONCE="$(curl -sS -m 20 -b "$JAR" "$BASE_URL/wp-admin/admin-ajax.php?action=rest-nonce")"
post /auth/logout "{}" -b "$JAR"
refused "logout without nonce refused" "$STATUS"
post /auth/logout "{}" -b "$JAR" -c "$JAR" -H "X-WP-Nonce: $NONCE"
check "logout with nonce -> 200" "$STATUS" "200"
code="$(curl -sS -m 20 -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE_URL/student/")"
check "dashboard redirects after logout" "$code" "302"

echo
echo "$((PASS + FAIL)) checks, $FAIL failures"
[ "$FAIL" -eq 0 ]
