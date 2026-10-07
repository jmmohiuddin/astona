#!/usr/bin/env bash
# Student courses/resources e2e over HTTP. Usage: BASE_URL=http://localhost:8080 tests/e2e/portal-courses.sh
# Creates a throwaway student, batches, lessons and a PDF through the wpcli container and removes them on exit.
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
API="$BASE_URL/wp-json/cc/v1"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
JAR="$TMP/jar"
TAG="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
LOGIN_A="88013$TAG"
PHONE_A="013$TAG"
PW="E2e-Pass-$RANDOM$RANDOM"

wp() { (cd "$ROOT" && docker compose run --rm -T wpcli "$@" 2>/dev/null); }

cleanup() {
	wp eval "
global \$wpdb; \$p = \$wpdb->prefix; require_once ABSPATH.'wp-admin/includes/user.php';
foreach (\$wpdb->get_col(\"SELECT id FROM {\$p}cc_batches WHERE name LIKE 'ZZ-E2E-$TAG%'\") as \$b) {
	foreach (CC_Content_Repository::modules_for_batch((int)\$b) as \$m) { CC_Content_Repository::delete_module(\$m['id']); }
	\$wpdb->delete(\$p.'cc_live_classes', ['batch_id'=>\$b]); \$wpdb->delete(\$p.'cc_enrollments', ['batch_id'=>\$b]); \$wpdb->delete(\$p.'cc_batches', ['id'=>\$b]);
}
\$u = get_user_by('login', '$LOGIN_A'); if (\$u) { \$wpdb->delete(\$p.'cc_students', ['user_id'=>\$u->ID]); wp_delete_user(\$u->ID); }
" >/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

ok()    { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail()  { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
json()  { python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1],""))' "$1" 2>/dev/null; }

# get PATH [curl args] -> CODE, HDR (lowercased), body in $TMP/b
get() {
	local path="$1"; shift
	CODE="$(curl -sS -m 30 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$BASE_URL$path" "$@")" || CODE=000
	HDR="$(tr -d '\r' < "$TMP/h" | tr 'A-Z' 'a-z')"
}

echo "Portal courses e2e: $BASE_URL (student $PHONE_A)"

wp eval "
global \$wpdb; \$p = \$wpdb->prefix; \$now = gmdate('Y-m-d H:i:s');
if (!get_role('cc_student')) { add_role('cc_student', 'Student', ['read' => true]); }
\$uid = wp_insert_user(['user_login' => '$LOGIN_A', 'user_pass' => '$PW', 'user_email' => '$LOGIN_A@students.invalid', 'role' => 'cc_student']);
\$wpdb->insert(\$p.'cc_students', ['user_id' => \$uid, 'full_name' => 'E2E Courses', 'must_change_pw' => 0, 'created_at' => \$now]);
\$mk = function (\$name) use (\$wpdb, \$p, \$now) { \$wpdb->insert(\$p.'cc_batches', ['course_id'=>0,'name'=>\$name,'capacity'=>10,'price'=>1,'start_date'=>'2030-01-01','schedule_text'=>'x','status'=>'open','application_open'=>1,'created_at'=>\$now,'updated_at'=>\$now]); return (int) \$wpdb->insert_id; };
\$own = \$mk('ZZ-E2E-$TAG own'); \$other = \$mk('ZZ-E2E-$TAG other');
\$wpdb->insert(\$p.'cc_enrollments', ['user_id'=>\$uid,'batch_id'=>\$own,'application_id'=>900000000+$TAG,'status'=>'active','enrolled_at'=>\$now]);
\$pdf = CC_Resource_Store::store_bytes(\"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n\", 'E2E Notes.pdf');
\$mod_own = CC_Content_Repository::create_module(\$own, 'E2E module'); \$mod_other = CC_Content_Repository::create_module(\$other, 'Other module');
\$l_own = CC_Content_Repository::create_lesson(\$mod_own, ['title'=>'E2E lesson','attachment_path'=>\$pdf['path'],'attachment_name'=>\$pdf['name']]);
\$l_other = CC_Content_Repository::create_lesson(\$mod_other, ['title'=>'Other lesson','attachment_path'=>\$pdf['path'],'attachment_name'=>\$pdf['name']]);
\$wpdb->insert(\$p.'cc_live_classes', ['batch_id'=>\$own,'title'=>'E2E live','starts_at'=>gmdate('Y-m-d H:i:s', time()+3600),'ends_at'=>gmdate('Y-m-d H:i:s', time()+7200),'provider'=>'meet','meeting_url_enc'=>CC_Crypto::encrypt('https://meet.google.com/zze-2ee-secret'),'created_by'=>1,'updated_at'=>\$now]);
echo json_encode(['own'=>\$own,'other'=>\$other,'l_own'=>\$l_own,'l_other'=>\$l_other]);
" > "$TMP/ids"
if [ -s "$TMP/ids" ]; then ok "fixtures created"; else fail "fixtures not created (is docker compose up and the plugin loaded?)"; exit 1; fi
OWN="$(json own < "$TMP/ids")"; OTHER="$(json other < "$TMP/ids")"; L_OWN="$(json l_own < "$TMP/ids")"; L_OTHER="$(json l_other < "$TMP/ids")"

echo "Anonymous"
for path in "/student/resources/$L_OWN/" "/student/courses/" "/student/courses/$OWN/" "/student/notices/"; do
	get "$path"
	check "anonymous $path redirects" "$CODE" "302"
done

echo "Login"
CODE="$(curl -sS -m 20 -o "$TMP/b" -w '%{http_code}' -X POST "$API/auth/login" -H 'Content-Type: application/json' -d "{\"phone\":\"$PHONE_A\",\"password\":\"$PW\"}" -c "$JAR")"
check "login 200" "$CODE" "200"

echo "Pages"
get "/student/courses/" -b "$JAR"; check "my courses 200" "$CODE" "200"
grep -q "ZZ-E2E-$TAG own" "$TMP/b" && ok "own batch listed" || fail "own batch listed"
grep -q "ZZ-E2E-$TAG other" "$TMP/b" && fail "other batch must not be listed" || ok "other batch not listed"
echo "$HDR" | grep -q 'cache-control:.*no-store' && ok "courses no-store" || fail "courses no-store"
get "/student/courses/$OWN/" -b "$JAR"; check "course page 200" "$CODE" "200"
grep -q "E2E lesson" "$TMP/b" && ok "lesson shown" || fail "lesson shown"
grep -q "/student/resources/$L_OWN/" "$TMP/b" && ok "PDF link shown" || fail "PDF link shown"
grep -q 'class="live-join' "$TMP/b" && ok "join button rendered" || fail "join button rendered"
grep -qi 'meet.google.com\|zze-2ee' "$TMP/b" && fail "meeting URL leaked into course HTML" || ok "no meeting URL in course HTML"
grep -q 'student-live.js' "$TMP/b" && ok "live script enqueued" || fail "live script enqueued"
get "/student/courses/$OTHER/" -b "$JAR"; check "other batch page 200 (friendly view)" "$CODE" "200"
grep -q "not available" "$TMP/b" && ok "friendly not-available message" || fail "friendly not-available message"
grep -q "Other lesson" "$TMP/b" && fail "other batch content leaked" || ok "other batch content hidden"
get "/student/" -b "$JAR"; check "dashboard 200" "$CODE" "200"
grep -q "E2E live" "$TMP/b" && ok "dashboard shows the live class" || fail "dashboard shows the live class"
grep -qi 'meet.google.com\|zze-2ee' "$TMP/b" && fail "meeting URL leaked into dashboard" || ok "no meeting URL in dashboard"
get "/student/notices/" -b "$JAR"; check "notices 200" "$CODE" "200"

echo "Resource stream"
get "/student/resources/$L_OWN/" -b "$JAR"
check "enrolled -> 200" "$CODE" "200"
echo "$HDR" | grep -q '^content-type: application/pdf' && ok "content-type pdf" || fail "content-type pdf"
echo "$HDR" | grep -q '^content-disposition: attachment; filename="e2e-notes.pdf"' && ok "attachment with sanitised name" || fail "attachment disposition ($(echo "$HDR" | grep content-disposition))"
echo "$HDR" | grep -q '^x-content-type-options: nosniff' && ok "nosniff" || fail "nosniff"
echo "$HDR" | grep -q '^cache-control:.*no-store' && ok "no-store" || fail "no-store"
head -c 5 "$TMP/b" | grep -q '%PDF-' && ok "body is PDF bytes" || fail "body is PDF bytes"
get "/student/resources/$L_OTHER/" -b "$JAR"; NOT_ENROLLED_CODE="$CODE"; NOT_ENROLLED_BODY="$(cat "$TMP/b")"
check "not enrolled -> 404" "$NOT_ENROLLED_CODE" "404"
get "/student/resources/999999999/" -b "$JAR"
check "unknown lesson -> 404" "$CODE" "404"
check "unknown and not-enrolled bodies identical" "$(cat "$TMP/b")" "$NOT_ENROLLED_BODY"
for path in "/student/resources/0/" "/student/resources/abc/" "/student/resources/..%2f..%2fwp-config.php/" "/student/resources/$L_OWN/../$L_OTHER/" "/student/resources/$L_OWN%00/" "/student/resources/99999999999999999999/"; do
	get "$path" --path-as-is -b "$JAR"
	[ "$CODE" = "404" ] && ok "odd id $path -> 404" || fail "odd id $path (got $CODE)"
done
get "/student/resources/$L_OWN/"; check "anonymous download redirects" "$CODE" "302"
grep -q '%PDF' "$TMP/b" && fail "anonymous got PDF bytes" || ok "anonymous gets no PDF bytes"

echo
echo "$((PASS + FAIL)) checks, $FAIL failures"
[ "$FAIL" -eq 0 ]
