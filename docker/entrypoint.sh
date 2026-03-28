#!/bin/bash
set -e

# uploads (Bind-Mount vom Host): bei Container-Start sicher beschreibbar für www-data
UPLOADS_DIR="/var/www/html/uploads"
mkdir -p "$UPLOADS_DIR/bericht_anhaenge_draft" "$UPLOADS_DIR/bericht_anhaenge"
chown -R www-data:www-data "$UPLOADS_DIR"
chmod -R 775 "$UPLOADS_DIR"

exec "$@"
