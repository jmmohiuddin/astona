#!/usr/bin/env bash
# Gallery & results e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/gallery.sh
# Needs the seeded site (wp cc seed). Creates throwaway records through the wpcli container and deletes them on exit.
# Result photos are private files, not attachments: tests/e2e/results-photo.sh covers them.
# Hidden records (not verified, not consented, drafts, empty categories) and images of unpublished gallery items must be absent from HTML, REST, feeds and sitemaps,
# and the CPT permalinks must 404.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
NOCONSENT="Zzconsent${TAG}"
UNVERIFIED="Zzunverified${TAG}"
SHOWN="Zzshown${TAG}"
DRAFT_ITEM="Zzdraftitem${TAG}"
EMPTY_CAT="Zzemptycat${TAG}"
HIDDEN_PHOTO="zz-e2e-hidden-photo-${TAG}"
TRASHED_PHOTO_NAME="zz-e2e-trashed-photo-${TAG}"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

cleanup() {
	wp eval "global \$wpdb;
foreach (\$wpdb->get_col(\"SELECT ID FROM {\$wpdb->posts} WHERE post_title LIKE 'Zz%${TAG}' OR post_title LIKE 'zz-e2e-%${TAG}'\") as \$i) { wp_delete_post(\$i, true); }
\$t = get_term_by('name', '${EMPTY_CAT}', 'cc_gallery_cat'); if (\$t) { wp_delete_term(\$t->term_id, 'cc_gallery_cat'); }
\$wpdb->query(\"DELETE FROM {\$wpdb->prefix}cc_audit_log WHERE entity_type = 'result' AND entity_id NOT IN (SELECT ID FROM {\$wpdb->posts})\");" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
status_of() { curl -sS -m 20 -o /dev/null -w '%{http_code}' "$@" 2>/dev/null || echo 000; }

# absent URL: whatever the status, none of the secrets may appear (redirects followed).
absent() {
	curl -sS -m 20 -L -D "$TMP/h" -o "$TMP/b" "$1" >/dev/null 2>&1
	local leaked=""
	for secret in "$NOCONSENT" "$UNVERIFIED" "$DRAFT_ITEM" "$EMPTY_CAT" "$HIDDEN_PHOTO" "$TRASHED_PHOTO_NAME"; do
		if grep -qi "$secret" "$TMP/b" "$TMP/h"; then leaked="$leaked $secret"; fi
	done
	if [ -z "$leaked" ]; then ok "$1 shows no hidden record"; else fail "$1 leaks:$leaked"; fi
}

echo "fixtures"
IDS="$(wp eval "
require_once ABSPATH . 'wp-admin/includes/image.php';
\$img = function (\$name) {
	\$c = imagecreatetruecolor(40, 30); ob_start(); imagejpeg(\$c); \$b = ob_get_clean();
	\$u = wp_upload_bits(\$name . '.jpg', null, \$b);
	\$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => \$name, 'post_status' => 'inherit'], \$u['file']);
	wp_update_attachment_metadata(\$id, wp_generate_attachment_metadata(\$id, \$u['file']));
	return \$id;
};
\$res = function (\$n, \$v, \$c) {
	return wp_insert_post(['post_type' => 'cc_result', 'post_title' => \$n, 'post_status' => 'publish', 'meta_input' => ['cc_result_verified' => \$v, 'cc_result_consent' => \$c, 'cc_result_year' => '2026', 'cc_result_exam' => 'HSC']]);
};
\$photo = \$img('${HIDDEN_PHOTO}');
\$a = \$res('${NOCONSENT}', '1', '0');
\$b = \$res('${UNVERIFIED}', '0', '1');
\$c = \$res('${SHOWN}', '1', '1');
\$d = wp_insert_post(['post_type' => 'cc_gallery_item', 'post_title' => '${DRAFT_ITEM}', 'post_status' => 'draft', 'meta_input' => ['cc_gallery_image' => \$img('zz-e2e-draft-${TAG}'), 'cc_gallery_alt' => 'x']]);
wp_insert_term('${EMPTY_CAT}', 'cc_gallery_cat');
\$photo2 = \$img('${TRASHED_PHOTO_NAME}');
\$e = wp_insert_post(['post_type' => 'cc_gallery_item', 'post_title' => 'Zztrashed${TAG}', 'post_status' => 'publish', 'meta_input' => ['cc_gallery_image' => \$photo2, 'cc_gallery_alt' => 'x']]);
wp_trash_post(\$e);
wp_insert_post(['post_type' => 'cc_gallery_item', 'post_title' => 'Zzgalhidden${TAG}', 'post_status' => 'draft', 'meta_input' => ['cc_gallery_image' => \$photo, 'cc_gallery_alt' => 'x']]);
echo \$a . ' ' . \$b . ' ' . \$c . ' ' . \$d . ' ' . \$photo . ' ' . \$photo2;
")"
A="$(echo "$IDS" | awk '{print $1}')"; B="$(echo "$IDS" | awk '{print $2}')"; C="$(echo "$IDS" | awk '{print $3}')"; D="$(echo "$IDS" | awk '{print $4}')"; PHOTO="$(echo "$IDS" | awk '{print $5}')"; PHOTO2="$(echo "$IDS" | awk '{print $6}')"
if [ -z "$A" ] || [ -z "$B" ] || [ -z "$C" ] || [ -z "$D" ] || [ -z "$PHOTO" ] || [ -z "$PHOTO2" ]; then echo "could not create fixtures: '$IDS'"; exit 1; fi
echo "  results #$A (no consent) #$B (unverified) #$C (shown), draft gallery item #$D, draft gallery image #$PHOTO"

echo "public pages"
check "/gallery/ status" "$(status_of "$BASE_URL/gallery/")" "200"
check "/results/ status" "$(status_of "$BASE_URL/results/")" "200"
curl -sS -m 20 "$BASE_URL/gallery/" -o "$TMP/gallery.html"
curl -sS -m 20 "$BASE_URL/results/" -o "$TMP/results.html"
if grep -q 'gallery-grid__img' "$TMP/gallery.html" && grep -q 'loading="lazy"' "$TMP/gallery.html"; then ok "gallery lists lazy-loaded images"; else fail "gallery lists lazy-loaded images"; fi
if grep -q 'gallery-grid__img[^>]*alt="[^"]' "$TMP/gallery.html"; then ok "gallery images have alt text"; else fail "gallery images have alt text"; fi
if grep -Eq '<img[^>]*width="[0-9]+"[^>]*height="[0-9]+"' "$TMP/gallery.html"; then ok "gallery images carry width and height"; else fail "gallery images carry width and height"; fi
if grep -q 'gallery.js' "$TMP/gallery.html"; then ok "gallery page loads gallery.js"; else fail "gallery page loads gallery.js"; fi
if grep -q 'gallery.js' "$TMP/results.html"; then fail "results page must not load gallery.js"; else ok "results page does not load gallery.js"; fi
if grep -q "$SHOWN" "$TMP/results.html"; then ok "verified and consented result is listed"; else fail "verified and consented result is listed"; fi
if grep -q 'id="gallery-campus"' "$TMP/gallery.html"; then ok "seeded Campus category is listed"; else fail "seeded Campus category is listed"; fi
absent "$BASE_URL/gallery/"
absent "$BASE_URL/results/"

echo "hidden records on every surface"
absent "$BASE_URL/"
absent "$BASE_URL/feed/"
absent "$BASE_URL/?s=Zz"
absent "$BASE_URL/wp-sitemap.xml"
absent "$BASE_URL/wp-sitemap-posts-page-1.xml"
absent "$BASE_URL/wp-json/wp/v2/search?search=Zz"
absent "$BASE_URL/wp-json/wp/v2/media?per_page=100"
absent "$BASE_URL/wp-json/wp/v2/media?search=$HIDDEN_PHOTO"
absent "$BASE_URL/?attachment_id=$PHOTO"
absent "$BASE_URL/?attachment_id=$PHOTO2"
absent "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?attachment_id=$PHOTO"
absent "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?attachment_id=$PHOTO2"
absent "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$PHOTO2"
check "?p=<draft gallery image> is a plain 404" "$(status_of "$BASE_URL/?p=$PHOTO")" "404"
check "?p=<trashed gallery item image> is a plain 404" "$(status_of "$BASE_URL/?p=$PHOTO2")" "404"

echo "CPTs are not individually public"
for id in $A $B $C; do
	check "REST cc_result/$id" "$(status_of "$BASE_URL/wp-json/wp/v2/cc_result/$id")" "404"
	check "REST CC_RESULT/$id" "$(status_of "$BASE_URL/wp-json/wp/v2/CC_RESULT/$id")" "404"
	check "/?post_type=cc_result&p=$id" "$(status_of "$BASE_URL/?post_type=cc_result&p=$id")" "404"
	check "/?p=$id" "$(status_of "$BASE_URL/?p=$id")" "404"
done
check "REST cc_result collection" "$(status_of "$BASE_URL/wp-json/wp/v2/cc_result")" "404"
check "REST cc_gallery_item/$D" "$(status_of "$BASE_URL/wp-json/wp/v2/cc_gallery_item/$D")" "404"
check "REST cc_gallery_item collection" "$(status_of "$BASE_URL/wp-json/wp/v2/cc_gallery_item")" "404"
check "/?post_type=cc_gallery_item&p=$D" "$(status_of "$BASE_URL/?post_type=cc_gallery_item&p=$D")" "404"
check "REST draft gallery image media single" "$(status_of "$BASE_URL/wp-json/wp/v2/media/$PHOTO")" "404"
check "REST trashed gallery item image media single" "$(status_of "$BASE_URL/wp-json/wp/v2/media/$PHOTO2")" "404"
for slug in cc_result cc_gallery_item; do
	if curl -sS -m 20 "$BASE_URL/wp-sitemap.xml" | grep -q "$slug"; then fail "sitemap lists $slug"; else ok "sitemap does not list $slug"; fi
done
if curl -sS -m 20 "$BASE_URL/wp-sitemap-posts-page-1.xml" | grep -Eq '/(gallery|results)/'; then ok "sitemap lists the gallery and results pages"; else fail "sitemap lists the gallery and results pages"; fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
