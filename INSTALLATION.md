# 🚒 Feuerwehr App - Installationsanleitung

Eine detaillierte Anleitung zur Installation der Feuerwehr-App in einem neuen Container.

## 📋 Systemanforderungen

- **Betriebssystem**: Ubuntu 20.04+ / Debian 11+ / Proxmox Container
- **RAM**: Mindestens 2GB (empfohlen: 4GB)
- **Speicherplatz**: Mindestens 10GB freier Speicherplatz
- **Netzwerk**: Internetverbindung für Docker-Images und Updates

## 🚀 Schnellinstallation (Empfohlen)

### Schritt 1: Repository klonen

```bash
# Repository klonen
git clone <ihr-repository-url>
cd feuerwehr-app

# Berechtigungen setzen
chmod +x install.sh
```

### Schritt 2: Automatische Installation

```bash
# Installationsskript ausführen
./install.sh
```

Das Skript installiert automatisch:
- Docker und Docker Compose
- Startet alle Container
- Konfiguriert die Datenbank
- Setzt alle notwendigen Berechtigungen

### Schritt 3: Installation überprüfen

Nach der Installation sollten folgende Services verfügbar sein:

- **Webanwendung**: http://localhost:8081
- **phpMyAdmin**: http://localhost:8080
- **MySQL**: Port 3306

**Standard-Admin-Zugang:**
- Benutzername: `admin`
- Passwort: `admin123`

## 🔧 Manuelle Installation

Falls Sie die Installation manuell durchführen möchten:

### Schritt 1: System vorbereiten

```bash
# System aktualisieren
sudo apt update && sudo apt upgrade -y

# Git installieren
sudo apt install git -y
```

### Schritt 2: Docker installieren

```bash
# Docker installieren
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Docker Compose installieren
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# Neustart erforderlich (oder neue Shell-Session)
newgrp docker
```

### Schritt 3: Projekt klonen und konfigurieren

```bash
# Repository klonen
git clone <ihr-repository-url>
cd feuerwehr-app

# Berechtigungen setzen
chmod 755 -R .
```

### Schritt 4: Container starten

```bash
# Container im Hintergrund starten
docker-compose up -d

# Status überprüfen
docker-compose ps
```

### Schritt 5: Installation überprüfen

```bash
# Container-Logs anzeigen
docker-compose logs

# Datenbank-Verbindung testen
docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password -e "SELECT 1;" feuerwehr_app
```

## ⚙️ Konfiguration nach der Installation

### 1. Admin-Passwort ändern

**WICHTIG**: Ändern Sie das Standard-Passwort sofort!

1. Melden Sie sich an: http://localhost:8081
2. Benutzername: `admin`, Passwort: `admin123`
3. Gehen Sie zu "Einstellungen" → "Benutzerverwaltung"
4. Ändern Sie Ihr Passwort

### 2. SMTP für E-Mail-Benachrichtigungen konfigurieren

1. Gehen Sie zu "Einstellungen" → "E-Mail-Einstellungen"
2. Konfigurieren Sie Ihre SMTP-Daten:
   - **SMTP Host**: z.B. `smtp.gmail.com`
   - **SMTP Port**: `587` (für TLS) oder `465` (für SSL)
   - **Benutzername**: Ihre E-Mail-Adresse
   - **Passwort**: Ihr E-Mail-Passwort oder App-Passwort
   - **Verschlüsselung**: TLS oder SSL
3. Testen Sie die E-Mail-Funktion

### 3. Google Calendar Integration (Optional)

1. Gehen Sie zu [Google Cloud Console](https://console.developers.google.com/)
2. Erstellen Sie ein neues Projekt oder wählen Sie ein bestehendes
3. Aktivieren Sie die "Google Calendar API"
4. Erstellen Sie einen API-Schlüssel
5. In der App: "Einstellungen" → "Google Calendar"
6. Tragen Sie den API-Schlüssel ein
7. Optional: Spezifische Kalender-ID eingeben

### 4. Fahrzeuge hinzufügen

1. Gehen Sie zu "Fahrzeuge verwalten"
2. Klicken Sie auf "Neues Fahrzeug hinzufügen"
3. Geben Sie Name, Typ und Beschreibung ein
4. Speichern Sie das Fahrzeug

## 🔍 Troubleshooting

### Container starten nicht

```bash
# Container-Status prüfen
docker-compose ps

# Logs anzeigen
docker-compose logs

# Container neu starten
docker-compose restart
```

### Datenbank-Verbindungsfehler

```bash
# MySQL-Container-Logs prüfen
docker-compose logs mysql

# Datenbank-Container neu starten
docker-compose restart mysql

# Datenbank-Verbindung testen
docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password -e "SHOW DATABASES;"
```

### Port-Konflikte

Falls Ports bereits belegt sind, ändern Sie die Ports in `docker-compose.yml`:

```yaml
ports:
  - "8082:80"  # Webanwendung auf Port 8082
  - "8081:80"  # phpMyAdmin auf Port 8081
  - "3307:3306"  # MySQL auf Port 3307
```

### Berechtigungsprobleme

```bash
# Berechtigungen korrigieren
sudo chown -R $USER:$USER .
chmod 755 -R .
```

## 📊 Container-Verwaltung

### Container stoppen

```bash
# Alle Container stoppen
docker-compose down

# Container und Volumes löschen
docker-compose down -v
```

### Container neu starten

```bash
# Container neu starten
docker-compose restart

# Einzelnen Service neu starten
docker-compose restart web
docker-compose restart mysql
```

### Logs anzeigen

```bash
# Alle Logs
docker-compose logs

# Live-Logs verfolgen
docker-compose logs -f

# Spezifischer Service
docker-compose logs web
docker-compose logs mysql
```

### Datenbank-Backup

```bash
# Backup erstellen
docker exec feuerwehr_mysql mysqldump -u feuerwehr_user -pfeuerwehr_password feuerwehr_app > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup wiederherstellen
docker exec -i feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app < backup.sql
```

## 🔒 Sicherheitshinweise

1. **Passwort ändern**: Ändern Sie das Standard-Admin-Passwort sofort
2. **Firewall**: Konfigurieren Sie eine Firewall für den Container
3. **Updates**: Halten Sie das System und die Container aktuell
4. **Backups**: Erstellen Sie regelmäßige Datenbank-Backups
5. **SSL**: Verwenden Sie HTTPS in der Produktion

## 📱 Erste Schritte nach der Installation

1. **Admin-Anmeldung**: Melden Sie sich mit `admin`/`admin123` an
2. **Passwort ändern**: Ändern Sie das Admin-Passwort
3. **SMTP konfigurieren**: Richten Sie E-Mail-Benachrichtigungen ein
4. **Fahrzeuge hinzufügen**: Fügen Sie Ihre Feuerwehrfahrzeuge hinzu
5. **Test-Reservierung**: Erstellen Sie eine Test-Reservierung
6. **Google Calendar**: Konfigurieren Sie die Kalender-Integration (optional)

## 🆘 Support

Bei Problemen:

1. Prüfen Sie die Container-Logs: `docker-compose logs`
2. Überprüfen Sie die Netzwerk-Verbindung
3. Stellen Sie sicher, dass alle Ports verfügbar sind
4. Kontaktieren Sie den Systemadministrator

---

**Wichtiger Hinweis**: Diese Anleitung ist für eine lokale/Test-Installation gedacht. Für Produktionsumgebungen sind zusätzliche Sicherheitsmaßnahmen erforderlich.
