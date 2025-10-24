#!/bin/bash

# Feuerwehr App - Installationsskript
# Für Ubuntu/Debian Systeme

echo "🚒 Feuerwehr App - Installation"
echo "================================"

# Farben definieren
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Funktion für farbige Ausgabe
print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

# System-Update
echo "📦 System wird aktualisiert..."
sudo apt update && sudo apt upgrade -y
print_status "System aktualisiert"

# Docker installieren
if ! command -v docker &> /dev/null; then
    echo "🐳 Docker wird installiert..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sudo sh get-docker.sh
    sudo usermod -aG docker $USER
    print_status "Docker installiert"
else
    print_status "Docker bereits installiert"
fi

# Docker Compose installieren
if ! command -v docker-compose &> /dev/null; then
    echo "🐳 Docker Compose wird installiert..."
    sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    sudo chmod +x /usr/local/bin/docker-compose
    print_status "Docker Compose installiert"
else
    print_status "Docker Compose bereits installiert"
fi

# Git installieren (falls nicht vorhanden)
if ! command -v git &> /dev/null; then
    echo "📥 Git wird installiert..."
    sudo apt install git -y
    print_status "Git installiert"
else
    print_status "Git bereits installiert"
fi

# Berechtigungen setzen
echo "🔐 Berechtigungen werden gesetzt..."
chmod +x install.sh
chmod 755 -R .
print_status "Berechtigungen gesetzt"

# Docker Container starten
echo "🚀 Docker Container werden gestartet..."
docker-compose up -d
print_status "Docker Container gestartet"

# Warten bis Container bereit sind
echo "⏳ Warten auf Container..."
sleep 30

# Container-Status prüfen
echo "🔍 Container-Status wird geprüft..."
if docker-compose ps | grep -q "Up"; then
    print_status "Container laufen erfolgreich"
else
    print_error "Einige Container sind nicht gestartet"
    docker-compose ps
    exit 1
fi

# Datenbank-Verbindung testen
echo "🗄️ Datenbank-Verbindung wird getestet..."
sleep 10
if docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password -e "SELECT 1;" feuerwehr_app &> /dev/null; then
    print_status "Datenbank-Verbindung erfolgreich"
else
    print_warning "Datenbank-Verbindung fehlgeschlagen - Container brauchen möglicherweise mehr Zeit"
fi

# Installation abgeschlossen
echo ""
echo "🎉 Installation abgeschlossen!"
echo "================================"
echo ""
echo "📱 Webanwendung: http://localhost"
echo "🗄️ phpMyAdmin: http://localhost:8080"
echo ""
echo "👤 Standard-Admin-Zugang:"
echo "   Benutzername: admin"
echo "   Passwort: admin123"
echo ""
echo "⚠️  WICHTIG: Ändern Sie das Admin-Passwort nach der ersten Anmeldung!"
echo ""
echo "📋 Nächste Schritte:"
echo "1. Melden Sie sich als Admin an"
echo "2. Gehen Sie zu 'Einstellungen'"
echo "3. Konfigurieren Sie SMTP für E-Mail-Benachrichtigungen"
echo "4. Konfigurieren Sie Google Calendar API (optional)"
echo "5. Fügen Sie Fahrzeuge hinzu"
echo ""
echo "📚 Weitere Informationen finden Sie in der README.md"
echo ""
print_status "Installation erfolgreich abgeschlossen!"
