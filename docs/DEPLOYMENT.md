# Production deployment runbook

Single host: Cloudflare -> TLS reverse proxy (nginx or Caddy) -> `docker-compose.prod.yml` (Apache + PHP 8.3 in the
`wordpress` container, MariaDB 10.11, a `cron` runner). Redis is optional and not wired in yet.
Dev stack and tests are described in `README.md`; this file is production only.

Status of this runbook: written, with the Compose file, Apache/PHP config and backup/restore scripts exercised locally
(see "Verified vs not verified" at the end). Nothing here has been run against a real server.

## 1. Server and accounts

| Item | Value |
|---|---|
| Size (SDD) | about 4 vCPU / 8 GB RAM, 80 GB SSD, Ubuntu LTS. Docker Engine 25+ with Compose v2.24+ (`!override` is used). |
| DNS / CDN | Cloudflare proxied (orange cloud), SSL/TLS mode **Full (strict)** with a Cloudflare Origin CA certificate (or Let's Encrypt) on the origin. |
| Firewall | Only 22 (admin IPs), 80 and 443 (Cloudflare ranges only, or enable Authenticated Origin Pulls). The compose stack publishes only `127.0.0.1:8080`; the database is never published. |
| Secrets | `.env` on the server (mode 600), a copy of `CC_ENC_KEY` in a password manager. Nothing secret in git. |
| Off-host backup | An R2 or S3 bucket plus restic, or equivalent. |

## 2. Layout and releases (symlink releases)

```
/srv/astona/
  shared/.env              the real env file (chmod 600), never in a release
  releases/20261007-1530/  a checkout of this repo (git archive / rsync), including docker/, wp-content/, scripts/
  current -> releases/20261007-1530
```

Every release links `.env` to the shared one: `ln -s /srv/astona/shared/.env /srv/astona/releases/<id>/.env`.
Always run compose from `/srv/astona/current` with:

```bash
cd /srv/astona/current
export COMPOSE_FILE=docker-compose.prod.yml
export COMPOSE_PROJECT_NAME=astona     # also put this in .env so cron/backups use the same project
```

Docker resolves the symlink when a container is created, so after switching `current` you must recreate the
containers that mount code: `docker compose up -d --force-recreate wordpress cron`.

Code is mounted read-only from the release. The WordPress core, Action Scheduler plugin and uploads live in the
`wp_data` volume; applicant photos in `private_data` (mounted at `CC_PRIVATE_DIR=/var/www/private`).

## 3. First deploy

1. Create the env file and fill every `CHANGE_ME` value (see `.env.example` for what each variable does):
   ```bash
   cp .env.example /srv/astona/shared/.env && chmod 600 /srv/astona/shared/.env
   openssl rand -base64 32            # -> CC_ENC_KEY  (store a copy in the password manager NOW)
   openssl rand -base64 24 | tr -d '/+=\n'   # -> DB passwords
   ```
   Required in production: `WORDPRESS_DB_*`, `MARIADB_ROOT_PASSWORD`, `CC_ENC_KEY`, `TURNSTILE_SITE_KEY`,
   `TURNSTILE_SECRET`, `CC_SMS_PRIMARY` (`bulksmsbd` or `greenweb`, plus that provider's credentials) and
   `CC_PAYMENT_GATEWAY=bkash` with `BKASH_MODE` and the four `BKASH_*` credentials. Do not set `CC_RATE_LIMIT_DISABLED`
   or `CC_STAFF_SECURITY_RELAXED`, and never `fake` for payments or SMS.
2. Remove the fake drivers from the release you deploy (README: "Adding another payment driver" step 8). They are
   already refused outside local/development, but do not ship them. Install the PHP dependency for PDF receipts in the
   release build: `composer install --no-dev --no-interaction` in `wp-content/plugins/coaching-platform` (installs mPDF;
   `vendor/` is not in Git, and without it receipts fall back to HTML).
3. Validate and start:
   ```bash
   docker compose config -q
   docker compose up -d
   docker compose ps          # db healthy, wordpress healthy; cron becomes healthy after install
   ```
4. Install WordPress **without seed content** (do not run `scripts/setup.sh`: it seeds sample content, creates an
   `admin` user and sets the dev site URL). The constants in `WORDPRESS_CONFIG_EXTRA` are evaluated by
   `wp-config.php` at runtime, so a change to them applies after `docker compose up -d` recreates the container.
   ```bash
   docker compose exec -T -u root wordpress sh -c 'mkdir -p /var/www/private && chown 33:33 /var/www/private && chmod 750 /var/www/private'
   docker compose run --rm wpcli core install --url="https://www.example.com" --title="Astona" \
     --admin_user="<owner-login-not-admin>" --admin_email="<owner@example.com>" --prompt=admin_password
   docker compose run --rm wpcli theme activate coaching-theme
   docker compose run --rm wpcli plugin activate coaching-platform
   docker compose run --rm wpcli plugin install action-scheduler --activate
   docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
   ```
   `DISALLOW_FILE_MODS` blocks installs from wp-admin; WP-CLI as shown is the intended path (checked locally:
   `plugin install action-scheduler` works with the constant set).
5. Create the owner account with a non-`admin` login. The command above uses `--admin_user`; the plugin roles are
   `cc_owner`, `cc_staff`, `cc_instructor`. Give staff their roles individually:
   ```bash
   docker compose run --rm wpcli user create <login> <email> --role=cc_owner --prompt=user_pass
   docker compose run --rm wpcli user list --fields=ID,user_login,roles
   ```
   If core install created a WordPress `administrator`, keep it only as break-glass or demote it.
6. `wp cc` commands. Only `wp cc seed` exists (demo content: **do not run in production**). There is no
   `wp cc migrate`: schema migrations run automatically when `cc_db_version` differs from the plugin's version
   (`CC_Migrations::maybe_upgrade()`). To force or check it:
   ```bash
   docker compose run --rm wpcli eval 'echo get_option("cc_db_version"), "\n";'
   docker compose run --rm wpcli eval 'CC_Migrations::run();'
   ```
   Migrations must be expand-only (add tables/columns/indexes; never drop or rename in the same release as the code
   that stops using them), so the previous release keeps working against the new schema. That is what makes the
   rollback in section 8 safe.
7. Two-factor sign-in is built in (no plugin): staff sign in at `https://<site>/admin/login/` (`/wp-login.php` answers
   404). The first time, each owner/staff/administrator is sent to **Astona > Security** to enrol an authenticator app
   and save their recovery codes. Do this for every account before go-live. A locked-out colleague is reset with
   `docker compose run --rm wpcli cc 2fa-reset <login>`. Add a Cloudflare rate-limit rule on `POST /admin/login*` (for
   example 5 per minute per IP) as a second layer; the plugin already limits code attempts. Student logins are throttled
   by the plugin itself. Staff are signed out after 60 idle minutes and must re-enter their password for CSV exports.
   Optional: after counsel confirms the periods (README "Data retention"), tick "Delete old personal data" in
   **Astona > Settings**; until then the daily job only reports what it would delete.
8. Configure the reverse proxy (section 4), Cloudflare (section 5), then run the smoke checks (section 9).
9. Enable the backup schedule (section 7) and take the first backup immediately.

## 4. Reverse proxy

Terminate TLS on the host and forward to `127.0.0.1:8080`. The `wordpress` image trusts `X-Forwarded-For` only from
private ranges (Docker bridge), which is the proxy. Rate limiting uses `REMOTE_ADDR`, so the proxy must **overwrite**
`X-Forwarded-For` with the real client address, otherwise every visitor shares one IP bucket (or a client can forge
one). With Cloudflare in front, that address is `CF-Connecting-IP`; this is only trustworthy when port 443 accepts
Cloudflare traffic only.

nginx example (`/etc/nginx/sites-available/astona`):

```nginx
server {
    listen 443 ssl http2;
    server_name www.example.com;
    ssl_certificate     /etc/ssl/astona/origin.pem;      # Cloudflare Origin CA cert
    ssl_certificate_key /etc/ssl/astona/origin.key;
    client_max_body_size 16m;                              # matches post_max_size in docker/php/prod.ini

    # Security headers (the Apache image has mod_headers disabled; set them here).
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;   # add "preload" only once sure
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    add_header Content-Security-Policy-Report-Only "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self'; report-uri https://<your-report-collector>/csp" always;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $http_cf_connecting_ip;   # overwrite, never append
        proxy_read_timeout 60s;
    }
}
server { listen 80; server_name www.example.com; return 301 https://$host$request_uri; }
```

Caddy equivalent: `reverse_proxy 127.0.0.1:8080 { header_up X-Forwarded-For {http.request.header.CF-Connecting-IP} }`
plus a `header { Strict-Transport-Security "max-age=31536000; includeSubDomains" ... }` block.

CSP guidance: ship `Content-Security-Policy-Report-Only` first (as above, adjust to what the theme loads), watch the
reports for a week or two, then switch the header name to `Content-Security-Policy`. Start HSTS with a short
`max-age` (for example 300) on the first day, then raise it.

## 5. Cloudflare

- SSL/TLS: Full (strict), Always Use HTTPS on, minimum TLS 1.2.
- **Cache bypass** (Cache Rules, action "Bypass cache"), matched on URI path, for anything personal or stateful:
  `/student/*`, `/wp-admin*`, `/wp-login.php`, `/admin*` (includes `/admin/login/`), `/wp-json/cc/v1/me*`, `/wp-json/cc/v1/live-classes/*`,
  `/wp-json/cc/v1/auth/*`, `/wp-json/cc/v1/applications*`, `/wp-json/cc/v1/forms/*`, `/wp-json/cc/v1/payments/*`,
  and any request with a `wordpress_logged_in_*` cookie.
- The app already sends `Cache-Control: private, no-store` on those routes (portal pages, auth, admission, live-class
  join, `/me/*`). Never enable "Cache Everything" without the bypass rules above. `GET /wp-json/cc/v1/courses` is
  public with `max-age=60` and may be cached.
- Rate limiting rules: `/admin/login*` (POST) and `/wp-json/cc/v1/auth/*` (defence in depth next to the plugin limiter).
- Turnstile: create a widget for the domain, put the keys in `.env` (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET`). Set both TOGETHER: the contact and admission forms render the widget only when the site key is set, while the server enforces the captcha as soon as the secret is set. A secret without a site key locks every applicant out of the admission form.
- Blocking `xmlrpc.php`, `readme.html`, `license.txt` is already done in `docker/apache/security.conf`.

## 6. Cron runner

WP-Cron is disabled in production (`DISABLE_WP_CRON`). The `cron` service in `docker-compose.prod.yml` runs
`wp action-scheduler run` and `wp cron event run --due-now` every 60 seconds with `restart: unless-stopped`. This
drives the payment reconciler, student provisioning and SMS sending. Its healthcheck is healthy while the last full
iteration is under 3 minutes old.

```bash
docker compose ps cron
docker compose logs --tail 30 cron
```

If cron is unhealthy, payments and SMS stall: treat it as an incident.

## 7. Backups and restore drills

Backups contain PII (names, phones, applicant photos, SMS logs). They must be encrypted and access-restricted.
`CC_ENC_KEY` is deliberately not in the backup; without it, restored ID numbers are unreadable.

Nightly schedule (host crontab, as the deploy user; set the env in the crontab or a root-only file):

```cron
15 2 * * *  cd /srv/astona/current && COMPOSE_FILE=docker-compose.prod.yml COMPOSE_PROJECT_NAME=astona BACKUP_DIR=/var/backups/astona AGE_RECIPIENT=age1xxxx RESTIC_REPOSITORY=s3:... RESTIC_PASSWORD_FILE=/root/.restic-pw AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... ./scripts/backup.sh >>/var/log/astona-backup.log 2>&1
```

`scripts/backup.sh`: `mariadb-dump --single-transaction --skip-ssl` inside the db container, tar of the private volume
and `wp-content/uploads`, age or openssl encryption (it refuses to run unencrypted), optional restic push with
`forget --keep-daily 30 --keep-monthly 12 --prune`, and local pruning of 30 daily + 12 monthly. Alert if
`/var/log/astona-backup.log` has no success line in 26 hours.

Quarterly restore drill checklist (record date, operator, result):

- [ ] Fetch the latest backup from the off-host store (restic restore) to a scratch directory on a **separate** host or
      a throwaway compose project (`COMPOSE_PROJECT_NAME=astona-drill`, a different `WEB_PORT`).
- [ ] Bring up `db` and `wordpress` for that project with the same `CC_ENC_KEY` from the password manager.
- [ ] `./scripts/restore.sh --yes --reconcile <backup-dir>` (add `AGE_IDENTITY_FILE` or `BACKUP_PASSPHRASE`).
- [ ] Row counts match production (applications, payments, students); an applicant photo opens; one ID number decrypts
      (`CC_Crypto`) in a controlled test.
- [ ] Re-query the last 48 hours of payments: `docker compose run --rm wpcli eval 'CC_Reconciler::run();'`, then
      compare with the gateway report and handle `reconcile_needed` rows.
- [ ] Time the drill; target recovery time agreed with the owner (write it here: `[TBD]`).
- [ ] Tear down the drill stack and delete the decrypted data.

## 8. Upgrade and rollback

Deploy a new release:

```bash
cd /srv/astona
id=$(date +%Y%m%d-%H%M)
mkdir releases/$id && git -C <repo> archive <tag-or-sha> | tar -x -C releases/$id   # or rsync
ln -s /srv/astona/shared/.env releases/$id/.env
./current/scripts/backup.sh                         # fresh backup first (with the env from section 7)
cd releases/$id && COMPOSE_FILE=docker-compose.prod.yml docker compose config -q && cd /srv/astona
ln -sfn releases/$id current
cd current && docker compose up -d --force-recreate wordpress cron
docker compose run --rm wpcli eval 'echo get_option("cc_db_version"), "\n";'    # migrations applied on first request
```

Image updates: bump the tags in `docker-compose.prod.yml` deliberately (`wordpress:6-php8.3-apache` follows 6.x
minors; pin an exact version such as `wordpress:6.8.2-php8.3-apache` if you want zero surprises), run
`docker compose pull && docker compose up -d`. Core and plugin updates are done with WP-CLI because
`DISALLOW_FILE_MODS` is on: `docker compose run --rm wpcli core update` / `plugin update action-scheduler`.

**Rollback** (code only, safe because migrations are expand-only):

```bash
cd /srv/astona
ln -sfn releases/<previous-id> current
cd current && docker compose up -d --force-recreate wordpress cron
```

If a release made a destructive data change (it should not), restore the pre-deploy backup with
`scripts/restore.sh` instead; that loses data written since the backup, so decide with the owner.

## 9. Smoke checks and monitoring

```bash
curl -sI https://www.example.com/ | head -5                     # 200, HSTS, no X-Powered-By, "Server: Apache" only
curl -s  https://www.example.com/wp-json/cc/v1/courses | head -c 200
curl -sI https://www.example.com/readme.html | head -1         # 403
curl -sI https://www.example.com/xmlrpc.php | head -1          # 403
curl -sI https://www.example.com/student/ | grep -i cache-control   # private, no-store
```

Uptime monitors (UptimeRobot or similar, 1 minute, alert to phone): `/`, `/wp-json/cc/v1/courses`, `/student/login/`,
`/admissions/`, and `/wp-json/cc/v1/health`. The health endpoint answers 200 `{"ok":true,...}` or 503 when the database is
unreachable, the reconciler has not run for 5 minutes (cron stopped), a payment has been initiated for over 10 minutes, or
any payment is in `reconcile_needed`. It returns booleans only. Also watch: cron container health, disk space (`/var/lib/docker`, backups), backup log age, TLS
certificate expiry, and `docker compose logs wordpress | grep -i "cc provisioning failed\|fatal"`.
Error tracking: Sentry is **not integrated**; if wanted, add the SDK/plugin and put the DSN in `.env` as
`SENTRY_DSN` (placeholder only; nothing reads it today).

Real client IPs: `docker compose logs --tail 5 wordpress` must show visitor addresses, not `172.x` (proxy
header check from section 4; not verified here).

## 10. Go-live checklist

Consolidated from the README pre-production lists.

Security and secrets
- [ ] `.env` is on the server only, mode 600, not in git; `git log -- .env` is empty.
- [ ] `CC_ENC_KEY` generated (32 random bytes, base64) and a copy is in the password manager, apart from backups.
- [ ] `CC_RATE_LIMIT_DISABLED` is not set; no `fake` payment or SMS driver anywhere; `WP_ENVIRONMENT_TYPE=production`.
- [ ] Owner login is not `admin`; strong unique passwords; every staff account enrolled in 2FA (Astona > Security); `/admin/login` throttled.
- [ ] HSTS and CSP (report-only first) set at the proxy; Cloudflare Full (strict); origin accepts Cloudflare only.
- [ ] `display_errors` off (prod.ini), `readme.html`, `license.txt`, `xmlrpc.php` denied (section 9 checks).
- [ ] Turnstile site key AND secret both set; contact form works and the admission form shows the security check, `Verify number` enables once it completes, and an application can be submitted.
- [ ] Real client IP reaches rate limiting (X-Forwarded-For overwritten at the proxy).
- [ ] Real client IP configured at the proxy/Apache layer (`mod_remoteip` with the Cloudflare ranges) BEFORE relying on per-IP limits: the limiter reads `REMOTE_ADDR` only, so behind a proxy all clients share one bucket and the admission code limits can lock everyone out.

Payments, SMS, data
- [ ] Real payment driver (bKash) implemented, `CC_PAYMENT_GATEWAY` set, fake gateway removed from the build.
- [ ] Reconciler late-payment window (`LATE_PAYMENT_WINDOW`, 24h placeholder) checked against the gateway's session expiry.
- [ ] Real SMS driver, `CC_SMS_PRIMARY` (and optional fallback), sender ID and Bangla/Unicode rules confirmed.
- [ ] Someone is named to resolve `reconcile_needed` payments (no admin review desk yet).
- [ ] Known risk L4 (phone ownership not proven at application) accepted by the owner or fixed.
- [ ] Object cache (Redis) decision made for the rate limiter; otherwise accept extra `wp_options` writes.

Operations
- [ ] Cron runner healthy; a test action completes within 70 seconds.
- [ ] First backup taken, encrypted, pushed off-host; first restore drill scheduled (quarterly).
- [ ] Uptime monitors and alert routing configured; emergency contacts below filled in.
- [ ] Sample content replaced (contacts, Privacy Policy and Terms legal-reviewed, courses, faculty, About, FAQ, brand).
- [ ] Rollback rehearsed once on staging (symlink switch plus recreate).

## 11. Emergency contacts

> **No emergency contacts, owners or escalation order have been provided yet. Every cell below is a placeholder.**
> Fill this table in before go-live (checklist in section 10) and keep it current. Do not put passwords, API keys or
> tokens here. Only names and ways to reach people. Fill in the escalation order (1 = call first) so that whoever is on
> duty at 2 a.m. knows exactly who to call next.

| Role | Name | Phone | Email | Availability (days / hours / time zone) | Escalation order |
|---|---|---|---|---|---|
| Site owner / approver | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |
| On-call engineer | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |
| Backup engineer | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |
| Hosting / Cloudflare account owner | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |
| Payment gateway support (bKash) | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |
| SMS gateway support | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |
| Data-protection / privacy contact | [TBD] | [TBD] | [TBD] | [TBD] | [TBD] |

## Verified vs not verified (at time of writing)

Verified locally: `docker compose -f docker-compose.prod.yml config` and the merged dev+prod config render with dummy env
values and contain no dev switches; the Apache and PHP overrides (403 on `readme.html`, `license.txt`, `xmlrpc.php`,
dotfiles; `Server: Apache`; `display_errors`/`expose_php` off; 12M/16M); `backup.sh` and `restore.sh` round trip into a
throwaway project; the dev `cron` service processes queued SMS.
Also verified locally: the prod stack boots as a throwaway project (wordpress and cron healthy, constants
`WP_ENVIRONMENT_TYPE=production`, `DISALLOW_FILE_MODS`, `FORCE_SSL_ADMIN`, `DISABLE_WP_CRON` active, dev switches
absent, WP-CLI install and plugin activation work).
Not verified: `FORCE_SSL_ADMIN` behind a real proxy, the nginx/Caddy snippets, Cloudflare rules, restic/age paths, and the
GitHub Actions workflow on GitHub itself.
