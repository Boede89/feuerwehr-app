<?php
/**
 * Hilfsfunktionen für Druck über CUPS oder Cloud-Drucker-URL.
 * Drucker nur aus einheit_settings (bei einheit_id > 0). Keine globalen Druckeinstellungen.
 */
function print_get_printer_config($db, $einheit_id = null) {
    $printer = '';
    $cups_server = getenv('CUPS_SERVER') ?: ($_SERVER['CUPS_SERVER'] ?? '');
    $cloud_url = '';
    $cloud_url_raw = false;
    $einheit_id = $einheit_id !== null ? (int)$einheit_id : 0;
    if ($einheit_id > 0) {
        if (!function_exists('load_settings_for_einheit')) {
            require_once dirname(__DIR__) . '/includes/einheit-settings-helper.php';
        }
        $settings = load_settings_for_einheit($db, $einheit_id);
        $printer = trim($settings['printer_destination'] ?? '');
        $cloud_url = trim($settings['printer_cloud_url'] ?? '');
        $cloud_url_raw = ($settings['printer_cloud_url_raw'] ?? '') === '1';
        $override = trim($settings['printer_cups_server'] ?? '');
        if ($override !== '') $cups_server = $override;
    }
    if ($cups_server === '' && (getenv('DOCKER') || file_exists('/.dockerenv'))) {
        $cups_server = 'host.docker.internal:631';
    }
    return ['printer' => $printer, 'cups_server' => $cups_server, 'cloud_url' => $cloud_url, 'cloud_url_raw' => $cloud_url_raw];
}

/**
 * Sendet PDF per HTTP POST an eine Cloud-Drucker-URL.
 * Unterstützt multipart/form-data (Standard) oder Raw-PDF (Content-Type: application/pdf).
 */
function print_send_pdf_via_url($pdf_content, $url, $debug = false, $raw_pdf = false) {
    $url = trim($url);
    if (empty($url) || !preg_match('#^https?://#i', $url)) {
        return ['success' => false, 'message' => 'Ungültige Cloud-Drucker-URL.'];
    }
    if ($raw_pdf) {
        $body = $pdf_content;
        $content_type = 'application/pdf';
    } else {
        $boundary = '----FeuerwehrAppPrint' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"druck.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . $pdf_content . "\r\n"
            . "--$boundary--\r\n";
        $content_type = "multipart/form-data; boundary=$boundary";
    }
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: $content_type\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ];
    $ctx = stream_context_create($opts);
    $response = @file_get_contents($url, false, $ctx);
    $http_code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\d\.\d\s+(\d+)#i', $h, $m)) {
                $http_code = (int)$m[1];
                break;
            }
        }
    }
    if ($http_code >= 200 && $http_code < 300) {
        $result = ['success' => true, 'message' => 'Druckauftrag wurde an Cloud-Drucker gesendet.'];
        if ($debug) {
            $result['debug'] = ['url' => $url, 'http_code' => $http_code];
        }
        return $result;
    }
    $result = ['success' => false, 'message' => 'Cloud-Drucker: HTTP ' . $http_code . ' – ' . ($response ? substr(trim($response), 0, 200) : 'Keine Antwort')];
    if ($debug) {
        $result['debug'] = ['url' => $url, 'http_code' => $http_code, 'response' => substr($response ?? '', 0, 500)];
    }
    return $result;
}

