#!/usr/bin/env bash
# Admission e2e with the Fake gateway and phone ownership proof (OTP read from the fake SMS outbox via wpcli). Usage: BASE_URL=http://localhost:8080 OPEN_BATCH=2 CLOSED_BATCH=7 tests/e2e/admission.sh
set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
OPEN_BATCH="${OPEN_BATCH:-2}"
CLOSED_BATCH="${CLOSED_BATCH:-7}"
API="$BASE_URL/wp-json/cc/v1"
PASS=0
FAIL=0
TMP="$(mktemp -d)"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=lib/phone-proof.sh
. "$(dirname "$0")/lib/phone-proof.sh"
trap 'rm -rf "$TMP"' EXIT

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got '$2', want '$3')"; fi; }
json() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1],""))' "$1" 2>/dev/null; }

# 1x1 PNG
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' | base64 -d > "$TMP/photo.png"

# apply KEY BATCH PHONE [PROOF] -> BODY, STATUS (unique phone per run so reruns do not hit 409)
apply() {
	local args=(-sS -m 30 -o "$TMP/body" -w '%{http_code}' -X POST "$API/applications")
	[ -n "$1" ] && args+=(-H "Idempotency-Key: $1")
	STATUS="$(curl "${args[@]}" -F "phone_proof=${4:-}" \
		-F "batch_id=$2" -F "full_name=Test Student" -F "gender=m" -F "dob=2008-05-17" \
		-F "id_doc_type=birth_cert" -F "id_doc_number=BC12345678" -F "student_phone=$3" \
		-F "guardian_name=Test Guardian" -F "guardian_phone=01811-223344" -F "email=test@example.com" \
		-F "institution=Test School" -F "class_level=Class 9" -F "passing_year=2026" -F "consent=1" \
		-F "photo=@$TMP/photo.png;type=image/png")" || STATUS=000
	BODY="$(cat "$TMP/body")"
}

SUFFIX="$(printf '%08d' $(( ($(date +%s) * 7919 + RANDOM) % 100000000 )))"
PHONE="013$SUFFIX"

echo "Admission e2e: $BASE_URL (open batch $OPEN_BATCH, closed batch $CLOSED_BATCH)"

TOKEN_JSON="$(curl -sS -m 20 "$API/forms/admission-token")"
KEY="$(printf '%s' "$TOKEN_JSON" | json idempotency_key)"
[ -n "$KEY" ] && ok "token issued" || fail "token issued"

PROOF="$(pp_proof "$PHONE")"
[ -n "$PROOF" ] && ok "phone verified (OTP from fake outbox -> phone_proof)" || fail "phone verified"

apply "" "$OPEN_BATCH" "$PHONE" "$PROOF"
check "missing Idempotency-Key -> 400" "$STATUS" "400"

apply "$KEY" "$CLOSED_BATCH" "$PHONE" "$PROOF"
check "closed batch -> 422" "$STATUS" "422"

apply "$KEY" "$OPEN_BATCH" "$PHONE"
check "no phone_proof -> 422" "$STATUS" "422"
check "422 names phone_proof" "$(printf '%s' "$BODY" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("details",{}).get("phone_proof",""))')" "Verify your phone number first."

OTHER_PHONE="014$(printf '%08d' $(( ($(date +%s) * 104729 + RANDOM) % 100000000 )))"
OTHER_PROOF="$(pp_proof "$OTHER_PHONE")"
apply "$KEY" "$OPEN_BATCH" "$PHONE" "$OTHER_PROOF"
check "another phone's proof -> 422" "$STATUS" "422"

apply "$KEY" "$OPEN_BATCH" "$PHONE" "${PROOF}x"
check "tampered proof -> 422" "$STATUS" "422"

apply "$KEY" "$OPEN_BATCH" "$PHONE" "$PROOF"
check "valid application -> 201" "$STATUS" "201"
REF="$(printf '%s' "$BODY" | json ref)"
REDIRECT="$(printf '%s' "$BODY" | json redirect_url)"
[ -n "$REF" ] && [ -n "$REDIRECT" ] && ok "ref and redirect_url returned" || fail "ref and redirect_url returned: $BODY"

