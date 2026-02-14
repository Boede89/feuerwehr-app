# Echte PDF-Generierung einrichten

Damit Anwesenheitslisten als echte PDF-Dateien (nicht HTML) heruntergeladen werden, ist eine der folgenden Optionen nötig:

## Option 1: Dompdf (empfohlen)

### Mit Docker (Composer ist bereits im Container)

```bash
docker exec -it feuerwehr_web composer install
```

### Ohne Docker

1. Composer installieren: `./install-composer.sh` (Linux) oder [getcomposer.org](https://getcomposer.org/download/)
2. Im Projektverzeichnis: `composer install`

## Option 2: TCPDF (ohne Composer)

1. Im Browser aufrufen: `install-tcpdf.php`
2. Das Skript lädt und installiert TCPDF automatisch

## Option 3: wkhtmltopdf

Falls wkhtmltopdf auf dem Server installiert ist, wird es automatisch verwendet.
