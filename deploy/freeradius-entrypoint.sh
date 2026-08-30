#!/bin/sh
set -eu

: "${RADIUS_SQL_HOST:?RADIUS_SQL_HOST is required}"
: "${RADIUS_SQL_DATABASE:?RADIUS_SQL_DATABASE is required}"
: "${RADIUS_SQL_USERNAME:?RADIUS_SQL_USERNAME is required}"
: "${RADIUS_SQL_PASSWORD:?RADIUS_SQL_PASSWORD is required}"
: "${RADIUS_LOCAL_TEST_SECRET:?RADIUS_LOCAL_TEST_SECRET is required}"

# Validate configuration before opening either UDP listener. `-C` does not run
# in debug mode, so packet data and database credentials are not logged. Keep
# validation errors on Docker stderr so a restart loop remains diagnosable.
freeradius -C
exec freeradius -f
