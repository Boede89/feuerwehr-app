<?php
/**
 * Hilfsfunktionen für Druck über CUPS oder Cloud-Drucker-URL.
 * Drucker aus einheit_settings: printer_list (JSON) oder Legacy printer_destination/printer_cloud_url.
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
        $override = trim($settings['printer_cups_server'] ?? '');
        if ($override !== '') $cups_server = $override;

        $list_raw = trim($settings['printer_list'] ?? '');
        if ($list_raw !== '') {
            $list = json_decode($list_raw, true);
            if (is_array($list)) {
                $default = null;
                foreach ($list as $p) {
                    if (!empty($p['is_default'])) {
                        $default = $p;
                        break;
                    }
                }
                if ($default === null && count($list) > 0) {
                    $default = $list[0];
                }
                if ($default !== null) {
                    if (($default['type'] ?? '') === 'cloud') {
                        $cloud_url = trim($default['cloud_url'] ?? '');
                        $cloud_url_raw = !empty($default['cloud_raw']);
                    } else {
                        $printer = trim($default['cups_name'] ?? '');
                    }
                }
            }
        }
        if ($printer === '' && $cloud_url === '') {
            $printer = trim($settings['printer_destination'] ?? '');
            $cloud_url = trim($settings['printer_cloud_url'] ?? '');
            $cloud_url_raw = ($settings['printer_cloud_url_raw'] ?? '') === '1';
        }
    }
    if ($cups_server === '' && (getenv('DOCKER') || file_exists('/.dockerenv'))) {
        $cups_server = 'host.docker.internal:631';
    }
    return ['printer' => $printer, 'cups_server' => $cups_server, 'cloud_url' => $cloud_url, 'cloud_url_raw' => $cloud_url_raw];
}

/**
 * Lädt die Druckerliste für eine Einheit.
 */
function print_get_printer_list($db, $einheit_id) {
    $list = [];
    if ($einheit_id <= 0) return $list;
    if (!function_exists('load_settings_for_einheit')) {
        require_once dirname(__DIR__) . '/includes/einheit-settings-helper.php';
    }
    $settings = load_settings_for_einheit($db, $einheit_id);
    $raw = trim($settings['printer_list'] ?? '');
    if ($raw !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $list = $dec;
    }
    if (empty($list) && (trim($settings['printer_destination'] ?? '') !== '' || trim($settings['printer_cloud_url'] ?? '') !== '')) {
        $p = ['id' => 'legacy', 'name' => 'Legacy-Drucker', 'type' => 'cups', 'cups_name' => trim($settings['printer_destination'] ?? ''), 'cups_uri' => '', 'cups_model' => 'everywhere', 'is_default' => true];
        if (trim($settings['printer_cloud_url'] ?? '') !== '') {
            $p = ['id' => 'legacy', 'name' => 'Cloud-Drucker', 'type' => 'cloud', 'cloud_url' => trim($settings['printer_cloud_url'] ?? ''), 'cloud_raw' => ($settings['printer_cloud_url_raw'] ?? '') === '1', 'is_default' => true];
        }
        $list = [$p];
    }
    return $list;
}

/**
 * Fügt Benutzername und Passwort in eine IPP/HTTPS-URI ein.
 * Format: scheme://user:password@host/path
 */
function print_inject_ipp_credentials($uri, $user, $password) {
    if ($user === '' && $password === '') return $uri;
    if (preg_match('#^(https?|ipp)://([^/]+)(/.*)?$#i', $uri, $m)) {
        $scheme = $m[1];
        $rest = $m[2];
        $path = $m[3] ?? '';
        if (strpos($rest, '@') !== false) {
            return $uri;
        }
        $cred = rawurlencode($user) . ':' . rawurlencode($password);
        return $scheme . '://' . $cred . '@' . $rest . $path;
    }
    return $uri;
}

/**
 * Registriert einen CUPS-Drucker per lpadmin (remote möglich mit CUPS_SERVER).
 * Hinweis: Von Docker aus schlägt lpadmin oft fehl („Bad file descriptor“).
 * Dann den Befehl auf dem Host ausführen.
 */
