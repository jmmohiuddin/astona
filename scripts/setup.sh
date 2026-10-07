#!/usr/bin/env bash
# One-shot local setup: start containers, install WordPress, activate theme/plugin, seed Astona sample content.
set -euo pipefail
cd "$(dirname "$0")/.."

SITE_URL="${SITE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(openssl rand -base64 12)}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@astona.example}"

wp() { docker compose run --rm -T wpcli "$@"; }

WAIT_ATTEMPTS=40   # x WAIT_INTERVAL seconds
WAIT_INTERVAL=3

# `up --wait` blocks until the db healthcheck passes and wordpress is running.
docker compose up -d --wait

# Named volumes are created root-owned; the private upload dir must be writable by www-data (uid 33).
docker compose exec -T -u root wordpress sh -c 'mkdir -p /var/www/private && chown 33:33 /var/www/private && chmod 750 /var/www/private'

# Note: do not probe with `wp eval` — WP-CLI refuses to run eval on a site that
# is not installed yet, so such a loop never terminates on a fresh volume.
echo "Waiting for WordPress files (wp-config.php) and database connection..."
attempt=0
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php \
	&& wp db check --quiet >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	if [ "$attempt" -ge "$WAIT_ATTEMPTS" ]; then
		echo "Timed out waiting for WordPress/DB. Last error:" >&2
		wp db check >&2 || true
		exit 1
	fi
	sleep "$WAIT_INTERVAL"
done

if ! wp core is-installed >/dev/null 2>&1; then
	wp core install --url="$SITE_URL" --title="Astona" \
		--admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" --admin_email="$ADMIN_EMAIL" --skip-email
	echo "Admin login: $ADMIN_USER / $ADMIN_PASSWORD  (save this — it is not stored)"
fi

wp theme activate coaching-theme
wp plugin activate coaching-platform
wp plugin is-installed action-scheduler || wp plugin install action-scheduler
wp plugin activate action-scheduler
wp rewrite structure '/%postname%/' --hard
wp cc seed
wp option get cc_seat_baseline >/dev/null 2>&1 || wp cc recount-seats --capture-baseline; wp cc recount-seats

echo "Done. Open $SITE_URL"
