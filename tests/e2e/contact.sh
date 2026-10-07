#!/usr/bin/env bash
# Contact e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/contact.sh
# Needs the seeded site (wp cc seed) and CC_RATE_LIMIT_DISABLED=1 (local compose default). The stored inquiry is read and
# deleted through the wpcli container.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
API="$BASE_URL/wp-json/cc/v1/contact"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
PHONE="01715$(printf '%06d' $((10#$TAG % 1000000)))"
NAME="ZZ Contact e2e $TAG"

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

STAFF="zzctstaff${TAG}"
STAFF_PW="Zz-$(printf '%s' "$TAG" | rev)-Pw!x9"
cleanup() {
	wp eval "require_once ABSPATH . 'wp-admin/includes/user.php'; \$u = get_user_by('login', '$STAFF'); if (\$u) { wp_delete_user(\$u->ID); }" >/dev/null
	wp eval "global \$wpdb; \$wpdb->query(\"DELETE FROM {\$wpdb->prefix}cc_inquiries WHERE name LIKE 'ZZ Contact e2e%'\");" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

# post JSON -> sets STATUS and BODY
post() {
	STATUS="$(curl -sS -m 20 -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "$1" "$API")" || STATUS=000
	BODY="$(cat "$TMP/body")"
}
payload() { printf '{"name":"%s","phone":"%s","email":"","topic":"fees","course_id":"","message":"%s"%s}' "$NAME" "$PHONE" "How much is the monthly fee for HSC?" "${1:-}"; }
stored() { wp eval "global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM '.\$wpdb->prefix.'cc_inquiries WHERE name = %s AND status = %s', '$NAME', 'new'));"; }

echo "Contact e2e: $BASE_URL"

STATUS="$(curl -sS -m 20 -o "$TMP/page" -w '%{http_code}' "$BASE_URL/contact/")" || STATUS=000
check "GET /contact/ returns 200" "$STATUS" "200"
grep -q 'id="ct-form"' "$TMP/page" && ok "form is present" || fail "form is present"
IFRAME="$(grep -o '<iframe class="ct-map"[^>]*>' "$TMP/page" | head -1)"
[ -n "$IFRAME" ] && ok "branch map iframe is present" || fail "branch map iframe is present"
for attr in 'sandbox="allow-scripts allow-same-origin"' 'loading="lazy"' 'referrerpolicy="no-referrer"' 'title="Map: '; do
	printf '%s' "$IFRAME" | grep -q -- "$attr" && ok "iframe has $attr" || fail "iframe has $attr"
done
printf '%s' "$IFRAME" | grep -Eq 'src="https://www\.(google\.com/maps/embed|openstreetmap\.org/export/embed\.html)\?' && ok "iframe src is an allowed embed" || fail "iframe src is an allowed embed"
grep -q 'assets/js/contact.js' "$TMP/page" && ok "contact.js is enqueued on /contact/" || fail "contact.js is enqueued on /contact/"
curl -sS -m 20 "$BASE_URL/" | grep -q 'assets/js/contact.js' && fail "contact.js leaks onto the home page" || ok "contact.js is not loaded on the home page"

post "$(payload)"
check "valid submission -> 200" "$STATUS" "200"
printf '%s' "$BODY" | grep -q '"ok":true' && ok "body is {ok:true}" || fail "body is {ok:true} (got $BODY)"
grep -qi '^cache-control:.*no-store' "$TMP/headers" && ok "response is no-store" || fail "response is no-store"
check "one new inquiry stored" "$(stored)" "1"

post "$(printf '{"name":"%s","phone":"12345","topic":"fees","message":"short"}' "$NAME")"
check "invalid submission -> 422" "$STATUS" "422"
printf '%s' "$BODY" | grep -q '"phone"' && printf '%s' "$BODY" | grep -q '"message"' && ok "422 names the bad fields" || fail "422 names the bad fields"

post "$(payload ',"company_site":"http://spam.example"')"
check "honeypot gets the success reply" "$STATUS" "200"
check "honeypot submission was not stored" "$(stored)" "1"

post_with() { # extra curl args in "$2"
	STATUS="$(curl -sS -m 20 -o "$TMP/body" -w '%{http_code}' -X POST -H 'Content-Type: application/json' $2 --data "$1" "$API")" || STATUS=000
	BODY="$(cat "$TMP/body")"
}
post_with "$(payload)" "-H Origin:https://evil.example"
check "foreign Origin -> 403" "$STATUS" "403"
post_with "$(payload)" "-H Referer:https://evil.example/contact/"
check "foreign Referer -> 403" "$STATUS" "403"
check "cross-site posts stored nothing extra" "$(stored)" "1"
post "$(printf '{"name":"%s","phone":"12345","topic":"fees","message":"short","company_site":"x"}' "$NAME")"
check "invalid body with honeypot still gets 422 (no oracle)" "$STATUS" "422"

echo "inquiries list row action (real browser-style POST)"
wp user create "$STAFF" "$STAFF@example.invalid" --role=cc_staff --user_pass="$STAFF_PW" >/dev/null
JAR="$TMP/jar"
ADMIN="$BASE_URL/wp-admin/admin.php"
curl -sS -m 20 -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null "$BASE_URL/admin/login/"
STATUS="$(curl -sS -m 30 -b "$JAR" -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/admin/login/" \
	--data-urlencode "log=$STAFF" --data-urlencode "pwd=$STAFF_PW" --data-urlencode "wp-submit=Log In" --data-urlencode "testcookie=1")" || STATUS=000
