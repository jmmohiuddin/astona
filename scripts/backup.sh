#!/usr/bin/env bash
# Backup the Astona stack: database dump + private volume (CC_PRIVATE_DIR) + wp-content/uploads.
#
# WARNING: backups contain PII (names, phones, applicant photos, encrypted ID numbers, SMS logs).
# Encrypt them, restrict access, and never copy them to unmanaged locations. CC_ENC_KEY is NOT backed up
# on purpose: keep it in a separate secret store, otherwise restored ID numbers cannot be decrypted.
#
# Usage:  scripts/backup.sh   (operates on the compose project selected by COMPOSE_FILE /
#                              COMPOSE_PROJECT_NAME; default: docker-compose.yml in the repo root)
# Env:    BACKUP_DIR (default ./backups)  AGE_RECIPIENT | BACKUP_PASSPHRASE  BACKUP_ALLOW_UNENCRYPTED=1
#         RESTIC_REPOSITORY (+ RESTIC_PASSWORD, cloud creds)  RETENTION_DAILY=30  RETENTION_MONTHLY=12
set -euo pipefail
cd "$(dirname "$0")/.."

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION_DAILY="${RETENTION_DAILY:-30}"
RETENTION_MONTHLY="${RETENTION_MONTHLY:-12}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME_GLOB='[0-9]*T[0-9]*Z'

log() { printf '%s backup: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

# --- encryption mode ---------------------------------------------------------------------------------
ENC_EXT=""
if [ -n "${AGE_RECIPIENT:-}" ]; then
	command -v age >/dev/null 2>&1 || die "AGE_RECIPIENT is set but 'age' is not installed"
	ENC_MODE=age
	ENC_EXT=".age"
elif [ -n "${BACKUP_PASSPHRASE:-}" ]; then
	command -v openssl >/dev/null 2>&1 || die "openssl is required for BACKUP_PASSPHRASE encryption"
	export BACKUP_PASSPHRASE
	ENC_MODE=openssl
	ENC_EXT=".enc"
elif [ "${BACKUP_ALLOW_UNENCRYPTED:-0}" = "1" ]; then
	ENC_MODE=none
	log "WARNING: writing UNENCRYPTED backups containing PII. Do not use this outside throwaway tests."
else
	die "no encryption configured. Set AGE_RECIPIENT or BACKUP_PASSPHRASE (or BACKUP_ALLOW_UNENCRYPTED=1 for tests only)."
fi

encrypt_stream() {
	case "$ENC_MODE" in
		age) age -r "$AGE_RECIPIENT" ;;
		openssl) openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -pass env:BACKUP_PASSPHRASE ;;
		*) cat ;;
	esac
}
decrypt_stream() {
	case "$ENC_MODE" in
		openssl) openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:BACKUP_PASSPHRASE ;;
		*) cat ;;
	esac
}

sha256_file() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }
dc() { docker compose "$@"; }

# --- preflight ---------------------------------------------------------------------------------------
dc exec -T db true >/dev/null 2>&1 || die "db service is not running in this compose project"
dc exec -T wordpress true >/dev/null 2>&1 || die "wordpress service is not running in this compose project"

PRIVATE_DIR="$(dc exec -T wordpress sh -c 'printf %s "${CC_PRIVATE_DIR:-/var/www/private}"')"
[ -n "$PRIVATE_DIR" ] || die "could not determine CC_PRIVATE_DIR"

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
WORK="$BACKUP_DIR/.tmp-$STAMP"
FINAL="$BACKUP_DIR/$STAMP"
mkdir "$WORK"
trap 'rm -rf "$WORK"' EXIT

