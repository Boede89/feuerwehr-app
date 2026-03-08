<?php
/**
 * Hilfsfunktionen für Druck über CUPS.
 * Drucker nur aus einheit_settings (bei einheit_id > 0). Keine globalen Druckeinstellungen.
 */
function print_get_printer_config($db, $einheit_id = null) {
    $printer = '';
    $cups_server = getenv('CUPS_SERVER') ?: ($_SERVER['CUPS_SERVER'] ?? '');
    $einheit_id = $einheit_id !== null ? (int)$einheit_id : 0;
    if ($einheit_id > 0) {
        if (!function_exists('load_settings_for_einheit')) {
            require_once dirname(__DIR__) . '/includes/einheit-settings-helper.php';
        }
        $settings = load_settings_for_einheit($db, $einheit_id);
        $printer = trim($settings['printer_destination'] ?? '');
        $override = trim($settings['printer_cups_server'] ?? '');
        if ($override !== '') $cups_server = $override;
    }
    if ($cups_server === '' && (getenv('DOCKER') || file_exists('/.dockerenv'))) {
        $cups_server = 'host.docker.internal:631';
    }
    return ['printer' => $printer, 'cups_server' => $cups_server];
}

function print_send_pdf($pdf_content, $printer_config, $debug = false) {
    if (empty($printer_config['printer'])) {
        return ['success' => false, 'message' => 'Kein Drucker konfiguriert. Bitte in den Einstellungen der Einheit (Drucker-Tab) einen Druckernamen eintragen.'];
    }
    if (empty($pdf_content) || strlen($pdf_content) < 100) {
        return ['success' => false, 'message' => 'PDF konnte nicht erzeugt werden.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'print_') . '.pdf';
    if (file_put_contents($tmp, $pdf_content) === false) {
        return ['success' => false, 'message' => 'Temporäre Datei konnte nicht erstellt werden.'];
    }
    $printer = escapeshellarg($printer_config['printer']);
    $file = escapeshellarg($tmp);
    $cups_server = $printer_config['cups_server'] ?: getenv('CUPS_SERVER') ?: ($_SERVER['CUPS_SERVER'] ?? '');
    $env = '';
    if ($cups_server !== '') {
        $env = 'CUPS_SERVER=' . escapeshellarg($cups_server) . ' ';
    }
    $cmd = $env . 'lp -d ' . $printer . ' ' . $file . ' 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    @unlink($tmp);
    if ($code !== 0) {
        $msg = 'Druck fehlgeschlagen: ' . implode(' ', $out);
        $result = ['success' => false, 'message' => $msg];
        if ($debug) {
            $result['debug'] = [
                'command' => $cmd,
                'exit_code' => $code,
                'output' => implode("\n", $out),
                'printer' => $printer_config['printer'],
                'cups_server' => $printer_config['cups_server'] ?: '(Standard)',
            ];
        }
        return $result;
    }
    return ['success' => true, 'message' => 'Druckauftrag wurde gesendet.'];
}
