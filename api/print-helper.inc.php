<?php
/**
 * Hilfsfunktionen für Druck per E-Mail (E-Mail Druck Tool), CUPS (lp) und Cloud-Drucker.
 */
function print_get_printer_config($db, $einheit_id = null) {
    $cloud_url = '';
    $cloud_url_raw = false;
    $settings = [];
    $einheit_id = $einheit_id !== null ? (int)$einheit_id : 0;
    if ($einheit_id > 0) {
        if (!function_exists('load_settings_for_einheit')) {
            require_once dirname(__DIR__) . '/includes/einheit-settings-helper.php';
        }
        $settings = load_settings_for_einheit($db, $einheit_id);
        $settings = is_array($settings) ? $settings : [];

        // printer_list prüfen (Cloud-Drucker)
        $list = print_get_printer_list($db, $einheit_id);
        $default = null;
        foreach ($list as $p) {
            if (!empty($p['is_default'])) {
                $default = $p;
                break;
            }
        }
        if ($default === null && !empty($list)) {
            $default = $list[0];
        }
        if ($default !== null && ($default['type'] ?? '') === 'cloud' && !empty($default['cloud_url'])) {
            $cloud_url = trim($default['cloud_url']);
            $cloud_url_raw = !empty($default['cloud_raw']);
        }

        // Fallback: Legacy Cloud-URL
        if ($cloud_url === '' && trim($settings['printer_cloud_url'] ?? '') !== '') {
            $cloud_url = trim($settings['printer_cloud_url']);
            $cloud_url_raw = ($settings['printer_cloud_url_raw'] ?? '') === '1';
        }
    }
    $printer_mode = $einheit_id > 0 ? trim($settings['printer_mode'] ?? '') : '';
    if ($printer_mode !== 'cups' && $printer_mode !== 'email') {
        $printer_mode = !empty(trim($settings['printer_email_recipient'] ?? '')) ? 'email' : (!empty(trim($settings['printer_cups_name'] ?? '')) ? 'cups' : 'email');
    }
    $printer_cups_name = $einheit_id > 0 ? trim($settings['printer_cups_name'] ?? '') : '';
    $printer_email = $einheit_id > 0 ? trim($settings['printer_email_recipient'] ?? '') : '';
    $printer_email_subject = $einheit_id > 0 ? trim($settings['printer_email_subject'] ?? '') ?: 'DRUCK' : 'DRUCK';
    return [
        'cloud_url' => $cloud_url,
        'cloud_url_raw' => $cloud_url_raw,
        'printer_mode' => $printer_mode ?: 'email',
        'printer_cups_name' => $printer_cups_name,
        'printer_email_recipient' => $printer_email,
        'printer_email_subject' => $printer_email_subject ?: 'DRUCK',
        'einheit_id' => $einheit_id,
    ];
}

/**
 * Lädt die Druckerliste für eine Einheit (nur Cloud-Drucker).
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
        if (is_array($dec)) {
            $list = array_filter($dec, function ($p) {
                return ($p['type'] ?? '') === 'cloud';
            });
            $list = array_values($list);
        }
    }
    if (empty($list) && trim($settings['printer_cloud_url'] ?? '') !== '') {
        $list = [['id' => 'legacy', 'name' => 'Legacy Cloud-Drucker', 'type' => 'cloud', 'cloud_url' => trim($settings['printer_cloud_url'] ?? ''), 'cloud_raw' => ($settings['printer_cloud_url_raw'] ?? '') === '1', 'is_default' => true]];
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

/**
 * Sendet mehrere PDFs in einer E-Mail an ein überwachtes Postfach (E-Mail Druck Tool).
 * @param array $attachments Array von [content, filename]
 */
function print_send_pdfs_via_email(array $attachments, $printer_config, $debug = false) {
    $to = trim($printer_config['printer_email_recipient'] ?? '');
    $subject = trim($printer_config['printer_email_subject'] ?? 'DRUCK');
    $einheit_id = (int)($printer_config['einheit_id'] ?? 0);
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Ungültige E-Mail-Adresse für Druck per E-Mail.'];
    }
    if (empty($attachments)) {
        return ['success' => false, 'message' => 'Keine PDFs zum Senden.'];
    }
    if (!function_exists('send_email_with_pdfs_for_einheit')) {
        require_once dirname(__DIR__) . '/includes/functions.php';
    }
    $body = 'Druckauftrag von der Feuerwehr-App. ' . count($attachments) . ' PDF(s) sind angehängt.';
    $ok = send_email_with_pdfs_for_einheit($to, $subject, $body, $attachments, $einheit_id);
    if ($ok) {
        $result = ['success' => true, 'message' => 'Druckauftrag wurde per E-Mail gesendet. Das E-Mail Druck Tool druckt die PDFs am Zielrechner.'];
        if ($debug) {
            $result['debug'] = ['to' => $to, 'subject' => $subject, 'attachments' => count($attachments)];
        }
        return $result;
    }
    return ['success' => false, 'message' => 'E-Mail-Versand fehlgeschlagen. SMTP-Einstellungen der Einheit prüfen.'];
}

/**
 * Sendet PDF per E-Mail an ein überwachtes Postfach (E-Mail Druck Tool).
 * Nutzt SMTP der Einheit. Der Betreff muss im E-Mail Druck Tool als Filter hinterlegt sein.
 */
