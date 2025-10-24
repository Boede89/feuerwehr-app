#!/bin/bash

# Datenbank-Reparatur-Skript für Feuerwehr App
# Fügt fehlende Spalten zur users-Tabelle hinzu

echo "🔧 Datenbank-Reparatur wird ausgeführt..."

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

# Fehlende Spalten zur users-Tabelle hinzufügen
echo "📊 Repariere users-Tabelle..."

docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "
USE feuerwehr_app;

-- Prüfen und hinzufügen der user_role Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'user_role');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN user_role ENUM(''admin'', ''approver'', ''user'') DEFAULT ''user'' AFTER is_admin', 
    'SELECT ''user_role column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prüfen und hinzufügen der email_notifications Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'email_notifications');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 1 AFTER user_role', 
    'SELECT ''email_notifications column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prüfen und hinzufügen der can_reservations Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'can_reservations');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN can_reservations TINYINT(1) DEFAULT 1 AFTER email_notifications', 
    'SELECT ''can_reservations column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prüfen und hinzufügen der can_atemschutz Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'can_atemschutz');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN can_atemschutz TINYINT(1) DEFAULT 0 AFTER can_reservations', 
    'SELECT ''can_atemschutz column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prüfen und hinzufügen der can_users Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'can_users');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN can_users TINYINT(1) DEFAULT 0 AFTER can_atemschutz', 
    'SELECT ''can_users column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prüfen und hinzufügen der can_settings Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'can_settings');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN can_settings TINYINT(1) DEFAULT 0 AFTER can_users', 
    'SELECT ''can_settings column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Prüfen und hinzufügen der can_vehicles Spalte
SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_schema = 'feuerwehr_app' AND table_name = 'users' AND column_name = 'can_vehicles');

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE users ADD COLUMN can_vehicles TINYINT(1) DEFAULT 0 AFTER can_settings', 
    'SELECT ''can_vehicles column already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Admin-Benutzer aktualisieren (falls vorhanden)
UPDATE users SET 
    user_role = 'admin',
    email_notifications = 1,
    can_reservations = 1,
    can_atemschutz = 1,
    can_users = 1,
    can_settings = 1,
    can_vehicles = 1
WHERE username = 'admin' AND is_admin = 1;

-- Alle anderen Benutzer auf Standard-Werte setzen
UPDATE users SET 
    user_role = COALESCE(user_role, 'user'),
    email_notifications = COALESCE(email_notifications, 1),
    can_reservations = COALESCE(can_reservations, 1),
    can_atemschutz = COALESCE(can_atemschutz, 0),
    can_users = COALESCE(can_users, 0),
    can_settings = COALESCE(can_settings, 0),
    can_vehicles = COALESCE(can_vehicles, 0)
WHERE username != 'admin' OR is_admin != 1;

SELECT 'Datenbank-Reparatur erfolgreich abgeschlossen!' as message;
"

echo "✅ Datenbank-Reparatur abgeschlossen!"
echo "🔍 Prüfe Tabellenstruktur..."

# Tabellenstruktur prüfen
docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "DESCRIBE users;"

echo "🎉 Die Anmeldung sollte jetzt funktionieren!"
