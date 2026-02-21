#!/bin/bash
# CUPS auf dem Host einrichten – für Drucker-Zugriff aus dem Container.
# Einmalig auf dem Host ausführen: sudo bash docker/host-setup-cups.sh
# Nutzt Netzwerk-Zugriff (TCP) statt Socket – stabiler, keine Socket-Probleme nach Tagen.

set -e
echo "=== CUPS Host-Setup für Feuerwehr-App ==="

if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte mit sudo ausführen: sudo bash docker/host-setup-cups.sh"
    exit 1
fi

echo "Installiere CUPS (falls nicht vorhanden)..."
apt-get update -qq
apt-get install -y cups cups-client

echo "Aktiviere CUPS für automatischen Start beim Boot..."
systemctl enable cups

echo "Richte automatischen Neustart bei Absturz ein..."
mkdir -p /etc/systemd/system/cups.service.d
cat > /etc/systemd/system/cups.service.d/override.conf << 'EOF'
[Service]
Restart=on-failure
RestartSec=5
EOF
systemctl daemon-reload

echo "Konfiguriere CUPS für Netzwerk-Zugriff (Container nutzt TCP statt Socket)..."
cp -a /etc/cups/cupsd.conf /etc/cups/cupsd.conf.bak.$(date +%Y%m%d) 2>/dev/null || true
# Listen auf allen Interfaces (nicht nur localhost)
sed -i 's/Listen localhost:631/Listen 0.0.0.0:631/' /etc/cups/cupsd.conf
# ServerAlias für Host-Header von Container
grep -q 'ServerAlias' /etc/cups/cupsd.conf || sed -i '1a ServerAlias *' /etc/cups/cupsd.conf
# Docker-Netzwerk erlauben (172.17.x und 172.18.x)
if ! grep -q 'Allow from 172.17.0.0/16' /etc/cups/cupsd.conf; then
    sed -i '0,/Allow from 127.0.0.1/s/Allow from 127.0.0.1/Allow from 127.0.0.1\n  Allow from 172.17.0.0\/16\n  Allow from 172.18.0.0\/16/' /etc/cups/cupsd.conf 2>/dev/null || \
    sed -i '0,/Allow from @LOCAL/s/Allow from @LOCAL/Allow from @LOCAL\n  Allow from 172.17.0.0\/16\n  Allow from 172.18.0.0\/16/' /etc/cups/cupsd.conf 2>/dev/null || true
fi

# Konfiguration prüfen
if ! cupsd -t 2>/dev/null; then
    echo "Warnung: cupsd.conf könnte ungültig sein. Bei Problemen: sudo cp /etc/cups/cupsd.conf.bak.* /etc/cups/cupsd.conf"
fi

echo "Starte CUPS..."
systemctl restart cups

echo ""
echo "CUPS ist eingerichtet (Netzwerk-Zugriff). Status:"
systemctl status cups --no-pager || true
echo ""
echo "Prüfen mit: lpstat -p"
echo "Drucker anlegen mit: sudo lpadmin -p NAME -E -v ipp://... -m everywhere"
echo ""
echo "Container neu starten: cd ~/feuerwehr-app && docker compose up -d --force-recreate web"