check "staff login redirects" "$STATUS" "302"
INQ_ID="$(wp eval "global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare('SELECT id FROM '.\$wpdb->prefix.'cc_inquiries WHERE name = %s AND status = %s ORDER BY id DESC LIMIT 1', '$NAME', 'new'));")"
inquiry_status() { wp eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare('SELECT status FROM '.\$wpdb->prefix.'cc_inquiries WHERE id = %d', $INQ_ID));"; }
STATUS="$(curl -sS -m 30 -b "$JAR" -G -o "$TMP/list" -w '%{http_code}' --data-urlencode "page=cc-inquiries" --data-urlencode "status=new" --data-urlencode "s=$NAME" "$ADMIN")" || STATUS=000
check "inquiries list loads for staff" "$STATUS" "200"
ACTION_URL="$(grep -o "formaction=\"[^\"]*id=$INQ_ID[^\"]*status=handled[^\"]*\"" "$TMP/list" | head -1 | sed 's/^formaction="//; s/"$//; s/&#038;/\&/g; s/&amp;/\&/g')"
[ -n "$ACTION_URL" ] && ok "list has a Mark handled button for the inquiry" || fail "list has a Mark handled button for the inquiry"
LIST_NONCE="$(grep -o 'name="_wpnonce" value="[^"]*"' "$TMP/list" | head -1 | sed 's/.*value="//; s/"$//')"
[ -n "$LIST_NONCE" ] && ok "list form carries its own _wpnonce (the colliding field)" || fail "list form carries its own _wpnonce"
STATUS="$(curl -sS -m 30 -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$ACTION_URL" \
	--data-urlencode "page=cc-inquiries" --data-urlencode "status=new" --data-urlencode "s=$NAME" \
	--data-urlencode "_wpnonce=$LIST_NONCE" --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=cc-inquiries")" || STATUS=000
check "list-style POST redirects (not 403)" "$STATUS" "302"
check "list-style POST marked the inquiry handled" "$(inquiry_status)" "handled"
STATUS="$(curl -sS -m 30 -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$(printf '%s' "$ACTION_URL" | sed 's/cc_nonce=[^&]*/cc_nonce=bad0nonce/')")" || STATUS=000
check "bad cc_nonce is refused" "$STATUS" "403"
STATUS="$(curl -sS -m 30 -b "$JAR" -o /dev/null -w '%{http_code}' "$ACTION_URL")" || STATUS=000
check "GET on the action URL is refused" "$STATUS" "405"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
