#!/bin/bash
#
# CUPS komplett deinstallieren und neu einrichten
# Auf dem HOST ausführen (nicht im Docker-Container) – dort wo host.docker.internal hinzeigt.
# Für Debian/Ubuntu. Mit sudo ausführen: sudo ./cups-neu-einrichten.sh
#

set -e

echo "=== CUPS komplett deinstallieren und neu einrichten ==="
echo ""

# 1. CUPS stoppen
echo "[1/6] CUPS-Dienst stoppen..."
systemctl stop cups 2>/dev/null || service cups stop 2>/dev/null || true

# 2. CUPS deinstallieren
echo "[2/6] CUPS und zugehörige Pakete deinstallieren..."
apt-get remove -y cups cups-client cups-bsd cups-filters cups-daemon 2>/dev/null || true
apt-get purge -y cups cups-client cups-bsd cups-filters cups-daemon 2>/dev/null || true
apt-get autoremove -y 2>/dev/null || true

# 3. Alte Konfiguration und Daten löschen
echo "[3/6] Alte Konfiguration und Drucker-Daten löschen..."
rm -rf /etc/cups
rm -rf /var/spool/cups
rm -rf /var/cache/cups
rm -rf /var/log/cups
rm -f /etc/cups/cupsd.conf
rm -f /etc/cups/printers.conf
rm -f /etc/cups/ppd/*.ppd 2>/dev/null || true

# 4. CUPS neu installieren
echo "[4/6] CUPS neu installieren..."
apt-get update
apt-get install -y cups cups-client cups-bsd

# 5. CUPS konfigurieren (für Zugriff von Docker)
echo "[5/6] CUPS konfigurieren..."

# Backup der Standard-Config falls vorhanden
if [ -f /etc/cups/cupsd.conf ]; then
    cp /etc/cups/cupsd.conf /etc/cups/cupsd.conf.bak
fi

# cupsd.conf anpassen: Remote-Zugriff und Port 631
cat > /etc/cups/cupsd.conf << 'CUPSDCONF'
# CUPS-Konfiguration für Feuerwehr-App (Docker-Zugriff)
LogLevel warn
PageLogFormat
MaxLogSize 0
Listen 0.0.0.0:631
Browsing Off
DefaultAuthType Basic
WebInterface Yes
<Location />
  Order allow,deny
  Allow @LOCAL
  Allow all
</Location>
<Location /admin>
  Order allow,deny
  Allow @LOCAL
  Allow all
</Location>
<Location /admin/conf>
  AuthType Default
  Require user @SYSTEM
  Order allow,deny
  Allow @LOCAL
  Allow all
</Location>
<Policy default>
  JobPrivateValues default
  SubscriptionPrivateValues default
  <Limit Create-Job Print-Job Print-URI Validate-Job>
    Order allow,deny
    Allow all
  </Limit>
  <Limit Send-Document Send-URI Hold-Job Release-Job Restart-Job Purge-Jobs Set-Job-Attributes Create-Job-Subscription Renew-Subscription Cancel-Subscription Get-Notifications Reprocess-Job Cancel-Current-Job Suspend-Current-Job Resume-Job Cancel-My-Jobs Close-Job CUPS-Move-Job CUPS-Get-Document>
    Require user @OWNER @SYSTEM
    Order deny,allow
  </Limit>
  <Limit Pause-Printer Resume-Printer Enable-Printer Disable-Printer Pause-Printer-After-Current-Job Hold-New-Jobs Release-Held-New-Jobs Deploy-Printer-Settings Schedule-Job-On-Hold-Host Print-Job Print-URI Create-Job-Subscription Renew-Subscription Cancel-Subscription Get-Notifications Reprocess-Job Cancel-Current-Job Suspend-Current-Job Resume-Job Cancel-My-Jobs Close-Job CUPS-Move-Job CUPS-Get-Document>
    AuthType Default
    Require user @SYSTEM
    Order deny,allow
  </Limit>
  <Limit All>
    Order deny,allow
  </Limit>
</Policy>
CUPSDCONF

# 6. CUPS starten und aktivieren
echo "[6/6] CUPS starten..."
systemctl enable cups 2>/dev/null || true
systemctl start cups 2>/dev/null || service cups start 2>/dev/null || true

echo ""
echo "=== CUPS neu eingerichtet ==="
echo ""
echo "CUPS läuft auf Port 631."
echo ""
echo "--- Drucker per Shell hinzufügen ---"
echo ""
echo "Workplace Pure (IPP mit Authentifizierung):"
echo "  lpadmin -p WacheAmern -E -v 'https://BENUTZER:PASSWORT@ipp.workplacepure.com/ipp/print/IHRE-ID/DRUCKER-ID' -m everywhere"
echo ""
echo "IPP-Netzwerkdrucker (ohne Auth):"
echo "  lpadmin -p DruckerName -E -v ipp://192.168.1.10/ipp/print -m everywhere"
echo ""
echo "USB-Drucker:"
echo "  lpinfo -v | grep usb   # URI ermitteln"
echo "  lpadmin -p DruckerName -E -v usb://Hersteller/Modell -m everywhere"
echo ""
echo "--- Nützliche Befehle ---"
echo "  lpstat -v              # Alle Drucker anzeigen"
echo "  lpstat -t              # Status"
echo "  lp -d DRUCKERNAME /etc/hosts   # Testdruck"
echo ""
echo "--- App-Einstellungen ---"
echo "  CUPS-Server: 172.17.0.1:631/version=1.1  (oder host.docker.internal:631/version=1.1)"
echo "  Drucker: Der Name aus lpstat -v (z.B. WacheAmern)"
echo ""
