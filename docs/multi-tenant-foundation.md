# Multi-tenant SaaS foundation

This phase is intentionally inert. It adds platform-owned tenant, membership,
subscription, payment audit, support-access, onboarding-abuse, and usage tables.
It does **not** scope existing SolarNet records, alter authentication, expose
tenant registration, enforce subscriptions, or change billing/network jobs.

## Safety switches

All switches default to off:

```dotenv
SAAS_ENABLED=false
SAAS_ENFORCEMENT_MODE=disabled
SAAS_REGISTRATION_ENABLED=false
SAAS_DEFAULT_TRIAL_DAYS=30
SAAS_DEFAULT_CURRENCY=PHP
SAAS_PRIMARY_TENANT_SLUG=solarnet
```

Do not enable them during this phase. Future enforcement must fail closed when
a tenant context is required, while platform routes must be explicitly separate.

## Production rollout

1. Take and verify a PostgreSQL backup.
2. Deploy the code with every SaaS switch disabled.
3. Run `php artisan migrate --force`.
4. Run the read-only preview: `php artisan saas:bootstrap-solarnet`.
5. Review the tenant/user counts.
6. Execute only after approval:

```bash
php artisan saas:bootstrap-solarnet \
  --execute \
  --confirm="CREATE SOLARNET TENANT FOUNDATION"
```

7. Run the preview again and verify one primary, subscription-exempt SolarNet
   tenant and one membership per legacy user.

The command is transactional and idempotent. It stops on ambiguous or altered
primary-tenant state. It never modifies customers, invoices, payments, devices,
financial entries, attendance, payroll, or any other operational record.

## Rollback boundary

Before any later phase references these tables, the migration can be rolled
back normally. After tenant identifiers are introduced into operational tables,
use a forward repair migration and a verified backup instead of dropping tenant
foundation data.

## Next gated phase

Inventory every tenant-owned table and uniqueness rule, add nullable tenant IDs,
backfill them to the protected SolarNet tenant, verify counts and foreign keys,
then add constraints. Request-time tenant resolution, authorization enforcement,
storage/cache/queue isolation, onboarding, and subscription enforcement remain
separate approval gates.
