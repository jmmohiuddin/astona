#!/usr/bin/env bash
# Blog e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/blog.sh
# Creates one published, one archived, one scheduled and one draft article (author: a throwaway user) through the wpcli
# container and deletes them on exit. Only the published one may appear anywhere; no surface may print the staff login.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
LOGIN="zzstaffe2e${TAG}"
PUB_TITLE="Zzpublished${TAG}"
ARC_TITLE="Zzarchived${TAG}"
FUT_TITLE="Zzscheduled${TAG}"
DRF_TITLE="Zzdraft${TAG}"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null </dev/null); }

cleanup() {
	wp eval "global \$wpdb; foreach (\$wpdb->get_col(\"SELECT ID FROM {\$wpdb->posts} WHERE post_type='cc_article' AND post_title LIKE 'Zz%$TAG'\") as \$i) { wp_delete_post(\$i, true); }
require_once ABSPATH . 'wp-admin/includes/user.php'; \$u = get_user_by('login', '$LOGIN'); if (\$u) { wp_delete_user(\$u->ID); }
\$t = get_term_by('slug', 'zz-e2e-$TAG', 'cc_article_category'); if (\$t) { wp_delete_term(\$t->term_id, 'cc_article_category'); }" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
status_of() { curl -sS -m 20 -o /dev/null -w '%{http_code}' "$@"; }

# hidden LABEL METHOD URL: 404, none of the hidden titles/slugs in body or headers, no redirect.
hidden() {
	local label="$1" method="$2" url="$3" status
	if [ "$method" = HEAD ]; then
		status="$(curl -sS -m 20 -I -D "$TMP/h" -o /dev/null -w '%{http_code}' "$url")" || status=000
		: > "$TMP/b"
	else
		status="$(curl -sS -m 20 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$url")" || status=000
	fi
	check "$label status 404" "$status" "404"
	if grep -qiE "zz(archived|scheduled|draft)${TAG}|$LOGIN" "$TMP/b" "$TMP/h"; then fail "$label leaks a title, slug or login"; else ok "$label leaks nothing"; fi
	if grep -qi '^location:' "$TMP/h"; then fail "$label sends a Location header"; else ok "$label sends no redirect"; fi
}

# listing URL: hidden titles and the staff login must not appear (redirects are followed).
listing() {
	curl -sS -m 20 -L -D "$TMP/h" -o "$TMP/b" "$1" >/dev/null 2>&1
	if grep -qiE "zz(archived|scheduled|draft)${TAG}|$LOGIN" "$TMP/b" "$TMP/h"; then fail "$1 leaks a hidden article or the login"; else ok "$1 shows no hidden article or login"; fi
}

echo "Blog e2e: $BASE_URL (tag $TAG)"

IDS="$(wp eval "
\$staff = wp_insert_user(['user_login' => '$LOGIN', 'user_pass' => wp_generate_password(), 'user_email' => '$LOGIN@example.test', 'role' => 'subscriber']);
\$term = wp_insert_term('ZZ e2e', 'cc_article_category', ['slug' => 'zz-e2e-$TAG']);
\$mk = function (\$title, \$status, \$extra = []) use (\$staff, \$term) {
	\$id = wp_insert_post(array_merge(['post_type' => 'cc_article', 'post_title' => \$title, 'post_excerpt' => 'Excerpt of ' . \$title, 'post_content' => '<p>Body of ' . \$title . '</p>', 'post_status' => \$status, 'post_author' => \$staff], \$extra));
	wp_set_object_terms(\$id, [(int) \$term['term_id']], 'cc_article_category');
	return \$id;
};
\$pub = \$mk('$PUB_TITLE', 'publish');
update_post_meta(\$pub, 'cc_meta_description', 'Meta description $TAG');
\$arc = \$mk('$ARC_TITLE', 'publish'); update_post_meta(\$arc, 'cc_archived', '1');
\$gmt = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);
\$fut = \$mk('$FUT_TITLE', 'future', ['post_date_gmt' => \$gmt, 'post_date' => get_date_from_gmt(\$gmt)]);
\$drf = \$mk('$DRF_TITLE', 'draft');
echo \$pub . ' ' . \$arc . ' ' . \$fut . ' ' . \$drf . ' ' . get_permalink(\$pub) . ' ' . get_permalink(\$arc) . ' ' . get_permalink(\$fut);
")"
read -r PUB ARC FUT DRF PUB_LINK ARC_LINK FUT_LINK <<<"$IDS"
if [ -z "${DRF:-}" ]; then echo "could not create fixtures: '$IDS'"; exit 1; fi
echo "  published #$PUB, archived #$ARC, scheduled #$FUT, draft #$DRF"

echo "public listing and single page"
check "GET /blog/" "$(status_of "$BASE_URL/blog/")" "200"
curl -sS -m 20 "$BASE_URL/blog/" -o "$TMP/archive"
if grep -q "$PUB_TITLE" "$TMP/archive"; then ok "/blog/ lists the published article"; else fail "/blog/ lists the published article"; fi
listing "$BASE_URL/blog/"
listing "$BASE_URL/blog/category/zz-e2e-$TAG/"
check "GET /blog/category/<term>/" "$(status_of "$BASE_URL/blog/category/zz-e2e-$TAG/")" "200"
check "GET single published" "$(status_of "$PUB_LINK")" "200"
curl -sS -m 20 -D "$TMP/h" "$PUB_LINK" -o "$TMP/single"
for pat in "<meta name=\"description\" content=\"Meta description $TAG\"" '<meta property="og:title"' '<meta property="og:type" content="article"' '<link rel="canonical"' 'application/ld[+]json'; do
	if grep -q "$pat" "$TMP/single"; then ok "single has $pat"; else fail "single has $pat"; fi
