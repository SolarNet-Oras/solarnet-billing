#!/bin/sh
set -eu

: "${RADIUS_DB_USERNAME:?RADIUS_DB_USERNAME is required}"
: "${RADIUS_DB_PASSWORD:?RADIUS_DB_PASSWORD is required}"

# The app database role owns the schema created by Laravel's migration. This
# one-time idempotent bootstrap creates a less-privileged role for FreeRADIUS.
# It never reads or changes customer, invoice, payment, DHCP, queue, or router
# records. Avoid echoing commands because the password is intentionally secret.
psql -v ON_ERROR_STOP=1 \
  -v radius_user="$RADIUS_DB_USERNAME" \
  -v radius_password="$RADIUS_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'radius_user', :'radius_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'radius_user')
\gexec

SELECT format('ALTER ROLE %I LOGIN PASSWORD %L', :'radius_user', :'radius_password')
\gexec

SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'radius_user')
\gexec
SELECT format('GRANT USAGE ON SCHEMA radius TO %I', :'radius_user')
\gexec
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA radius TO %I', :'radius_user')
\gexec
SELECT format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA radius TO %I', :'radius_user')
\gexec
SELECT format('ALTER ROLE %I SET search_path = radius', :'radius_user')
\gexec
SQL

echo 'FreeRADIUS database role and radius-schema grants are ready.'
