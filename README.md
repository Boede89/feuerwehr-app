# Feuerwehr App

Eine moderne Webanwendung für die Verwaltung von Feuerwehrfahrzeug-Reservierungen mit Google Calendar Integration.

## Features

- 🚒 **Fahrzeugreservierung**: Einfache Reservierung von Feuerwehrfahrzeugen
- 📅 **Google Calendar Integration**: Automatische Termineinträge bei genehmigten Reservierungen
- 📧 **E-Mail-Benachrichtigungen**: Automatische Benachrichtigungen für Anträge und Entscheidungen
- 👥 **Benutzerverwaltung**: Admin-Bereich für Benutzer- und Fahrzeugverwaltung
- 📱 **Responsive Design**: Optimiert für Desktop und mobile Geräte
- 🔒 **Sicherheit**: CSRF-Schutz und sichere Authentifizierung
- ⚡ **Kollisionsprüfung**: Automatische Prüfung auf Terminüberschneidungen

## Systemanforderungen

- Docker & Docker Compose
- Proxmox/Ubuntu Container
- Mindestens 2GB RAM
- 10GB freier Speicherplatz

## Installation

### 1. Repository klonen

```bash
git clone <repository-url>
cd feuerwehr-app
```

### 2. Docker Container starten

```bash
docker-compose up -d
```

### 3. Datenbank initialisieren

Die Datenbank wird automatisch mit dem Schema initialisiert. Der Standard-Admin-Benutzer ist:

- **Benutzername**: admin
- **Passwort**: admin123

### 4. Anwendung aufrufen

- **Webanwendung**: http://localhost
- **phpMyAdmin**: http://localhost:8080

## Konfiguration

### SMTP Einstellungen

1. Melden Sie sich als Admin an
2. Gehen Sie zu "Einstellungen"
3. Konfigurieren Sie die SMTP-Einstellungen:
   - SMTP Host
   - SMTP Port (meist 587 für TLS)
   - Benutzername und Passwort
   - Verschlüsselung (TLS/SSL)

### Google Calendar Integration

1. Gehen Sie zu [Google Cloud Console](https://console.developers.google.com/)
2. Erstellen Sie ein neues Projekt oder wählen Sie ein bestehendes
3. Aktivieren Sie die Google Calendar API
4. Erstellen Sie einen API-Schlüssel
5. Tragen Sie den API-Schlüssel in den Einstellungen ein
6. Optional: Geben Sie eine spezifische Kalender-ID ein (Standard: primary)

## Verwendung

### Für Benutzer

1. **Fahrzeug reservieren**:
   - Startseite → "Fahrzeug reservieren"
   - Fahrzeug auswählen
   - Daten eingeben (Name, E-Mail, Grund, Zeitraum)
   - Antrag absenden

2. **Status prüfen**:
   - Sie erhalten E-Mail-Benachrichtigungen über den Status Ihres Antrags

### Für Administratoren

1. **Anmelden**:
   - Benutzername: admin
   - Passwort: admin123

2. **Dashboard**:
   - Übersicht über alle Anträge und Statistiken

3. **Reservierungen verwalten**:
   - Anträge genehmigen oder ablehnen
   - Bei Ablehnung Grund angeben

4. **Fahrzeuge verwalten**:
   - Neue Fahrzeuge hinzufügen
   - Bestehende Fahrzeuge bearbeiten oder löschen

5. **Einstellungen**:
   - SMTP-Konfiguration
   - Google Calendar API
   - App-Einstellungen

## Datenbankstruktur

### Haupttabellen

- `users` - Benutzer (Admins)
- `vehicles` - Fahrzeuge
- `reservations` - Reservierungen
- `settings` - App-Einstellungen
- `calendar_events` - Google Calendar Events
- `activity_log` - Aktivitätsprotokoll

## Sicherheit

- CSRF-Token für alle Formulare
- Passwort-Hashing mit PHP password_hash()
- SQL-Injection-Schutz durch Prepared Statements
- XSS-Schutz durch htmlspecialchars()
- Sichere Session-Verwaltung

## Entwicklung

### Lokale Entwicklung

```bash
# Container starten
docker-compose up -d

# Logs anzeigen
docker-compose logs -f

# Container stoppen
docker-compose down
```

### Datenbank-Backup

```bash
# Backup erstellen
docker exec feuerwehr_mysql mysqldump -u feuerwehr_user -p feuerwehr_app > backup.sql

# Backup wiederherstellen
docker exec -i feuerwehr_mysql mysql -u feuerwehr_user -p feuerwehr_app < backup.sql
```

## Troubleshooting

### Häufige Probleme

1. **E-Mails werden nicht versendet**:
   - SMTP-Einstellungen überprüfen
   - Test-E-Mail senden
   - Firewall-Einstellungen prüfen

2. **Google Calendar funktioniert nicht**:
   - API-Schlüssel überprüfen
   - Kalender-Berechtigungen prüfen
   - Internetverbindung testen

3. **Datenbank-Verbindungsfehler**:
   - Container-Status prüfen: `docker-compose ps`
   - Logs anzeigen: `docker-compose logs mysql`

### Logs anzeigen

```bash
# Alle Logs
docker-compose logs

# Spezifischer Service
docker-compose logs web
docker-compose logs mysql
```

## Lizenz

Dieses Projekt ist für den internen Gebrauch der Feuerwehr bestimmt.

## Support

Bei Problemen oder Fragen wenden Sie sich an den Systemadministrator.

---

**Wichtiger Hinweis**: Ändern Sie das Standard-Admin-Passwort nach der ersten Anmeldung!
