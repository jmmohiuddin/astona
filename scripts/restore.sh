#!/usr/bin/env bash
# Restore a backup made by scripts/backup.sh into the compose project selected by COMPOSE_FILE /
# COMPOSE_PROJECT_NAME (default: docker-compose.yml in the repo root).
#
# THIS OVERWRITES the target stack's database, private volume and uploads. Point it at the right stack:
#   - restore drill / new server:  use a throwaway or freshly built stack (COMPOSE_PROJECT_NAME=astona-drill)
#   - disaster recovery:           the production stack, after taking a fresh backup of what is left.
# The backup contains PII; handle the decrypted data accordingly. CC_ENC_KEY is NOT in the backup:
# the target stack must use the SAME CC_ENC_KEY as the original, or stored ID numbers cannot be decrypted.
#
# Usage:  scripts/restore.sh [--yes] [--reconcile] <backup-dir>
#   --yes         skip the interactive confirmation (required when no terminal is attached)
#   --reconcile   run the payment reconciler right after the restore
# Env:    BACKUP_PASSPHRASE (openssl backups) or AGE_IDENTITY_FILE (age backups)
#
# AFTER RESTORE (SDD): re-query payments from the last 48 hours against the gateway, because payments may
# have completed at the gateway while the restored database did not know about them:
#     docker compose run --rm wpcli eval 'CC_Reconciler::run();'
# The reconciler only covers payments still initiated/executing, and cancelled ones inside its late-payment
# window (24h placeholder in class-reconciler.php), so also compare the last 48h of payments with the
# gateway's own report and handle `reconcile_needed` rows by hand.
set -euo pipefail
cd "$(dirname "$0")/.."

log() { printf '%s restore: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }
dc() { docker compose "$@"; }
sha256_check() { if command -v sha256sum >/dev/null 2>&1; then sha256sum -c "$@"; else shasum -a 256 -c "$@"; fi; }

ASSUME_YES=0
RUN_RECONCILER=0
SRC=""
for arg in "$@"; do
	case "$arg" in
		--yes) ASSUME_YES=1 ;;
		--reconcile) RUN_RECONCILER=1 ;;
		-h|--help) sed -n '2,24p' "$0"; exit 0 ;;
		-*) die "unknown option: $arg" ;;
		*) [ -z "$SRC" ] || die "only one backup directory may be given"; SRC="$arg" ;;
	esac
done
[ -n "$SRC" ] || die "usage: scripts/restore.sh [--yes] [--reconcile] <backup-dir>"
[ -d "$SRC" ] || die "not a directory: $SRC"
[ -f "$SRC/MANIFEST.txt" ] && [ -f "$SRC/SHA256SUMS" ] || die "$SRC is not a backup made by backup.sh (MANIFEST.txt/SHA256SUMS missing)"

ENC_MODE="$(sed -n 's/^encryption=//p' "$SRC/MANIFEST.txt")"
case "$ENC_MODE" in
	age)     EXT=".age"; [ -n "${AGE_IDENTITY_FILE:-}" ] || die "age backup: set AGE_IDENTITY_FILE"; command -v age >/dev/null 2>&1 || die "age is not installed" ;;
	openssl) EXT=".enc"; [ -n "${BACKUP_PASSPHRASE:-}" ] || die "openssl backup: set BACKUP_PASSPHRASE"; export BACKUP_PASSPHRASE ;;
	none)    EXT="" ;;
	*)       die "unknown encryption mode '$ENC_MODE' in MANIFEST.txt" ;;
esac

decrypt_stream() {
	case "$ENC_MODE" in
		age) age -d -i "$AGE_IDENTITY_FILE" ;;
		openssl) openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:BACKUP_PASSPHRASE ;;
		*) cat ;;
	esac
}

log "verifying checksums"
( cd "$SRC" && sha256_check --quiet SHA256SUMS ) || die "checksum verification failed; backup is corrupt or tampered with"

dc exec -T db true >/dev/null 2>&1 || die "db service is not running in the target compose project"
dc exec -T wordpress true >/dev/null 2>&1 || die "wordpress service is not running in the target compose project"

DB_CONTAINER="$(dc ps -q db)"
PROJECT="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$DB_CONTAINER")"
PRIVATE_DIR="$(dc exec -T wordpress sh -c 'printf %s "${CC_PRIVATE_DIR:-/var/www/private}"')"

log "TARGET compose project: $PROJECT (private dir $PRIVATE_DIR)"
log "this overwrites its database, private volume and wp-content/uploads with backup $(basename "$SRC")"
if [ "$ASSUME_YES" != "1" ]; then
	[ -t 0 ] || die "no terminal attached; pass --yes to confirm non-interactively"
	printf 'Type the project name (%s) to continue: ' "$PROJECT" >&2
	read -r answer
	[ "$answer" = "$PROJECT" ] || die "confirmation did not match; nothing was changed"
fi

# Stop the scheduler so nothing writes while data is replaced; always start it again.
HAD_CRON="$(dc ps -q cron 2>/dev/null || true)"
if [ -n "$HAD_CRON" ]; then
	dc stop cron >/dev/null
	trap 'dc start cron >/dev/null 2>&1 || true' EXIT
fi

log "importing database"
decrypt_stream < "$SRC/db.sql.gz$EXT" | gunzip \
	| dc exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb --skip-ssl -uroot'

log "restoring private volume"
decrypt_stream < "$SRC/private.tar.gz$EXT" \
	| dc exec -T -u root wordpress sh -c 'd="$1"; mkdir -p "$d"; find "$d" -mindepth 1 -delete; tar -xz -C "$d" --strip-components=1; chown -R 33:33 "$d"; chmod 750 "$d"' _ "$PRIVATE_DIR"

log "restoring uploads"
decrypt_stream < "$SRC/uploads.tar.gz$EXT" \
	| dc exec -T -u root wordpress sh -c 'cd /var/www/html/wp-content && mkdir -p uploads && find uploads -mindepth 1 -delete; tar -xz; chown -R 33:33 uploads'

log "flushing caches (best effort)"
dc run --rm -T wpcli cache flush >/dev/null 2>&1 || log "cache flush skipped (wpcli not ready or site not installed)"

if [ "$RUN_RECONCILER" = "1" ]; then
	log "running payment reconciler"
	dc run --rm -T wpcli eval 'CC_Reconciler::run();'
fi

log "restore complete"
cat >&2 <<'MSG'

NEXT STEPS (do not skip):
  1. Re-query recent payments (last 48h) against the gateway:
       docker compose run --rm wpcli eval 'CC_Reconciler::run();'
     then review payments in status reconcile_needed by hand.
  2. Make sure the stack uses the original CC_ENC_KEY (otherwise ID numbers cannot be decrypted).
  3. Check the scheduler: docker compose logs --tail 20 cron  (it is restarted automatically).
  4. Smoke-test: home page, /wp-json/cc/v1/courses, student login, an admin login.
MSG
