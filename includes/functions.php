<?php
/**
 * Allgemeine Funktionen für die Feuerwehr App
 */

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
function validate_datetime($datetime, $format = 'Y-m-d H:i') {
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
function send_email($to, $subject, $message, $headers = '') {
    global $db;
    
    try {
        // SMTP-Einstellungen aus der Datenbank laden
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $smtp_host = $settings['smtp_host'] ?? '';
        $smtp_port = $settings['smtp_port'] ?? '587';
        $smtp_username = $settings['smtp_username'] ?? '';
        $smtp_password = $settings['smtp_password'] ?? '';
        $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
        $smtp_from_email = $settings['smtp_from_email'] ?? 'noreply@feuerwehr-app.local';
        $smtp_from_name = $settings['smtp_from_name'] ?? 'Feuerwehr App';
        
        // Wenn SMTP-Einstellungen konfiguriert sind, verwende PHPMailer
        if (!empty($smtp_host) && !empty($smtp_username)) {
            return send_email_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
        } else {
            // Fallback auf einfache mail() Funktion
            if (empty($headers)) {
                $headers = "From: $smtp_from_name <$smtp_from_email>\r\n";
                $headers .= "Reply-To: $smtp_from_email\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            }
            
            return mail($to, $subject, $message, $headers);
        }
    } catch (Exception $e) {
        error_log('E-Mail Fehler: ' . $e->getMessage());
        return false;
    }
}

/**
 * E-Mail über SMTP senden (vereinfachte Version)
 */
function send_email_smtp($to, $subject, $message, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $from_email, $from_name) {
    // Verwende die PHP mail() Funktion mit konfigurierten Headers
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Zusätzliche Parameter für SMTP
    $additional_parameters = "-f$from_email";
    
    // Debug-Informationen
    error_log("SMTP Debug - Host: $smtp_host, Port: $smtp_port, From: $from_email, To: $to");
    
    // Versuche E-Mail zu senden
    $result = mail($to, $subject, $message, $headers, $additional_parameters);
    
    if (!$result) {
        error_log("E-Mail konnte nicht gesendet werden. Prüfen Sie die PHP mail() Konfiguration.");
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
 * Benutzer ist Admin
 */
function is_admin() {
    return is_logged_in() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

/**
 * Weiterleitung
 */
function redirect($url) {
    header("Location: $url");
    exit();
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
 * Google Kalender API - Event erstellen
 */
function create_google_calendar_event($vehicle_name, $reason, $start_datetime, $end_datetime) {
    global $db;
    
    try {
        // Einstellungen laden
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('google_calendar_api_key', 'google_calendar_id')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $api_key = $settings['google_calendar_api_key'] ?? '';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        
        if (empty($api_key)) {
            error_log('Google Calendar API Key nicht konfiguriert');
            return false;
        }
        
        require_once 'google_calendar.php';
        
        $google_calendar = new GoogleCalendar($api_key, $calendar_id);
        
        $title = $vehicle_name . ' - ' . $reason;
        $description = "Fahrzeugreservierung über Feuerwehr App\nFahrzeug: $vehicle_name\nGrund: $reason";
        
        $event_id = $google_calendar->createEvent($title, $start_datetime, $end_datetime, $description);
        
        if ($event_id) {
            // Event ID in der Datenbank speichern
            $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$reservation_id, $event_id, $title, $start_datetime, $end_datetime]);
            return $event_id;
        }
        
        return false;
    } catch (Exception $e) {
        error_log('Google Calendar Fehler: ' . $e->getMessage());
        return false;
    }
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
?>