function print_send_pdf($pdf_content, $printer_config, $debug = false) {
    $cloud_url = trim($printer_config['cloud_url'] ?? '');
    $printer = trim($printer_config['printer'] ?? '');
    if (empty($cloud_url) && empty($printer)) {
        return ['success' => false, 'message' => 'Kein Drucker konfiguriert. Bitte Druckername oder Cloud-Drucker-URL in den Einstellungen der Einheit (Drucker-Tab) eintragen.'];
    }
    if (empty($pdf_content) || strlen($pdf_content) < 100) {
        return ['success' => false, 'message' => 'PDF konnte nicht erzeugt werden.'];
    }
    // Cloud-Drucker-URL: PDF per HTTP POST senden
    if (!empty($cloud_url)) {
        return print_send_pdf_via_url($pdf_content, $cloud_url, $debug, !empty($printer_config['cloud_url_raw']));
    }
    $printer = escapeshellarg($printer_config['printer']);
    $cups_server = $printer_config['cups_server'] ?: getenv('CUPS_SERVER') ?: ($_SERVER['CUPS_SERVER'] ?? '');
    $old_cups = $cups_server !== '' ? getenv('CUPS_SERVER') : null;
    if ($cups_server !== '') {
        putenv('CUPS_SERVER=' . $cups_server);
    }
    // PDF per stdin pipen – document-format für IPP-Cloud-Drucker (z.B. Workplace Pure) wichtig
    $cmd = 'lp -d ' . $printer . ' -o document-format=application/pdf -';
    $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptorspec, $pipes, null, null);
    if (is_resource($proc)) {
        fwrite($pipes[0], $pdf_content);
        fclose($pipes[0]);
        $output_str = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    } else {
        // Fallback: Temp-Datei
        $tmp = tempnam(sys_get_temp_dir(), 'print_') . '.pdf';
        if (file_put_contents($tmp, $pdf_content) === false) {
            return ['success' => false, 'message' => 'Temporäre Datei konnte nicht erstellt werden.'];
        }
        $file = escapeshellarg($tmp);
        $envStr = ($cups_server !== '') ? 'CUPS_SERVER=' . escapeshellarg($cups_server) . ' ' : '';
        exec($envStr . 'lp -d ' . $printer . ' -o document-format=application/pdf ' . $file . ' 2>&1', $out, $code);
        $output_str = implode("\n", $out);
        @unlink($tmp);
    }
    if ($cups_server !== '') {
        putenv($old_cups !== false ? 'CUPS_SERVER=' . $old_cups : 'CUPS_SERVER=');
    }
    if ($code !== 0) {
        $msg = 'Druck fehlgeschlagen: ' . trim($output_str);
        $result = ['success' => false, 'message' => $msg];
        if ($debug) {
            $result['debug'] = [
                'command' => $cmd,
                'exit_code' => $code,
                'output' => $output_str,
                'printer' => $printer_config['printer'],
                'cups_server' => $printer_config['cups_server'] ?: '(Standard)',
            ];
        }
        return $result;
    }
    $result = ['success' => true, 'message' => 'Druckauftrag wurde gesendet.'];
    if ($debug) {
        $result['debug'] = [
            'lp_output' => $output_str,
            'job_id' => $output_str,
            'printer' => $printer_config['printer'],
            'cups_server' => $printer_config['cups_server'] ?: '(Standard)',
        ];
    }
    return $result;
}

/**
 * CUPS-Diagnose: Warteschlangen-Status und Drucker-Info abrufen.
 */
function print_diagnose($printer_config) {
    $cups_server = $printer_config['cups_server'] ?: getenv('CUPS_SERVER') ?: ($_SERVER['CUPS_SERVER'] ?? '');
    $env = $cups_server ? 'CUPS_SERVER=' . escapeshellarg($cups_server) . ' ' : '';
    $result = ['lpstat_t' => '', 'lpq' => '', 'lpstat_v' => ''];
    @exec($env . 'lpstat -t 2>&1', $out1);
    $result['lpstat_t'] = implode("\n", $out1 ?? []);
    @exec($env . 'lpq -a 2>&1', $out2);
    if (empty($out2) || (isset($out2[0]) && strpos($out2[0], 'not found') !== false)) {
        @exec($env . 'lpstat -o 2>&1', $out2); // Fallback wenn lpq fehlt
    }
    $result['lpq'] = implode("\n", $out2 ?? []);
    @exec($env . 'lpstat -v 2>&1', $out3);
    $result['lpstat_v'] = implode("\n", $out3 ?? []);
    return $result;
}
