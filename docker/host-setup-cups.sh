#!/bin/bash
# CUPS auf dem Host einrichten – für Drucker-Zugriff aus dem Container.
# Einmalig auf dem Host ausführen: sudo bash docker/host-setup-cups.sh
# Danach startet CUPS automatisch beim Host-Neustart.

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

echo "Starte CUPS..."
systemctl start cups

echo ""
echo "CUPS ist eingerichtet. Status:"
systemctl status cups --no-pager || true
echo ""
echo "Prüfen mit: lpstat -p"
echo "Drucker anlegen mit: sudo lpadmin -p NAME -E -v ipp://... -m everywhere"
echo ""
echo "WICHTIG: Container neu starten, damit der CUPS-Socket gemountet wird:"
echo "  cd ~/feuerwehr-app && docker compose restart web"
