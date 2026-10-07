#!/usr/bin/env bash
# Hidden-notice privacy e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/notices-privacy.sh
# Creates a throwaway batch, a batch-targeted notice and a public notice through the wpcli container and deletes them on exit.
# Every public surface must answer for the targeted notice without its title or slug; the public notice must still work.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
SECRET="Zzhidden${TAG}"
SECRET_SLUG="zzhidden${TAG}"
PUBLIC_TITLE="Zzpublic${TAG}"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

cleanup() {
	wp eval "global \$wpdb; \$p = \$wpdb->prefix;
foreach (['$SECRET', '$PUBLIC_TITLE'] as \$t) { foreach (\$wpdb->get_col(\$wpdb->prepare(\"SELECT ID FROM {\$wpdb->posts} WHERE post_type='cc_notice' AND post_title LIKE %s\", \$t.'%')) as \$i) { \$wpdb->delete(\$p.'cc_notice_targets', ['notice_id' => \$i]); wp_delete_post(\$i, true); } }
\$wpdb->delete(\$p.'cc_batches', ['name' => 'ZZ-E2E-NOTICE-$TAG']);" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }

# hidden LABEL METHOD URL: 404, no title/slug in body or headers, no redirect.
hidden() {
	local label="$1" method="$2" url="$3" status
	if [ "$method" = HEAD ]; then
		status="$(curl -sS -m 20 -I -D "$TMP/h" -o /dev/null -w '%{http_code}' "$url")" || status=000
		: > "$TMP/b"
	else
		status="$(curl -sS -m 20 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$url")" || status=000
	fi
	check "$label status 404" "$status" "404"
	if grep -qi "$SECRET" "$TMP/b" "$TMP/h"; then fail "$label leaks the title or slug"; else ok "$label does not leak the title or slug"; fi
	if grep -qi '^location:' "$TMP/h"; then fail "$label sends a Location header"; else ok "$label sends no redirect"; fi
}

# listing URL: whatever the status, the title and slug must not appear (redirects are followed).
listing() {
	curl -sS -m 20 -L -D "$TMP/h" -o "$TMP/b" "$1" >/dev/null 2>&1
	if grep -qi "$SECRET" "$TMP/b" "$TMP/h"; then fail "$1 leaks the title or slug"; else ok "$1 does not list the hidden notice"; fi
}

status_of() { curl -sS -m 20 -o /dev/null -w '%{http_code}' "$@"; }

echo "Notices privacy e2e: $BASE_URL (tag $TAG)"

IDS="$(wp eval "
global \$wpdb; \$p = \$wpdb->prefix; \$f = 'Y-m-d H:i:s';
\$wpdb->insert(\$p.'cc_batches', ['course_id' => 0, 'name' => 'ZZ-E2E-NOTICE-$TAG', 'start_date' => gmdate('Y-m-d'), 'created_at' => gmdate(\$f), 'updated_at' => gmdate(\$f)]);
\$b = (int) \$wpdb->insert_id;
\$h = wp_insert_post(['post_type' => 'cc_notice', 'post_title' => '$SECRET', 'post_name' => '$SECRET_SLUG', 'post_content' => 'private body $TAG', 'post_status' => 'publish']);
\$wpdb->insert(\$p.'cc_notice_targets', ['notice_id' => \$h, 'batch_id' => \$b]);
\$pub = wp_insert_post(['post_type' => 'cc_notice', 'post_title' => '$PUBLIC_TITLE', 'post_content' => 'public body $TAG', 'post_status' => 'publish']);
echo \$h . ' ' . \$pub . ' ' . get_permalink(\$pub);
")"
HID="$(echo "$IDS" | awk '{print $1}')"
PUB="$(echo "$IDS" | awk '{print $2}')"
PUB_LINK="$(echo "$IDS" | awk '{print $3}')"
if [ -z "$HID" ] || [ -z "$PUB" ]; then echo "could not create fixtures: '$IDS'"; exit 1; fi
echo "  hidden notice #$HID, public notice #$PUB"

echo "REST single route, every spelling"
for route in cc_notice CC_NOTICE Cc_notice cc_NOTICE; do
	hidden "GET /wp-json/wp/v2/$route/ID" GET "$BASE_URL/wp-json/wp/v2/$route/$HID"
	hidden "HEAD /wp-json/wp/v2/$route/ID" HEAD "$BASE_URL/wp-json/wp/v2/$route/$HID"
	hidden "GET ?rest_route=/wp/v2/$route/ID" GET "$BASE_URL/?rest_route=/wp/v2/$route/$HID"
done
hidden "GET trailing slash" GET "$BASE_URL/wp-json/wp/v2/cc_notice/$HID/"

echo "oEmbed and ?p="
hidden "oEmbed ?p=ID" GET "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$HID"
ENCODED="$(python3 -c 'import sys,urllib.parse as u; print(u.quote(sys.argv[1], safe=""))' "$BASE_URL/?p=$HID")"
hidden "oEmbed ?p=ID (encoded url)" GET "$BASE_URL/wp-json/oembed/1.0/embed?url=$ENCODED"
hidden "/?p=ID" GET "$BASE_URL/?p=$HID"
hidden "HEAD /?p=ID" HEAD "$BASE_URL/?p=$HID"
hidden "/?p=ID&post_type=cc_notice" GET "$BASE_URL/?p=$HID&post_type=cc_notice"
hidden "name guess /notices/${SECRET_SLUG%????}" GET "$BASE_URL/notices/${SECRET_SLUG%????}"
hidden "single permalink" GET "$BASE_URL/notices/$SECRET_SLUG/"

echo "listings"
listing "$BASE_URL/"
listing "$BASE_URL/notices/"
listing "$BASE_URL/?post_type=cc_notice"
listing "$BASE_URL/feed/"
listing "$BASE_URL/?post_type=cc_notice&feed=rss2"
listing "$BASE_URL/notices/feed/"
listing "$BASE_URL/wp-sitemap.xml"
listing "$BASE_URL/wp-sitemap-posts-cc_notice-1.xml"
listing "$BASE_URL/?s=Zzhidden"  # the search page echoes the query, so search by a prefix of the title
listing "$BASE_URL/?s=private+body+$TAG"
listing "$BASE_URL/wp-json/wp/v2/search?search=$SECRET"
listing "$BASE_URL/wp-json/wp/v2/cc_notice?per_page=100"
listing "$BASE_URL/wp-json/wp/v2/cc_notice?include=$HID"
listing "$BASE_URL/wp-json/wp/v2/cc_notice?slug=$SECRET_SLUG"
listing "$BASE_URL/wp-json/wp/v2/cc_notice?search=$SECRET"

echo "public notice still works"
check "GET /wp-json/wp/v2/cc_notice/ID" "$(status_of "$BASE_URL/wp-json/wp/v2/cc_notice/$PUB")" "200"
check "GET /wp-json/wp/v2/CC_NOTICE/ID" "$(status_of "$BASE_URL/wp-json/wp/v2/CC_NOTICE/$PUB")" "200"
check "HEAD /wp-json/wp/v2/cc_notice/ID" "$(status_of -I "$BASE_URL/wp-json/wp/v2/cc_notice/$PUB")" "200"
check "oEmbed ?p=ID" "$(status_of "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$PUB")" "200"
check "permalink" "$(status_of "$PUB_LINK")" "200"
check "/?p=ID follows the canonical redirect" "$(status_of -L "$BASE_URL/?p=$PUB")" "200"
if curl -sS -m 20 "$BASE_URL/notices/" | grep -q "$PUBLIC_TITLE"; then ok "archive lists the public notice"; else fail "archive lists the public notice"; fi
if curl -sS -m 20 "$BASE_URL/" | grep -q "$PUBLIC_TITLE"; then ok "front-page teaser shows the public notice"; else fail "front-page teaser shows the public notice"; fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
