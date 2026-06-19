#!/bin/sh
set -eu

port="${PORT:-8080}"

# The PHP Apache image requires prefork. Remove incompatible MPM links in case
# the hosting environment or a cached image enabled another implementation.
rm -f /etc/apache2/mods-enabled/mpm_event.load \
    /etc/apache2/mods-enabled/mpm_event.conf \
    /etc/apache2/mods-enabled/mpm_worker.load \
    /etc/apache2/mods-enabled/mpm_worker.conf
a2enmod mpm_prefork >/dev/null

if [ "${AKINO_RUNTIME_BOOTSTRAP:-0}" = "1" ]; then
    attempt=1

    until php /var/www/html/tools/setup_database.php --skip-seed; do
        if [ "$attempt" -ge 10 ]; then
            echo "Database schema setup failed after ${attempt} attempts; starting the web server in fallback mode." >&2
            break
        fi

        attempt=$((attempt + 1))
        sleep 2
    done
fi

sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
