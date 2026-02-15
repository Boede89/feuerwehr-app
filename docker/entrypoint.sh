#!/bin/bash
set -e

# uploads-Ordner für Logo-Upload anlegen und beschreibbar machen
UPLOADS_DIR="/var/www/html/uploads"
if [ ! -d "$UPLOADS_DIR" ]; then
    mkdir -p "$UPLOADS_DIR"
fi
chown -R www-data:www-data "$UPLOADS_DIR"
chmod 755 "$UPLOADS_DIR"

exec "$@"
