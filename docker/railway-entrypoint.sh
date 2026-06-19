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

sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
