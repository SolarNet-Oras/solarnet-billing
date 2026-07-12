#!/bin/bash
# Supervisor wrapper: waits until PHP 8.4 is installed AND all required
# extensions are enabled, then serves the Laravel app on port 8001.
#
# Previously we only waited for pdo_pgsql — but mbstring is enabled
# by a later dpkg trigger, so the server sometimes came up without
# mbstring loaded, causing "Call to undefined function mb_split()"
# 500 errors on every request (login etc. surfaced as 502 at the ingress).
required_exts=(pdo_pgsql mbstring openssl tokenizer json bcmath curl xml)
while true; do
    if [ -x /usr/bin/php8.4 ]; then
        mods=$(/usr/bin/php8.4 -m 2>/dev/null)
        missing=0
        for ext in "${required_exts[@]}"; do
            echo "$mods" | grep -qi "^${ext}$" || { missing=1; break; }
        done
        [ "$missing" = "0" ] && break
    fi
    sleep 2
done

cd /app/backend
exec /usr/bin/php8.4 -S 0.0.0.0:8001 -t /app/backend/public
