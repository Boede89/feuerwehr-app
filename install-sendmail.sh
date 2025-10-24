#!/bin/bash

echo "🔧 Sendmail Installation"
echo "======================="

# 1. System aktualisieren
echo "1. System aktualisieren..."
apt-get update -y

# 2. Sendmail installieren
echo "2. Sendmail installieren..."
apt-get install -y sendmail

# 3. Sendmail konfigurieren
echo "3. Sendmail konfigurieren..."
echo "127.0.0.1 localhost" >> /etc/hosts
echo "localhost" > /etc/mailname

# 4. Sendmail starten
echo "4. Sendmail starten..."
service sendmail start

# 5. Sendmail beim Boot starten
echo "5. Sendmail beim Boot starten..."
update-rc.d sendmail defaults

# 6. Testen
echo "6. Sendmail testen..."
echo "Test E-Mail" | sendmail -v test@example.com

echo "✅ Sendmail Installation abgeschlossen!"
echo "📧 E-Mail-System sollte jetzt funktionieren!"
