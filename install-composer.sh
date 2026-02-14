#!/bin/bash
#
# Composer in Linux-Container installieren
#
# HINWEIS: Das Dockerfile enthält bereits Composer. Falls Sie Docker nutzen,
# reicht oft: docker exec -it feuerwehr_web composer install
#
# Dieses Skript ist nützlich, wenn Composer fehlt (z.B. anderer Container).
#
# Ausführung:
#   chmod +x install-composer.sh
#   ./install-composer.sh
#
# Im Docker-Container:
#   docker exec -it feuerwehr_web bash -c "cd /var/www/html && ./install-composer.sh"
#

set -e

echo "=== Composer Installation ==="

# Prüfen ob Composer bereits installiert ist
if command -v composer &> /dev/null; then
    echo "Composer ist bereits installiert: $(composer --version)"
    echo ""
    echo "Führe 'composer install' aus..."
    composer install --no-interaction 2>/dev/null || true
    exit 0
fi

# Prüfen ob PHP vorhanden ist
if ! command -v php &> /dev/null; then
    echo "Fehler: PHP ist nicht installiert. Bitte zuerst PHP installieren."
    exit 1
fi

# Composer herunterladen
echo "Lade Composer herunter..."
EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
    echo "Fehler: Composer-Checksum stimmt nicht überein!"
    rm -f composer-setup.php
    exit 1
fi

# Composer installieren – zuerst global, sonst lokal
INSTALL_DIR=""
if [ -w /usr/local/bin ]; then
    echo "Installiere Composer nach /usr/local/bin/composer..."
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
elif [ -w /usr/bin ]; then
    echo "Installiere Composer nach /usr/bin/composer..."
    php composer-setup.php --install-dir=/usr/bin --filename=composer
else
    echo "Installiere Composer nach $(pwd)/composer.phar..."
    php composer-setup.php --install-dir="$(pwd)" --filename=composer.phar
    chmod +x composer.phar
    echo "Verwende: php composer.phar install"
fi
rm -f composer-setup.php

# Prüfen und ggf. composer install ausführen
if command -v composer &> /dev/null; then
    echo "✓ Composer erfolgreich installiert: $(composer --version)"
    echo ""
    echo "Führe 'composer install' aus..."
    composer install --no-interaction 2>/dev/null || echo "Bitte manuell: composer install"
elif [ -f "$(pwd)/composer.phar" ]; then
    echo "✓ Composer erfolgreich installiert: $(php composer.phar --version)"
    echo ""
    echo "Führe 'composer install' aus..."
    php composer.phar install --no-interaction 2>/dev/null || echo "Bitte manuell: php composer.phar install"
else
    echo "Fehler: Composer konnte nicht installiert werden."
    exit 1
fi
