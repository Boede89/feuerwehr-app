#!/bin/bash
# Schnellfix: CUPS für Docker-Zugriff freigeben (bei "Forbidden" / "blockiert Zugriff")
# Ausführen: sudo bash docker/cups-allow-docker.sh

set -e
if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte mit sudo ausführen: sudo bash docker/cups-allow-docker.sh"
    exit 1
fi

CONF="/etc/cups/cupsd.conf"
cp -a "$CONF" "${CONF}.bak.$(date +%Y%m%d%H%M)"

# Listen auf allen Interfaces
sed -i 's/Listen localhost:631/Listen 0.0.0.0:631/' "$CONF"
sed -i 's/Listen \*:631/Listen 0.0.0.0:631/' "$CONF" 2>/dev/null || true

# ServerAlias
grep -q 'ServerAlias' "$CONF" || sed -i '1a ServerAlias *' "$CONF"

# Docker-Netzwerk erlauben
if ! grep -q 'Allow from 172.17.0.0/16' "$CONF"; then
    if grep -q 'Allow from 127.0.0.1' "$CONF"; then
        sed -i '0,/Allow from 127.0.0.1/s/\(Allow from 127.0.0.1\)/\1\n  Allow from 172.17.0.0\/16\n  Allow from 172.18.0.0\/16/' "$CONF"
    elif grep -q 'Allow from @LOCAL' "$CONF"; then
        sed -i '0,/Allow from @LOCAL/s/\(Allow from @LOCAL\)/\1\n  Allow from 172.17.0.0\/16\n  Allow from 172.18.0.0\/16/' "$CONF"
    else
        # Vor </Location> einfügen
        sed -i '0,/<\/Location>/s/\(<\/Location>\)/  Allow from 172.17.0.0\/16\n  Allow from 172.18.0.0\/16\n\1/' "$CONF"
    fi
fi

systemctl restart cups
echo "CUPS neu gestartet. Prüfen: lpstat -p"