function print_register_cups_printer($name, $uri, $model, $cups_server = '') {
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    if ($name === '' || strlen($uri) < 5) {
        return ['success' => false, 'message' => 'Ungültiger Druckername oder URI.'];
    }
    $model = $model ?: 'everywhere';
    $cmd = 'lpadmin -p ' . escapeshellarg($name) . ' -E -v ' . escapeshellarg($uri) . ' -m ' . escapeshellarg($model) . ' 2>&1';

    $old_cups = getenv('CUPS_SERVER');
    if ($cups_server !== '') {
        putenv('CUPS_SERVER=' . $cups_server);
    }
    $out = [];
    exec($cmd, $out, $code);
    if ($cups_server !== '') {
        putenv($old_cups !== false ? 'CUPS_SERVER=' . $old_cups : 'CUPS_SERVER=');
    }

    $output = implode("\n", $out);
    $host_cmd = ($cups_server ? "CUPS_SERVER=" . escapeshellarg($cups_server) . " " : "") . $cmd;

    if ($code !== 0) {
        return [
            'success' => false,
            'message' => 'lpadmin fehlgeschlagen: ' . trim($output) . ' (Von Docker aus oft nicht möglich – Befehl auf dem Host ausführen.)',
            'lpadmin_cmd' => $host_cmd,
        ];
    }
    return ['success' => true, 'message' => 'Drucker registriert.', 'lpadmin_cmd' => $host_cmd];
}

/**
 * Sendet PDF per HTTP POST an eine Cloud-Drucker-URL.
 * Unterstützt multipart/form-data (Standard) oder Raw-PDF (Content-Type: application/pdf).
 * Verwendet cURL (zuverlässiger für HTTPS) mit Fallback auf file_get_contents.
 */
function print_send_pdf_via_url($pdf_content, $url, $debug = false, $raw_pdf = false) {
    $url = trim($url);
    if (empty($url) || !preg_match('#^https?://#i', $url)) {
        return ['success' => false, 'message' => 'Ungültige Cloud-Drucker-URL.'];
    }
    $use_curlfile = false;
    $tmp = '';
    if (function_exists('curl_init') && class_exists('CURLFile') && !$raw_pdf) {
        $use_curlfile = true; // CURLFile = korrektes multipart, oft besser für Cloud-Drucker (z.B. Princh)
    }

    if ($raw_pdf) {
        $body = $pdf_content;
        $content_type = 'application/pdf';
    } elseif ($use_curlfile) {
        $tmp = tempnam(sys_get_temp_dir(), 'print_') . '.pdf';
        if (file_put_contents($tmp, $pdf_content) === false) {
            return ['success' => false, 'message' => 'Temporäre Datei konnte nicht erstellt werden.'];
        }
        $body = ['file' => new CURLFile($tmp, 'application/pdf', 'druck.pdf')];
        $content_type = null; // cURL setzt multipart automatisch
    } else {
        $boundary = '----FeuerwehrAppPrint' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"druck.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . $pdf_content . "\r\n"
            . "--$boundary--\r\n";
        $content_type = "multipart/form-data; boundary=$boundary";
    }

    $headers = ['User-Agent: Feuerwehr-App/1.0'];
    if ($content_type !== null) {
        $headers[] = "Content-Type: $content_type";
        if (is_string($body)) {
            $headers[] = 'Content-Length: ' . strlen($body);
        }
    }

    // cURL: zuverlässiger für HTTPS, bessere SSL/TLS-Unterstützung
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curl_opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];
        if (defined('CURLOPT_POSTREDIR')) {
            $curl_opts[CURLOPT_POSTREDIR] = 3; // POST bei 301/302 beibehalten
        }
        curl_setopt_array($ch, $curl_opts);
        // TLS 1.2+ erzwingen für Cloud-Server
        if (defined('CURLOPT_SSLVERSION')) {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }
        $response = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);
        if (!empty($tmp)) {
            @unlink($tmp);
        }
        if ($response === false && $curl_err !== '') {
            $err_msg = 'SSL/Verbindung: ' . $curl_err;
            if ($debug) {
                return ['success' => false, 'message' => $err_msg, 'debug' => ['url' => $url, 'curl_error' => $curl_err]];
            }
            return ['success' => false, 'message' => $err_msg];
        }
    } else {
        // Fallback: file_get_contents mit SSL-Kontext
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $opts['ssl']['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
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
        if ($response === false && $http_code === 0) {
            $err_msg = 'Verbindung fehlgeschlagen. Prüfen: allow_url_fopen, OpenSSL, Firewall. Bei HTTPS: cURL-Erweiterung empfohlen.';
            return ['success' => false, 'message' => $err_msg];
        }
    }

    if ($http_code >= 200 && $http_code < 300) {
        $result = ['success' => true, 'message' => 'Druckauftrag wurde an Cloud-Drucker gesendet.'];
        if ($debug) {
            $result['debug'] = ['url' => $url, 'http_code' => $http_code];
        }
        return $result;
    }
    $response_preview = is_string($response) ? substr(trim($response), 0, 200) : 'Keine Antwort';
    $result = ['success' => false, 'message' => 'Cloud-Drucker: HTTP ' . $http_code . ' – ' . $response_preview];
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
