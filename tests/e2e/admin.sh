#!/usr/bin/env bash
# Admin screens e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/admin.sh
# Creates a throwaway cc_owner, a batch and two applications through the wpcli container and removes them on exit.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
JAR="$TMP/jar"

TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
OWNER="zze2eowner$TAG"
OWNER_PW="Owner-Pass-$RANDOM$RANDOM"
STU_LOGIN="88013$TAG"
STU_PW="Stu-Pass-$RANDOM$RANDOM"
ID_NUMBER="1990$TAG"
MASKED="****${ID_NUMBER: -4}"
ADMIN_URL="$BASE_URL/wp-admin/admin.php"
BATCH_ID=""
APP_A=""
APP_B=""

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

cleanup() {
	wp eval "
global \$wpdb; \$p = \$wpdb->prefix;
require_once ABSPATH.'wp-admin/includes/user.php';
foreach ( array_filter( [ '$APP_A', '$APP_B' ] ) as \$a ) {
	\$wpdb->delete( \$p.'cc_audit_log', [ 'entity_type' => 'application', 'entity_id' => (int) \$a ] );
	\$wpdb->delete( \$p.'cc_applications', [ 'id' => (int) \$a ] );
}
if ( '$BATCH_ID' !== '' ) { \$wpdb->delete( \$p.'cc_batches', [ 'id' => (int) '$BATCH_ID' ] ); }
\$s = get_user_by( 'login', '$STU_LOGIN' );
if ( \$s ) {
	\$wpdb->delete( \$p.'cc_students', [ 'user_id' => \$s->ID ] );
	wp_delete_user( \$s->ID );
}
\$u = get_user_by( 'login', '$OWNER' );
if ( \$u ) {
	\$wpdb->delete( \$p.'cc_audit_log', [ 'actor_id' => \$u->ID ] );
	wp_delete_user( \$u->ID );
}
" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
has()   { if grep -qF -- "$2" "$TMP/body"; then ok "$1"; else fail "$1 (missing '$2')"; fi; }
hasnt() { if grep -qF -- "$2" "$TMP/body"; then fail "$1 (found '$2')"; else ok "$1"; fi; }

# req METHOD URL [curl args...] -> STATUS, HEADERS (lower-cased), body in $TMP/body
req() {
	local method="$1" url="$2"; shift 2
	STATUS="$(curl -sS -m 30 -b "$JAR" -c "$JAR" -D "$TMP/headers" -o "$TMP/body" -w '%{http_code}' -X "$method" "$url" "$@")" || STATUS=000
	HEADERS="$(tr -d '\r' < "$TMP/headers" | tr 'A-Z' 'a-z')"
}

# Value of the _wpnonce field in the first form of the last response whose markup contains $1.
nonce_for() {
	python3 - "$1" "$TMP/body" <<'PY'
import re, sys
html = open(sys.argv[2], encoding='utf-8').read()
for form in re.findall(r'<form[^>]*>.*?</form>', html, re.S):
    if sys.argv[1] in form:
        m = re.search(r'name="_wpnonce"[^>]*value="([^"]+)"', form) or re.search(r'value="([^"]+)"[^>]*name="_wpnonce"', form)
        if m:
            print(m.group(1))
            break
PY
}

audit_count() {
	wp eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT COUNT(*) FROM '.\$wpdb->prefix.'cc_audit_log WHERE action = %s AND entity_id = %d', '$1', $2 ) );"
}

echo "Admin e2e: $BASE_URL (owner $OWNER)"

wp user create "$OWNER" "$OWNER@example.invalid" --role=cc_owner --user_pass="$OWNER_PW" >/dev/null
SEED="$(wp eval "
global \$wpdb; \$p = \$wpdb->prefix; \$now = gmdate('Y-m-d H:i:s');
\$wpdb->insert( \$p.'cc_batches', [ 'course_id' => 0, 'name' => 'ZZ E2E $TAG', 'capacity' => 10, 'seats_taken' => 0, 'price' => 100, 'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => \$now, 'updated_at' => \$now ] );
\$batch = (int) \$wpdb->insert_id; \$ids = [];
foreach ( [ 'Alpha', 'Beta' ] as \$name ) {
	\$wpdb->insert( \$p.'cc_applications', [ 'public_ref' => substr( 'ZZE2E'.strtoupper( bin2hex( random_bytes( 10 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => \$batch, 'student_phone' => '+8801700000000', 'full_name' => \"ZZ\$name $TAG\", 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => CC_Crypto::encrypt( '$ID_NUMBER' ), 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001', 'institution' => 'I', 'class_level' => '10', 'consent_at' => \$now, 'status' => 'pending', 'created_at' => \$now, 'updated_at' => \$now ] );
	\$ids[] = (int) \$wpdb->insert_id;
}
echo \$batch.' '.implode( ' ', \$ids );
")"
read -r BATCH_ID APP_A APP_B <<< "$SEED"
if [ -z "$APP_B" ]; then fail "fixtures created (got '$SEED')"; exit 1; fi
ok "fixtures created (batch $BATCH_ID, applications $APP_A, $APP_B)"

echo "login"
curl -sS -m 20 -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null "$BASE_URL/admin/login/"
req POST "$BASE_URL/admin/login/" -b "wordpress_test_cookie=WP%20Cookie%20check" \
	--data-urlencode "log=$OWNER" --data-urlencode "pwd=$OWNER_PW" --data-urlencode "wp-submit=Log In" \
	--data-urlencode "redirect_to=$ADMIN_URL" --data-urlencode "testcookie=1"
check "login redirects" "$STATUS" "302"

echo "reveal ID number"
req GET "$ADMIN_URL?page=cc-applications&view=$APP_A"
check "application page loads" "$STATUS" "200"
has "ID number is masked before reveal" "$MASKED"
hasnt "full ID number not on the page before reveal" "$ID_NUMBER"
NONCE="$(nonce_for 'cc_app_reveal')"
if [ -n "$NONCE" ]; then ok "reveal nonce found"; else fail "reveal nonce found"; fi

req POST "$ADMIN_URL?page=cc-applications&view=$APP_A" -d "cc_app_reveal=1" -d "id=$APP_A" -d "_wpnonce=bad0nonce"
check "reveal with a bad nonce is refused" "$STATUS" "403"
hasnt "bad nonce does not reveal" "$ID_NUMBER"
check "bad nonce is not audited" "$(audit_count application.reveal_id "$APP_A")" "0"

req POST "$ADMIN_URL?page=cc-applications&view=$APP_A" -d "cc_app_reveal=1" -d "id=$APP_A" -d "_wpnonce=$NONCE"
check "reveal POST returns 200 (no crash, no redirect)" "$STATUS" "200"
has "revealed number shown" "$ID_NUMBER"
hasnt "masked value replaced" "$MASKED"
if echo "$HEADERS" | grep -q '^cache-control:.*no-store'; then ok "reveal response is no-store"; else fail "reveal response is no-store"; fi
if echo "$HEADERS" | grep -q '^location:'; then fail "reveal does not redirect (number never in a URL)"; else ok "reveal does not redirect (number never in a URL)"; fi
check "reveal audited once" "$(audit_count application.reveal_id "$APP_A")" "1"
req GET "$ADMIN_URL?page=cc-applications&view=$APP_A"
hasnt "a plain reload shows the number masked again" "$ID_NUMBER"

echo "export honours the search box"
req GET "$ADMIN_URL?page=cc-applications&s=ZZAlpha%20$TAG"
LINK="$(python3 - "$TMP/body" <<'PY'
import html, re, sys
m = re.search(r'href="([^"]*action=cc_app_export[^"]*)"', open(sys.argv[1], encoding='utf-8').read())
print(html.unescape(m.group(1)) if m else '')
PY
)"
if [ -n "$LINK" ]; then ok "export link rendered"; else fail "export link rendered"; fi
case "$LINK" in
	*"&s=ZZAlpha"*|*"?s=ZZAlpha"*) ok "export link carries the search term as s" ;;
	*) fail "export link carries the search term as s ($LINK)" ;;
esac
req GET "$LINK"
check "export returns 200" "$STATUS" "200"
has "export contains the matching application" "ZZAlpha $TAG"
hasnt "export excludes the non-matching application" "ZZBeta $TAG"
has "phone exported as digits without a plus" ",8801700000000,"
hasnt "phone is not formula-prefixed" "'+880"
EXPECTED="$(wp eval "echo CC_Audit::diff_hash( CC_Admin_Applications::filters_from_request( [ 's' => 'ZZAlpha $TAG' ] ) );")"
RECORDED="$(wp eval "global \$wpdb; echo \$wpdb->get_var( \$wpdb->prepare( 'SELECT diff_hash FROM '.\$wpdb->prefix.'cc_audit_log WHERE action = %s AND actor_id = (SELECT ID FROM '.\$wpdb->users.' WHERE user_login = %s) ORDER BY id DESC LIMIT 1', 'application.export', '$OWNER' ) );")"
check "export audit row records the search filter" "$RECORDED" "$EXPECTED"
req GET "$ADMIN_URL?page=cc-students&s=Nobody%20$TAG"
case "$(grep -o 'href="[^"]*action=cc_students_export[^"]*"' "$TMP/body" | head -1)" in
	*"s=Nobody"*) ok "students export link carries the search term as s" ;;
	*) fail "students export link carries the search term as s" ;;
esac

echo "owner default landing"
rm -f "$TMP/jar2"
curl -sS -m 20 -c "$TMP/jar2" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null "$BASE_URL/admin/login/"
STATUS="$(curl -sS -m 30 -b "$TMP/jar2" -c "$TMP/jar2" -D "$TMP/headers" -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/admin/login/" -b "wordpress_test_cookie=WP%20Cookie%20check" \
	--data-urlencode "log=$OWNER" --data-urlencode "pwd=$OWNER_PW" --data-urlencode "wp-submit=Log In" --data-urlencode "testcookie=1")"
LOCATION="$(tr -d '\r' < "$TMP/headers" | grep -i '^location:' | head -1)"
case "$LOCATION" in
	*"page=cc-dashboard"*) ok "login without redirect_to lands on the Astona dashboard" ;;
	*) fail "login without redirect_to lands on the Astona dashboard ($LOCATION)" ;;
esac

echo "student is redirected from admin.php?page=cc-*"
wp eval "
if ( ! get_role( 'cc_student' ) ) { add_role( 'cc_student', 'Student', [ 'read' => true ] ); }
\$id = wp_insert_user( [ 'user_login' => '$STU_LOGIN', 'user_pass' => '$STU_PW', 'user_email' => '$STU_LOGIN@students.invalid', 'role' => 'cc_student' ] );
global \$wpdb;
\$wpdb->insert( \$wpdb->prefix.'cc_students', [ 'user_id' => \$id, 'full_name' => 'ZZ E2E Student', 'must_change_pw' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
" >/dev/null
SJAR="$TMP/sjar"
STATUS="$(curl -sS -m 20 -c "$SJAR" -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/wp-json/cc/v1/auth/login" -H 'Content-Type: application/json' -d "{\"phone\":\"${STU_LOGIN#88}\",\"password\":\"$STU_PW\"}")"
check "throwaway student logs in" "$STATUS" "200"
for target in "admin.php?page=cc-students" "admin.php?page=cc-dashboard" "profile.php"; do
	STATUS="$(curl -sS -m 20 -b "$SJAR" -D "$TMP/headers" -o /dev/null -w '%{http_code}' "$BASE_URL/wp-admin/$target")"
	LOCATION="$(tr -d '\r' < "$TMP/headers" | grep -i '^location:' | head -1)"
	case "$STATUS $LOCATION" in
		"302 "*"/student/"*) ok "student $target redirects to /student/" ;;
		*) fail "student $target redirects to /student/ (got $STATUS $LOCATION)" ;;
	esac
done

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