apply "$KEY" "$OPEN_BATCH" "$PHONE"
check "replay same key (no proof needed) -> 200" "$STATUS" "200"
check "replay returns same ref" "$(printf '%s' "$BODY" | json ref)" "$REF"

apply "$KEY" "$OPEN_BATCH" "$OTHER_PHONE"
check "replay with a different student phone -> 409" "$STATUS" "409"
check "409 is idempotency_conflict and hides the first ref" "$(printf '%s' "$BODY" | json code)|$(printf '%s' "$BODY" | json ref)" "idempotency_conflict|"

NEW_KEY="$(curl -sS -m 20 "$API/forms/admission-token" | json idempotency_key)"
apply "$NEW_KEY" "$OPEN_BATCH" "$PHONE" "$PROOF"
check "used proof cannot create another application -> 422" "$STATUS" "422"

PROOF2="$(pp_proof "$PHONE")"
apply "$NEW_KEY" "$OPEN_BATCH" "$PHONE" "$PROOF2"
check "duplicate student phone -> 409" "$STATUS" "409"

STATUS_BODY="$(curl -sS -m 20 "$API/applications/$REF/status")"
check "status before payment is pending" "$(printf '%s' "$STATUS_BODY" | json status)" "pending"

# Fake checkout page: find the Pay form action/fields, submit it, follow redirects to the callback.
PID="$(printf '%s' "$REDIRECT" | sed -n 's/.*[?&]pid=\([^&]*\).*/\1/p')"
CHECKOUT="$(curl -sS -m 20 "$REDIRECT")"
ACTION_URL="$(printf '%s' "$CHECKOUT" | grep -Eo 'action="[^"]+"' | head -1 | sed 's/action="//; s/"$//; s/&amp;/\&/g')"
if [ -z "$ACTION_URL" ]; then
	fail "fake checkout page has a form"
else
	ok "fake checkout page has a form"
	curl -sS -m 30 -L -o /dev/null -w '%{http_code}' -d "pid=$PID" -d "action=pay" "$ACTION_URL" > "$TMP/pay_status" || true
	check "pay + redirect chain ends 200" "$(cat "$TMP/pay_status")" "200"
fi

FINAL="$(curl -sS -m 20 "$API/applications/$REF/status")"
check "status after pay is approved" "$(printf '%s' "$FINAL" | json status)" "approved"
check "payment_status after pay is completed" "$(printf '%s' "$FINAL" | json payment_status)" "completed"

RETRY_STATUS="$(curl -sS -m 20 -o /dev/null -w '%{http_code}' -X POST "$API/applications/$REF/payment")"
check "retry payment on a paid application -> 409" "$RETRY_STATUS" "409"

HEADERS="$(curl -sS -m 20 -D - -o /dev/null "$API/applications/$REF/status")"
printf '%s' "$HEADERS" | grep -qi 'cache-control: private, no-store' && ok "status is no-store" || fail "status is no-store"

# Turnstile on the admission page: widget and script only with a site key (injected by filter inside wpcli), none on the live no-key page.
for mode in key nokey; do
	if (cd "$ROOT" && docker compose run --rm -T wpcli eval-file /tests/integration/admission-turnstile-render-test.php "$mode" >"$TMP/ts-$mode" 2>&1); then ok "admission page Turnstile rendering ($mode)"; else fail "admission page Turnstile rendering ($mode)"; grep FAIL "$TMP/ts-$mode"; fi
done
PAGE="$(curl -sS -m 20 "$BASE_URL/admissions/?batch=$OPEN_BATCH")"
printf '%s' "$PAGE" | grep -q 'id="adm-form"' && ok "admission form renders" || fail "admission form renders"
if [ -z "${TURNSTILE_SITE_KEY:-}" ]; then
	printf '%s' "$PAGE" | grep -q 'adm-turnstile' && fail "no site key: no widget on the page" || ok "no site key: no widget on the page"
fi

echo "Passed: $PASS  Failed: $FAIL"
[ "$FAIL" -eq 0 ]
