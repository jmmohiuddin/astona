#!/usr/bin/env bash
# Media privacy e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/media-privacy.sh
# Creates throwaway gallery-item images (one on a DRAFT item, one on a published item that is then TRASHED) through the
# wpcli container and deletes them on exit. Anonymous visitors must get no title, thumbnail or redirect for them from the
# media REST API, oEmbed, ?p=, ?attachment_id= or the attachment permalink.
# Result photos are not attachments any more (private files behind signed URLs): see tests/e2e/results-photo.sh. A legacy
# result photo that is still an attachment (migration failed or pending) is covered here as a third hidden image.
# Not covered by design: the raw file of a gallery image under wp-content/uploads/ is served by the web server.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
SECRET="zzmediaprivacy${TAG}"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null </dev/null); }

cleanup() {
	wp eval "global \$wpdb;
foreach (\$wpdb->get_col(\"SELECT ID FROM {\$wpdb->posts} WHERE post_title LIKE '%${SECRET}%'\") as \$i) { wp_delete_post(\$i, true); }
\$wpdb->query(\"DELETE FROM {\$wpdb->prefix}cc_audit_log WHERE entity_type = 'result' AND entity_id NOT IN (SELECT ID FROM {\$wpdb->posts})\");" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }

# closed LABEL URL [METHOD]: 404, no secret in body/headers, no Location header.
closed() {
	local label="$1" url="$2" method="${3:-GET}" status
	if [ "$method" = HEAD ]; then
		status="$(curl -sS -m 20 -I -D "$TMP/h" -o /dev/null -w '%{http_code}' "$url")" || status=000
		: > "$TMP/b"
	else
		status="$(curl -sS -m 20 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$url")" || status=000
	fi
	check "$label status 404" "$status" "404"
	if grep -qi "$SECRET" "$TMP/b" "$TMP/h"; then fail "$label leaks the title or file name"; else ok "$label leaks nothing"; fi
	if grep -qi '^location:' "$TMP/h"; then fail "$label sends a Location header"; else ok "$label sends no redirect"; fi
}

# nodata URL: whatever the status, the secret must not appear (redirects followed).
nodata() {
	curl -sS -m 20 -L -D "$TMP/h" -o "$TMP/b" "$1" >/dev/null 2>&1
	if grep -qi "$SECRET" "$TMP/b" "$TMP/h"; then fail "$1 leaks the title or file name"; else ok "$1 leaks nothing"; fi
}

echo "Media privacy e2e: $BASE_URL (tag $TAG)"

IDS="$(wp eval "
require_once ABSPATH . 'wp-admin/includes/image.php';
\$img = function (\$name) {
	\$c = imagecreatetruecolor(40, 30); ob_start(); imagejpeg(\$c); \$b = ob_get_clean();
	\$u = wp_upload_bits(\$name . '.jpg', null, \$b);
	\$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => \$name, 'post_status' => 'inherit'], \$u['file']);
	wp_update_attachment_metadata(\$id, wp_generate_attachment_metadata(\$id, \$u['file']));
	return \$id;
};
\$item = function (\$n, \$image) {
	return wp_insert_post(['post_type' => 'cc_gallery_item', 'post_title' => \$n, 'post_status' => 'publish', 'meta_input' => ['cc_gallery_image' => \$image, 'cc_gallery_alt' => 'zz alt']]);
};
\$a = \$img('${SECRET}-draft');
\$t = \$img('${SECRET}-trashed');
\$ra = \$item('Zz ${SECRET} draft item', \$a);
wp_update_post(['ID' => \$ra, 'post_status' => 'draft']);
\$rt = \$item('Zz ${SECRET} trashed item', \$t);
wp_trash_post(\$rt);
\$l = \$img('${SECRET}-legacy');
wp_insert_post(['post_type' => 'cc_result', 'post_title' => 'Zz ${SECRET} legacy result', 'post_status' => 'publish', 'meta_input' => ['cc_result_verified' => '1', 'cc_result_consent' => '1', 'cc_result_photo' => \$l]]);
echo \$a . ' ' . \$t . ' ' . get_attachment_link(\$a) . ' ' . get_attachment_link(\$t) . ' ' . \$l . ' ' . get_attachment_link(\$l);
")"
read -r A T A_LINK T_LINK L L_LINK <<<"$IDS"
if [ -z "${L_LINK:-}" ]; then echo "could not create fixtures: '$IDS'"; exit 1; fi
echo "  draft-item image #$A, trashed-item image #$T, legacy result photo (not yet migrated) #$L"

echo "anonymous media REST is closed"
for url in "$BASE_URL/wp-json/wp/v2/media" "$BASE_URL/wp-json/wp/v2/MEDIA" "$BASE_URL/?rest_route=/wp/v2/media" "$BASE_URL/?rest_route=/wp/v2/Media" \
	"$BASE_URL/wp-json/wp/v2/media?search=$SECRET" "$BASE_URL/wp-json/wp/v2/media?per_page=100&orderby=id&order=desc"; do
	closed "$url" "$url"
done

echo "hidden gallery images on every route"
for pair in "draft-item $A $A_LINK" "trashed-item $T $T_LINK" "legacy-result-photo $L $L_LINK"; do
	read -r name id link <<<"$pair"
	for route in media MEDIA Media; do
		closed "$name GET /wp/v2/$route/ID" "$BASE_URL/wp-json/wp/v2/$route/$id"
		closed "$name HEAD /wp/v2/$route/ID" "$BASE_URL/wp-json/wp/v2/$route/$id" HEAD
		closed "$name ?rest_route=/wp/v2/$route/ID" "$BASE_URL/?rest_route=/wp/v2/$route/$id"
	done
	closed "$name oEmbed ?attachment_id=" "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?attachment_id=$id"
	closed "$name oEmbed ?p=" "$BASE_URL/wp-json/oembed/1.0/embed?url=$BASE_URL/?p=$id"
	closed "$name oEmbed attachment permalink" "$BASE_URL/wp-json/oembed/1.0/embed?url=$link"
	closed "$name /?p=ID" "$BASE_URL/?p=$id"
	closed "$name HEAD /?p=ID" "$BASE_URL/?p=$id" HEAD
	closed "$name /?page_id=ID" "$BASE_URL/?page_id=$id"
	closed "$name /?attachment_id=ID" "$BASE_URL/?attachment_id=$id"
	closed "$name attachment permalink" "$link"
	closed "$name attachment permalink /embed/" "${link%/}/embed/"
	# the search page echoes its query, so search the untagged prefix and look for the tagged name
	nodata "$BASE_URL/?s=zzmediaprivacy"
	nodata "$BASE_URL/wp-json/wp/v2/search?search=$SECRET"
done

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
