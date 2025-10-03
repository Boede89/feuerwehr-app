#!/bin/bash

echo "📧 Mail-Server Setup für Feuerwehr App"
echo "======================================"

# Postfix installieren
echo "1. Postfix wird installiert..."
apt-get update
apt-get install -y postfix mailutils

# Postfix konfigurieren
echo "2. Postfix wird konfiguriert..."
cat > /etc/postfix/main.cf << EOF
# Basic configuration
myhostname = feuerwehr-app.local
mydomain = feuerwehr-app.local
myorigin = \$mydomain
inet_interfaces = all
inet_protocols = all
mydestination = \$myhostname, localhost.\$mydomain, localhost, \$mydomain
relayhost = [smtp.gmail.com]:587
smtp_sasl_auth_enable = yes
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
smtp_sasl_security_options = noanonymous
smtp_tls_security_level = encrypt
smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt
EOF

# Gmail SMTP Anmeldedaten konfigurieren
echo "3. Gmail SMTP Anmeldedaten werden konfiguriert..."
echo "[smtp.gmail.com]:587 loeschzug.amern@gmail.com:YOUR_APP_PASSWORD" > /etc/postfix/sasl_passwd
chmod 600 /etc/postfix/sasl_passwd
postmap /etc/postfix/sasl_passwd

# Postfix neu starten
echo "4. Postfix wird neu gestartet..."
service postfix restart

# Test-E-Mail senden
echo "5. Test-E-Mail wird gesendet..."
echo "Test E-Mail von Feuerwehr App" | mail -s "Test E-Mail" test@example.com

echo "✅ Mail-Server Setup abgeschlossen!"
echo ""
echo "⚠️  WICHTIG: Ersetzen Sie 'YOUR_APP_PASSWORD' mit dem Gmail App-Passwort!"
echo "   Gmail App-Passwort erstellen:"
echo "   1. Gehen Sie zu https://myaccount.google.com/security"
echo "   2. Aktivieren Sie die 2-Faktor-Authentifizierung"
echo "   3. Erstellen Sie ein App-Passwort für 'Mail'"
echo "   4. Verwenden Sie dieses Passwort in den SMTP-Einstellungen"
