# Solarnet ISP Billing — Production Deployment Runbook

Target: **Hetzner Cloud CX22** (€4.51/mo, 2 vCPU / 4 GB RAM / 40 GB SSD).
Works identically on any Ubuntu 22.04+ VPS (DigitalOcean, Contabo, AWS Lightsail, self-hosted).

Stack: Caddy (auto-HTTPS) → React SPA + Laravel API → PostgreSQL 16 + Redis 7 + queue worker + scheduler.

---

## 1. Prepare the server (5 min)

1. **Order a VPS**
   - Hetzner Cloud → Add Server → **Ubuntu 22.04**, type **CX22**, choose a region close to your MikroTik router.
   - Add your SSH key. Copy the assigned **public IPv4** — this is what you whitelist on MikroTik.

2. **Point a domain (or subdomain) at the VPS**
   - In your DNS provider: `billing.yourdomain.com  A  <VPS_IP>`
   - (You can also use a free `nip.io` / `sslip.io` name to test without a real domain, e.g. `1-2-3-4.nip.io`.)

3. **SSH in and install Docker**
   ```bash
   ssh root@<VPS_IP>
   apt update && apt -y upgrade
   curl -fsSL https://get.docker.com | sh
   apt -y install git ufw
   ufw allow 22 && ufw allow 80 && ufw allow 443 && ufw --force enable
   ```

## 2. Get the code onto the server (2 min)

Push your Emergent codebase to GitHub first (use "Save to GitHub" button in Emergent chat input).

```bash
cd /opt
git clone https://github.com/<your-org>/solarnet-billing.git
cd solarnet-billing/deploy
cp .env.production.example .env
```

## 3. Fill in secrets (2 min)

Edit `.env` and set:

