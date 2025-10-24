#!/bin/bash

# Datenbank-Setup-Skript für Feuerwehr App
# Erstellt alle notwendigen Tabellen manuell

echo "🗄️ Datenbank-Setup wird ausgeführt..."

# Warten bis MySQL bereit ist (mehrfache Versuche)
echo "⏳ Warte auf MySQL-Container..."
MYSQL_READY=false
for i in {1..15}; do
    echo "Versuch $i/15: Prüfe MySQL-Container..."
    if docker ps | grep -q "feuerwehr_mysql"; then
        echo "MySQL-Container läuft, teste Verbindung..."
        if docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "SELECT 1;" &> /dev/null; then
            echo "✅ MySQL ist bereit!"
            MYSQL_READY=true
            break
        else
            echo "MySQL läuft noch nicht, warte 20 Sekunden..."
            sleep 20
        fi
    else
        echo "MySQL-Container läuft noch nicht, warte 20 Sekunden..."
        sleep 20
    fi
done

if [ "$MYSQL_READY" = false ]; then
    echo "❌ MySQL-Container ist nach 15 Versuchen nicht bereit!"
    echo "🔍 Container-Status:"
    docker ps -a | grep feuerwehr
    echo "📋 MySQL-Logs:"
    docker logs feuerwehr_mysql --tail 30
    echo "⏳ Versuchen Sie es in 2-3 Minuten erneut mit: ./setup-database.sh"
    exit 1
fi

# Datenbank und Tabellen erstellen
echo "📊 Erstelle Datenbank und Tabellen..."

# Zuerst MySQL-Benutzer für alle möglichen Hosts erstellen
echo "👤 Erstelle MySQL-Benutzer für alle Hosts..."
docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "
-- Root-Benutzer für alle Hosts aktivieren
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY 'root_password_2024';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;

-- Feuerwehr-Benutzer für alle möglichen Hosts erstellen
CREATE USER IF NOT EXISTS 'feuerwehr_user'@'%' IDENTIFIED BY 'feuerwehr_password';
CREATE USER IF NOT EXISTS 'feuerwehr_user'@'localhost' IDENTIFIED BY 'feuerwehr_password';
CREATE USER IF NOT EXISTS 'feuerwehr_user'@'127.0.0.1' IDENTIFIED BY 'feuerwehr_password';

-- Berechtigungen für alle Hosts setzen
GRANT ALL PRIVILEGES ON feuerwehr_app.* TO 'feuerwehr_user'@'%';
GRANT ALL PRIVILEGES ON feuerwehr_app.* TO 'feuerwehr_user'@'localhost';
GRANT ALL PRIVILEGES ON feuerwehr_app.* TO 'feuerwehr_user'@'127.0.0.1';

-- Zusätzliche Berechtigungen für Datenbank-Operationen
GRANT CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES ON feuerwehr_app.* TO 'feuerwehr_user'@'%';
GRANT CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES ON feuerwehr_app.* TO 'feuerwehr_user'@'localhost';
GRANT CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES ON feuerwehr_app.* TO 'feuerwehr_user'@'127.0.0.1';

-- Berechtigungen sofort aktivieren
FLUSH PRIVILEGES;

SELECT 'MySQL-Benutzer erfolgreich erstellt!' as message;
"

# Jetzt Datenbank und Tabellen erstellen
echo "📊 Erstelle Datenbank und Tabellen..."
docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "
CREATE DATABASE IF NOT EXISTS feuerwehr_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE feuerwehr_app;

-- Benutzer Tabelle
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Fahrzeuge Tabelle
CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    description TEXT,
    capacity INT DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Reservierungen Tabelle
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    requester_name VARCHAR(100) NOT NULL,
    requester_email VARCHAR(100) NOT NULL,
    reason TEXT NOT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    rejection_reason TEXT NULL,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Einstellungen Tabelle
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Google Calendar Events Tabelle
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    google_event_id VARCHAR(255) UNIQUE,
    calendar_id VARCHAR(255),
    event_title VARCHAR(255) NOT NULL,
    event_description TEXT,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
);

-- Aktivitätsprotokoll Tabelle
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Feedback Tabelle
CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'replied') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Atemschutz-Träger Tabelle
CREATE TABLE IF NOT EXISTS atemschutz_traeger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    vorname VARCHAR(100) NOT NULL,
    geburtsdatum DATE NOT NULL,
    eintrittsdatum DATE NOT NULL,
    funktion VARCHAR(100),
    telefon VARCHAR(20),
    email VARCHAR(100),
    notfallkontakt VARCHAR(200),
    notfalltelefon VARCHAR(20),
    medizinische_beschraenkungen TEXT,
    ausbildungsstand VARCHAR(100),
    letzte_untersuchung DATE,
    naechste_untersuchung DATE,
    status ENUM('aktiv', 'inaktiv', 'gesperrt') DEFAULT 'aktiv',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Atemschutz-Einsätze Tabelle
