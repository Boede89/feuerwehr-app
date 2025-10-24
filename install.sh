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

# System-Update und notwendige Pakete installieren
echo "📦 System wird aktualisiert..."
sudo apt update && sudo apt upgrade -y
sudo apt install -y curl wget ca-certificates gnupg lsb-release
print_status "System aktualisiert"

# Docker installieren
if ! command -v docker &> /dev/null; then
    echo "🐳 Docker wird installiert..."
    
    # Docker GPG Key hinzufügen
    sudo mkdir -p /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    
    # Docker Repository hinzufügen
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
    
    # Docker installieren
    sudo apt update
    sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    
    # Docker Service starten
    sudo systemctl start docker
    sudo systemctl enable docker
    
    # Benutzer zur Docker-Gruppe hinzufügen
    sudo usermod -aG docker $USER
    
    print_status "Docker installiert"
else
    print_status "Docker bereits installiert"
fi

# Docker Compose installieren (falls nicht vorhanden)
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "🐳 Docker Compose wird installiert..."
    
    # Neueste Version von Docker Compose herunterladen
    COMPOSE_VERSION=$(curl -s https://api.github.com/repos/docker/compose/releases/latest | grep 'tag_name' | cut -d\" -f4)
    sudo curl -L "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
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

# Bestehende Container und Volumes löschen (für saubere Installation)
echo "🧹 Bestehende Container werden entfernt..."
if command -v docker-compose &> /dev/null; then
    docker-compose down -v 2>/dev/null || true
elif docker compose version &> /dev/null; then
    docker compose down -v 2>/dev/null || true
fi

# Container starten
if command -v docker-compose &> /dev/null; then
    docker-compose up -d
    COMPOSE_CMD="docker-compose"
elif docker compose version &> /dev/null; then
    docker compose up -d
    COMPOSE_CMD="docker compose"
else
    print_error "Docker Compose nicht gefunden!"
    exit 1
fi

print_status "Docker Container gestartet"

# Warten bis Container bereit sind
echo "⏳ Warten auf Container-Initialisierung..."
sleep 45

# Container-Status prüfen
echo "🔍 Container-Status wird geprüft..."
if $COMPOSE_CMD ps | grep -q "Up"; then
    print_status "Container laufen erfolgreich"
else
    print_error "Einige Container sind nicht gestartet"
    $COMPOSE_CMD ps
    print_warning "Versuche Container-Neustart..."
    $COMPOSE_CMD restart
    sleep 30
    if $COMPOSE_CMD ps | grep -q "Up"; then
        print_status "Container nach Neustart erfolgreich"
    else
        print_error "Container-Start fehlgeschlagen"
        $COMPOSE_CMD logs
        exit 1
    fi
fi

# Datenbank-Verbindung testen
echo "🗄️ Datenbank-Verbindung wird getestet..."
sleep 10

# Mehrfache Versuche für Datenbank-Verbindung
DB_CONNECTED=false
for i in {1..5}; do
    echo "Versuch $i/5: Datenbank-Verbindung testen..."
    if docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password -e "SELECT 1;" feuerwehr_app &> /dev/null; then
        print_status "Datenbank-Verbindung erfolgreich"
        DB_CONNECTED=true
        break
    else
        echo "Versuch $i fehlgeschlagen, warte 10 Sekunden..."
        sleep 10
    fi
done

if [ "$DB_CONNECTED" = false ]; then
    print_warning "Datenbank-Verbindung fehlgeschlagen - Container brauchen möglicherweise mehr Zeit"
    print_warning "Versuchen Sie es später mit: docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password feuerwehr_app"
else
    # Datenbank-Setup ausführen
    echo "🔧 Führe Datenbank-Setup aus..."
    chmod +x setup-database.sh
    ./setup-database.sh
fi

# Installation abgeschlossen
echo ""
echo "🎉 Installation abgeschlossen!"
echo "================================"
echo ""
echo "📱 Webanwendung: http://localhost:8081"
echo "🗄️ phpMyAdmin: http://localhost:8080"
echo ""
echo "👤 Standard-Admin-Zugang:"
echo "   Benutzername: admin"
echo "   Passwort: admin123"
echo ""
echo "⚠️  WICHTIGE HINWEISE:"
echo "1. Ändern Sie das Admin-Passwort nach der ersten Anmeldung!"
echo "2. Falls Docker-Befehle nicht funktionieren, melden Sie sich neu an oder führen Sie aus:"
echo "   newgrp docker"
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
