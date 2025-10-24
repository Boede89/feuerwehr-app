#!/bin/bash

# Schnelles Datenbank-Setup für Feuerwehr App
# Für den Fall, dass das automatische Setup zu lange dauert

echo "🚀 Schnelles Datenbank-Setup..."

# Prüfen ob MySQL läuft
if ! docker ps | grep -q "feuerwehr_mysql"; then
    echo "❌ MySQL-Container läuft nicht!"
    echo "Starten Sie die Container mit: docker compose up -d"
    exit 1
fi

# Warten auf MySQL (kürzere Wartezeit)
echo "⏳ Warte auf MySQL (max. 2 Minuten)..."
for i in {1..6}; do
    echo "Versuch $i/6..."
    if docker exec feuerwehr_mysql mysql -u root -proot_password_2024 -e "SELECT 1;" &> /dev/null; then
        echo "✅ MySQL ist bereit!"
        break
    else
        echo "Warte 20 Sekunden..."
        sleep 20
    fi
done

# Robustes Setup mit Benutzer-Berechtigungen
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

# Einfaches Setup nur mit den wichtigsten Tabellen
echo "📊 Erstelle wichtige Tabellen..."

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

-- Admin-Benutzer erstellen
INSERT IGNORE INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) 
VALUES ('admin', 'admin@feuerwehr.local', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 1, 1);

-- Beispiel-Fahrzeuge hinzufügen
INSERT IGNORE INTO vehicles (name, type, description, capacity, is_active) VALUES
('LF 10', 'Löschfahrzeug', 'Standard-Löschfahrzeug mit 1000L Wassertank', 9, 1),
('LF 20', 'Löschfahrzeug', 'Großes Löschfahrzeug mit 2000L Wassertank', 9, 1),
('DLK 23', 'Drehleiter', 'Drehleiter mit 23m Arbeitshöhe', 3, 1);

SELECT 'Schnelles Setup abgeschlossen!' as message;
"

echo "✅ Schnelles Setup abgeschlossen!"
echo "🔍 Prüfe Tabellen..."
docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app -e "SHOW TABLES;"

echo "🎉 Sie können sich jetzt mit admin/admin123 anmelden!"
