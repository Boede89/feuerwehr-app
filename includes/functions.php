<?php
/**
 * Allgemeine Funktionen für die Feuerwehr App
 */

// Google Calendar Klassen laden (pfadsicher relativ zu diesem Verzeichnis)
$gc_sa_path = __DIR__ . '/google_calendar_service_account.php';
$gc_api_path = __DIR__ . '/google_calendar.php';
if (file_exists($gc_sa_path)) {
    require_once $gc_sa_path;
}
if (file_exists($gc_api_path)) {
    require_once $gc_api_path;
}

/**
 * Logo-HTML für PDF-Formulare (Anwesenheitsliste etc.).
 * Liest app_logo aus settings und gibt img-Tag mit Base64 zurück.
 */
function get_pdf_logo_html() {
    global $db;
    $logo_path = '';
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_logo'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty(trim($row['setting_value'] ?? ''))) {
            $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($row['setting_value']));
            $logo_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $rel;
        }
    } catch (Exception $e) {}
    if ($logo_path === '' || !file_exists($logo_path) || !is_readable($logo_path)) {
        return '';
    }
    $data = @file_get_contents($logo_path);
    if ($data === false) return '';
    $mime = 'image/png';
    $ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
    elseif ($ext === 'gif') $mime = 'image/gif';
    elseif ($ext === 'webp') $mime = 'image/webp';
    $b64 = base64_encode($data);
    return '<img src="data:' . $mime . ';base64,' . $b64 . '" alt="" style="max-height: 120px; max-width: 320px; display: block; margin: 0 0 2px 0;">';
}

/**
 * Sanitize Input
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * E-Mail validieren
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Datum validieren
 */
function validate_date($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Datum und Uhrzeit validieren
 */
function validate_datetime($datetime, $format = 'Y-m-d\TH:i') {
    // HTML datetime-local Input verwendet das Format Y-m-d\TH:i
    $d = DateTime::createFromFormat($format, $datetime);
    return $d && $d->format($format) === $datetime;
}

/**
 * Passwort hashen
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Passwort verifizieren
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Zufälliges Token generieren
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * E-Mail senden
 */
function send_email($to, $subject, $message, $headers = '', $isHtml = false) {
    global $db;
    
    try {
        // SMTP-Einstellungen aus der Datenbank laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $smtp_host = $settings['smtp_host'] ?? '';
        $smtp_port = $settings['smtp_port'] ?? '587';
        $smtp_username = $settings['smtp_username'] ?? '';
        $smtp_password = $settings['smtp_password'] ?? '';
        $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
        $smtp_from_email = $settings['smtp_from_email'] ?? 'noreply@feuerwehr-app.local';
        $smtp_from_name = $settings['smtp_from_name'] ?? 'Feuerwehr App';
        
        // Verwende Gmail SMTP direkt für zuverlässige E-Mail-Zustellung
        if (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password)) {
            error_log("E-Mail wird über Gmail SMTP gesendet an: $to");
            return send_email_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name, $isHtml);
        } else {
            // Fallback auf mail() Funktion
            if (empty($headers)) {
                $headers = "From: $smtp_from_name <$smtp_from_email>\r\n";
                $headers .= "Reply-To: $smtp_from_email\r\n";
                $headers .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            }
            
            $additional_parameters = "-f$smtp_from_email";
            error_log("SMTP nicht konfiguriert. Verwende mail() Funktion für: $to");
            return mail($to, $subject, $message, $headers, $additional_parameters);
        }
    } catch (Exception $e) {
        error_log('E-Mail Fehler: ' . $e->getMessage());
        return false;
    }
}

/**
 * E-Mail über SMTP senden (echte SMTP-Implementierung)
 */
function send_email_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $from_email, $from_name, $isHtml = false) {
    require_once 'smtp.php';
    
    try {
        $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $from_email, $from_name);
        $result = $smtp->send($to, $subject, $message, $isHtml);
        
        if ($result) {
            error_log("SMTP E-Mail erfolgreich gesendet an: $to");
        } else {
            error_log("SMTP E-Mail fehlgeschlagen an: $to");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("SMTP Fehler: " . $e->getMessage());
        return false;
    }
}

/**
 * E-Mail für eine bestimmte Einheit senden (SMTP nur aus einheit_settings).
 * Wenn die Einheit keine SMTP-Einstellungen hat, wird keine E-Mail gesendet (kein globaler Fallback).
 * @param int|null $einheit_id Einheit-ID (> 0). Bei null/0 wird false zurückgegeben.
 * @return bool true bei Erfolg, false wenn Einheit kein SMTP hat oder Versand fehlschlägt
 */
function send_email_for_einheit($to, $subject, $message, $einheit_id, $isHtml = false) {
    global $db;
    $einheit_id = (int)$einheit_id;
    if ($einheit_id <= 0) return false;
    if (!function_exists('load_settings_for_einheit')) {
        require_once __DIR__ . '/einheit-settings-helper.php';
    }
    $settings = load_settings_for_einheit($db, $einheit_id);
    $smtp_host = trim($settings['smtp_host'] ?? '');
    $smtp_port = trim($settings['smtp_port'] ?? '') ?: '587';
    $smtp_username = trim($settings['smtp_username'] ?? '');
    $smtp_password = trim($settings['smtp_password'] ?? '');
    $smtp_encryption = trim($settings['smtp_encryption'] ?? '') ?: 'tls';
    $smtp_from_email = trim($settings['smtp_from_email'] ?? '') ?: 'noreply@feuerwehr-app.local';
    $smtp_from_name = trim($settings['smtp_from_name'] ?? '') ?: 'Feuerwehr App';
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        error_log("send_email_for_einheit: Einheit $einheit_id hat keine SMTP-Einstellungen – keine E-Mail gesendet.");
        return false;
    }
    return send_email_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name, $isHtml);
}

/**
 * E-Mail mit PDF-Anhang senden (SMTP der Einheit).
 * Für Druck per E-Mail: PDF wird an Postfach gesendet, das E-Mail Druck Tool druckt es.
 * @param int $einheit_id Einheit-ID (> 0). Bei 0 wird false zurückgegeben.
 * @return bool true bei Erfolg
 */
function send_email_with_pdf_for_einheit($to, $subject, $message, $pdfContent, $pdfFilename, $einheit_id) {
    global $db;
    $einheit_id = (int)$einheit_id;
    if ($einheit_id <= 0) return false;
    if (!function_exists('load_settings_for_einheit')) {
        require_once __DIR__ . '/einheit-settings-helper.php';
    }
    $settings = load_settings_for_einheit($db, $einheit_id);
    $smtp_host = trim($settings['smtp_host'] ?? '');
    $smtp_port = trim($settings['smtp_port'] ?? '') ?: '587';
    $smtp_username = trim($settings['smtp_username'] ?? '');
    $smtp_password = trim($settings['smtp_password'] ?? '');
    $smtp_encryption = trim($settings['smtp_encryption'] ?? '') ?: 'tls';
    $smtp_from_email = trim($settings['smtp_from_email'] ?? '') ?: 'noreply@feuerwehr-app.local';
    $smtp_from_name = trim($settings['smtp_from_name'] ?? '') ?: 'Feuerwehr App';
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        error_log("send_email_with_pdf_for_einheit: Einheit $einheit_id hat keine SMTP-Einstellungen – keine E-Mail gesendet.");
        return false;
    }
    require_once __DIR__ . '/smtp.php';
    $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
    return $smtp->sendWithAttachment($to, $subject, $message, true, $pdfContent, $pdfFilename);
}

/**
 * E-Mail mit mehreren PDF-Anhängen senden (SMTP der Einheit).
 * @param array $attachments Array von [content, filename] – z.B. [[$pdf1, 'Anwesenheit.pdf'], [$pdf2, 'Maengel.pdf']]
 * @return bool true bei Erfolg
 */
function send_email_with_pdfs_for_einheit($to, $subject, $message, array $attachments, $einheit_id) {
    global $db;
    $einheit_id = (int)$einheit_id;
    if ($einheit_id <= 0 || empty($attachments)) return false;
    if (!function_exists('load_settings_for_einheit')) {
        require_once __DIR__ . '/einheit-settings-helper.php';
    }
    $settings = load_settings_for_einheit($db, $einheit_id);
    $smtp_host = trim($settings['smtp_host'] ?? '');
    $smtp_port = trim($settings['smtp_port'] ?? '') ?: '587';
    $smtp_username = trim($settings['smtp_username'] ?? '');
    $smtp_password = trim($settings['smtp_password'] ?? '');
    $smtp_encryption = trim($settings['smtp_encryption'] ?? '') ?: 'tls';
    $smtp_from_email = trim($settings['smtp_from_email'] ?? '') ?: 'noreply@feuerwehr-app.local';
    $smtp_from_name = trim($settings['smtp_from_name'] ?? '') ?: 'Feuerwehr App';
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        error_log("send_email_with_pdfs_for_einheit: Einheit $einheit_id hat keine SMTP-Einstellungen – keine E-Mail gesendet.");
        return false;
    }
    require_once __DIR__ . '/smtp.php';
    $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
    return $smtp->sendWithMultipleAttachments($to, $subject, $message, true, $attachments);
}

/**
 * E-Mail mit PDF-Anhang senden (globale SMTP-Einstellungen)
 */
function send_email_with_pdf_attachment($to, $subject, $htmlBody, $pdfContent, $pdfFilename) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $smtp_host = $settings['smtp_host'] ?? '';
        $smtp_port = $settings['smtp_port'] ?? '587';
        $smtp_username = $settings['smtp_username'] ?? '';
        $smtp_password = $settings['smtp_password'] ?? '';
        $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
        $from_email = $settings['smtp_from_email'] ?? 'noreply@feuerwehr-app.local';
        $from_name = $settings['smtp_from_name'] ?? 'Feuerwehr App';
        if (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password)) {
            require_once __DIR__ . '/smtp.php';
            $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $from_email, $from_name);
            return $smtp->sendWithAttachment($to, $subject, $htmlBody, true, $pdfContent, $pdfFilename);
        }
        return false;
    } catch (Exception $e) {
        error_log('send_email_with_pdf_attachment: ' . $e->getMessage());
        return false;
    }
}

/**
 * E-Mail über Gmail senden (vereinfachte Version)
 */
function send_email_gmail($to, $subject, $message, $username, $password, $from_email, $from_name) {
    // Verwende die PHP mail() Funktion mit Gmail-spezifischen Headers
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 3\r\n";
    
    // Zusätzliche Parameter für Gmail
    $additional_parameters = "-f$from_email";
    
    // Debug-Informationen
    error_log("Gmail Debug - From: $from_email, To: $to, Username: $username");
    
    // Versuche E-Mail zu senden
    $result = mail($to, $subject, $message, $headers, $additional_parameters);
    
    if (!$result) {
        error_log("Gmail E-Mail konnte nicht gesendet werden. Prüfen Sie die PHP mail() Konfiguration.");
    }
    
    return $result;
}

/**
 * Erfolgsmeldung anzeigen
 */
function show_success($message) {
    return '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> ' . htmlspecialchars($message) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
}

/**
 * Fehlermeldung anzeigen
 */
function show_error($message) {
    return '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($message) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
}

/**
 * Warnung anzeigen
 */
function show_warning($message) {
    return '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($message) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
}

/**
 * Info anzeigen
 */
function show_info($message) {
    return '<div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle"></i> ' . htmlspecialchars($message) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
}

/**
 * Benutzer ist eingeloggt
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Benutzer ist ein Systembenutzer (nur Autologin, kein Mitglied, kein Dashboard-Zugriff)
 */
function is_system_user() {
    return !empty($_SESSION['is_system_user']);
}

/**
 * Benutzer ist Admin
 */
function is_admin() {
    return is_logged_in() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

/**
 * Aktuell gewählte Einheit aus Session
 */
function get_current_unit_id() {
    return isset($_SESSION['current_unit_id']) ? (int)$_SESSION['current_unit_id'] : null;
}

/**
 * Einheiten, auf die der Benutzer Zugriff hat
 */
function get_accessible_units() {
    global $db;
    if (!isset($_SESSION['user_id'])) {
        return [];
    }
    try {
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.slug FROM units u
            INNER JOIN user_units uu ON u.id = uu.unit_id
            WHERE uu.user_id = ?
            ORDER BY u.name
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($units)) return $units;
    } catch (Exception $e) { /* Tabelle evtl. noch nicht vorhanden */ }
    // Fallback vor Migration: Einheit 1
    try {
        $stmt = $db->query("SELECT id, name, slug FROM units WHERE id = 1");
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        return $u ? [$u] : [['id' => 1, 'name' => 'Standard', 'slug' => 'standard']];
    } catch (Exception $e) {
        return [['id' => 1, 'name' => 'Standard', 'slug' => 'standard']];
    }
}