function print_send_pdf_via_email($pdf_content, $printer_config, $debug = false) {
    $to = trim($printer_config['printer_email_recipient'] ?? '');
    $subject = trim($printer_config['printer_email_subject'] ?? 'DRUCK');
    $einheit_id = (int)($printer_config['einheit_id'] ?? 0);
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Ungültige E-Mail-Adresse für Druck per E-Mail.'];
    }
    if (!function_exists('send_email_with_pdf_for_einheit')) {
        require_once dirname(__DIR__) . '/includes/functions.php';
    }
    $body = 'Druckauftrag von der Feuerwehr-App. Das PDF ist angehängt.';
    $filename = 'Feuerwehr-App-' . date('Y-m-d-His') . '.pdf';
    $ok = send_email_with_pdf_for_einheit($to, $subject, $body, $pdf_content, $filename, $einheit_id);
    if ($ok) {
        $result = ['success' => true, 'message' => 'Druckauftrag wurde per E-Mail gesendet. Das E-Mail Druck Tool druckt das PDF am Zielrechner.'];
        if ($debug) {
            $result['debug'] = ['to' => $to, 'subject' => $subject];
        }
        return $result;
    }
    return ['success' => false, 'message' => 'E-Mail-Versand fehlgeschlagen. SMTP-Einstellungen der Einheit prüfen.'];
}

/**
 * Druckt PDF direkt über CUPS (lp).
 */
function print_send_pdf_via_cups($pdf_content, $printer_config, $debug = false) {
    $printer_name = trim($printer_config['printer_cups_name'] ?? '');
    if (empty($printer_name)) {
        return ['success' => false, 'message' => 'Kein CUPS-Drucker ausgewählt.'];
    }
    if (empty($pdf_content) || strlen($pdf_content) < 100 || substr($pdf_content, 0, 5) !== '%PDF-') {
        return ['success' => false, 'message' => 'PDF-Inhalt ungültig.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'print_') . '.pdf';
    if (file_put_contents($tmp, $pdf_content) === false) {
        return ['success' => false, 'message' => 'Temporäre Datei konnte nicht erstellt werden.'];
    }
    $lp_path = '';
    foreach (['/usr/bin/lp', '/usr/local/bin/lp', 'lp'] as $path) {
        if (strpos($path, '/') !== false && is_executable($path)) {
            $lp_path = $path;
            break;
        }
        if (strpos($path, '/') === false) {
            $out = @shell_exec('which ' . escapeshellarg($path) . ' 2>/dev/null');
            if ($out && trim($out)) {
                $lp_path = trim(explode("\n", $out)[0]);
                break;
            }
        }
    }
    if ($lp_path === '') {
        @unlink($tmp);
        return ['success' => false, 'message' => 'lp-Befehl nicht gefunden. CUPS muss installiert sein.'];
    }
    $cmd = escapeshellcmd($lp_path) . ' -d ' . escapeshellarg($printer_name) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $ret);
    @unlink($tmp);
    if ($ret === 0) {
        return ['success' => true, 'message' => 'Druckauftrag an CUPS-Drucker gesendet.'];
    }
    $err = implode(' ', $output);
    return ['success' => false, 'message' => 'CUPS-Druck fehlgeschlagen: ' . ($err ?: 'Unbekannter Fehler')];
}

/**
 * Sendet mehrere PDFs an CUPS-Drucker.
 */
function print_send_pdfs_via_cups(array $attachments, $printer_config, $debug = false) {
    if (empty($attachments)) {
        return ['success' => false, 'message' => 'Keine PDFs zum Drucken.'];
    }
    foreach ($attachments as $att) {
        $result = print_send_pdf_via_cups($att[0], $printer_config, $debug);
        if (!$result['success']) return $result;
    }
    return ['success' => true, 'message' => count($attachments) . ' Druckauftrag(e) an CUPS-Drucker gesendet.'];
}

function print_send_pdf($pdf_content, $printer_config, $debug = false) {
    $printer_mode = trim($printer_config['printer_mode'] ?? '');
    $printer_cups = trim($printer_config['printer_cups_name'] ?? '');
    $cloud_url = trim($printer_config['cloud_url'] ?? '');
    $printer_email = trim($printer_config['printer_email_recipient'] ?? '');
    if ($printer_mode !== 'cups' && $printer_mode !== 'email') {
        $printer_mode = !empty($printer_email) ? 'email' : (!empty($printer_cups) ? 'cups' : (!empty($cloud_url) ? 'cloud' : ''));
    }
    if ($printer_mode === '') {
        $printer_mode = !empty($printer_email) ? 'email' : (!empty($cloud_url) ? 'cloud' : '');
    }
    if (empty($pdf_content) || strlen($pdf_content) < 100) {
        return ['success' => false, 'message' => 'PDF konnte nicht erzeugt werden.'];
    }
    if (substr($pdf_content, 0, 5) !== '%PDF-') {
        return ['success' => false, 'message' => 'PDF-Inhalt ungültig (kein PDF-Header).'];
    }
    if ($printer_mode === 'cups' && !empty($printer_cups)) {
        return print_send_pdf_via_cups($pdf_content, $printer_config, $debug);
    }
    if (($printer_mode !== 'cups' || $printer_mode === '') && !empty($printer_email)) {
        return print_send_pdf_via_email($pdf_content, $printer_config, $debug);
    }
    if (!empty($cloud_url)) {
        return print_send_pdf_via_url($pdf_content, $cloud_url, $debug, !empty($printer_config['cloud_url_raw']));
    }
    return ['success' => false, 'message' => 'Kein Drucker konfiguriert. Bitte CUPS-Drucker oder E-Mail-Postfach in den Einstellungen (Drucker-Tab) wählen.'];
}
