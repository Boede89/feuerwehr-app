#!/bin/bash

# Admin-Benutzer-Reparatur-Skript für Feuerwehr App
# Überprüft und repariert den Admin-Benutzer

echo "👤 Admin-Benutzer-Reparatur wird ausgeführt..."

# Prüfen ob MySQL läuft
if ! docker ps | grep -q "feuerwehr_mysql"; then
    echo "❌ MySQL-Container läuft nicht!"
    echo "Starten Sie die Container mit: docker compose up -d"
    exit 1
fi

# Warten auf MySQL
echo "⏳ Warte auf MySQL..."
for i in {1..5}; do
    if docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "SELECT 1;" &> /dev/null; then
        echo "✅ MySQL ist bereit!"
        break
    else
        echo "Versuch $i/5: Warte 10 Sekunden..."
        sleep 10
    fi
done

# Admin-Benutzer überprüfen und reparieren
echo "🔍 Überprüfe Admin-Benutzer..."

docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "
USE feuerwehr_app;

-- Prüfe ob Admin-Benutzer existiert
SELECT 'Admin-Benutzer-Status:' as info;
SELECT id, username, email, is_admin, user_role, is_active, created_at FROM users WHERE username = 'admin';

-- Falls Admin-Benutzer nicht existiert oder Probleme hat, erstelle/repariere ihn
INSERT INTO users (
    username, 
    email, 
    password_hash, 
    first_name, 
    last_name, 
    is_admin, 
    user_role, 
    email_notifications, 
    can_reservations, 
    can_atemschutz, 
    can_users, 
    can_settings, 
    can_vehicles, 
    is_active
) VALUES (
    'admin', 
    'admin@feuerwehr.local', 
    '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'Admin', 
    'User', 
    1, 
    'admin', 
    1, 
    1, 
    1, 
    1, 
    1, 
    1, 
    1
) ON DUPLICATE KEY UPDATE
    password_hash = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    is_admin = 1,
    user_role = 'admin',
    email_notifications = 1,
    can_reservations = 1,
    can_atemschutz = 1,
    can_users = 1,
    can_settings = 1,
    can_vehicles = 1,
    is_active = 1,
    updated_at = CURRENT_TIMESTAMP;

-- Zeige finalen Status
SELECT 'Finaler Admin-Benutzer-Status:' as info;
SELECT id, username, email, is_admin, user_role, is_active, created_at, updated_at FROM users WHERE username = 'admin';

-- Teste Passwort-Hash (sollte 'password' sein)
SELECT 'Passwort-Test:' as info;
SELECT username, 
       CASE 
           WHEN password_hash = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' THEN 'Korrekt (password)'
           ELSE 'Falsch'
       END as password_status
FROM users WHERE username = 'admin';

SELECT 'Admin-Benutzer-Reparatur abgeschlossen!' as message;
"

echo "✅ Admin-Benutzer-Reparatur abgeschlossen!"
echo ""
echo "🔑 Anmeldedaten:"
echo "   Benutzername: admin"
echo "   Passwort: password"
echo ""
echo "🌐 Anmelde-URL: http://localhost:8081/login.php"
echo ""
echo "⚠️  WICHTIG: Ändern Sie das Passwort nach der ersten Anmeldung!"
