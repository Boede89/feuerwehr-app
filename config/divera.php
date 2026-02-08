<?php
/**
 * Divera 24/7 – API-Konfiguration für Termin-Webhook
 *
 * Access Key: In Divera 24/7 unter Verwaltung → API-Verwaltung / Zugangsdaten erzeugen.
 * Optional: config/divera.local.php anlegen (siehe config/divera.local.php.example),
 * dort den echten Key eintragen (divera.local.php wird von Git ignoriert).
 * API-Dokumentation: https://api.divera247.com/ (v2/event – Termin erstellen)
 */
$divera_config = [
    'api_base_url' => 'https://app.divera247.com',
    'access_key'   => '', // z.B. 'Ihr-Access-Key-von-Divera'
];

// Lokale Overrides (z.B. config/divera.local.php mit $divera_config['access_key'] = '...';)
if (is_file(__DIR__ . '/divera.local.php')) {
    include __DIR__ . '/divera.local.php';
}