CREATE TABLE IF NOT EXISTS atemschutz_einsaetze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    traeger_id INT NOT NULL,
    einsatzdatum DATE NOT NULL,
    einsatzzeit TIME NOT NULL,
    einsatzart VARCHAR(100) NOT NULL,
    einsatzort VARCHAR(200),
    einsatzleiter VARCHAR(100),
    verwendete_atemschutzgeraete VARCHAR(200),
    einsatzdauer_minuten INT,
    bemerkungen TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (traeger_id) REFERENCES atemschutz_traeger(id) ON DELETE CASCADE
);

-- Dashboard-Einstellungen Tabelle
CREATE TABLE IF NOT EXISTS dashboard_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    setting_name VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_setting (user_id, setting_name)
);

-- Dashboard-Präferenzen Tabelle
CREATE TABLE IF NOT EXISTS dashboard_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    preference_name VARCHAR(100) NOT NULL,
    preference_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_preference (user_id, preference_name)
);

-- Standard-Admin-Benutzer erstellen
INSERT IGNORE INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) 
VALUES ('admin', 'admin@feuerwehr.local', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 1, 1);

-- Standard-Fahrzeuge hinzufügen
INSERT IGNORE INTO vehicles (name, type, description, capacity, is_active) VALUES
('LF 10', 'Löschfahrzeug', 'Standard-Löschfahrzeug mit 1000L Wassertank', 9, 1),
('LF 20', 'Löschfahrzeug', 'Großes Löschfahrzeug mit 2000L Wassertank', 9, 1),
('DLK 23', 'Drehleiter', 'Drehleiter mit 23m Arbeitshöhe', 3, 1),
('RW 2', 'Rüstwagen', 'Rüstwagen für technische Hilfeleistung', 9, 1),
('MTF', 'Mannschaftstransportfahrzeug', 'Transportfahrzeug für Personal', 8, 1);

-- Standard-Einstellungen hinzufügen
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('app_name', 'Feuerwehr App', 'Name der Anwendung'),
('app_version', '2.0', 'Version der Anwendung'),
('smtp_host', '', 'SMTP-Server für E-Mail-Versand'),
('smtp_port', '587', 'SMTP-Port'),
('smtp_username', '', 'SMTP-Benutzername'),
('smtp_password', '', 'SMTP-Passwort'),
('smtp_encryption', 'tls', 'SMTP-Verschlüsselung'),
('google_calendar_api_key', '', 'Google Calendar API-Schlüssel'),
('google_calendar_id', 'primary', 'Google Calendar ID'),
('email_from_name', 'Feuerwehr App', 'Absender-Name für E-Mails'),
('email_from_address', 'noreply@feuerwehr.local', 'Absender-E-Mail-Adresse');

SELECT 'Datenbank-Setup erfolgreich abgeschlossen!' as message;
"

echo "✅ Datenbank-Setup abgeschlossen!"
echo "🔍 Prüfe Tabellen..."

# Tabellen-Status prüfen
echo "📋 Verfügbare Tabellen:"
docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "SHOW TABLES;"

# Prüfen ob wichtige Tabellen existieren
echo "🔍 Prüfe wichtige Tabellen..."
TABLES_EXIST=true

# Prüfe users-Tabelle
if ! docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "DESCRIBE users;" &> /dev/null; then
    echo "❌ users-Tabelle fehlt!"
    TABLES_EXIST=false
else
    echo "✅ users-Tabelle vorhanden"
fi

# Prüfe vehicles-Tabelle
if ! docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "DESCRIBE vehicles;" &> /dev/null; then
    echo "❌ vehicles-Tabelle fehlt!"
    TABLES_EXIST=false
else
    echo "✅ vehicles-Tabelle vorhanden"
fi

# Prüfe reservations-Tabelle
if ! docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "DESCRIBE reservations;" &> /dev/null; then
    echo "❌ reservations-Tabelle fehlt!"
    TABLES_EXIST=false
else
    echo "✅ reservations-Tabelle vorhanden"
fi

# Prüfe Admin-Benutzer
echo "👤 Prüfe Admin-Benutzer..."
ADMIN_COUNT=$(docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "SELECT COUNT(*) FROM users WHERE username='admin';" -s -N 2>/dev/null || echo "0")
if [ "$ADMIN_COUNT" -gt 0 ]; then
    echo "✅ Admin-Benutzer vorhanden"
else
    echo "❌ Admin-Benutzer fehlt!"
    TABLES_EXIST=false
fi

# Prüfe Beispiel-Fahrzeuge
echo "🚒 Prüfe Beispiel-Fahrzeuge..."
VEHICLE_COUNT=$(docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "SELECT COUNT(*) FROM vehicles;" -s -N 2>/dev/null || echo "0")
if [ "$VEHICLE_COUNT" -gt 0 ]; then
    echo "✅ Beispiel-Fahrzeuge vorhanden ($VEHICLE_COUNT Fahrzeuge)"
else
    echo "❌ Beispiel-Fahrzeuge fehlen!"
    TABLES_EXIST=false
fi

if [ "$TABLES_EXIST" = true ]; then
    echo "🎉 Datenbank ist vollständig bereit!"
    echo "🔗 Sie können sich jetzt mit admin/admin123 anmelden"
else
    echo "⚠️  Einige Tabellen oder Daten fehlen!"
    echo "🔄 Versuchen Sie einen Container-Neustart: docker compose restart"
fi
