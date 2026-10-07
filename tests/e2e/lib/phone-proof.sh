# shellcheck shell=bash
# Source this from a dev-stack e2e script to obtain a phone_proof for POST /cc/v1/applications.
# Needs: API (e.g. http://localhost:8080/wp-json/cc/v1), curl, python3, docker compose. Optional: ROOT (repo root).
# The OTP is read from the fake SMS outbox through the wpcli container (local dev only).
PP_ROOT="${ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"

# pp_e164 01712-345678 -> +8801712345678
pp_e164() {
	local d="${1//[^0-9]/}"
	case "$d" in
		880*) printf '+%s' "$d" ;;
		0*) printf '+88%s' "$d" ;;
		*) printf '+880%s' "$d" ;;
	esac
}

# pp_code PHONE -> the 6 digit code of the newest verification SMS (empty if none)
pp_code() {
	(cd "$PP_ROOT" && docker compose run --rm -T wpcli eval-file /tests/e2e/lib/read-apply-code.php "$(pp_e164 "$1")" 2>/dev/null) | sed -n 's/^CODE:\([0-9]\{6\}\).*/\1/p' | tail -1
}

# pp_request PHONE -> prints the HTTP status of the request-code call
pp_request() {
	curl -sS -m 30 -o /dev/null -w '%{http_code}' -X POST "$API/applications/verify-phone/request" \
		-H 'Content-Type: application/json' -d "{\"phone\":\"$1\"}"
}

# pp_confirm PHONE CODE -> prints the response body (JSON)
pp_confirm() {
	curl -sS -m 30 -X POST "$API/applications/verify-phone/confirm" \
		-H 'Content-Type: application/json' -d "{\"phone\":\"$1\",\"code\":\"$2\"}"
}

# pp_proof PHONE -> prints a fresh phone_proof (empty on failure)
pp_proof() {
	local status code
	status="$(pp_request "$1")"
	[ "$status" = "200" ] || return 1
	code="$(pp_code "$1")"
	[ -n "$code" ] || return 1
	pp_confirm "$1" "$code" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("phone_proof",""))' 2>/dev/null
}
