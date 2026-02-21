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

# ServerAlias (nach Browsing oder am Anfang)
grep -q 'ServerAlias' "$CONF" || sed -i '/^Browsing /a ServerAlias *' "$CONF"
grep -q 'ServerAlias' "$CONF" || sed -i '1a ServerAlias *' "$CONF"

# Docker-Netzwerk erlauben – in ALLE Location-Blöcke (127.0.0.1 oder @LOCAL)
if ! grep -q 'Allow from 172.17.0.0/16' "$CONF"; then
    if grep -q 'Allow from 127.0.0.1' "$CONF"; then
        sed -i '/Allow from 127.0.0.1/a\
  Allow from 172.17.0.0\/16\
  Allow from 172.18.0.0\/16' "$CONF"
    fi
    if grep -q 'Allow from @LOCAL' "$CONF" && ! grep -q 'Allow from 172.17.0.0/16' "$CONF"; then
        sed -i '/Allow from @LOCAL/a\
  Allow from 172.17.0.0\/16\
  Allow from 172.18.0.0\/16' "$CONF"
    fi
    # Fallback: vor erstem </Location>
    if ! grep -q 'Allow from 172.17.0.0/16' "$CONF"; then
        sed -i '0,/<\/Location>/s/<\/Location>/  Allow from 172.17.0.0\/16\
  Allow from 172.18.0.0\/16\
<\/Location>/' "$CONF"
    fi
fi

cupsd -t 2>/dev/null || { echo "Fehler: cupsd.conf ungültig. Wiederherstellen: cp ${CONF}.bak.* $CONF"; exit 1; }
systemctl restart cups
echo "CUPS neu gestartet. Prüfen: lpstat -p"