/**
 * Prüft ob der Benutzer Zugriff auf eine Einheit hat
 */
function can_access_unit($unit_id) {
    global $db;
    if (!isset($_SESSION['user_id'])) return false;
    if (is_admin()) return true; // Admin hat Zugriff auf alle Einheiten
    try {
        $stmt = $db->prepare("SELECT 1 FROM user_units WHERE user_id = ? AND unit_id = ?");
        $stmt->execute([$_SESSION['user_id'], (int)$unit_id]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        // Vor Migration: Einheit 1 für alle
        return (int)$unit_id === 1;
    }
}

/**
 * Prüft ob eine Einheiten-Auswahl erforderlich ist und leitet ggf. weiter
 */
function require_unit_selected() {
    $unit_id = get_current_unit_id();
    $einheit_id = function_exists('get_current_einheit_id') ? get_current_einheit_id() : null;
    if (!$unit_id && !$einheit_id) {
        redirect('index.php');
    }
    return $unit_id ?: $einheit_id;
}

/**
 * Prüft ob Benutzer Genehmiger oder Admin ist
 */
function can_approve_reservations() {
    return is_logged_in() && isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'approver']);
}

/**
 * Prüfen ob Benutzer Admin-Zugriff hat (nur für Einstellungen)
 */
function has_admin_access() {
    return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Prüft ob der Benutzer Admin-Berechtigung hat (erweiterte Prüfung)
 * Unterstützt Superadmin, Einheitsadmin sowie alte role-basierte und permission-basierte Berechtigungen
 */
function hasAdminPermission($user_id = null) {
    global $db;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    
    if (!$user_id) {
        return false;
    }
    
    try {
        $stmt = $db->prepare("SELECT user_role, is_admin, can_settings, user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // Superadmin: Zugriff auf alles
        if (($user['user_type'] ?? '') === 'superadmin') {
            return true;
        }
        
        // Einheitsadmin: Zugriff auf Einstellungen seiner Einheit
        if (($user['user_type'] ?? '') === 'einheitsadmin') {
            return true;
        }
        
        // Prüfe alte role-basierte Berechtigung
        if ($user['user_role'] === 'admin') {
            return true;
        }
        
        // Prüfe neue permission-basierte Berechtigung
        if ($user['is_admin'] || $user['can_settings']) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking admin permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Prüft ob der aktuelle Benutzer eine bestimmte Berechtigung hat
 * @param string $permission Berechtigung (admin, reservations, users, settings, vehicles, atemschutz)
 * @return bool
 */
function has_permission($permission) {
    global $db;
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        // Sicherstellen, dass alle Berechtigungsspalten existieren
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_reservations TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_users TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_settings TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_vehicles TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_atemschutz TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_members TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_ric TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_courses TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_forms TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_auswertung TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN can_forms_fill TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        foreach (['can_reservations_readonly', 'can_atemschutz_readonly', 'can_members_readonly', 'can_ric_readonly', 'can_courses_readonly', 'can_forms_readonly'] as $col) {
            try { $db->exec("ALTER TABLE users ADD COLUMN $col TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN divera_access_key VARCHAR(512) NULL DEFAULT NULL");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN is_system_user TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN autologin_token VARCHAR(64) NULL DEFAULT NULL");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        try {
            $db->exec("ALTER TABLE users ADD COLUMN autologin_expires DATETIME NULL DEFAULT NULL");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        
        $stmt = $db->prepare("SELECT is_admin, user_role, is_system_user, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_forms_fill, can_auswertung, can_users, can_settings, can_vehicles, can_reservations_readonly, can_atemschutz_readonly, can_members_readonly, can_ric_readonly, can_courses_readonly, can_forms_readonly FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // Admin hat alle Berechtigungen - prüfe sowohl is_admin als auch user_role
        // Robuste Prüfung: is_admin kann 1, '1', true sein, oder user_role === 'admin'
        $isAdmin = false;
        if (isset($user['is_admin'])) {
            $isAdmin = ($user['is_admin'] == 1 || $user['is_admin'] === '1' || $user['is_admin'] === true);
        }
        // Fallback: Prüfe auch user_role falls vorhanden
        if (!$isAdmin && isset($user['user_role']) && $user['user_role'] === 'admin') {
            $isAdmin = true;
        }
        
        if ($isAdmin) {
            return true; // Admin hat alle Berechtigungen, einschließlich members
        }
        
        // Spezifische Berechtigung prüfen
        switch ($permission) {
            case 'admin':
                return $isAdmin;
            case 'reservations':
                return (bool)($user['can_reservations'] ?? 0);
            case 'users':
                return (bool)($user['can_users'] ?? 0);
            case 'settings':
                return (bool)($user['can_settings'] ?? 0);
            case 'vehicles':
                return (bool)($user['can_vehicles'] ?? 0);
            case 'atemschutz':
                return (bool)($user['can_atemschutz'] ?? 0);
            case 'members':
                return (bool)($user['can_members'] ?? 0);
            case 'ric':
                return (bool)($user['can_ric'] ?? 0);
            case 'courses':
                return (bool)($user['can_courses'] ?? 0);
            case 'forms':
                return (bool)($user['can_forms'] ?? 0);
            case 'forms_fill':
                return (bool)($user['can_forms_fill'] ?? 0);
            case 'auswertung':
                return (bool)($user['can_auswertung'] ?? 0);
            default:
                return false;
        }
    } catch (Exception $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Prüft ob der Benutzer Formulare ausfüllen darf (Formulare ausfüllen ODER Formularcenter)
 * @return bool
 */
function has_form_fill_permission() {
    return has_permission('forms_fill') || has_permission('forms');
}

/**
 * Prüft ob der Benutzer Schreibrechte für eine Berechtigung hat (nicht nur Leserechte)
 * @param string $permission Berechtigung (reservations, atemschutz, members, ric, courses, forms)
 * @return bool
 */
function has_permission_write($permission) {
    global $db;
    if (!isset($_SESSION['user_id'])) return false;
    if (!has_permission($permission)) return false;
    $readonly_cols = [
        'reservations' => 'can_reservations_readonly',
        'atemschutz' => 'can_atemschutz_readonly',
        'members' => 'can_members_readonly',
        'ric' => 'can_ric_readonly',
        'courses' => 'can_courses_readonly',
        'forms' => 'can_forms_readonly',
    ];
    if (!isset($readonly_cols[$permission])) return true; // z.B. forms_fill hat kein readonly
    try {
        $col = $readonly_cols[$permission];
        $stmt = $db->prepare("SELECT $col FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && empty($row[$col]); // Schreibrecht wenn readonly=0
    } catch (Exception $e) {
        return true; // Bei Fehler Schreibrecht annehmen
    }
}

/**
 * Prüft mehrere Berechtigungen (OR-Verknüpfung)
 * @param array $permissions Array von Berechtigungen
 * @return bool
 */
function has_any_permission($permissions) {
    foreach ($permissions as $permission) {
        if (has_permission($permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Prüft mehrere Berechtigungen (AND-Verknüpfung)
 * @param array $permissions Array von Berechtigungen
 * @return bool
 */
function has_all_permissions($permissions) {
    foreach ($permissions as $permission) {
        if (!has_permission($permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Generiert die Admin-Navigation basierend auf Benutzerberechtigungen
 * @return string HTML für die Navigation
 */
function get_admin_navigation() {
    $nav_items = [];
    
    // Dashboard - immer sichtbar für eingeloggte Benutzer
    if (is_logged_in()) {
        $nav_items[] = '<li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>';
    }
    
    // Atemschutz (sichtbar für Benutzer mit Atemschutz-Recht)
    if (has_permission('atemschutz')) {
        $nav_items[] = '<li class="nav-item"><a class="nav-link" href="atemschutz.php"><i class="fas fa-user-shield"></i> Atemschutz</a></li>';
    }

    // Einstellungen - nur für Einstellungen-Recht (inkl. Fahrzeuge und Benutzer)
    if (has_permission('settings') || has_permission('vehicles') || has_permission('users')) {
        $nav_items[] = '<li class="nav-item"><a class="nav-link" href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>';
    }
    
    // Profil-Link wird über das Benutzer-Dropdown angeboten, nicht in der Hauptnavigation
    
    return implode("\n                ", $nav_items);
}

/**
 * Weiterleitung
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Einheiten-System: Aktuelle Einheit aus Session
 */
function get_current_einheit_id() {
    return isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : null;
}

/**
 * Zählt Superadmins in der Datenbank.
 * Berücksichtigt auch Legacy-Admins (is_admin=1 oder user_role='admin'), die von Skripten
 * wie init-database.php erstellt wurden und kein user_type='superadmin' haben.
 */
function count_superadmins() {
    global $db;
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE 
            COALESCE(user_type, '') = 'superadmin' 
            OR COALESCE(is_admin, 0) = 1 
            OR COALESCE(user_role, '') = 'admin'");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Prüft ob ein Benutzer Superadmin-Rechte hat (für Löschlogik).
 * Berücksichtigt user_type und Legacy-Felder (is_admin, user_role).
 */
function user_has_superadmin_rights($user) {
    if (($user['user_type'] ?? '') === 'superadmin') return true;
    if (($user['is_admin'] ?? 0) == 1) return true;
    if (($user['user_role'] ?? '') === 'admin') return true;
    return false;
}

/**
 * Löscht einen Benutzer sicher (bereinigt Referenzen vor dem Löschen).
 * Gibt true bei Erfolg zurück, sonst false. Fehlermeldung in $error (byref).
 */
function delete_user_safe($user_id, &$error = '') {
    global $db;
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        $error = 'Ungültige Benutzer-ID.';
        return false;
    }
    try {
        // Tabellen mit user_id-Referenz bereinigen (vor DELETE, falls FK blockiert)
        $updates = [
            ['members', 'user_id'],
            ['user_einheiten', 'user_id'],
            ['dashboard_preferences', 'user_id'],
            ['dashboard_settings', 'user_id'],
            ['anwesenheitsliste_drafts', 'user_id'],
            ['reservations', 'approved_by'],
            ['room_reservations', 'approved_by'],
            ['atemschutz_entries', 'requester_id'],
            ['atemschutz_entries', 'approved_by'],
        ];
        foreach ($updates as $t) {
            try {
                $db->exec("UPDATE `{$t[0]}` SET `{$t[1]}` = NULL WHERE `{$t[1]}` = $user_id");
            } catch (Exception $e) { /* Tabelle/Spalte kann fehlen */ }
        }
        // activity_log: Einträge des Benutzers löschen (user_id oft NOT NULL)
        try {
            $db->exec("DELETE FROM activity_log WHERE user_id = $user_id");
        } catch (Exception $e) {}
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $error = $e->getMessage();
        return false;
    }
}

/**
 * Einheiten-System: Prüft ob Benutzer Superadmin ist
 */
function is_superadmin($user_id = null) {
    global $db;
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
    if (!$user_id) return false;
    try {
        $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && ($row['user_type'] ?? '') === 'superadmin';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Einheiten-System: Prüft ob Benutzer Einheitsadmin ist
 */
function is_einheitsadmin($user_id = null) {
    global $db;
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
    if (!$user_id) return false;
    try {
        $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && ($row['user_type'] ?? '') === 'einheitsadmin';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Einheiten-System: Prüft ob Benutzer Zugriff auf Einheit hat
 */
function user_has_einheit_access($user_id, $einheit_id) {
    global $db;
    if (!$user_id || !$einheit_id) return false;
    if (is_superadmin($user_id)) return true;
    try {
        $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && (int)($u['einheit_id'] ?? 0) === (int)$einheit_id) return true;
        $stmt = $db->prepare("SELECT 1 FROM user_einheiten WHERE user_id = ? AND einheit_id = ?");
        $stmt->execute([$user_id, $einheit_id]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}

/**
 * Einheiten-System: Alle Einheiten, auf die der Benutzer Zugriff hat
 */
function get_user_einheiten($user_id = null) {
    global $db;
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
    if (!$user_id) return [];
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'einheiten'");
        if (!$stmt || !$stmt->fetch()) return [];
    } catch (Exception $e) { return []; }
    if (is_superadmin($user_id) || (function_exists('hasAdminPermission') && hasAdminPermission($user_id))) {
        $stmt = $db->query("SELECT id, name, sort_order FROM einheiten WHERE is_active = 1 ORDER BY sort_order, name");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
    $einheiten = [];
    try {
        $stmt = $db->prepare("SELECT e.id, e.name, e.sort_order FROM einheiten e INNER JOIN user_einheiten ue ON ue.einheit_id = e.id WHERE ue.user_id = ? AND e.is_active = 1");
        $stmt->execute([$user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $einheiten[] = $row;
        $stmt = $db->prepare("SELECT e.id, e.name, e.sort_order FROM einheiten e INNER JOIN users u ON u.einheit_id = e.id WHERE u.id = ? AND e.is_active = 1");
        $stmt->execute([$user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!in_array((int)$row['id'], array_map('intval', array_column($einheiten, 'id')))) $einheiten[] = $row;
        }
        usort($einheiten, fn($a,$b) => ($a['sort_order']??0) - ($b['sort_order']??0) ?: strcmp($a['name'], $b['name']));
    } catch (Exception $e) { /* user_einheiten könnte fehlen */ }
    return $einheiten;
}

/**
 * Einheiten-System: Einheit-ID für Admin-Filter (null = alle, sonst nur diese Einheit)
 * Superadmin: filtert nach ausgewählter Einheit (current_einheit_id), wenn gesetzt
 * Einheitsadmin/User: IMMER strikt nach Benutzer-Einheit filtern – keine Daten anderer Einheiten
 */
function get_admin_einheit_filter() {
    global $db;
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) return null;
    if (is_superadmin($user_id)) {
        $cur = get_current_einheit_id();
        return $cur ? (int)$cur : null;
    }
    if (is_einheitsadmin($user_id)) {
        $eid = $_SESSION['einheit_id'] ?? null;
        if ($eid) return (int)$eid;
        $eid = get_current_einheit_id();
        if ($eid) return (int)$eid;
    }
    // Reguläre Benutzer und Einheitsadmins ohne Session: strikt nach Benutzer-Einheit
    try {
        $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $eid = $row ? (int)($row['einheit_id'] ?? 0) : 0;
        return $eid > 0 ? $eid : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Einheiten-System: Kann Benutzer Einheit wechseln?
 * Superadmin und Admins (hasAdminPermission) dürfen die Einheit im Menü wechseln.
 */
function can_switch_einheit() {
    return is_logged_in() && !is_system_user() && (is_superadmin() || (function_exists('hasAdminPermission') && hasAdminPermission()));
}

/**
 * Datum formatieren
 */
function format_date($date, $format = 'd.m.Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Datum und Uhrzeit formatieren
 */
function format_datetime($datetime, $format = 'd.m.Y H:i') {
    if (empty($datetime)) return '';
    return date($format, strtotime($datetime));
}

/**
 * Datum/Uhrzeit aus DB (UTC) in Europe/Berlin für Anzeige formatieren.
 * Behebt 1-Stunden-Versatz bei created_at/updated_at aus MySQL TIMESTAMP.
 */
function format_datetime_berlin($datetime, $format = 'd.m.Y H:i') {
    if (empty($datetime)) return '';
    try {
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($format);
    } catch (Exception $e) {
        return date($format, strtotime($datetime));
    }
}

/**
 * Status Badge generieren
 */
function get_status_badge($status) {
    switch($status) {
        case 'pending':
            return '<span class="badge badge-pending">Ausstehend</span>';
        case 'approved':
            return '<span class="badge badge-approved">Genehmigt</span>';
        case 'rejected':
            return '<span class="badge badge-rejected">Abgelehnt</span>';
        default:
            return '<span class="badge bg-secondary">Unbekannt</span>';
    }
}

/**
 * CSRF Token generieren
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token();
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token validieren
 */
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Logging
 */
function log_activity($user_id, $action, $details = '') {
    global $db;
    
    try {
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action, $details]);
    } catch(PDOException $e) {
        error_log("Logging error: " . $e->getMessage());
    }
}

/**
 * Google Kalender API - Konflikte prüfen
 */
function check_calendar_conflicts($vehicle_name, $start_datetime, $end_datetime) {
    global $db;
    
    try {
        // Setze aggressive Timeouts
        set_time_limit(60);
        ini_set('default_socket_timeout', 30);
        ini_set('max_execution_time', 60);
        
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';

        if ($auth_type === 'service_account') {
            $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
            if (class_exists('GoogleCalendarServiceAccount') && !empty($service_account_json)) {
                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                $events = $google_calendar->getEvents($start_datetime, $end_datetime);
                $conflicts = [];
                if ($events && is_array($events)) {
                    foreach ($events as $event) {
                        // Ignoriere stornierte Events (cancelled)
                        if (isset($event['status']) && $event['status'] === 'cancelled') {
                            continue;
                        }
                        
                        // Prüfe nur Events, die das exakte Fahrzeug enthalten (nicht andere Fahrzeuge)
                        if (isset($event['summary']) && stripos($event['summary'], $vehicle_name) !== false) {
                            $conflicts[] = [
                                'title' => $event['summary'],
                                'start' => $event['start']['dateTime'] ?? $event['start']['date'],
                                'end' => $event['end']['dateTime'] ?? $event['end']['date']
                            ];
                        }
                    }
                }
                return $conflicts;
            }
        } else {
            $api_key = $settings['google_calendar_api_key'] ?? '';
            if (class_exists('GoogleCalendar') && !empty($api_key)) {
                $google_calendar = new GoogleCalendar($api_key, $calendar_id);
                $events = $google_calendar->getEvents($start_datetime, $end_datetime);
                $conflicts = [];
                if ($events && is_array($events)) {
                    foreach ($events as $event) {
                        // Prüfe nur Events, die das exakte Fahrzeug enthalten (nicht andere Fahrzeuge)
                        if (isset($event['summary']) && stripos($event['summary'], $vehicle_name) !== false) {
                            $conflicts[] = [
                                'title' => $event['summary'],
                                'start' => $event['start']['dateTime'] ?? $event['start']['date'],
                                'end' => $event['end']['dateTime'] ?? $event['end']['date']
                            ];
                        }
                    }
                }
                return $conflicts;
            }
        }
        return [];
    } catch (Exception $e) {
        error_log('Google Calendar Konfliktprüfung Fehler: ' . $e->getMessage());
        return [];
    }
}

/**
 * Google Calendar Event löschen
 */
function delete_google_calendar_event($event_id) {
    global $db;
    
    try {
        if (!class_exists('GoogleCalendarServiceAccount')) {
            error_log('GoogleCalendarServiceAccount Klasse nicht verfügbar');
            return false;
        }
        
        // Lade Google Calendar Einstellungen (wie bei create_google_calendar_event)
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
        
        if (empty($service_account_json)) {
            error_log('Google Calendar Service Account JSON nicht gefunden');
            return false;
        }
        
        // Erstelle GoogleCalendarServiceAccount mit korrekten Parametern
        $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
        
        // Verwende die öffentliche deleteEvent Methode
        error_log("GC DELETE: Versuche deleteEvent für $event_id");
        
        // Zusätzlich in Datenbank loggen
        try {
            $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
            $stmt->execute(['INFO', "GC DELETE: Versuche deleteEvent für $event_id", "delete_google_calendar_event"]);
        } catch (Exception $e) {
            // Ignoriere DB-Log-Fehler
        }
        
        $result = $calendar_service->deleteEvent($event_id);
        if ($result) {
            error_log("GC DELETE: deleteEvent erfolgreich: $event_id");
            
            // Erfolg in Datenbank loggen
            try {
                $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                $stmt->execute(['INFO', "GC DELETE: deleteEvent erfolgreich: $event_id", "delete_google_calendar_event"]);
            } catch (Exception $e) {
                // Ignoriere DB-Log-Fehler
            }
            
            return true;
        }
        // Manche Bibliotheken geben false zurück, wenn das Event bereits nicht mehr existiert
        // Behandle 404/410 logisch als Erfolg: Event ist nicht mehr im Kalender
        if (method_exists($calendar_service, 'getLastErrorCode')) {
            $code = $calendar_service->getLastErrorCode();
            if (in_array($code, [404, 410], true)) {
                error_log("GC DELETE: Event bereits weg (HTTP $code): $event_id -> behandle als Erfolg");
                return true;
            }
        }
        
        error_log("GC DELETE: Beide Löschmethoden fehlgeschlagen für $event_id");
        return false;
    } catch (Exception $e) {
        error_log('Fehler beim Löschen des Google Calendar Events: ' . $e->getMessage());
        return false;
    }
}

/**
 * Google Calendar Event per Titel/Zeitraum finden und löschen (Fallback)
 */
function delete_google_calendar_event_by_hint($title, $start_datetime, $end_datetime) {
    global $db;
    try {
        // Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';

        if ($auth_type !== 'service_account' || empty($service_account_json)) {
            error_log('GC DELETE HINT: Service Account nicht konfiguriert, breche ab');
            return false;
        }

        if (!class_exists('GoogleCalendarServiceAccount')) {
            error_log('GC DELETE HINT: GoogleCalendarServiceAccount Klasse nicht verfügbar');
            return false;
        }
        $svc = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
        // Hole Events im Zeitraum (kleines Pufferfenster von +/- 10 Minuten)
        // Prüfe, ob die Service-Account-Klasse das Listen von Events unterstützt
        // Logge Paramater in DB (falls Tabelle vorhanden)
        try {
            $stmt2 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
            $stmt2->execute(['DEBUG', 'GC DELETE HINT: Aufruf mit title=' . $title . ', start=' . $start_datetime . ', end=' . $end_datetime, 'delete_google_calendar_event_by_hint']);
        } catch (Throwable $t) {}

        if (!method_exists($svc, 'getEvents')) {
            error_log('GC DELETE HINT: getEvents() nicht verfügbar in GoogleCalendarServiceAccount – Fallback übersprungen');
            try {
                $stmt3 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                $stmt3->execute(['INFO', 'GC DELETE HINT: getEvents() nicht verfügbar – Fallback übersprungen', 'delete_google_calendar_event_by_hint']);
            } catch (Throwable $t) {}
            return false;
        }
        // Zeitfenster: großzügiger suchen (±24h), um Parser-/Zeitzonenabweichungen zu tolerieren
        $timeWindowSeconds = 24 * 3600;
        $start = date('c', strtotime($start_datetime) - $timeWindowSeconds);
        $end = date('c', strtotime($end_datetime) + $timeWindowSeconds);

        // Query-Strategien: 1) voller Titel, 2) nur Fahrzeugteil vor " - ", 3) nur Reason
        $vehiclePart = trim(strtok($title, '-'));
        $reasonPart = '';
        if (strpos($title, '-') !== false) {
            $parts = explode('-', $title, 2);
            $vehiclePart = trim($parts[0]);
            $reasonPart = trim($parts[1]);
        }

        $candidates = [];
        $queries = array_values(array_unique(array_filter([$title, $vehiclePart, $reasonPart])));
        foreach ($queries as $q) {
            $result = $svc->getEvents($start, $end, $q);
            if (is_array($result)) {
                $candidates = array_merge($candidates, $result);
                try {
                    $stmtCnt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                    $stmtCnt->execute(['DEBUG', 'GC DELETE HINT: Query "' . $q . '" lieferte ' . count($result) . ' Treffer', 'delete_google_calendar_event_by_hint']);
                } catch (Throwable $t) {}
            }
        }
        // Duplikate nach Event-ID entfernen
        $unique = [];
        foreach ($candidates as $ev) {
            $eid = $ev['id'] ?? null;
            if ($eid && !isset($unique[$eid])) {
                $unique[$eid] = $ev;
            }
        }
        $events = array_values($unique);
        try {
            $stmtCnt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
            $stmtCnt->execute(['DEBUG', 'GC DELETE HINT: Gesamtkandidaten nach Merge: ' . count($events), 'delete_google_calendar_event_by_hint']);
        } catch (Throwable $t) {}
        if (!$events || !is_array($events)) {
            error_log('GC DELETE HINT: Keine Events im Zeitraum gefunden');
            try {
                $stmt4 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                $stmt4->execute(['INFO', 'GC DELETE HINT: Keine Events im Zeitraum gefunden', 'delete_google_calendar_event_by_hint']);
            } catch (Throwable $t) {}
            return false;
        }

        $deletedAny = false;
        $requestedStartTs = strtotime($start_datetime);
        $requestedEndTs = strtotime($end_datetime);
        $toleranceSeconds = 90 * 60; // 90 Minuten Toleranz
        foreach ($events as $event) {
            $summary = $event['summary'] ?? '';
            $eid = $event['id'] ?? '';
            if (!$eid) continue;
            // Zeit aus Event bestimmen
            $evStart = $event['start']['dateTime'] ?? ($event['start']['date'] ?? null);
            $evEnd = $event['end']['dateTime'] ?? ($event['end']['date'] ?? null);
            $evStartTs = $evStart ? strtotime($evStart) : null;
            $evEndTs = $evEnd ? strtotime($evEnd) : null;

            // Heuristik: Titel-Match locker + Zeitliche Nähe
            $titleMatches = (
                $summary === $title ||
                stripos($summary, $title) !== false ||
                ($vehiclePart && stripos($summary, $vehiclePart) !== false)
            );
            $timeMatches = ($evStartTs && $evEndTs &&
                abs($evStartTs - $requestedStartTs) <= $toleranceSeconds &&
                abs($evEndTs - $requestedEndTs) <= $toleranceSeconds);

            if ($titleMatches || $timeMatches) {
                $ok = $svc->deleteEvent($eid);
                if ($ok) {
                    error_log('GC DELETE HINT: Event per Hint gelöscht: ' . $eid . ' (' . $summary . ')');
                    try {
                        $stmt5 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                        $stmt5->execute(['INFO', 'GC DELETE HINT: Event per Hint gelöscht: ' . $eid . ' (' . $summary . ')', 'delete_google_calendar_event_by_hint']);
                    } catch (Throwable $t) {}
                    $deletedAny = true;
                } else {
                    error_log('GC DELETE HINT: Löschen per Hint fehlgeschlagen: ' . $eid . ' (' . $summary . ')');
                    try {
                        $stmt6 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                        $stmt6->execute(['WARNING', 'GC DELETE HINT: Löschen per Hint fehlgeschlagen: ' . $eid . ' (' . $summary . ')', 'delete_google_calendar_event_by_hint']);
                    } catch (Throwable $t) {}
                }
            }
        }
        return $deletedAny;
    } catch (Exception $e) {
        error_log('GC DELETE HINT: Exception: ' . $e->getMessage());
        try {
            $stmt7 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
            $stmt7->execute(['ERROR', 'GC DELETE HINT: Exception: ' . $e->getMessage(), 'delete_google_calendar_event_by_hint']);
        } catch (Throwable $t) {}
        return false;
    }
}

/**
 * Intelligente Google Calendar Event-Erstellung bei Genehmigung
 * Prüft ob bereits ein Event mit gleichem Zeitraum/Grund existiert und erweitert den Titel
 */
function create_or_update_google_calendar_event($vehicle_name, $reason, $start_datetime, $end_datetime, $reservation_id, $location = null) {
    global $db;
    
    error_log('=== Intelligente Google Calendar Event-Erstellung ===');
    error_log('Parameter: vehicle_name=' . $vehicle_name . ', reason=' . $reason . ', start=' . $start_datetime . ', end=' . $end_datetime . ', reservation_id=' . $reservation_id);
    
    try {
        // Prüfe ob bereits ein Event mit gleichem Zeitraum und Grund existiert
        $stmt = $db->prepare("
            SELECT ce.google_event_id, ce.title 
            FROM calendar_events ce 
            JOIN reservations r ON ce.reservation_id = r.id 
            WHERE r.start_datetime = ? AND r.end_datetime = ? AND r.reason = ?
            LIMIT 1
        ");
        $stmt->execute([$start_datetime, $end_datetime, $reason]);
        $existing_event = $stmt->fetch();
        
        if ($existing_event) {
            // Event existiert bereits - erweitere den Titel
            error_log('Bestehendes Event gefunden: ' . $existing_event['google_event_id']);
            
            // Prüfe ob das Fahrzeug bereits im Titel steht
            $current_title = $existing_event['title'];
            if (stripos($current_title, $vehicle_name) === false) {
                // Fahrzeug noch nicht im Titel - baue kanonischen Titel: "Fahrzeuge, ... - Grund"
                $canonicalVehicles = $current_title;
                // Wenn der aktuelle Titel das Muster " - <reason>" enthält, entferne den Grund
                $needle = ' - ' . $reason;
                if (stripos($current_title, $needle) !== false) {
                    $canonicalVehicles = trim(str_ireplace($needle, '', $current_title));
                }
                // Fahrzeuge extrahieren und vereinigen
                $vehicleParts = array_filter(array_map('trim', explode(',', $canonicalVehicles)));
                // Sicherstellen, dass das aktuelle Fahrzeug enthalten ist
                if (!in_array($vehicle_name, $vehicleParts, true)) {
                    $vehicleParts[] = $vehicle_name;
                }
                $vehiclesJoined = implode(', ', $vehicleParts);
                $new_title = $vehiclesJoined . ' - ' . $reason;
                
                // Aktualisiere den Google Calendar Event
                $update_success = update_google_calendar_event_title($existing_event['google_event_id'], $new_title);
                
                if ($update_success) {
                    // Aktualisiere alle betroffenen calendar_events Einträge
                $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
                    $stmt->execute([$new_title, $existing_event['google_event_id']]);
                    
                    // Prüfe ob bereits ein calendar_events Eintrag für diese Reservierung existiert
                    $stmt = $db->prepare("SELECT id FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation_id]);
                    $existing_calendar_event = $stmt->fetch();
                    
                    if (!$existing_calendar_event) {
                        // Speichere die neue Reservierung mit der bestehenden Google Event ID
                        $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$reservation_id, $existing_event['google_event_id'], $new_title, $start_datetime, $end_datetime]);
                        error_log('Neuer calendar_events Eintrag für Reservierung ' . $reservation_id . ' erstellt');
                    } else {
                        error_log('calendar_events Eintrag für Reservierung ' . $reservation_id . ' existiert bereits');
                    }
                    
                    error_log('Event-Titel erfolgreich erweitert: ' . $new_title);
                    return $existing_event['google_event_id'];
                } else {
                    error_log('Fehler beim Aktualisieren des Event-Titels');
                    return false;
                }
            } else {
                // Fahrzeug bereits im Titel - prüfe ob calendar_events Eintrag existiert
                $stmt = $db->prepare("SELECT id FROM calendar_events WHERE reservation_id = ?");
                $stmt->execute([$reservation_id]);
                $existing_calendar_event = $stmt->fetch();
                
                if (!$existing_calendar_event) {
                    // Nur calendar_events Eintrag hinzufügen
                    // Stelle sicher, dass Titel im kanonischen Format vorliegt
                    $normalized_title = $current_title;
                    $needle = ' - ' . $reason;
                    if (stripos($current_title, $needle) === false) {
                        // Falls Grund fehlt, hänge ihn an
                        $normalized_title = trim($current_title) . ' - ' . $reason;
                    }
                    $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$reservation_id, $existing_event['google_event_id'], $normalized_title, $start_datetime, $end_datetime]);
                    error_log('Neuer calendar_events Eintrag für Reservierung ' . $reservation_id . ' erstellt (Fahrzeug bereits im Titel)');
                } else {
                    error_log('calendar_events Eintrag für Reservierung ' . $reservation_id . ' existiert bereits (Fahrzeug bereits im Titel)');
                }
                
                error_log('Fahrzeug bereits im Titel - nur calendar_events Eintrag hinzugefügt');
                return $existing_event['google_event_id'];
            }
        } else {
            // Kein Event existiert - erstelle neues
            error_log('Kein bestehendes Event gefunden - erstelle neues');
            $title = $vehicle_name . ' - ' . $reason;
            
            error_log('Rufe create_google_calendar_event auf mit: title=' . $title . ', reason=' . $reason . ', start=' . $start_datetime . ', end=' . $end_datetime . ', reservation_id=' . $reservation_id . ', location=' . ($location ?? 'null'));
            
            $google_event_id = create_google_calendar_event($title, $reason, $start_datetime, $end_datetime, $reservation_id, $location);
            
            error_log('create_google_calendar_event Rückgabe: ' . ($google_event_id ? $google_event_id : 'FALSE'));
            
            // create_google_calendar_event erstellt bereits den calendar_events Eintrag
            // Daher kein zusätzliches INSERT nötig
            
            error_log('create_or_update_google_calendar_event gibt zurück: ' . ($google_event_id ? $google_event_id : 'FALSE'));
            return $google_event_id;
        }
        
    } catch (Exception $e) {
        error_log('Fehler bei intelligenter Google Calendar Event-Erstellung: ' . $e->getMessage());
        return false;
    }
}

/**
 * Google Calendar Event Titel aktualisieren
 */
function update_google_calendar_event_title($google_event_id, $new_title) {
    error_log('=== update_google_calendar_event_title Start ===');
    error_log('Parameter: google_event_id=' . $google_event_id . ', new_title=' . $new_title);
    
    try {
        // Google Calendar Einstellungen laden
        global $db;
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        
        error_log('update_google_calendar_event_title: auth_type=' . $auth_type . ', calendar_id=' . $calendar_id);
        
        if ($auth_type === 'service_account') {
            $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
            if (class_exists('GoogleCalendarServiceAccount') && !empty($service_account_json)) {
                error_log('update_google_calendar_event_title: Erstelle GoogleCalendarServiceAccount');
                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                
                // Hole das bestehende Event
                error_log('update_google_calendar_event_title: Hole Event ' . $google_event_id);
                $event = $google_calendar->getEvent($google_event_id);
                if ($event) {
                    error_log('update_google_calendar_event_title: Event gefunden, aktueller Titel: ' . ($event['summary'] ?? 'N/A'));
                    // Aktualisiere den Titel
                    $event['summary'] = $new_title;
                    
                    // Update das Event
                    error_log('update_google_calendar_event_title: Update Event mit neuem Titel: ' . $new_title);
                    $result = $google_calendar->updateEvent($google_event_id, $event);
                    error_log('update_google_calendar_event_title: Update Ergebnis: ' . ($result ? 'TRUE' : 'FALSE'));
                    return $result !== false;
                } else {
                    error_log('update_google_calendar_event_title: Event nicht gefunden');
                }
            } else {
                error_log('update_google_calendar_event_title: GoogleCalendarServiceAccount nicht verfügbar oder Service Account JSON leer');
            }
        } else {
            error_log('update_google_calendar_event_title: Auth Type ist nicht service_account: ' . $auth_type);
        }
        
        error_log('update_google_calendar_event_title: Rückgabe FALSE');
        return false;
    } catch (Exception $e) {
        error_log('Fehler beim Aktualisieren des Google Calendar Event-Titels: ' . $e->getMessage());
        error_log('update_google_calendar_event_title: Exception Stack Trace: ' . $e->getTraceAsString());
        return false;
    }
}

/**
 * Google Kalender API - Event erstellen
 */
function create_google_calendar_event($title, $reason, $start_datetime, $end_datetime, $reservation_id = null, $location = null) {
    global $db;
    
    // Sofortiges Logging am Anfang
    error_log('=== Google Calendar Event Start ===');
    error_log('Parameter: title=' . $title . ', reason=' . $reason . ', start=' . $start_datetime . ', end=' . $end_datetime . ', reservation_id=' . $reservation_id . ', location=' . ($location ?? 'null'));
    
    try {
        // Google Calendar Einstellungen laden
        error_log('Google Calendar: Lade Einstellungen...');
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        
        error_log('Google Calendar: Einstellungen geladen - auth_type=' . $auth_type . ', calendar_id=' . $calendar_id);
        
        if ($auth_type === 'service_account') {
            // Service Account verwenden
            $service_account_file = $settings['google_calendar_service_account_file'] ?? '';
            $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
            
            error_log('Google Calendar: Service Account - file=' . (!empty($service_account_file) ? 'gesetzt' : 'leer') . ', json=' . (!empty($service_account_json) ? 'gesetzt' : 'leer'));
            
            // Prüfe ob Service Account Klasse verfügbar ist
            if (class_exists('GoogleCalendarServiceAccount')) {
                error_log('Google Calendar: Service Account Klasse verfügbar');
                // JSON-Inhalt hat Priorität über Datei
                if (!empty($service_account_json)) {
                    // JSON-Inhalt verwenden
                    error_log('Google Calendar: Verwende JSON-Inhalt');
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                } elseif (!empty($service_account_file) && file_exists($service_account_file)) {
                    // Datei verwenden
                    error_log('Google Calendar: Verwende Datei');
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_file, $calendar_id, false);
                } else {
                    error_log('Google Calendar Service Account nicht konfiguriert (weder Datei noch JSON-Inhalt)');
                    return false;
                }
            } else {
                error_log('Google Calendar Service Account Klasse nicht verfügbar - Google Calendar deaktiviert');
                return false;
            }
        } else {
            // API Key verwenden (Fallback)
            $api_key = $settings['google_calendar_api_key'] ?? '';
            
            if (empty($api_key)) {
                error_log('Google Calendar API Key nicht konfiguriert');
                return false;
            }
            
            if (class_exists('GoogleCalendar')) {
                $google_calendar = new GoogleCalendar($api_key, $calendar_id);
            } else {
                error_log('Google Calendar Klasse nicht verfügbar - Google Calendar deaktiviert');
                return false;
            }
        }
        
        // Event-Details erstellen
        // $title wird bereits als Parameter übergeben (kann mehrere Fahrzeuge enthalten)
        $description = ''; // Keine Beschreibung mehr
        $event_location = $location ?? 'Nicht angegeben';
        
        error_log('Google Calendar: Event-Details - title=' . $title . ', location=' . $event_location);
        
        // Setze aggressive Timeouts für die API-Anfrage
        set_time_limit(120); // 120 Sekunden Timeout
        ini_set('default_socket_timeout', 60); // 60 Sekunden Socket-Timeout
        ini_set('max_execution_time', 120); // 120 Sekunden Max Execution Time
        
        // Event erstellen
        error_log('Google Calendar: Versuche Event zu erstellen - Titel: ' . $title . ', Start: ' . $start_datetime . ', Ende: ' . $end_datetime . ', Ort: ' . $event_location);
        $event_id = $google_calendar->createEvent($title, $start_datetime, $end_datetime, $description, $event_location);
        error_log('Google Calendar: createEvent Rückgabe: ' . ($event_id ? $event_id : 'false'));
        
        if ($event_id && $reservation_id) {
            // Event ID in der Datenbank speichern
            try {
                $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$reservation_id, $event_id, $title, $start_datetime, $end_datetime]);
                error_log('Google Calendar: Event in Datenbank gespeichert - ID: ' . $event_id);
            } catch (Exception $e) {
                error_log('Google Calendar: Fehler beim Speichern in Datenbank: ' . $e->getMessage());
            }
        }
        
        error_log('=== Google Calendar Event Ende - Erfolg: ' . ($event_id ? 'JA' : 'NEIN') . ' ===');
        return $event_id;
    } catch (Exception $e) {
        error_log('Google Calendar Fehler: ' . $e->getMessage());
        error_log('=== Google Calendar Event Ende - Fehler ===');
        return false;
    }
}

/**
 * Entfernt ein Fahrzeug aus einem Google Calendar Event-Titel
 * @param string $google_event_id Google Event ID
 * @param string $vehicle_name Fahrzeugname zum Entfernen
 * @param int $reservation_id Reservierungs-ID (für Löschung falls letztes Fahrzeug)
 * @return bool Erfolg
 */
function remove_vehicle_from_calendar_event($google_event_id, $vehicle_name, $reservation_id = null) {
    global $db;
    
    try {
        error_log("REMOVE VEHICLE: Starte Entfernung von '$vehicle_name' aus Event $google_event_id");
        
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
        
        if ($auth_type !== 'service_account' || empty($service_account_json)) {
            error_log('REMOVE VEHICLE: Service Account nicht konfiguriert');
            return false;
        }
        
        if (!class_exists('GoogleCalendarServiceAccount')) {
            error_log('REMOVE VEHICLE: GoogleCalendarServiceAccount Klasse nicht verfügbar');
            return false;
        }
        
        $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
        
        // Event abrufen
        $event = $google_calendar->getEvent($google_event_id);
        if (!$event) {
            error_log("REMOVE VEHICLE: Event $google_event_id nicht gefunden");
            return false;
        }
        
        $current_title = $event['summary'] ?? '';
        error_log("REMOVE VEHICLE: Aktueller Titel: $current_title");
        
        // Titel in Fahrzeugs-Teil und Grund aufteilen
        $titleParts = explode(' - ', $current_title, 2);
        $vehiclesPart = trim($titleParts[0] ?? '');
        $reasonPart = trim($titleParts[1] ?? '');

        // Liste der Fahrzeuge aus dem vorderen Titelteil extrahieren (kommagetrennt)
        $vehicles = array_filter(array_map('trim', explode(',', $vehiclesPart)), function($v) { return $v !== ''; });

        // Entferne das gewünschte Fahrzeug (case-insensitive Vergleich)
        $vehiclesRemaining = [];
        foreach ($vehicles as $v) {
            if (strcasecmp($v, $vehicle_name) !== 0) {
                $vehiclesRemaining[] = $v;
            }
        }

        if (empty($vehiclesRemaining)) {
            // Kein Fahrzeug mehr im Titel -> gesamten Kalendereintrag löschen
            error_log("REMOVE VEHICLE: Keine Fahrzeuge mehr im Titel – lösche komplettes Event $google_event_id");
            $deleted = $google_calendar->deleteEvent($google_event_id);
            if ($deleted) {
                try {
                    // Alle Verknüpfungen auf dieses Event entfernen
                    $stmt = $db->prepare("DELETE FROM calendar_events WHERE google_event_id = ?");
                    $stmt->execute([$google_event_id]);
                    
                    // Auch die Reservierung selbst löschen, da sie nicht mehr im Kalender existiert
                    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                    $stmt->execute([$reservation_id]);
                    error_log("REMOVE VEHICLE: Reservierung $reservation_id ebenfalls gelöscht");
                    
                } catch (Exception $e) {
                    error_log('REMOVE VEHICLE: Fehler beim Entfernen der DB-Verknüpfungen: ' . $e->getMessage());
                }
                return true;
            }
            error_log("REMOVE VEHICLE: Löschen des Events fehlgeschlagen");
            return false;
        }

        // Neuen Titel zusammenbauen: verbleibende Fahrzeuge + unveränderter Grund
        $newVehiclesPart = implode(', ', $vehiclesRemaining);
        $new_title = $reasonPart !== '' ? ($newVehiclesPart . ' - ' . $reasonPart) : $newVehiclesPart;
        error_log("REMOVE VEHICLE: Neuer Titel: $new_title");
        
        // Event aktualisieren
        $event['summary'] = $new_title;
        $result = $google_calendar->updateEvent($google_event_id, $event);
        
        if ($result) {
            // Kalender-Event-Titel in allen Verknüpfungen aktualisieren
            $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
            $stmt->execute([$new_title, $google_event_id]);

            // Spezifische Reservierung (das entfernte Fahrzeug) aus DB entfernen
            if (!empty($reservation_id)) {
                try {
                    $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation_id]);
                } catch (Exception $e) {
                    error_log('REMOVE VEHICLE: Fehler beim Entfernen calendar_events für Reservierung ' . $reservation_id . ': ' . $e->getMessage());
                }
                try {
                    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                    $stmt->execute([$reservation_id]);
                    error_log("REMOVE VEHICLE: Reservierung $reservation_id nach Teil-Entfernung gelöscht");
                } catch (Exception $e) {
                    error_log('REMOVE VEHICLE: Fehler beim Löschen der Reservierung ' . $reservation_id . ': ' . $e->getMessage());
                }
            }

            error_log("REMOVE VEHICLE: Erfolgreich - Fahrzeug '$vehicle_name' aus Event entfernt und Reservierung bereinigt");
            return true;
        } else {
            error_log("REMOVE VEHICLE: Fehler beim Aktualisieren des Events");
            return false;
        }
        
    } catch (Exception $e) {
        error_log('REMOVE VEHICLE: Exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Entfernt einen Fahrzeugnamen aus einem Titel-String
 * @param string $title Aktueller Titel
 * @param string $vehicle_name Fahrzeugname zum Entfernen
 * @return string Neuer Titel
 */
function remove_vehicle_from_title($title, $vehicle_name) {
    // Verschiedene Muster für Fahrzeug-Entfernung
    $patterns = [
        // "Fahrzeug1, Fahrzeug2 - Grund" -> "Fahrzeug2 - Grund" (wenn Fahrzeug1 entfernt wird)
        '/\b' . preg_quote($vehicle_name, '/') . '\s*,\s*/',
        // ", Fahrzeug1" am Ende
        '/,\s*' . preg_quote($vehicle_name, '/') . '\s*$/',
        // "Fahrzeug1" am Anfang
        '/^' . preg_quote($vehicle_name, '/') . '\s*,\s*/',
        // "Fahrzeug1 - Grund" -> "Grund" (wenn nur ein Fahrzeug)
        '/^' . preg_quote($vehicle_name, '/') . '\s*-\s*/',
    ];
    
    $new_title = $title;
    foreach ($patterns as $pattern) {
        $new_title = preg_replace($pattern, '', $new_title);
    }
    
    // Aufräumen: doppelte Leerzeichen, führende/nachfolgende Kommas
    $new_title = preg_replace('/\s+/', ' ', $new_title);
    $new_title = preg_replace('/^,\s*|,\s*$/', '', $new_title);
    $new_title = trim($new_title);
    
    return $new_title;
}

/**
 * Kollisionsprüfung für Fahrzeugreservierungen
 */
function check_vehicle_conflict($vehicle_id, $start_datetime, $end_datetime, $exclude_id = null) {
    global $db;
    
    try {
        $sql = "SELECT id FROM reservations 
                WHERE vehicle_id = ? 
                AND status = 'approved' 
                AND ((start_datetime <= ? AND end_datetime > ?) 
                     OR (start_datetime < ? AND end_datetime >= ?) 
                     OR (start_datetime >= ? AND end_datetime <= ?))";
        
        $params = [$vehicle_id, $start_datetime, $start_datetime, $end_datetime, $end_datetime, $start_datetime, $end_datetime];
        
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch() !== false;
    } catch(PDOException $e) {
        error_log("Conflict check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Prüft ob ein Raum für den angegebenen Zeitraum bereits genehmigt reserviert ist.
 */
function check_room_conflict($room_id, $start_datetime, $end_datetime, $exclude_id = null) {
    global $db;
    try {
        $sql = "SELECT id FROM room_reservations
                WHERE room_id = ?
                AND status = 'approved'
                AND ((start_datetime <= ? AND end_datetime > ?)
                     OR (start_datetime < ? AND end_datetime >= ?)
                     OR (start_datetime >= ? AND end_datetime <= ?))";
        $params = [$room_id, $start_datetime, $start_datetime, $end_datetime, $end_datetime, $start_datetime, $end_datetime];
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        error_log("Room conflict check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Liefert Details zu bestehenden Raumreservierungen, die mit dem angegebenen Zeitraum kollidieren.
 * @return array Liste der Konflikte mit id, requester_name, room_name, start_datetime, end_datetime, reason
 */
function get_room_conflicts($room_id, $start_datetime, $end_datetime, $exclude_id = null) {
    global $db;
    try {
        $sql = "SELECT rr.id, rr.requester_name, rr.start_datetime, rr.end_datetime, rr.reason, ro.name as room_name
                FROM room_reservations rr
                JOIN rooms ro ON rr.room_id = ro.id
                WHERE rr.room_id = ?
                AND rr.status = 'approved'
                AND ((rr.start_datetime <= ? AND rr.end_datetime > ?)
                     OR (rr.start_datetime < ? AND rr.end_datetime >= ?)
                     OR (rr.start_datetime >= ? AND rr.end_datetime <= ?))";
        $params = [$room_id, $start_datetime, $start_datetime, $end_datetime, $end_datetime, $start_datetime, $end_datetime];
        if ($exclude_id) {
            $sql .= " AND rr.id != ?";
            $params[] = $exclude_id;
        }
        $sql .= " ORDER BY rr.start_datetime";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("get_room_conflicts error: " . $e->getMessage());
        return [];
    }
}

/**
 * Verknüpft einen Atemschutzgeräteträger mit einem Mitglied
 * Erstellt automatisch ein Mitglied, falls noch keines existiert
 * 
 * @param int $traeger_id Die ID des Geräteträgers
 * @param string $first_name Vorname
 * @param string $last_name Nachname
 * @param string|null $email E-Mail (optional)
 * @param string|null $birthdate Geburtsdatum (optional)
 * @return int|null Die member_id oder null bei Fehler
 */
function link_traeger_to_member($traeger_id, $first_name, $last_name, $email = null, $birthdate = null) {
    global $db;
    
    try {
        // Sicherstellen, dass members Tabelle existiert
        $db->exec(
            "CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NULL,
                birthdate DATE NULL,
                phone VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
        
        // Sicherstellen, dass member_id Spalte in atemschutz_traeger existiert
        try {
            $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        
        // Foreign Key hinzufügen falls nicht vorhanden
        try {
            $stmt = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atemschutz_traeger' AND COLUMN_NAME = 'member_id' AND REFERENCED_TABLE_NAME = 'members'");
            if (!$stmt->fetch()) {
                $db->exec("ALTER TABLE atemschutz_traeger ADD FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL");
            }
        } catch (Exception $e) {
            // Foreign Key existiert bereits oder Fehler, ignoriere
        }
        
        // Prüfe, ob bereits ein Mitglied mit diesem Namen existiert
        // Priorität: Mitglied mit user_id > Mitglied ohne user_id
        $stmt = $db->prepare("
            SELECT id, user_id FROM members 
            WHERE first_name = ? AND last_name = ?
            ORDER BY user_id IS NULL ASC
            LIMIT 1
        ");
        $stmt->execute([$first_name, $last_name]);
        $existing_member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_member) {
            // Mitglied existiert bereits, verknüpfe es
            $member_id = $existing_member['id'];
            
            // Aktualisiere E-Mail und Geburtsdatum falls vorhanden (nur wenn noch nicht gesetzt)
            if ($email || $birthdate) {
                $update_fields = [];
                $update_params = [];
                
                if ($email) {
                    // Nur aktualisieren wenn noch keine E-Mail vorhanden
                    $stmt_check = $db->prepare("SELECT email FROM members WHERE id = ?");
                    $stmt_check->execute([$member_id]);
                    $current = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    if (empty($current['email'])) {
                        $update_fields[] = "email = ?";
                        $update_params[] = $email;
                    }
                }
                if ($birthdate) {
                    // Nur aktualisieren wenn noch kein Geburtsdatum vorhanden
                    $stmt_check = $db->prepare("SELECT birthdate FROM members WHERE id = ?");
                    $stmt_check->execute([$member_id]);
                    $current = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    if (empty($current['birthdate'])) {
                        $update_fields[] = "birthdate = ?";
                        $update_params[] = $birthdate;
                    }
                }
                
                if (!empty($update_fields)) {
                    $update_params[] = $member_id;
                    $stmt = $db->prepare("UPDATE members SET " . implode(", ", $update_fields) . " WHERE id = ?");
                    $stmt->execute($update_params);
                }
            }
        } else {
            // Neues Mitglied erstellen (automatisch als PA-Träger markieren)
            $stmt = $db->prepare("
                INSERT INTO members (first_name, last_name, email, birthdate, is_pa_traeger) 
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$first_name, $last_name, $email, $birthdate]);
            $member_id = $db->lastInsertId();
        }
        
        // Stelle sicher, dass is_pa_traeger = 1 gesetzt ist (da es ein Geräteträger ist)
        $stmt = $db->prepare("UPDATE members SET is_pa_traeger = 1 WHERE id = ?");
        $stmt->execute([$member_id]);
        
        // Verknüpfe Geräteträger mit Mitglied
        $stmt = $db->prepare("UPDATE atemschutz_traeger SET member_id = ? WHERE id = ?");
        $stmt->execute([$member_id, $traeger_id]);
        
        return $member_id;
    } catch (Exception $e) {
        error_log("Fehler beim Verknüpfen von Geräteträger mit Mitglied: " . $e->getMessage());
        return null;
    }
}

/**
 * Synchronisiert alle bestehenden Geräteträger mit Mitgliedern
 * 
 * @return int Anzahl der verknüpften Geräteträger
 */
function sync_all_traeger_to_members() {
    global $db;
    
    try {
        // Sicherstellen, dass Tabellen existieren
        $db->exec(
            "CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NULL,
                birthdate DATE NULL,
                phone VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
        
        try {
            $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
        } catch (Exception $e) {
            // Spalte existiert bereits
        }
        
        // Lade alle Geräteträger ohne member_id
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, birthdate 
            FROM atemschutz_traeger 
            WHERE member_id IS NULL
        ");
        $stmt->execute();
        $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($traeger as $t) {
            $member_id = link_traeger_to_member(
                $t['id'],
                $t['first_name'],
                $t['last_name'],
                $t['email'],
                $t['birthdate']
            );
            if ($member_id) {
                $count++;
            }
        }
        
        return $count;
    } catch (Exception $e) {
        error_log("Fehler beim Synchronisieren der Geräteträger: " . $e->getMessage());
        return 0;
    }
}

/**
 * Führt doppelte Mitglieder zusammen
 * Findet Mitglieder mit gleichem Namen und führt sie zu einem zusammen
 * Priorität: Mitglied mit user_id wird behalten
 * 
 * @return array Statistik: ['merged' => Anzahl zusammengeführter, 'deleted' => Anzahl gelöschter]
 */
function merge_duplicate_members() {
    global $db;
    
    try {
        $db->beginTransaction();
        
        // Finde doppelte Mitglieder (gleicher Name)
        $stmt = $db->query("
            SELECT 
                first_name, 
                last_name,
                GROUP_CONCAT(id ORDER BY user_id IS NULL ASC, id ASC) as member_ids,
                COUNT(*) as count
            FROM members
            GROUP BY first_name, last_name
            HAVING count > 1
        ");
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $merged_count = 0;
        $deleted_count = 0;
        
        foreach ($duplicates as $dup) {
            $member_ids = explode(',', $dup['member_ids']);
            $member_ids = array_map('intval', $member_ids);
            
            // Das erste Mitglied behalten (hat Priorität: mit user_id oder niedrigste ID)
            $keep_id = $member_ids[0];
            $delete_ids = array_slice($member_ids, 1);
            
            // Lade Daten des zu behaltenden Mitglieds
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$keep_id]);
            $keep_member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Sammle alle Daten der zu löschenden Mitglieder
            $all_emails = [$keep_member['email']];
            $all_birthdates = [$keep_member['birthdate']];
            $all_phones = [$keep_member['phone']];
            $all_user_ids = [$keep_member['user_id']];
            
            foreach ($delete_ids as $delete_id) {
                $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
                $stmt->execute([$delete_id]);
                $delete_member = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($delete_member) {
                    if (!empty($delete_member['email'])) $all_emails[] = $delete_member['email'];
                    if (!empty($delete_member['birthdate'])) $all_birthdates[] = $delete_member['birthdate'];
                    if (!empty($delete_member['phone'])) $all_phones[] = $delete_member['phone'];
                    if (!empty($delete_member['user_id'])) $all_user_ids[] = $delete_member['user_id'];
                }
            }
            
            // Bestimme die besten Werte (nicht leer, Priorität für user_id)
            $best_email = !empty($keep_member['email']) ? $keep_member['email'] : (count($all_emails) > 1 ? array_filter($all_emails)[0] ?? null : null);
            $best_birthdate = !empty($keep_member['birthdate']) ? $keep_member['birthdate'] : (count($all_birthdates) > 1 ? array_filter($all_birthdates)[0] ?? null : null);
            $best_phone = !empty($keep_member['phone']) ? $keep_member['phone'] : (count($all_phones) > 1 ? array_filter($all_phones)[0] ?? null : null);
            $best_user_id = !empty($keep_member['user_id']) ? $keep_member['user_id'] : (count($all_user_ids) > 1 ? array_filter($all_user_ids)[0] ?? null : null);
            
            // Aktualisiere das zu behaltende Mitglied mit den besten Werten
            $update_fields = [];
            $update_params = [];
            
            if ($best_email && $best_email !== $keep_member['email']) {
                $update_fields[] = "email = ?";
                $update_params[] = $best_email;
            }
            if ($best_birthdate && $best_birthdate !== $keep_member['birthdate']) {
                $update_fields[] = "birthdate = ?";
                $update_params[] = $best_birthdate;
            }
            if ($best_phone && $best_phone !== $keep_member['phone']) {
                $update_fields[] = "phone = ?";
                $update_params[] = $best_phone;
            }
            if ($best_user_id && $best_user_id !== $keep_member['user_id']) {
                $update_fields[] = "user_id = ?";
                $update_params[] = $best_user_id;
            }
            
            if (!empty($update_fields)) {
                $update_params[] = $keep_id;
                $stmt = $db->prepare("UPDATE members SET " . implode(", ", $update_fields) . " WHERE id = ?");
                $stmt->execute($update_params);
            }
            
            // Aktualisiere alle atemschutz_traeger, die auf die zu löschenden Mitglieder verweisen
            foreach ($delete_ids as $delete_id) {
                $stmt = $db->prepare("UPDATE atemschutz_traeger SET member_id = ? WHERE member_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
            }
            
            // Lösche die doppelten Mitglieder
            $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
            $stmt = $db->prepare("DELETE FROM members WHERE id IN ($placeholders)");
            $stmt->execute($delete_ids);
            
            $merged_count++;
            $deleted_count += count($delete_ids);
        }
        
        $db->commit();
        
        return [
            'merged' => $merged_count,
            'deleted' => $deleted_count
        ];
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Fehler beim Zusammenführen doppelter Mitglieder: " . $e->getMessage());
        return [
            'merged' => 0,
            'deleted' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Speichert den letzten Divera-JSON-Payload im Debug-Log (max. 5 Einträge).
 * @param array $payload Der an Divera gesendete JSON-Body (ohne Access Key)
 * @param string $source Quelle: 'reservation' oder 'form'
 * @param int $einheit_id Einheit-ID (> 0), damit Log nur für diese Einheit sichtbar ist
 */
function log_divera_debug_payload($payload, $source = 'reservation', $einheit_id = 0) {
    global $db;
    if (empty($db) || $einheit_id <= 0) return;
    try {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => $source,
            'type'      => 'post',
            'payload'   => $payload,
        ];
        log_divera_debug_entry($entry, $einheit_id);
    } catch (Exception $e) {}
}

/**
 * Speichert eine Divera-API-Response im Debug-Log (zur Fehlersuche bei ID-Parsing).
 * @param int $einheit_id Einheit-ID (> 0)
 */
function log_divera_debug_response($raw_response, $context = 'create', $einheit_id = 0) {
    global $db;
    if (empty($db) || !is_string($raw_response) || $einheit_id <= 0) return;
    try {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => 'reservation',
            'type'      => 'response',
            'context'   => $context,
            'payload'   => ['raw_response' => substr($raw_response, 0, 2000)],
        ];
        log_divera_debug_entry($entry, $einheit_id);
    } catch (Exception $e) {}
}

/**
 * Protokolliert, dass eine Divera-Löschung übersprungen wurde (z.B. fehlende Event-ID oder Access Key).
 * @param int $reservation_id Reservierungs-ID
 * @param string $reason Grund (z.B. 'event_id_null', 'key_empty', 'find_by_foreign_id_failed')
 * @param int $einheit_id Einheit-ID (> 0)
 */
function log_divera_debug_skip($reservation_id, $reason, $einheit_id = 0) {
    global $db;
    if (empty($db) || $einheit_id <= 0) return;
    try {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => 'reservation',
            'type'      => 'delete_skip',
            'payload'   => [
                'reservation_id' => (int) $reservation_id,
                'reason'         => $reason,
            ],
        ];
        log_divera_debug_entry($entry, $einheit_id);
    } catch (Exception $e) {}
}

/**
 * Speichert einen Divera-DELETE-Request im Debug-Log (max. 5 Einträge).
 * @param int $event_id Divera-Event-ID
 * @param string $url_path API-Pfad ohne Access Key (z.B. /api/v2/events/123)
 * @param int $einheit_id Einheit-ID (> 0)
 */
function log_divera_debug_delete($event_id, $url_path, $einheit_id = 0) {
    global $db;
    if (empty($db) || $einheit_id <= 0) return;
    try {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'source'    => 'reservation',
            'type'      => 'delete',
            'payload'   => [
                'method'    => 'DELETE',
                'event_id'  => (int) $event_id,
                'url_path'  => $url_path,
            ],
        ];
        log_divera_debug_entry($entry, $einheit_id);
    } catch (Exception $e) {}
}

/**
 * Fügt einen Debug-Eintrag zur Liste hinzu (max. 5 Einträge, pro Einheit).
 * @param array $entry Eintrag mit timestamp, source, type, payload
 * @param int $einheit_id Einheit-ID (> 0), Einträge werden in einheit_settings gespeichert
 */
function log_divera_debug_entry($entry, $einheit_id = 0) {
    global $db;
    if (empty($db) || $einheit_id <= 0) return;
    if (!function_exists('ensure_einheit_settings_table')) {
        require_once __DIR__ . '/einheit-settings-helper.php';
    }
    ensure_einheit_settings_table($db);
    $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key = 'divera_debug_payloads' LIMIT 1");
    $stmt->execute([$einheit_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $list = $row && $row['setting_value'] !== '' ? json_decode($row['setting_value'], true) : [];
    if (!is_array($list)) $list = [];
    array_unshift($list, $entry);
    $list = array_slice($list, 0, 5);
    $stmt = $db->prepare("INSERT INTO einheit_settings (einheit_id, setting_key, setting_value) VALUES (?, 'divera_debug_payloads', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$einheit_id, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]);
}

/**
 * Sendet eine genehmigte Reservierung als Termin an Divera 24/7 (API v2/events).
 * Nutzt dasselbe JSON-Format und dieselbe Erfolgsprüfung wie das Formular „Termin an Divera 24/7 senden“.
 *
 * @param array $reservation Reservierungs-Datensatz (start_datetime, end_datetime, reason, location, vehicle_name, …)
 * @param string $access_key Divera-Accesskey (aus Profil oder Einheits-Einstellungen)
 * @param string $api_base_url Basis-URL der Divera-API (z. B. https://app.divera247.com)
 * @param array|null $divera_error Ausgabe: bei Fehlschlag ['code' => int, 'message' => string]
 * @param int|null $divera_event_id Ausgabe: bei Erfolg die Divera-Event-ID (zum späteren Löschen)
 * @param bool $is_room true = Raumreservierung (kein address-Feld, Divera akzeptiert sonst evtl. keine Antwort)
 * @return bool true bei HTTP 2xx (wie im Formular), false sonst
 */
function send_reservation_to_divera($reservation, $access_key, $api_base_url = 'https://app.divera247.com', &$divera_error = null, &$divera_event_id = null, $is_room = false) {
    $divera_error = null;
    $divera_event_id = null;
    // Key bereinigen: Trim + unsichtbare Zeichen (z. B. beim Kopieren) entfernen
    $access_key = trim((string) $access_key);
    $access_key = preg_replace('/[\r\n\t\v]+/', '', $access_key);
    if ($access_key === '') {
        return false;
    }
    $base = rtrim(trim((string) $api_base_url), '/') ?: 'https://app.divera247.com';
    $start_ts = strtotime($reservation['start_datetime'] ?? '');
    $end_ts = strtotime($reservation['end_datetime'] ?? '');
    if ($start_ts <= 0 || $end_ts <= 0) {
        error_log('Divera: Ungültige Zeiten für Reservierung (start/end).');
        return false;
    }
    $vehicle_name = $reservation['vehicle_name'] ?? 'Fahrzeug';
    $title = $vehicle_name . ' - ' . ($reservation['reason'] ?? 'Reservierung');
    $group_ids = isset($reservation['_divera_group_ids']) && is_array($reservation['_divera_group_ids'])
        ? array_map('intval', array_filter($reservation['_divera_group_ids']))
        : [];
    // Bei Raumreservierungen: keine Gruppen, kein address – minimale Payload für Divera-Kompatibilität
    $use_groups = !$is_room && !empty($group_ids);
    $reservation_id = (int) ($reservation['id'] ?? 0);
    $event = [
        'notification_type' => $use_groups ? 3 : 2,
        'title'             => $title,
        'ts_start'          => $start_ts,
        'ts_end'            => $end_ts,
    ];
    if (!$is_room) {
        $event['address'] = trim($reservation['location'] ?? '');
    }
    if ($reservation_id > 0) {
        $event['foreign_id'] = (string) $reservation_id;
    }
    if ($use_groups) {
        $event['group'] = $group_ids;
    }
    $body = ['Event' => $event];
    if ($use_groups) {
        $body['usingGroups'] = $group_ids;
    }
    $einheit_id = (int)($reservation['einheit_id'] ?? $reservation['_einheit_id'] ?? 0);
    if ($einheit_id <= 0 && !empty($reservation['vehicle_id']) && !empty($GLOBALS['db'])) {
        try {
            $stmt = $GLOBALS['db']->prepare("SELECT einheit_id FROM vehicles WHERE id = ?");
            $stmt->execute([$reservation['vehicle_id']]);
            $einheit_id = (int)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {}
    }
    if ($einheit_id <= 0 && !empty($reservation['room_id']) && !empty($GLOBALS['db'])) {
        try {
            $stmt = $GLOBALS['db']->prepare("SELECT einheit_id FROM rooms WHERE id = ?");
            $stmt->execute([$reservation['room_id']]);
            $einheit_id = (int)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {}
    }
    log_divera_debug_payload($body, $is_room ? 'room_reservation' : 'reservation', $einheit_id);
    $url = $base . '/api/v2/events?accesskey=' . urlencode($access_key);
    $json_body = json_encode($body);

    $raw = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($json_body),
                ],
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);
            if ($curl_err !== '') {
                error_log('Divera POST cURL-Fehler: ' . $curl_err . ' (Reservierung-ID: ' . $reservation_id . ')');
                $divera_error = ['code' => 0, 'message' => 'Verbindungsfehler: ' . $curl_err];
                return false;
            }
        }
    }
    if ($code === 0) {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($json_body) . "\r\n",
                'content' => $json_body,
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    // Response immer loggen (Erfolg und Fehler), damit in „Letzte API-Anfragen“ die Divera-Antwort sichtbar ist
    if ($einheit_id > 0) {
        if (is_string($raw) && $raw !== '') {
            log_divera_debug_response($raw, $code >= 200 && $code < 300 ? 'create' : 'create_failed', $einheit_id);
        } elseif ($raw === false || $raw === '') {
            log_divera_debug_response('(leere Antwort oder Verbindungsfehler)', 'create_failed', $einheit_id);
        }
    }
    // Erfolgsprüfung wie im Formular: nur HTTP 2xx (Formular prüft nicht auf data.success)
    $success = $code >= 200 && $code < 300;
    if (!$success) {
        $msg = null;
        if (is_array($data)) {
            $msg = $data['data']['message'] ?? $data['message'] ?? $data['error'] ?? null;
            if (is_array($msg)) {
                $msg = implode(' ', $msg);
            }
            if ($msg === null && !empty($data['errors'])) {
                $msg = is_array($data['errors']) ? implode(' ', $data['errors']) : (string) $data['errors'];
            }
        }
        if ($msg === null || $msg === '') {
            $msg = $code === 403 ? 'Accesskey fehlt oder ist ungültig.' : ('HTTP ' . $code);
        }
        $divera_error = ['code' => $code, 'message' => $msg];
        $rid = (int) ($reservation['id'] ?? 0);
        error_log('Divera Termin fehlgeschlagen. HTTP ' . $code . '. Reservierung-ID: ' . $rid . '. Response: ' . (is_string($raw) ? substr($raw, 0, 500) : '') . ' Message: ' . $msg);
    } elseif ($success && is_array($data)) {
        // Event-ID aus Response extrahieren (Divera API: data.id)
        $divera_event_id = 0;
        if (isset($data['data']['id'])) {
            $divera_event_id = (int) $data['data']['id'];
        } elseif (isset($data['data']['data']['id'])) {
            $divera_event_id = (int) $data['data']['data']['id'];
        } elseif (isset($data['id'])) {
            $divera_event_id = (int) $data['id'];
        } elseif (!empty($data['data']) && is_numeric($data['data'])) {
            $divera_event_id = (int) $data['data'];
        }
        if ($divera_event_id <= 0 && is_string($raw)) {
            error_log('Divera: Erfolg, aber Event-ID nicht gefunden. Response im Debug-Tab prüfen.');
        }
    }
    return $success;
}

/**
 * Sucht ein Divera-Event anhand der foreign_id (Reservierungs-ID).
 * @param int $reservation_id Reservierungs-ID (= foreign_id in Divera)
 * @param string $access_key Divera-Accesskey
 * @param string $api_base_url Basis-URL der Divera-API
 * @return int|null Divera-Event-ID oder null wenn nicht gefunden
 */
function find_divera_event_by_foreign_id($reservation_id, $access_key, $api_base_url = 'https://app.divera247.com') {
    $reservation_id = (int) $reservation_id;
    if ($reservation_id <= 0) return null;
    $access_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string) $access_key));
    if ($access_key === '') return null;
    $base = rtrim(trim((string) $api_base_url), '/') ?: 'https://app.divera247.com';
    $url = $base . '/api/v2/events?accesskey=' . urlencode($access_key);
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['data'])) {
        error_log('Divera find_by_foreign_id: Keine Daten oder ungültige Response für Reservierung ' . $reservation_id);
        return null;
    }
    $foreign_id_needle = (string) $reservation_id;
    $items = $data['data']['items'] ?? $data['data'];
    if (!is_array($items)) {
        error_log('Divera find_by_foreign_id: items ist kein Array. Struktur: ' . substr(json_encode(array_keys($data['data'])), 0, 200));
        return null;
    }
    foreach ($items as $event_id => $event) {
        if (!is_array($event)) continue;
        $fid = isset($event['foreign_id']) ? (string) $event['foreign_id'] : '';
        if ($fid === $foreign_id_needle) {
            return (int) (isset($event['id']) ? $event['id'] : $event_id);
        }
    }
    error_log('Divera find_by_foreign_id: Kein Event mit foreign_id=' . $foreign_id_needle . ' gefunden (Reservierung ' . $reservation_id . ')');
    return null;
}

/**
 * Holt alle Termine von Divera 24/7 (API GET /api/v2/events).
 * Nutzt das Feld "date" (event-result) bzw. ts_start/ts_end (falls vorhanden).
 * @param string $access_key Divera-Accesskey (z.B. Personal-API-Accesskey des Benutzers)
 * @param string $api_base_url Basis-URL der Divera-API
 * @param int|null $from_ts Optional: Nur Termine ab diesem Unix-Timestamp
 * @param int|null $to_ts Optional: Nur Termine bis zu diesem Unix-Timestamp
 * @param string|null $error Ausgabe: Fehlermeldung bei API-Fehler
 * @return array Liste von Events: [['id'=>int,'title'=>string,'text'=>string,'ts_start'=>int,'ts_end'=>int,'address'=>string], ...]
 */
function fetch_divera_events($access_key, $api_base_url = 'https://app.divera247.com', $from_ts = null, $to_ts = null, &$error = null) {
    $access_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string) $access_key));
    if ($access_key === '') return [];
    $base = rtrim(trim((string) $api_base_url), '/') ?: 'https://app.divera247.com';
    $url = $base . '/api/v2/events?accesskey=' . urlencode($access_key);
    $ctx = stream_context_create(['http' => ['timeout' => 20]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $error = 'Divera-API: Keine gültige JSON-Antwort';
        return [];
    }
    if (isset($data['success']) && $data['success'] === false) {
        $error = $data['message'] ?? $data['error'] ?? 'Divera-API: Zugriff verweigert oder ungültiger Access Key';
        return [];
    }
    if (empty($data['data'])) return [];
    $dataBlock = $data['data'];
    $items = $dataBlock['items'] ?? $dataBlock;
    if (!is_array($items)) return [];
    $events = [];
    foreach ($items as $event_id => $event) {
        if (!is_array($event)) continue;
        // Evtl. verschachtelt: Event oder data
        $ev = $event['Event'] ?? $event['data'] ?? $event;
        if (!is_array($ev)) continue;
        $id = (int) (isset($ev['id']) ? $ev['id'] : $event['id'] ?? $event_id);
        if ($id <= 0 && is_numeric($event_id)) $id = (int) $event_id;
        if ($id <= 0) continue;
        // Terminszeit: Divera nutzt "start"/"end"; Fallbacks: date, ts_start, ts_end (NICHT ts_create!)
        $start_ts = (int) ($ev['start'] ?? $event['start'] ?? 0);
        $end_ts = (int) ($ev['end'] ?? $event['end'] ?? 0);
        $date_ts = (int) ($ev['date'] ?? $event['date'] ?? 0);
        $ts_start = $start_ts ?: (int) ($ev['ts_start'] ?? $event['ts_start'] ?? $date_ts);
        $ts_end = $end_ts ?: (int) ($ev['ts_end'] ?? $event['ts_end'] ?? $ts_start);
        if ($ts_start <= 0) $ts_start = $date_ts;
        if ($ts_end <= 0) $ts_end = $ts_start;
        // date/ts als String (z.B. "2026-02-15 19:00:00")
        if ($ts_start <= 0 && !empty($ev['date'])) {
            $parsed = is_numeric($ev['date']) ? (int)$ev['date'] : strtotime($ev['date']);
            if ($parsed > 0) { $ts_start = $parsed; $ts_end = $ts_start; }
        }
        if ($ts_start <= 0 && !empty($ev['ts_start'])) {
            $parsed = is_numeric($ev['ts_start']) ? (int)$ev['ts_start'] : strtotime($ev['ts_start']);
            if ($parsed > 0) {
                $ts_start = $parsed;
                $te = $ev['ts_end'] ?? $ts_start;
                $ts_end = is_numeric($te) ? (int)$te : (strtotime($te) ?: $ts_start);
            }
        }
        // Falls Timestamp in Millisekunden (Wert > 10^10)
        if ($ts_start > 10000000000) { $ts_start = (int)($ts_start / 1000); $ts_end = (int)($ts_end / 1000); }
        // Letzter Fallback: ts_create (Erstellungsdatum – kann vom Termindatum abweichen)
        if ($ts_start <= 0 && $ts_end <= 0) {
            $ts_create = (int) ($ev['ts_create'] ?? $event['ts_create'] ?? 0);
            if ($ts_create > 0) {
                $ts_start = $ts_create;
                $ts_end = $ts_create;
            } else {
                continue;
            }
        }
        if ($from_ts !== null && $ts_end < $from_ts) continue;
        if ($to_ts !== null && $ts_start > $to_ts) continue;
        $events[] = [
            'id'        => $id,
            'title'     => trim((string) ($ev['title'] ?? $event['title'] ?? '')),
            'text'      => trim((string) ($ev['text'] ?? $event['text'] ?? '')),
            'ts_start'  => $ts_start,
            'ts_end'    => $ts_end,
            'address'   => trim((string) ($ev['address'] ?? $event['address'] ?? '')),
        ];
    }
    usort($events, fn($a, $b) => $a['ts_start'] - $b['ts_start']);
    return $events;
}

/**
 * Holt aktive (nicht geschlossene) Einsätze von Divera 24/7 (API GET /api/v2/alarms oder /api/v2/alarms/list?closed=0).
 * @param string $access_key Divera-Accesskey
 * @param string $api_base_url Basis-URL der Divera-API
 * @param string|null $error Ausgabe: Fehlermeldung bei API-Fehler
 * @return array Liste von Alarms: [['id'=>int,'title'=>string,'text'=>string,'address'=>string,'date'=>int,'ts_create'=>int], ...]
 */
function fetch_divera_alarms($access_key, $api_base_url = 'https://app.divera247.com', &$error = null) {
    $access_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string) $access_key));
    if ($access_key === '') {
        $error = 'Divera Access Key fehlt';
        return [];
    }
    $base = rtrim(trim((string) $api_base_url), '/') ?: 'https://app.divera247.com';
    $url = $base . '/api/v2/alarms?accesskey=' . urlencode($access_key);
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $error = 'Divera-API: Keine gültige JSON-Antwort';
        return [];
    }
    if (isset($data['success']) && $data['success'] === false) {
        $error = $data['message'] ?? $data['error'] ?? 'Divera-API: Zugriff verweigert oder ungültiger Access Key';
        return [];
    }
    $dataBlock = $data['data'] ?? [];
    $items = $dataBlock['items'] ?? $dataBlock;
    if (!is_array($items)) return [];
    $alarms = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) continue;
        $date_ts = (int) ($item['date'] ?? $item['ts_create'] ?? 0);
        if ($date_ts > 10000000000) $date_ts = (int)($date_ts / 1000);
        $alarms[] = [
            'id'        => $id,
            'title'     => trim((string) ($item['title'] ?? '')),
            'text'      => trim((string) ($item['text'] ?? '')),
            'address'   => trim((string) ($item['address'] ?? '')),
            'date'      => $date_ts,
            'ts_create' => (int) ($item['ts_create'] ?? $date_ts),
            'closed'    => !empty($item['closed']),
        ];
    }
    usort($alarms, fn($a, $b) => ($b['date'] ?? 0) - ($a['date'] ?? 0));
    return $alarms;
}

/**
 * Sendet einen Dienstplan-Eintrag als Termin an Divera 24/7.
 * @param array $entry ['datum'=>Y-m-d, 'bezeichnung'=>string, 'typ'=>string, 'uhrzeit_dienstbeginn'=>H:i:s|null, 'uhrzeit_dienstende'=>H:i:s|null]
 * @param string $access_key Divera-Accesskey
 * @param string $api_base_url Basis-URL
 * @param array $group_ids Optional: Divera-Gruppen-IDs
 * @return array ['success'=>bool, 'event_id'=>int|null, 'error'=>string|null]
 */
function send_dienstplan_to_divera($entry, $access_key, $api_base_url = 'https://app.divera247.com', $group_ids = []) {
    $access_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string) $access_key));
    if ($access_key === '') return ['success' => false, 'event_id' => null, 'error' => 'Accesskey fehlt'];
    $datum = $entry['datum'] ?? '';
    $bezeichnung = trim((string) ($entry['bezeichnung'] ?? ''));
    if ($datum === '' || $bezeichnung === '') return ['success' => false, 'event_id' => null, 'error' => 'Datum oder Bezeichnung fehlt'];
    $uhrzeit_beginn = trim((string) ($entry['uhrzeit_dienstbeginn'] ?? ''));
    $uhrzeit_ende = trim((string) ($entry['uhrzeit_dienstende'] ?? ''));
    $start_str = $uhrzeit_beginn !== '' ? $datum . ' ' . $uhrzeit_beginn : $datum . ' 09:00:00';
    $start_ts = strtotime($start_str);
    if ($uhrzeit_ende !== '') {
        $end_ts = strtotime($datum . ' ' . $uhrzeit_ende);
    } else {
        $end_ts = $uhrzeit_beginn !== '' ? strtotime('+3 hours', $start_ts) : strtotime($datum . ' 12:00:00');
    }
    if ($start_ts <= 0) return ['success' => false, 'event_id' => null, 'error' => 'Ungültiges Datum'];
    $typ_label = function_exists('get_dienstplan_typ_label') ? get_dienstplan_typ_label($entry['typ'] ?? 'uebungsdienst') : 'Dienst';
    $title = $typ_label . ': ' . $bezeichnung;
    $group_ids = array_values(array_filter(array_map('intval', $group_ids)));
    $use_groups = !empty($group_ids);
    $event = [
        'notification_type' => $use_groups ? 3 : 2,
        'title'             => $title,
        'ts_start'          => $start_ts,
        'ts_end'            => $end_ts,
        'address'           => '',
    ];
    if ($use_groups) {
        $event['group'] = $group_ids;
    }
    $body = ['Event' => $event];
    if ($use_groups) $body['usingGroups'] = $group_ids;
    $base = rtrim(trim((string) $api_base_url), '/') ?: 'https://app.divera247.com';
    $url = $base . '/api/v2/events?accesskey=' . urlencode($access_key);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 15,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $event_id = null;
    if ($code >= 200 && $code < 300 && is_array($data)) {
        $event_id = (int) ($data['data']['id'] ?? $data['data']['data']['id'] ?? $data['id'] ?? 0);
    }
    if ($code >= 200 && $code < 300) {
        return ['success' => true, 'event_id' => $event_id, 'error' => null];
    }
    $msg = is_array($data) ? ($data['data']['message'] ?? $data['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
    return ['success' => false, 'event_id' => null, 'error' => $msg];
}

/**
 * Löscht einen Termin in Divera 24/7 (API DELETE /api/v2/events/{id}).
 * Accesskey muss als Query-Parameter übergeben werden (wie bei POST).
 * @param int $event_id Divera-Event-ID (Pfad-Parameter)
 * @param string $access_key Divera-Accesskey (Einheits- oder Benutzer-Key)
 * @param string $api_base_url Basis-URL der Divera-API
 * @return bool true bei HTTP 2xx, false sonst
 */
function delete_divera_event($event_id, $access_key, $api_base_url = 'https://app.divera247.com', $einheit_id = 0) {
    $event_id = (int) $event_id;
    if ($event_id <= 0) {
        return false;
    }
    $access_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string) $access_key));
    if ($access_key === '') {
        error_log('Divera Event löschen: Accesskey fehlt (Event-ID: ' . $event_id . ')');
        return false;
    }
    $base = rtrim(trim((string) $api_base_url), '/') ?: 'https://app.divera247.com';
    $url = $base . '/api/v2/events/' . $event_id . '?accesskey=' . urlencode($access_key);
    log_divera_debug_delete($event_id, '/api/v2/events/' . $event_id, $einheit_id);

    $ch = curl_init($url);
    if (!$ch) {
        error_log('Divera Event löschen: cURL konnte nicht initialisiert werden');
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err !== '') {
        error_log('Divera Event löschen cURL-Fehler: ' . $curl_err . ' (Event-ID: ' . $event_id . ')');
        return false;
    }
    $success = $code >= 200 && $code < 300;
    if ($success && is_string($raw) && $einheit_id > 0) {
        log_divera_debug_response($raw, 'delete', $einheit_id);
    }
    if (!$success) {
        error_log('Divera Event löschen fehlgeschlagen. HTTP ' . $code . '. Event-ID: ' . $event_id . '. Response: ' . (is_string($raw) ? substr($raw, 0, 500) : ''));
    }
    return $success;
}

/**
 * Berechnet die Besatzungsstärke für eine Liste von Mitglieds-IDs.
 * Format: Zugführer/Gruppenführer/Mannschaft/Summe
 * Qualifikationen werden anhand des Namens zugeordnet (Zugführer, Gruppenführer, sonst Mannschaft).
 *
 * @param int[] $member_ids Array von Mitglieds-IDs
 * @param PDO|null $db Datenbankverbindung
 * @return string z.B. "1/2/5/8"
 */
function get_besatzungsstaerke($member_ids, $db = null) {
    global $db;
    $db = $db ?: ($GLOBALS['db'] ?? null);
    if (!$db || empty($member_ids)) {
        return '0/0/0/0';
    }
    $ids = array_map('intval', array_filter($member_ids));
    if (empty($ids)) return '0/0/0/0';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $db->prepare("
            SELECT m.id, LOWER(TRIM(COALESCE(q.name, ''))) AS qual_name
            FROM members m
            LEFT JOIN member_qualifications q ON q.id = m.qualification_id
            WHERE m.id IN ($placeholders)
        ");
        $stmt->execute($ids);
        $zf = $gf = $m = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['qual_name'] ?? '';
            if (strpos($name, 'zugführer') !== false || $name === 'zf') {
                $zf++;
            } elseif (strpos($name, 'gruppenführer') !== false || $name === 'gf') {
                $gf++;
            } else {
                $m++;
            }
        }
        $sum = $zf + $gf + $m;
        return $zf . '/' . $gf . '/' . $m . '/' . $sum;
    } catch (Exception $e) {
        return '0/0/0/0';
    }
}

/**
 * Leitet die Qualifikation eines Mitglieds aus dessen absolvierten Lehrgängen ab.
 * Verwendet die Qualifikation mit der niedrigsten sort_order (höchste Stufe).
 * Gibt die qualification_id zurück oder null, wenn keine Lehrgänge mit Qualifikation vorhanden sind.
 *
 * @param int $member_id Mitglieds-ID
 * @param PDO|null $db Datenbankverbindung (optional, verwendet global $db wenn nicht übergeben)
 * @return int|null qualification_id oder null
 */
function get_member_qualification_from_courses($member_id, $db = null) {
    global $db;
    $db = $db ?: $GLOBALS['db'] ?? null;
    if (!$db || $member_id <= 0) {
        return null;
    }
    try {
        $stmt = $db->prepare("
            SELECT c.qualification_id
            FROM member_courses mc
            JOIN courses c ON c.id = mc.course_id AND c.qualification_id IS NOT NULL
            JOIN members m ON m.id = mc.member_id
            JOIN member_qualifications q ON q.id = c.qualification_id
            WHERE mc.member_id = ? AND c.einheit_id = COALESCE(m.einheit_id, 1)
            ORDER BY q.sort_order ASC
            LIMIT 1
        ");
        $stmt->execute([$member_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && !empty($row['qualification_id']) ? (int)$row['qualification_id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Aktualisiert die Qualifikation eines Mitglieds basierend auf dessen absolvierten Lehrgängen.
 * Verwendet die Qualifikation mit der niedrigsten sort_order (höchste Stufe).
 * Überschreibt NUR wenn der Mitglied Lehrgänge mit Qualifikation hat – sonst bleibt die
 * manuell gesetzte Qualifikation erhalten.
 *
 * @param int $member_id Mitglieds-ID
 * @param PDO|null $db Datenbankverbindung (optional)
 * @return bool true bei Erfolg
 */
function update_member_qualification_from_courses($member_id, $db = null) {
    global $db;
    $db = $db ?: $GLOBALS['db'] ?? null;
    if (!$db || $member_id <= 0) {
        return false;
    }
    try {
        $qual_id = get_member_qualification_from_courses($member_id, $db);
        // Nur aktualisieren wenn aus Lehrgängen eine Qualifikation ableitbar ist
        // (sonst manuell gesetzte Qualifikation ohne Lehrgang-Verknüpfung beibehalten)
        if ($qual_id !== null) {
            $stmt = $db->prepare("UPDATE members SET qualification_id = ? WHERE id = ?");
            $stmt->execute([$qual_id, $member_id]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Fügt einem Mitglied die Lehrgänge hinzu, die mit der angegebenen Qualifikation verknüpft sind.
 * Wird aufgerufen wenn eine Qualifikation manuell zugewiesen wird – der Lehrgang gilt dann als absolviert.
 *
 * @param int $member_id Mitglieds-ID
 * @param int $qualification_id Qualifikations-ID
 * @param PDO|null $db Datenbankverbindung (optional)
 * @return bool true bei Erfolg
 */
function add_courses_for_qualification_to_member($member_id, $qualification_id, $db = null) {
    global $db;
    $db = $db ?: $GLOBALS['db'] ?? null;
    if (!$db || $member_id <= 0 || $qualification_id <= 0) {
        return false;
    }
    try {
        $member_einheit = 1;
        $stmt_m = $db->prepare("SELECT COALESCE(einheit_id, 1) FROM members WHERE id = ?");
        $stmt_m->execute([$member_id]);
        if ($r = $stmt_m->fetch(PDO::FETCH_COLUMN)) $member_einheit = (int)$r ?: 1;
        $stmt = $db->prepare("SELECT id FROM courses WHERE qualification_id = ? AND einheit_id = ?");
        $stmt->execute([$qualification_id, $member_einheit]);
        $courses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($courses)) {
            return true;
        }
        $stmt_ins = $db->prepare("INSERT INTO member_courses (member_id, course_id, completed_date) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE member_id = member_id");
        foreach ($courses as $course_id) {
            $stmt_ins->execute([$member_id, (int)$course_id]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
