#!/usr/bin/env bash
# Smoke test against a running site. Usage: BASE_URL=http://localhost:8080 tests/e2e/smoke.sh
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
PASS=0
FAIL=0

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }

# fetch PATH -> sets STATUS and BODY
fetch() {
	local out
	out="$(curl -sS -m 20 -w $'\n%{http_code}' "$BASE_URL$1" 2>/dev/null)" || out=$'\n000'
	STATUS="${out##*$'\n'}"
	BODY="${out%$'\n'*}"
}

# page PATH PATTERN DESCRIPTION: 200 and body matches extended regex
page() {
	fetch "$1"
	if [ "$STATUS" != "200" ]; then fail "$1 returned HTTP $STATUS (want 200)"; return; fi
	ok "$1 returns 200"
	if printf '%s' "$BODY" | grep -Eiq -- "$2"; then ok "$1 contains $3"; else fail "$1 missing $3"; fi
}

echo "Smoke test: $BASE_URL"

page "/"                          '<title>[^<]+</title>'            "a page title"
page "/courses/"                  'hsc-science-2027'                "link to HSC Science 2027"
page "/courses/hsc-science-2027/" '<h1[^>]*>[^<]*[A-Za-z]'          "an h1 heading"
page "/notices/"                  '<h1[^>]*>[^<]*[A-Za-z]'          "an h1 heading"
page "/faculty/"                  '<h1[^>]*>[^<]*[A-Za-z]'          "an h1 heading"
page "/admissions/"               '<h1[^>]*>[^<]*[A-Za-z]'          "an h1 heading"

echo "REST: courses"
fetch "/wp-json/cc/v1/courses"
if [ "$STATUS" = "200" ]; then ok "/wp-json/cc/v1/courses returns 200"; else fail "/wp-json/cc/v1/courses returned HTTP $STATUS"; fi
if printf '%s' "$BODY" | python3 -c '
import json, sys
d = json.load(sys.stdin)
items = d.get("items") if isinstance(d, dict) else d
sys.exit(0 if isinstance(items, list) and len(items) > 0 else 1)
' 2>/dev/null; then ok "courses JSON has non-empty items"; else fail "courses JSON missing non-empty items"; fi

echo "JSON-LD"
for path in "/" "/courses/hsc-science-2027/"; do
	fetch "$path"
	if printf '%s' "$BODY" | grep -Eiq '<script[^>]*type=["'\'']application/ld\+json["'\'']'; then
		ok "$path has JSON-LD script tag"
	else
		fail "$path missing JSON-LD script tag"
	fi
done

echo "User enumeration"
# /?author=1 must not redirect to /author/<login>/ or expose a user
LOC="$(curl -sS -m 20 -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE_URL/?author=1" 2>/dev/null || echo '000 ')"
if printf '%s' "$LOC" | grep -Eq '/author/'; then
	fail "/?author=1 redirects to an author archive ($LOC)"
else
	ok "/?author=1 does not reveal an author archive ($LOC)"
fi

fetch "/wp-json/wp/v2/users"
if [ "$STATUS" = "401" ] || [ "$STATUS" = "403" ] || [ "$STATUS" = "404" ]; then
	ok "/wp-json/wp/v2/users is blocked (HTTP $STATUS)"
elif [ "$STATUS" = "200" ] && printf '%s' "$BODY" | grep -Eq '^\s*\[\s*\]\s*$'; then
	ok "/wp-json/wp/v2/users returns an empty list"
else
	fail "/wp-json/wp/v2/users leaks data (HTTP $STATUS)"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
