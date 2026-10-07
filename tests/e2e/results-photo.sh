#!/usr/bin/env bash
# Private result photos e2e over real HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/results-photo.sh
# Creates throwaway results with private photos through the wpcli container (public, unconsented, unverified, draft,
# trashed) and deletes them on exit. Asserts: only the public result shows an <img> on /results/ and its signed URL returns
# image bytes; every other state, forged, tampered, expired or wrong-id URL gets the identical 404; consent revocation
# stops an already issued URL at once; no result photo bytes are reachable under /wp-content/uploads or any other public
# path.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
N_PUBLIC="Zzrphoto${TAG}Public"
N_NOCONSENT="Zzrphoto${TAG}NoConsent"
N_UNVERIFIED="Zzrphoto${TAG}Unverified"
N_DRAFT="Zzrphoto${TAG}Draft"
N_TRASHED="Zzrphoto${TAG}Trashed"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null </dev/null); }

cleanup() {
	wp eval "global \$wpdb;
foreach (\$wpdb->get_col(\"SELECT ID FROM {\$wpdb->posts} WHERE post_title LIKE 'Zzrphoto${TAG}%'\") as \$i) { wp_delete_post(\$i, true); }
\$wpdb->query(\"DELETE FROM {\$wpdb->prefix}cc_audit_log WHERE entity_type = 'result' AND entity_id NOT IN (SELECT ID FROM {\$wpdb->posts})\");" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }

# fetch URL [curl args...]: status in $STATUS, headers in $TMP/h, body in $TMP/b
fetch() {
	local url="$1"; shift
	STATUS="$(curl -sS -m 20 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$@" "$url" 2>/dev/null)" || STATUS=000
}
header() { grep -i "^$1:" "$TMP/h" | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
# fingerprint of the last response: status, all headers except Date, and a body hash
fingerprint() { { head -1 "$TMP/h" | tr -d '\r'; grep -v -i '^date:' "$TMP/h" | tail -n +2 | tr -d '\r' | sort; md5sum < "$TMP/b" 2>/dev/null || md5 < "$TMP/b"; } | tr '\n' '|'; }
magic() { od -An -tx1 -N3 "$TMP/b" | tr -d ' \n'; }
# signed URL path for a result id and variant, printed relative to the site root
signed() { wp eval "echo parse_url( CC_Result_Photo_Access::signed_url( $1, '${2:-orig}', ${3:-600} ), PHP_URL_PATH );" | tr -d '\r\n'; }

echo "Results photo e2e: $BASE_URL (tag $TAG)"

IDS="$(wp eval "
\$jpeg = function (\$r, \$g, \$b) {
	\$c = imagecreatetruecolor(200, 150); imagefill(\$c, 0, 0, imagecolorallocate(\$c, \$r, \$g, \$b)); ob_start(); imagejpeg(\$c); return ob_get_clean();
};
\$mk = function (\$name, \$v, \$c, \$status, \$rgb) use (\$jpeg) {
	\$path = CC_Result_Photo_Store::store_bytes(\$jpeg(...\$rgb));
	if (is_wp_error(\$path)) { echo 'ERR ' . \$path->get_error_message(); exit(1); }
	\$id = wp_insert_post(['post_type' => 'cc_result', 'post_title' => \$name, 'post_status' => \$status === 'trash' ? 'publish' : \$status, 'meta_input' => [
		'cc_result_verified' => \$v, 'cc_result_consent' => \$c, 'cc_result_year' => '2026', 'cc_result_exam' => 'HSC', 'cc_result_score' => 'GPA 5.00',
		'cc_result_photo_path' => \$path, 'cc_result_photo_alt' => 'Photo of ' . \$name]]);
	if (\$status === 'trash') { wp_trash_post(\$id); }
	return \$id . ',' . \$path;
};
echo implode(' ', [
	\$mk('${N_PUBLIC}', '1', '1', 'publish', [200, 30, 30]),
	\$mk('${N_NOCONSENT}', '1', '0', 'publish', [30, 200, 30]),
	\$mk('${N_UNVERIFIED}', '0', '1', 'publish', [30, 30, 200]),
	\$mk('${N_DRAFT}', '1', '1', 'draft', [200, 200, 30]),
	\$mk('${N_TRASHED}', '1', '1', 'trash', [30, 200, 200]),
]);
")"
read -r F_PUBLIC F_NOCONSENT F_UNVERIFIED F_DRAFT F_TRASHED <<<"$IDS"
if [ -z "${F_TRASHED:-}" ] || [[ "$IDS" == ERR* ]]; then echo "could not create fixtures: '$IDS'"; exit 1; fi
ID_PUBLIC="${F_PUBLIC%%,*}"; PATH_PUBLIC="${F_PUBLIC#*,}"
ID_NOCONSENT="${F_NOCONSENT%%,*}"; PATH_NOCONSENT="${F_NOCONSENT#*,}"
ID_UNVERIFIED="${F_UNVERIFIED%%,*}"; PATH_UNVERIFIED="${F_UNVERIFIED#*,}"
ID_DRAFT="${F_DRAFT%%,*}"; PATH_DRAFT="${F_DRAFT#*,}"
ID_TRASHED="${F_TRASHED%%,*}"; PATH_TRASHED="${F_TRASHED#*,}"
echo "  results public #$ID_PUBLIC, no consent #$ID_NOCONSENT, unverified #$ID_UNVERIFIED, draft #$ID_DRAFT, trashed #$ID_TRASHED"

echo "/results/ page"
fetch "$BASE_URL/results/"
check "/results/ status" "$STATUS" "200"
cp "$TMP/b" "$TMP/results.html"
check "/results/ Cache-Control (page embeds expiring URLs)" "$(header cache-control)" "private, no-cache"
for n in "$N_NOCONSENT" "$N_UNVERIFIED" "$N_DRAFT" "$N_TRASHED"; do
	if grep -q "$n" "$TMP/results.html"; then fail "/results/ must not list $n"; else ok "/results/ does not list $n"; fi
done
if grep -q "$N_PUBLIC" "$TMP/results.html"; then ok "/results/ lists the public result"; else fail "/results/ lists the public result"; fi
IMG_SRC="$(grep -o "<img[^>]*alt=\"Photo of ${N_PUBLIC}\"[^>]*>" "$TMP/results.html" | grep -o 'src="[^"]*"' | head -1 | sed 's/^src="//; s/"$//; s/&#038;/\&/g; s/&amp;/\&/g')"
WEBP_SRC="$(grep -o "<source[^>]*type=\"image/webp\"[^>]*>" "$TMP/results.html" | grep -o "srcset=\"[^\"]*${ID_PUBLIC}/[^\"]*\"" | head -1 | sed 's/^srcset="//; s/"$//')"
check "exactly one result photo <img> on the page" "$(grep -o '<img[^>]*results-card__photo[^>]*>' "$TMP/results.html" | grep -c "alt=\"Photo of Zzrphoto${TAG}" )" "1"
check "the photo <img> belongs to the public result only" "$(grep -o "<img[^>]*results-card__photo[^>]*>" "$TMP/results.html" | grep -c "Photo of Zzrphoto${TAG}Public")" "1"
case "$IMG_SRC" in */cc-result-photo/"$ID_PUBLIC"/*) ok "img src is a signed /cc-result-photo/ URL" ;; *) fail "img src is a signed /cc-result-photo/ URL ($IMG_SRC)" ;; esac
if echo "$IMG_SRC $WEBP_SRC" | grep -q "wp-content"; then fail "page must not reference wp-content for the photo"; else ok "page never references wp-content for the photo"; fi
for n in "$PATH_PUBLIC" "$PATH_NOCONSENT" "$PATH_UNVERIFIED" "$PATH_DRAFT" "$PATH_TRASHED"; do
	if grep -q "$(basename "$n" | cut -d. -f1)" "$TMP/results.html"; then fail "page leaks the private file name $n"; else ok "page does not contain the private file name of $n"; fi
done

echo "signed URL of the public result"
fetch "$IMG_SRC"
check "signed URL status" "$STATUS" "200"
check "image bytes (JPEG magic)" "$(magic)" "ffd8ff"
check "Content-Type" "$(header content-type)" "image/jpeg"
check "Content-Disposition" "$(header content-disposition | cut -d';' -f1)" "inline"
check "X-Content-Type-Options" "$(header x-content-type-options)" "nosniff"
check "Cache-Control" "$(header cache-control)" "private, max-age=300"
if header cache-control | grep -Eqi 'public|immutable|s-maxage'; then fail "Cache-Control must never be public or immutable"; else ok "Cache-Control is never public or immutable"; fi
check "Content-Length matches the body" "$(header content-length)" "$(wc -c < "$TMP/b" | tr -d ' ')"
fetch "$IMG_SRC" -I
check "HEAD status" "$STATUS" "200"
fetch "$IMG_SRC" -X POST
check "POST is a 404" "$STATUS" "404"
if [ -n "$WEBP_SRC" ]; then
	fetch "$WEBP_SRC"
	check "WebP variant status" "$STATUS" "200"
	check "WebP variant Content-Type" "$(header content-type)" "image/webp"
else
	fail "page offers a WebP <source> for the photo"
fi

echo "uniform 404s"
PUB_PATH="$(echo "$IMG_SRC" | sed 's#^https\{0,1\}://[^/]*##')"
PUB_SEG="${PUB_PATH#/cc-result-photo/}"                 # id/expiry/sig.ext
PUB_EXP="$(echo "$PUB_SEG" | cut -d/ -f2)"
PUB_SIG="$(echo "$PUB_SEG" | cut -d/ -f3 | cut -d. -f1)"
BAD_SIG="$(echo "$PUB_SIG" | sed 's/^./0/; s/^00/01/')"
[ "$BAD_SIG" = "$PUB_SIG" ] && BAD_SIG="1${PUB_SIG#?}"
NONEXISTENT=$((ID_PUBLIC + 99999999))
NOSUCH_PATH="$(wp eval "\$e = time() + 300; echo '/cc-result-photo/${NONEXISTENT}/' . \$e . '/' . CC_Result_Photo_Access::sign(${NONEXISTENT}, \$e, 'orig') . '.jpg';" | tr -d '\r\n')"
EXPIRED_PATH="$(signed "$ID_PUBLIC" orig -30)"
OTHER_ID_PATH="/cc-result-photo/${ID_NOCONSENT}/${PUB_EXP}/${PUB_SIG}.jpg"
declare -a CASES=(
	"unconsented result|$(signed "$ID_NOCONSENT")"
	"unverified result|$(signed "$ID_UNVERIFIED")"
	"draft result|$(signed "$ID_DRAFT")"
	"trashed result|$(signed "$ID_TRASHED")"
	"no such result (validly signed)|$NOSUCH_PATH"
	"tampered signature|/cc-result-photo/${ID_PUBLIC}/${PUB_EXP}/${BAD_SIG}.jpg"
	"tampered expiry|/cc-result-photo/${ID_PUBLIC}/$((PUB_EXP + 60))/${PUB_SIG}.jpg"
	"leading zero in the result id (non-canonical)|/cc-result-photo/0${ID_PUBLIC}/${PUB_EXP}/${PUB_SIG}.jpg"
	"expired URL|$EXPIRED_PATH"
	"signature of another result|$OTHER_ID_PATH"
	"wrong extension|/cc-result-photo/${ID_PUBLIC}/${PUB_EXP}/${PUB_SIG}.png"
	"webp extension on an orig signature|/cc-result-photo/${ID_PUBLIC}/${PUB_EXP}/${PUB_SIG}.webp"
	"garbage after the prefix|/cc-result-photo/not-a-url"
	"bare prefix|/cc-result-photo/"
	"directory-style probe|/cc-result-photo/${ID_PUBLIC}/"
)
REF=""
for c in "${CASES[@]}"; do
	label="${c%%|*}"; path="${c#*|}"
	fetch "$BASE_URL$path"
	check "$label: 404" "$STATUS" "404"
	fp="$(fingerprint)"
	if [ -z "$REF" ]; then REF="$fp"; fi
	if [ "$fp" = "$REF" ]; then ok "$label: identical status, headers and body"; else fail "$label: response differs from the first 404"; fi
	if [ "$(magic)" = "ffd8ff" ]; then fail "$label: returned image bytes"; fi
done
check "the shared 404 body" "$(cat "$TMP/b" | tr -d '\n')" "Not found"
check "404 is never cacheable" "$(header cache-control)" "no-store"

echo "immediate revocation"
fetch "$BASE_URL$PUB_PATH"; check "issued URL works before revocation" "$STATUS" "200"
wp eval "update_post_meta(${ID_PUBLIC}, 'cc_result_consent', '0');" >/dev/null
fetch "$BASE_URL$PUB_PATH"; check "consent off: the same issued URL is 404 immediately" "$STATUS" "404"
fetch "$BASE_URL/results/"
if grep -q "$N_PUBLIC" "$TMP/b"; then fail "consent off: result still listed"; else ok "consent off: result gone from /results/"; fi
wp eval "update_post_meta(${ID_PUBLIC}, 'cc_result_consent', '1'); update_post_meta(${ID_PUBLIC}, 'cc_result_verified', '0');" >/dev/null
fetch "$BASE_URL$PUB_PATH"; check "verified off: the same issued URL is 404 immediately" "$STATUS" "404"
wp eval "update_post_meta(${ID_PUBLIC}, 'cc_result_verified', '1');" >/dev/null
fetch "$BASE_URL$PUB_PATH"; check "flags restored: the URL works again (state re-read per request)" "$STATUS" "200"

echo "nothing reachable under the web root"
UPLOADS_HITS="$(wp eval "
\$dir = wp_upload_dir( null, false )['basedir']; \$hits = 0;
foreach ( ['${PATH_PUBLIC}', '${PATH_NOCONSENT}', '${PATH_UNVERIFIED}', '${PATH_DRAFT}', '${PATH_TRASHED}'] as \$rel ) {
	\$name = basename( \$rel );
	\$found = trim( (string) shell_exec( 'find ' . escapeshellarg( \$dir ) . ' ' . escapeshellarg( ABSPATH ) . 'wp-content -name ' . escapeshellarg( substr( \$name, 0, 32 ) . '*' ) . ' 2>/dev/null' ) );
	if ( '' !== \$found ) { \$hits++; }
}
echo \$hits;" | tr -d '\r\n')"
check "no result photo file exists anywhere under wp-content or uploads" "$UPLOADS_HITS" "0"
for rel in "$PATH_PUBLIC" "$PATH_NOCONSENT" "$PATH_UNVERIFIED" "$PATH_DRAFT" "$PATH_TRASHED"; do
	base="$(basename "$rel")"
	year="$(date +%Y)"; month="$(date +%m)"
	hits=0
	for url in "$BASE_URL/wp-content/uploads/$base" "$BASE_URL/wp-content/uploads/$base.webp" "$BASE_URL/wp-content/uploads/$year/$month/$base" "$BASE_URL/wp-content/uploads/$year/$month/$base.webp" \
		"$BASE_URL/wp-content/uploads/$rel" "$BASE_URL/wp-content/uploads/$rel.webp" "$BASE_URL/$rel" "$BASE_URL/private/$rel" "$BASE_URL/wp-content/private/$rel" "$BASE_URL/wp-content/$rel"; do
		fetch "$url" --path-as-is
		if [ "$STATUS" = "200" ] && [ "$(magic)" = "ffd8ff" ]; then hits=$((hits + 1)); fi
	done
	check "no public URL serves $base" "$hits" "0"
done
fetch "$BASE_URL/wp-content/../private/$PATH_PUBLIC" --path-as-is
if [ "$STATUS" = "200" ] && [ "$(magic)" = "ffd8ff" ]; then fail "traversal to the private dir serves the photo"; else ok "traversal to the private dir serves nothing"; fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
