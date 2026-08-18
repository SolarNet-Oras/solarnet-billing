# SolarNet FreeRADIUS + IPoE safe rollout

## Non-negotiable safety boundary

SolarNet continues to use the existing customer Simple Queues, DHCP leases,
and billing suspension firewall rules. This module does **not** enable
RouterOS RADIUS, DHCP `use-radius`, HotSpot, captive portal, CoA,
Disconnect-Request, queue replacement, firewall changes, or any RouterOS
command.

The first release is intentionally restricted to an **isolated test NAS**. A
production NAS cannot be synchronized through the application. This protects
the active customer DHCP servers, where enabling DHCP RADIUS would affect every
client on that server.

## What this adds

- `radius_subscribers`: a local, audited policy projection derived from the
  existing SolarNet customer, plan, invoice, payment, MAC, and suspension
  records.
- A private PostgreSQL `radius` schema containing standard FreeRADIUS SQL
  tables (`nas`, `radcheck`, `radreply`, `radacct`, and related tables).
- An optional `freeradius` Docker profile, not started by normal deployment.
- Per-NAS shared secrets encrypted in SolarNet and never returned by the API.
- A `FreeRADIUS NAS approval` card for one exact, isolated router source IP.

For DHCP authentication, RouterOS supplies the client MAC address as the
RADIUS user name. SolarNet normalizes it to `AA:BB:CC:DD:EE:FF`, refuses a
missing/all-zero MAC, and refuses a MAC that conflicts with another active,
suspended, or expired customer. A normal `100 Mbps down / 50 Mbps up` plan is
represented as the RouterOS RADIUS reply `Mikrotik-Rate-Limit = 50M/100M`.

## 1. Deploy the code and database schema

On the VPS, from `/var/www/solarnet-billing`:

```sh
git fetch origin main
git pull --ff-only origin main
cd deploy
docker compose -f docker-compose.prod.yml --env-file .env exec -T backend php artisan migrate --force
```

The migration creates tables only. It does not start FreeRADIUS or change a
router, DHCP server, queue, firewall, lease, customer, payment, or invoice.

## 2. Add the isolated FreeRADIUS values

Edit `/var/www/solarnet-billing/deploy/.env`. Do not commit this file.

```env
# FreeRADIUS is an optional, internal service.
FREERADIUS_ENABLED=true
RADIUS_SQL_SYNC_ENABLED=false
RADIUS_SQL_SCHEMA=radius
RADIUS_INTERIM_UPDATE_SECONDS=300

# A separate least-privileged PostgreSQL login for FreeRADIUS.
RADIUS_DB_USERNAME=solarnet_radius
RADIUS_DB_PASSWORD=replace_with_a_long_unique_password

# The service is not reachable from a router while this remains loopback.
RADIUS_BIND_ADDRESS=127.0.0.1

# Used only by radtest inside the FreeRADIUS container.
RADIUS_LOCAL_TEST_SECRET=replace_with_a_second_long_unique_secret
```

Generate secrets on the VPS rather than copying them through a chat:

```sh
openssl rand -base64 36
```

There is no global `RADIUS_SECRET`. Each approved NAS receives its own shared
secret, stored encrypted by SolarNet. Never put any NAS secret in frontend
code, a ticket, an export, an email, or a log.

## 3. Validate and start only the internal service

```sh
docker compose -f docker-compose.prod.yml --env-file .env --profile freeradius config --quiet
docker compose -f docker-compose.prod.yml --env-file .env --profile freeradius build freeradius
docker compose -f docker-compose.prod.yml --env-file .env --profile freeradius-bootstrap run --rm freeradius-bootstrap
docker compose -f docker-compose.prod.yml --env-file .env --profile freeradius up -d freeradius
docker compose -f docker-compose.prod.yml --env-file .env logs --tail=100 freeradius
```

The first command validates Compose. The bootstrap command creates only the
least-privileged PostgreSQL role and grants it access only to the `radius`
schema. The RADIUS service publishes UDP 1812 and 1813 on `127.0.0.1` only; it
is not public and a MikroTik cannot reach it at this stage.

If the service fails its configuration check, stop here. Existing SolarNet
service continues normally because nothing has been connected to a router.

## 4. Stage and test one customer locally

1. In **RADIUS / IPoE**, select one known active test customer with an exact,
   unique MAC address and click **Sync**. This remains local while SQL syncing
   is disabled.
2. Change only `RADIUS_SQL_SYNC_ENABLED=true` in `.env`.
3. Recreate only the Laravel services that read that environment value:

   ```sh
   docker compose -f docker-compose.prod.yml --env-file .env up -d --force-recreate backend worker cron
   docker compose -f docker-compose.prod.yml --env-file .env exec -T backend php artisan config:clear
   ```

4. Click **Sync** again for that one customer. This writes only SolarNet-owned
   `radius.radcheck` and `radius.radreply` policy rows.
5. Test from inside the FreeRADIUS container. Replace the MAC with the exact
   staged MAC:

   ```sh
   docker compose -f docker-compose.prod.yml --env-file .env exec -T freeradius \
     sh -lc 'radtest "AA:BB:CC:DD:EE:FF" unused 127.0.0.1 0 "$RADIUS_LOCAL_TEST_SECRET"'
   ```

Expected result: `Access-Accept` with the `Mikrotik-Rate-Limit` reply. A MAC
that is suspended, pending, missing, conflicting, or unknown must receive
`Access-Reject`.

This proves the policy and service without involving MikroTik.

## 5. NAS approval is deliberately separate

Only after the internal test passes, a Super Administrator or Administrator can
add one exact source address in **FreeRADIUS NAS approval**. The NAS must stay
in **isolated test** mode. Its secret is saved locally first. Selecting **Sync
NAS** writes a row to `radius.nas`; restart FreeRADIUS after that step because
its SQL NAS client list is loaded at startup:

```sh
docker compose -f docker-compose.prod.yml --env-file .env restart freeradius
```

Do **not** change `RADIUS_BIND_ADDRESS` or run RouterOS `/radius add` or DHCP
`use-radius=yes` as part of this release. Those are future, reviewed changes
that require a dedicated test VLAN/DHCP server and a one-customer change plan.

## Observability and accounting

When an isolated NAS is eventually approved in a future release, FreeRADIUS
can write standard accounting records to `radius.radacct`. This release does
not treat RADIUS accounting as billing truth and does not use it to suspend,
restore, invoice, or charge any customer. The existing payment and queue
workflows remain authoritative.

## Rollback

No database cleanup is required to return to the current behavior:

```sh
cd /var/www/solarnet-billing/deploy
docker compose -f docker-compose.prod.yml --env-file .env stop freeradius
```

Then set `RADIUS_SQL_SYNC_ENABLED=false` and recreate only `backend`, `worker`,
and `cron`. Do not remove customers, invoices, payments, leases, queues,
firewall rules, Docker volumes, or the PostgreSQL database. The staged records
remain as audit evidence and have no network effect.