| Variable | How to get it |
|---|---|
| `CUSTOMER_DOMAIN` | Customer portal hostname, e.g. `solarnetportal.com` |
| `ADMIN_DOMAIN` | Staff billing hostname, e.g. `billing.solarnetportal.com` |
| `ATTENDANCE_DOMAIN` | Dedicated attendance PWA hostname, e.g. `attendance.solarnetportal.com` |
| `LEGACY_ADMIN_DOMAIN` | Existing billing hostname to redirect, e.g. `billing.solarnetconnection.com` |
| `ACME_EMAIL` | Your email (for Let's Encrypt) |
| `DB_PASSWORD` | `openssl rand -base64 32` |
| `JWT_SECRET` | `openssl rand -base64 48` |
| `APP_KEY` | Leave empty; we'll generate it below |

Generate the Laravel `APP_KEY`:
```bash
docker compose -f docker-compose.prod.yml --env-file .env run --rm backend \
  php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```
Paste the output into `.env` as `APP_KEY=base64:...`.

## Domain cutover (existing installation)

Before deploying the multi-domain configuration, point these DNS records to
the same VPS IPv4 address and wait for public DNS to resolve them:

```text
solarnetportal.com             A   <VPS_IPV4>
billing.solarnetportal.com     A   <VPS_IPV4>
attendance.solarnetportal.com  A   <VPS_IPV4>
billing.solarnetconnection.com A   <VPS_IPV4>   # keep during the redirect
```

Do not leave a conflicting `AAAA` or `CNAME` record. If a proxy such as
Cloudflare is in use, keep these records DNS-only while Caddy obtains its
certificates. Then add the three `*_DOMAIN` values above to `deploy/.env`.
The legacy hostname permanently redirects to `ADMIN_DOMAIN`; no customer,
billing, router, or credential data is changed by the cutover.

## 4. Deploy (5 min first time)

```bash
chmod +x deploy.sh
./deploy.sh
```

For a domain-only cutover of an existing production system, use this instead
so no migration or seeder writes are attempted:

```bash
RUN_MIGRATIONS=0 RUN_SEEDERS=0 ./deploy.sh
```

The script will:
- Build one frontend for both hostnames. It calls `/api` on the domain the user opened, so customer and staff sessions stay same-origin.
- Build the Laravel PHP image
- Start Postgres + Redis and wait for them to be healthy
- Run migrations + seeders
- Cache Laravel config/routes/views (production optimisation)
- Start Caddy which will automatically request a TLS certificate

**First TLS certificates take ~30 seconds after DNS is correct.** Then browse to the customer and staff domains.

## 5. Create the first administrator securely

Before the first deployment, set these values in `deploy/.env` (do not commit that file):

```env
ADMIN_EMAIL=your-real-admin@example.com
ADMIN_PASSWORD=use-a-unique-password-of-at-least-12-characters
ADMIN_NAME=Your Name
ADMIN_PHONE=YourContactNumber
```

The deployment seeds this account only when both `ADMIN_EMAIL` and `ADMIN_PASSWORD` are supplied. SolarNet does not ship a shared default administrator account or password.

## Optional customer browser notifications

SolarNet can send account-specific push notifications for 7/3/1-day billing reminders, due day, overdue/grace/suspension warnings, suspension, verified payment, and service restoration. This is **not** sent from MikroTik or inferred from a DHCP lease. A customer must first sign in to the portal on their own device and choose **Enable billing alerts**. Tapping an alert opens an internal customer portal route, where the server still verifies the portal session owns the account.

Each enabled browser is stored as a separate active subscription. Delivery attempts are kept in the customer notification audit log and are deduplicated per customer, event, billing item, scheduled date, and browser subscription. An expired provider subscription is revoked rather than reused. Browser Web Push can record provider acceptance and a portal click, but cannot prove that the operating system visibly displayed an alert.

Install the server-side Web Push library once, from the production `deploy` directory:

```bash
docker compose -f docker-compose.prod.yml --env-file .env run --rm --no-deps backend \
  composer require minishlink/web-push --no-interaction
```

Generate a stable VAPID key pair once. Keep the private key only in `deploy/.env`; do not commit or share it:

```bash
docker compose -f docker-compose.prod.yml --env-file .env run --rm --no-deps backend \
  php -r 'require "vendor/autoload.php"; var_export(Minishlink\WebPush\VAPID::createVapidKeys()); echo PHP_EOL;'
```

Add the generated values to `deploy/.env`:

```env
WEB_PUSH_ENABLED=true
WEB_PUSH_VAPID_SUBJECT=mailto:your-support-email@example.com
WEB_PUSH_VAPID_PUBLIC_KEY=generated-public-key
WEB_PUSH_VAPID_PRIVATE_KEY=generated-private-key
```

Restart through the regular deployment command so the backend, queue worker, and scheduler load the new configuration. A server-side test that does not modify any router is available after a customer has enabled alerts. First list the actual account numbers; do not type the literal placeholder `CUSTOMER_ACCOUNT_NUMBER`:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T backend \
  php artisan tinker --execute='dump(App\Models\Customer::orderBy("full_name")->get(["account_number","full_name"])->toArray());'
```

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T backend \
  php artisan web-push:test ACTUAL_ACCOUNT_NUMBER
```

You can also run `php artisan web-push:test` with no account argument to list available account numbers. For `sent`, the selected customer must have already signed in on that device and enabled alerts. `skipped_no_subscription` means no active device subscription exists; `skipped_not_configured` means the VAPID package or environment values are missing.

## Optional Semaphore transactional SMS

SolarNet uses Semaphore for explicit transactional SMS only. The daily unpaid-invoice reminder remains **Web Push only**; configuring Semaphore does not create recurring SMS messages.

Create an API key and optionally register a Sender Name in Semaphore, then add these server-side values to `deploy/.env` (never commit or place the key in the frontend):

```env
SMS_DRIVER=semaphore
SEMAPHORE_API_KEY='your-semaphore-api-key'
# Optional: omit this until the custom Sender Name is active in Semaphore.
SEMAPHORE_SENDER_NAME=SolarNet
SEMAPHORE_BASE_URL=https://api.semaphore.co/api/v4
```

Semaphore supports Philippine numbers and uses the account's default Sender Name when `SEMAPHORE_SENDER_NAME` is omitted. Restart through the normal deployment command, then send one explicit test to a real mobile number:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T backend \
  php artisan sms:semaphore-test 09171234567
```

The command reports provider acceptance only; it does not alter billing, customer records, or MikroTik.

To verify the API token and remaining SMS units without sending a message, use:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T backend \
  php artisan sms:semaphore-status
```

## 6. Point MikroTik at the new server

Your new **fixed** server IP is what you got from Hetzner in step 1.
On the MikroTik:

```mikrotik
/ip service set api address=<VPS_IP>/32 port=8728
/ip firewall filter add chain=input protocol=tcp dst-port=8728 \
  src-address=<VPS_IP> action=accept place-before=0 \
  comment="Solarnet billing API"
```

In the app **Routers → Add**, enter **your MikroTik's public IP** as Host (not the VPS IP).

---

## Updating the app later

```bash
cd /opt/solarnet-billing
git pull
cd deploy
./deploy.sh
```

## Backups

Automated daily Postgres dump to `/root/backups`:
```bash
cat >/etc/cron.daily/solarnet-backup <<'EOF'
#!/usr/bin/env bash
mkdir -p /root/backups
cd /opt/solarnet-billing/deploy
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U "$(grep ^DB_USERNAME .env|cut -d= -f2)" \
          "$(grep ^DB_DATABASE .env|cut -d= -f2)" \
  | gzip > /root/backups/isp_$(date +\%F).sql.gz
find /root/backups -name 'isp_*.sql.gz' -mtime +14 -delete
EOF
chmod +x /etc/cron.daily/solarnet-backup
```

Restore:
```bash
gunzip -c /root/backups/isp_YYYY-MM-DD.sql.gz | \
  docker compose -f docker-compose.prod.yml exec -T postgres \
    psql -U $DB_USERNAME $DB_DATABASE
```

## Common operations

| Task | Command (run in `/opt/solarnet-billing/deploy`) |
|---|---|
| Tail logs (all) | `docker compose -f docker-compose.prod.yml logs -f` |
| Tail one service | `docker compose -f docker-compose.prod.yml logs -f backend` |
| Restart backend | `docker compose -f docker-compose.prod.yml restart backend backend-nginx worker cron` |
| Run artisan | `docker compose -f docker-compose.prod.yml exec backend php artisan <cmd>` |
| DB shell | `docker compose -f docker-compose.prod.yml exec postgres psql -U isp_user isp_billing` |
| Stop everything | `docker compose -f docker-compose.prod.yml down` |
| Full rebuild | `docker compose -f docker-compose.prod.yml build --no-cache && ./deploy.sh` |

## Automatic collection planner (staging only until approved)

Phase 1 is protected by two independent gates. A scheduled run creates plans
only when both the container environment and the application setting are
enabled. Keep the environment gate unset or false in production until the
staging report has been approved.

```dotenv
COLLECTION_PLANNER_AUTOMATION_ENABLED=false
COLLECTION_PLANNER_DAILY_AT=07:15

# SaaS hostname foundation. Keep enforcement and registration disabled until
# tenant-scoped business tables and authorization tests are approved.
SAAS_APP_DOMAIN=app.solarnetportal.com
SAAS_ADMIN_DOMAIN=admin.solarnetportal.com
SAAS_BILLING_DOMAIN=billing.solarnetportal.com
SAAS_DOMAINS_ENABLED=false
SAAS_ENABLED=false
SAAS_ENFORCEMENT_MODE=disabled
SAAS_REGISTRATION_ENABLED=false
SAAS_OWNERSHIP_AUTO_ASSIGNMENT=true
```

Create subscription plans through the audit-first command. Existing plans are
never silently changed:

```bash
php artisan saas:configure-plan \
  --code=monthly-3499 \
  --name="Monthly ISP Subscription" \
  --amount=3499 \
  --trial-days=7 \
  --grace-days=3
```

Tenant onboarding remains an administrator-controlled operation. Audit a
candidate first; the audit does not write to the database:

```bash
php artisan saas:onboard-tenant \
  --slug=branch-a \
  --name="ISP Branch A" \
  --owner-email=owner@example.com \
  --plan=monthly-3499
```

Execution requires an existing active owner account, an existing active SaaS
plan, and the exact tenant-specific confirmation shown by the audit. It creates
only the tenant, owner membership, trial subscription, subscription event, and
onboarding audit record. It does not create billing or financial records.

The safe audit command records a planning-run preview but does not create
assignments or collection plans:

```bash
php artisan collection:generate-daily-plans --date=YYYY-MM-DD --dry-run
```

Execution additionally requires the application setting
`collection_planner.auto_enabled=true`. Manual execution requires the exact
confirmation phrase. The scheduler runs the same idempotent command in the
foreground with single-server and overlap locks.

## Troubleshooting

- **502 Bad Gateway** — backend still starting; `docker compose logs backend` and wait ~15 s.
- **Cert didn't issue** — DNS not pointing at the VPS, or ports 80/443 not open. `docker compose logs caddy`.
- **DB connection refused** — Postgres not healthy yet; `docker compose ps` and check the health column.
- **Emails not sending** — set real SMTP creds in `.env` and `docker compose restart backend worker cron`.

## Security hardening (recommended before going live)

1. Disable root SSH login, use a sudo user + ssh key only.
2. Enable `fail2ban`: `apt install -y fail2ban`.
3. Change the default admin password inside the app.
4. Restrict Postgres/Redis to `internal` network (already done — no ports exposed to host).
5. Enable automatic Docker log rotation:
   ```bash
   cat >/etc/docker/daemon.json <<EOF
   { "log-driver": "json-file", "log-opts": { "max-size": "50m", "max-file": "5" } }
   EOF
   systemctl restart docker
   ```
# SaaS platform administration

`admin.solarnetportal.com` has an independently staged control plane. Keep
`SAAS_PLATFORM_ENABLED=false` until its tests and deployment checks pass. This
flag does not enable tenant query enforcement and therefore does not change the
existing `billing.solarnetportal.com` data boundary. SolarNet remains the
protected primary, lifetime subscription-exempt tenant.

Public ISP applications use two additional independent gates:
`SAAS_REGISTRATION_ENABLED` for application/email verification and
`SAAS_TENANT_APP_ENABLED` for approved tenant sign-in. Neither gate enables
global tenant query enforcement or exposes legacy billing APIs. Applications
remain pending until an authenticated platform administrator approves them.
### Operational tenant ownership backfill (staged)

After migration `2026_11_06_000003` has run, audit the legacy operational
roots without changing records:

```bash
php artisan saas:backfill-solarnet-operational-ownership
```

Execution is transactional and requires both `--execute` and the exact
confirmation phrase printed by the command's deployment procedure. Completing
this root backfill does **not** enable tenant workspace navigation; descendant
operational tables and API authorization remain explicit release blockers.
# Descendant operational isolation and API authorization

Operational child/history models inherit tenant isolation through their owned
parent. Protected operational API routes resolve either the authenticated ISP
tenant on `app.solarnetportal.com` or the primary SolarNet tenant on the private
billing domain. Platform administration never receives operational API access.

Audit the foundation without changing data:

```bash
php artisan saas:audit-module-isolation
```

The audit must report `descendant_isolation_ready` and
`operational_api_authorization_ready` as true. Tenant navigation remains staged
behind `production_activation_requires_separate_approval`; do not enable global
SaaS enforcement as part of this foundation deployment.

## Controlled tenant workspace activation

Workspace access is independently fail-closed even if global SaaS flags are
changed. Keep `SAAS_WORKSPACE_ACTIVATION_MODE=staged` by default. A later
canary rollout must use `canary` plus an explicit comma-separated
`SAAS_WORKSPACE_CANARY_TENANTS` allowlist. Run the read-only readiness command
before and after any configuration change:

The first canary is read-only with `SAAS_WORKSPACE_CANARY_READ_ONLY=true`.
Unsafe HTTP methods are rejected before operational authorization. Production
console and worker scopes continue to resolve the primary SolarNet tenant so
existing recurring billing and queue processing remain isolated and available.

Tenant password recovery and staff access requests use the company login ID.
Only an active `tenant_admin` membership can approve staff; approval creates an
active `tenant_staff` membership under the existing company subscription and
never grants a SolarNet role or a separate subscription.

```bash
php artisan saas:audit-workspace-activation
```

Rollback requires no database migration: restore activation mode to `staged`,
clear the canary allowlist, and disable global SaaS enforcement.
