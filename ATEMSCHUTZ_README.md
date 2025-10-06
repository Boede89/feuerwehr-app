# Atemschutztauglichkeits-Überwachung

## Übersicht
Die Atemschutztauglichkeits-Überwachung ermöglicht es, Atemschutzgeräteträger zu verwalten und deren Tauglichkeitsnachweise zu überwachen.

## Funktionen

### 📋 Geräteträger-Verwaltung
- **Hinzufügen**: Neue Atemschutzgeräteträger anlegen
- **Bearbeiten**: Bestehende Geräteträger aktualisieren
- **Löschen**: Geräteträger entfernen
- **Übersicht**: Alle Geräteträger in einer übersichtlichen Tabelle

### 📊 Automatische Berechnungen
- **Alter**: Wird automatisch aus dem Geburtsdatum berechnet
- **Bis-Daten**: Werden automatisch basierend auf dem Am-Datum berechnet:
  - **Strecke**: 1 Jahr nach Am-Datum
  - **Übung/Einsatz**: 1 Jahr nach Am-Datum
  - **G26.3**: 3 Jahre (unter 50 Jahre) oder 1 Jahr (50+ Jahre)

### 🎨 Status-Anzeige
- 🔴 **Abgelaufen**: Zertifikat ist bereits abgelaufen
- 🟡 **Läuft bald ab**: Zertifikat läuft in den nächsten 30 Tagen ab
- 🔵 **Gültig**: Zertifikat ist noch gültig
- ⚪ **Nicht angegeben**: Kein Datum hinterlegt

### 📱 Mobile Optimierung
- Responsive Design für Smartphones und Tablets
- Touch-freundliche Bedienelemente
- Optimierte Tabellenansicht für kleine Bildschirme

## Installation

### 1. Datenbank-Setup
```sql
-- Führe das SQL-Script aus
source setup-atemschutz-database.sql
```

### 2. Berechtigungen vergeben
1. Gehe zu **Admin → Benutzer**
2. Wähle einen Benutzer aus
3. Aktiviere **"Atemschutztauglichkeits-Überwachung"**
4. Speichere die Änderungen

### 3. Zugriff
- **Hauptseite**: Link erscheint nur für berechtigte Benutzer
- **Admin-Bereich**: Direkter Zugriff über die Sidebar

## Verwendung

### Geräteträger hinzufügen
1. Klicke auf **"Neuer Geräteträger"**
2. Fülle die Pflichtfelder aus:
   - Vorname
   - Nachname
   - Geburtsdatum
3. Optional: E-Mail-Adresse
4. Optional: Am-Daten für Strecke, G26.3, Übung/Einsatz
5. Klicke **"Speichern"**

### Geräteträger bearbeiten
1. Klicke auf das **Bearbeiten-Symbol** (Stift) bei einem Geräteträger
2. Ändere die gewünschten Daten
3. Klicke **"Speichern"**

### Status überwachen
- Die **Status-Karten** zeigen eine Übersicht über alle Zertifikate
- **Farbkodierte Badges** in der Tabelle zeigen den Status jedes Zertifikats
- **Automatische Aktualisierung** der Bis-Daten bei Änderungen

## Technische Details

### Datenbank-Schema
```sql
atemschutz_traeger:
- id (INT, PRIMARY KEY)
- vorname (VARCHAR(100))
- nachname (VARCHAR(100))
- email (VARCHAR(255), NULL)
- geburtsdatum (DATE)
- alter_jahre (INT)
- strecke_am (DATE, NULL)
- strecke_bis (DATE, NULL)
- g263_am (DATE, NULL)
- g263_bis (DATE, NULL)
- uebung_am (DATE, NULL)
- uebung_bis (DATE, NULL)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

### Berechtigungen
- `can_atemschutz`: Zugriff auf die Atemschutztauglichkeits-Überwachung

## Support
Bei Fragen oder Problemen wende dich an den Systemadministrator.
