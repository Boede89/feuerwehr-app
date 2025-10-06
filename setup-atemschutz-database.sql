-- Atemschutztauglichkeits-Überwachung Datenbank-Setup
-- Führe dieses Script aus, um die erforderlichen Tabellen und Berechtigungen zu erstellen

-- Tabelle für Atemschutzgeräteträger erstellen
CREATE TABLE IF NOT EXISTS atemschutz_traeger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    geburtsdatum DATE NOT NULL,
    alter_jahre INT NOT NULL,
    strecke_am DATE NULL,
    strecke_bis DATE NULL,
    g263_am DATE NULL,
    g263_bis DATE NULL,
    uebung_am DATE NULL,
    uebung_bis DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Berechtigung für Atemschutztauglichkeit zu users-Tabelle hinzufügen
ALTER TABLE users ADD COLUMN IF NOT EXISTS can_atemschutz BOOLEAN DEFAULT FALSE;

-- Erfolgsmeldung
SELECT 'Atemschutztauglichkeits-System erfolgreich eingerichtet!' as status;
