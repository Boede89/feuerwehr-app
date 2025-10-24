# GitHub Setup - Feuerwehr App

## Schritt-für-Schritt Anleitung zum Hochladen auf GitHub

### 1. GitHub Repository erstellen

1. Gehen Sie zu [GitHub.com](https://github.com) und melden Sie sich an
2. Klicken Sie auf das **"+"** Symbol oben rechts → **"New repository"**
3. Füllen Sie die Felder aus:
   - **Repository name**: `feuerwehr-app` (oder gewünschter Name)
   - **Description**: `Moderne Webanwendung für Feuerwehrfahrzeug-Reservierungen mit Google Calendar Integration`
   - **Visibility**: Wählen Sie `Public` oder `Private` je nach Bedarf
   - **Initialize**: ❌ **NICHT** ankreuzen (da wir bereits ein lokales Repository haben)
4. Klicken Sie auf **"Create repository"**

### 2. Lokales Repository mit GitHub verbinden

Führen Sie diese Befehle in Ihrem Terminal aus (im Projektverzeichnis):

```bash
# Remote Repository hinzufügen (ersetzen Sie USERNAME und REPO_NAME)
git remote add origin https://github.com/USERNAME/REPO_NAME.git

# Branch auf 'main' setzen (falls nötig)
git branch -M main

# Erste Push zum GitHub Repository
git push -u origin main
```

### 3. Alternative: GitHub CLI verwenden

Falls Sie GitHub CLI installiert haben:

```bash
# Repository auf GitHub erstellen und hochladen
gh repo create feuerwehr-app --public --description "Moderne Webanwendung für Feuerwehrfahrzeug-Reservierungen mit Google Calendar Integration" --source=. --remote=origin --push
```

### 4. Repository-URL notieren

Nach dem erfolgreichen Upload erhalten Sie eine URL wie:
```
https://github.com/USERNAME/feuerwehr-app
```

## Verwendung in Proxmox/Ubuntu Container

### Schnelle Installation auf einem neuen Server:

```bash
# Repository klonen
git clone https://github.com/USERNAME/feuerwehr-app.git
cd feuerwehr-app

# Installation starten
chmod +x install.sh
./install.sh
```

### Oder mit Docker Compose direkt:

```bash
# Repository klonen
git clone https://github.com/USERNAME/feuerwehr-app.git
cd feuerwehr-app

# Container starten
docker-compose up -d
```

## Repository-Struktur

Nach dem Upload enthält Ihr GitHub Repository:

```
feuerwehr-app/
├── 📁 admin/                 # Admin-Bereich
├── 📁 assets/               # CSS und statische Dateien
├── 📁 config/               # Konfigurationsdateien
├── 📁 database/             # Datenbankschema
├── 📁 docker/               # Docker-Konfiguration
├── 📁 includes/             # PHP-Funktionen
├── 📄 .gitignore           # Git Ignore-Datei
├── 📄 .htaccess            # Apache-Konfiguration
├── 📄 Dockerfile           # Docker-Container-Definition
├── 📄 README.md            # Projekt-Dokumentation
├── 📄 docker-compose.yml   # Docker Compose-Konfiguration
├── 📄 install.sh           # Installationsskript
└── 📄 *.php               # PHP-Anwendungsdateien
```

## Nützliche GitHub-Features

### 1. Releases erstellen
- Gehen Sie zu **"Releases"** → **"Create a new release"**
- Erstellen Sie Versionen wie `v1.0.0`, `v1.1.0`, etc.
- Fügen Sie Release Notes hinzu

### 2. Issues verwenden
- Erstellen Sie Issues für Bug-Reports oder Feature-Requests
- Verwenden Sie Labels für Kategorisierung

### 3. Wiki aktivieren
- Gehen Sie zu **"Settings"** → **"Features"** → **"Wiki"** aktivieren
- Erstellen Sie detaillierte Dokumentation

### 4. GitHub Pages (optional)
- Für eine öffentliche Dokumentations-Website
- Gehen Sie zu **"Settings"** → **"Pages"**

## Sicherheitshinweise

### 1. Sensitive Daten
- ❌ **NIEMALS** Passwörter oder API-Keys in den Code committen
- ✅ Verwenden Sie Environment-Variablen oder separate Config-Dateien
- ✅ Die `.gitignore` ist bereits konfiguriert

### 2. Standard-Passwörter ändern
- Ändern Sie das Admin-Passwort nach der Installation
- Verwenden Sie starke Passwörter für die Datenbank

### 3. Repository-Berechtigungen
- Überprüfen Sie die Collaborator-Berechtigungen
- Verwenden Sie Branch-Protection für wichtige Branches

## Updates und Wartung

### Code-Änderungen hochladen:
```bash
git add .
git commit -m "Beschreibung der Änderungen"
git push origin main
```

### Updates von GitHub holen:
```bash
git pull origin main
```

### Neuen Server aktualisieren:
```bash
git pull origin main
docker-compose down
docker-compose up -d
```

## Support und Dokumentation

- **README.md**: Enthält Installationsanweisungen und Features
- **Issues**: Für Bug-Reports und Feature-Requests
- **Wiki**: Für detaillierte Dokumentation (optional)

---

**Fertig!** Ihr Feuerwehr-App-Projekt ist jetzt auf GitHub verfügbar und kann einfach in jeden Container geklont werden! 🚒✨