# --- 1. database ---------------------------------------------------------------------------------------
# mariadb-dump runs INSIDE the db container (matching server version). --skip-ssl: the compose-internal
# DB has no TLS, and the MariaDB 11.x client in the wp-cli image would otherwise refuse (WP-CLI TLS quirk).
log "dumping database"
dc exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --skip-ssl -uroot \
	--single-transaction --quick --routines --events --triggers --default-character-set=utf8mb4 \
	--databases "$MARIADB_DATABASE"' \
	| gzip -9 | encrypt_stream > "$WORK/db.sql.gz$ENC_EXT"

# --- 2. private volume (applicant photos) ------------------------------------------------------------
log "archiving private volume ($PRIVATE_DIR)"
dc exec -T wordpress sh -c 'cd "$(dirname "$1")" && tar -cz "$(basename "$1")"' _ "$PRIVATE_DIR" \
	| encrypt_stream > "$WORK/private.tar.gz$ENC_EXT"

# --- 3. uploads --------------------------------------------------------------------------------------
log "archiving wp-content/uploads"
dc exec -T wordpress sh -c 'cd /var/www/html/wp-content && if [ -d uploads ]; then tar -cz uploads; else tar -cz -T /dev/null; fi' \
	| encrypt_stream > "$WORK/uploads.tar.gz$ENC_EXT"

# --- sanity: the dump must end with the mariadb-dump completion marker (cannot be checked with age) ---
if [ "$ENC_MODE" != "age" ]; then
	decrypt_stream < "$WORK/db.sql.gz$ENC_EXT" | gunzip | tail -n 1 | grep -q 'Dump completed' \
		|| die "database dump looks truncated"
fi

{
	echo "created_utc=$STAMP"
	echo "encryption=$ENC_MODE"
	echo "private_dir=$PRIVATE_DIR"
	echo "contains_pii=yes"
	echo "note=CC_ENC_KEY is not part of this backup"
} > "$WORK/MANIFEST.txt"
( cd "$WORK" && sha256_file "db.sql.gz$ENC_EXT" "private.tar.gz$ENC_EXT" "uploads.tar.gz$ENC_EXT" > SHA256SUMS )

mv "$WORK" "$FINAL"
trap - EXIT
log "wrote $FINAL ($(du -sh "$FINAL" | cut -f1))"

# --- optional off-host push ------------------------------------------------------------------------
if [ -n "${RESTIC_REPOSITORY:-}" ]; then
	command -v restic >/dev/null 2>&1 || die "RESTIC_REPOSITORY is set but restic is not installed"
	log "pushing to restic repository"
	restic snapshots >/dev/null 2>&1 || restic init
	restic backup --tag astona "$FINAL"
	restic forget --tag astona --keep-daily "$RETENTION_DAILY" --keep-monthly "$RETENTION_MONTHLY" --prune
fi

# --- local retention: newest backup per day for N days + newest per month for M months -------------------
# Only directories named like 20261007T020000Z directly inside BACKUP_DIR are ever considered.
log "pruning local backups (keep $RETENTION_DAILY daily, $RETENTION_MONTHLY monthly)"
ALL="$(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name "$NAME_GLOB" -exec basename {} \; | sort -r)"
KEEP="$(printf '%s\n' "$ALL" | awk -v nd="$RETENTION_DAILY" -v nm="$RETENTION_MONTHLY" '
	NF { d = substr($0, 1, 8); m = substr($0, 1, 6); keep = 0
	     if (!(d in sd) && nd_seen < nd) { sd[d] = 1; nd_seen++; keep = 1 }
	     if (!(m in sm) && nm_seen < nm) { sm[m] = 1; nm_seen++; keep = 1 }
	     if (keep) print $0 }')"
printf '%s\n' "$ALL" | while IFS= read -r name; do
	[ -n "$name" ] || continue
	[ "$name" != "$STAMP" ] || continue # never delete the backup just written
	printf '%s\n' "$KEEP" | grep -qx "$name" && continue
	case "$name" in
		[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z)
			log "removing expired backup $name"
			rm -rf -- "${BACKUP_DIR:?}/$name" ;;
	esac
done
log "done"