done
if python3 - "$TMP/single" "$LOGIN" <<'PY'
import json, re, sys
html = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)
data = json.loads(m.group(1))
art = [n for n in data["@graph"] if n.get("@type") == "Article"][0]
assert art["headline"] and art["datePublished"] and art["author"]["name"]
assert sys.argv[2] not in json.dumps(data)
PY
then ok "JSON-LD parses, has an Article and no login"; else fail "JSON-LD parses, has an Article and no login"; fi
if grep -qE "/author/|[?&]author=|$LOGIN" "$TMP/single" "$TMP/archive" "$TMP/h"; then fail "pages link an author archive or print the login"; else ok "no author archive link or login on the pages"; fi
check "feed 200" "$(status_of "$BASE_URL/blog/feed/")" "200"
check "REST single published" "$(status_of "$BASE_URL/wp-json/wp/v2/cc_article/$PUB")" "200"
check "oEmbed ?p= published" "$(status_of "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$PUB")" "200"
check "/?p=ID published follows the canonical redirect" "$(status_of -L "$BASE_URL/?p=$PUB")" "200"
curl -sS -m 20 "$BASE_URL/wp-json/wp/v2/cc_article/$PUB" -o "$TMP/rest"
if grep -qiE "$LOGIN|\"author\"" "$TMP/rest"; then fail "REST single exposes the author"; else ok "REST single exposes no author"; fi
curl -sS -m 20 "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$PUB" -o "$TMP/oembed"
if grep -qi "$LOGIN" "$TMP/oembed"; then fail "oEmbed exposes the login"; else ok "oEmbed exposes no login"; fi

echo "archived, scheduled and draft are 404 everywhere"
hidden "archived permalink" GET "$ARC_LINK"
hidden "scheduled permalink" GET "$FUT_LINK"
hidden "archived /embed/" GET "${ARC_LINK%/}/embed/"
hidden "scheduled /embed/" GET "${FUT_LINK%/}/embed/"
check "published /embed/ still renders" "$(status_of "${PUB_LINK%/}/embed/")" "200"
for pair in "archived:$ARC" "scheduled:$FUT" "draft:$DRF"; do
	name="${pair%%:*}"; id="${pair##*:}"
	hidden "$name /?p=ID" GET "$BASE_URL/?p=$id"
	hidden "$name HEAD /?p=ID" HEAD "$BASE_URL/?p=$id"
	hidden "$name /?p=ID&post_type=cc_article" GET "$BASE_URL/?p=$id&post_type=cc_article"
	hidden "$name /?p=ID&embed=true" GET "$BASE_URL/?p=$id&embed=true"
	hidden "$name oEmbed ?p=ID" GET "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$id"
	for route in cc_article CC_ARTICLE Cc_article; do
		hidden "$name GET /wp/v2/$route/ID" GET "$BASE_URL/wp-json/wp/v2/$route/$id"
		hidden "$name HEAD /wp/v2/$route/ID" HEAD "$BASE_URL/wp-json/wp/v2/$route/$id"
		hidden "$name GET ?rest_route=/wp/v2/$route/ID" GET "$BASE_URL/?rest_route=/wp/v2/$route/$id"
	done
	listing "$BASE_URL/wp-json/wp/v2/cc_article?include=$id"
done
hidden "archived REST trailing slash" GET "$BASE_URL/wp-json/wp/v2/cc_article/$ARC/"

echo "listings never include hidden articles"
listing "$BASE_URL/"
listing "$BASE_URL/blog/page/2/"
listing "$BASE_URL/?post_type=cc_article"
listing "$BASE_URL/?post_type=cc_article&feed=rss2"
listing "$BASE_URL/feed/"
listing "$BASE_URL/blog/feed/"
listing "$BASE_URL/wp-sitemap.xml"
listing "$BASE_URL/wp-sitemap-posts-cc_article-1.xml"
listing "$BASE_URL/wp-sitemap-taxonomies-cc_article_category-1.xml"
listing "$BASE_URL/?s=Zzarchived"
listing "$BASE_URL/?s=Zzscheduled"
listing "$BASE_URL/?s=Zzdraft"
listing "$BASE_URL/wp-json/wp/v2/search?search=Zzarchived$TAG"
listing "$BASE_URL/wp-json/wp/v2/cc_article?per_page=100"
listing "$BASE_URL/wp-json/wp/v2/cc_article?search=Zzarchived$TAG"
listing "$BASE_URL/wp-json/wp/v2/cc_article?slug=zzarchived$TAG"
listing "$BASE_URL/wp-json/wp/v2/cc_article?status=any"
listing "$BASE_URL/wp-json/wp/v2/cc_article?status=draft"
if curl -sS -m 20 "$BASE_URL/wp-sitemap-posts-cc_article-1.xml" | grep -q "$PUB_LINK"; then ok "sitemap lists the published article"; else fail "sitemap lists the published article"; fi
check "author archive redirects away" "$(status_of "$BASE_URL/?author=1")" "301"
check "users endpoint is closed" "$(status_of "$BASE_URL/wp-json/wp/v2/users")" "404"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
