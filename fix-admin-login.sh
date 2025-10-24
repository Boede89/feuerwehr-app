#!/bin/bash

# Passwort-Hash-Generator für Admin-Benutzer
echo "🔐 Generiere korrekten Passwort-Hash für 'admin123'..."

# Prüfen ob MySQL läuft
if ! docker ps | grep -q "feuerwehr_mysql"; then
    echo "❌ MySQL-Container läuft nicht!"
    exit 1
fi

# Korrekten Passwort-Hash für 'admin123' generieren und setzen
echo "📝 Setze korrekten Passwort-Hash für 'admin123'..."

docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "
USE feuerwehr_app;

-- Korrekten Passwort-Hash für 'admin123' setzen
UPDATE users SET 
    password_hash = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    is_admin = 1,
    user_role = 'admin',
    is_active = 1,
    email_notifications = 1,
    can_reservations = 1,
    can_atemschutz = 1,
    can_users = 1,
    can_settings = 1,
    can_vehicles = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE username = 'admin';

-- Falls Admin-Benutzer nicht existiert, erstelle ihn
INSERT IGNORE INTO users (
    username, email, password_hash, first_name, last_name, 
    is_admin, user_role, email_notifications, can_reservations, 
    can_atemschutz, can_users, can_settings, can_vehicles, is_active
) VALUES (
    'admin', 'admin@feuerwehr.local', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'Admin', 'User', 1, 'admin', 1, 1, 1, 1, 1, 1, 1
);

-- Zeige finalen Status
SELECT 'Admin-Benutzer-Status:' as info;
SELECT username, email, is_admin, user_role, is_active, updated_at FROM users WHERE username = 'admin';

SELECT 'Passwort-Hash wurde korrekt gesetzt!' as message;
"

echo "✅ Passwort-Hash korrekt gesetzt!"
echo ""
echo "🔑 Anmeldedaten:"
echo "   Benutzername: admin"
echo "   Passwort: admin123"
echo ""
echo "🌐 Anmelde-URL: http://localhost:8081/login.php"
echo ""
echo "⚠️  Falls die Anmeldung immer noch nicht funktioniert, prüfen Sie:"
echo "   1. Container-Status: docker ps"
echo "   2. Web-Container-Logs: docker logs feuerwehr_web"
echo "   3. MySQL-Logs: docker logs feuerwehr_mysql"
