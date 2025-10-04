<?php
/**
 * Repariere includes/functions.php
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Repariere Functions</title></head><body>";
echo "<h1>🔧 Repariere includes/functions.php</h1>";

try {
    echo "<h2>1. Erstelle Backup der aktuellen functions.php</h2>";
    
    if (file_exists('includes/functions.php')) {
        $backup_content = file_get_contents('includes/functions.php');
        file_put_contents('includes/functions_backup.php', $backup_content);
        echo "✅ Backup erstellt: includes/functions_backup.php<br>";
    }
    
    echo "<h2>2. Erstelle neue, funktionierende functions.php</h2>";
    
    $new_functions_content = '<?php
/**
 * Allgemeine Funktionen für die Feuerwehr App
 */

// Google Calendar Klassen laden
if (file_exists(\'includes/google_calendar_service_account.php\')) {
    require_once \'includes/google_calendar_service_account.php\';
}

if (file_exists(\'includes/google_calendar.php\')) {
    require_once \'includes/google_calendar.php\';
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
function validate_date($date, $format = \'Y-m-d\') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Datum und Uhrzeit validieren
 */
function validate_datetime($datetime, $format = \'Y-m-d\\TH:i\') {
    // HTML datetime-local Input verwendet das Format Y-m-d\\TH:i
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
 * CSRF Token generieren
 */
function generate_csrf_token() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION[\'csrf_token\'])) {
        $_SESSION[\'csrf_token\'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION[\'csrf_token\'];
}

/**
 * CSRF Token validieren
 */
function validate_csrf_token($token) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION[\'csrf_token\']) && hash_equals($_SESSION[\'csrf_token\'], $token);
}

/**
 * Redirect
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Prüfe ob Benutzer eingeloggt ist
 */
function is_logged_in() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION[\'user_id\']) && !empty($_SESSION[\'user_id\']);
}

/**
 * Prüfe ob Benutzer Admin ist
 */
function is_admin() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION[\'role\']) && $_SESSION[\'role\'] === \'admin\';
}

/**
 * Prüfe ob Benutzer Reservierungen genehmigen kann
 */
function can_approve_reservations() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION[\'role\']) && in_array($_SESSION[\'role\'], [\'admin\', \'approver\']);
}

/**
 * Prüfe ob Benutzer Admin-Zugriff hat
 */
function has_admin_access() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION[\'role\']) && $_SESSION[\'role\'] === \'admin\';
}

/**
 * Aktivität loggen
 */
function log_activity($user_id, $action, $description = \'\') {
    global $db;
    
    try {
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id,
            $action,
            $description,
            $_SERVER[\'REMOTE_ADDR\'] ?? \'\',
            $_SERVER[\'HTTP_USER_AGENT\'] ?? \'\'
        ]);
    } catch (Exception $e) {
        error_log(\'Fehler beim Loggen der Aktivität: \' . $e->getMessage());
    }
}

/**
 * Prüfe Fahrzeug-Konflikte
 */
function check_vehicle_conflict($vehicle_id, $start_datetime, $end_datetime, $exclude_reservation_id = null) {
    global $db;
    
    try {
        $sql = "SELECT COUNT(*) FROM reservations WHERE vehicle_id = ? AND status = \'approved\' AND ((start_datetime <= ? AND end_datetime > ?) OR (start_datetime < ? AND end_datetime >= ?) OR (start_datetime >= ? AND end_datetime <= ?))";
        $params = [$vehicle_id, $start_datetime, $start_datetime, $end_datetime, $end_datetime, $start_datetime, $end_datetime];
        
        if ($exclude_reservation_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_reservation_id;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();
        
        return $count > 0;
    } catch (Exception $e) {
        error_log(\'Fehler bei Fahrzeug-Konfliktprüfung: \' . $e->getMessage());
        return false;
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
        ini_set(\'default_socket_timeout\', 30);
        ini_set(\'max_execution_time\', 60);
        
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE \'google_calendar_%\'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $auth_type = $settings[\'google_calendar_auth_type\'] ?? \'service_account\';
        $calendar_id = $settings[\'google_calendar_id\'] ?? \'primary\';

        if ($auth_type === \'service_account\') {
            $service_account_json = $settings[\'google_calendar_service_account_json\'] ?? \'\';
            if (class_exists(\'GoogleCalendarServiceAccount\') && !empty($service_account_json)) {
                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                $events = $google_calendar->getEvents($start_datetime, $end_datetime);
                $conflicts = [];
                if ($events && is_array($events)) {
                    foreach ($events as $event) {
                        // Prüfe nur Events, die das exakte Fahrzeug enthalten (nicht andere Fahrzeuge)
                        if (isset($event[\'summary\']) && stripos($event[\'summary\'], $vehicle_name) !== false) {
                            $conflicts[] = [
                                \'title\' => $event[\'summary\'],
                                \'start\' => $event[\'start\'][\'dateTime\'] ?? $event[\'start\'][\'date\'],
                                \'end\' => $event[\'end\'][\'dateTime\'] ?? $event[\'end\'][\'date\']
                            ];
                        }
                    }
                }
                return $conflicts;
            }
        } else {
            $api_key = $settings[\'google_calendar_api_key\'] ?? \'\';
            if (class_exists(\'GoogleCalendar\') && !empty($api_key)) {
                $google_calendar = new GoogleCalendar($api_key, $calendar_id);
                $events = $google_calendar->getEvents($start_datetime, $end_datetime);
                $conflicts = [];
                if ($events && is_array($events)) {
                    foreach ($events as $event) {
                        // Prüfe nur Events, die das exakte Fahrzeug enthalten (nicht andere Fahrzeuge)
                        if (isset($event[\'summary\']) && stripos($event[\'summary\'], $vehicle_name) !== false) {
                            $conflicts[] = [
                                \'title\' => $event[\'summary\'],
                                \'start\' => $event[\'start\'][\'dateTime\'] ?? $event[\'start\'][\'date\'],
                                \'end\' => $event[\'end\'][\'dateTime\'] ?? $event[\'end\'][\'date\']
                            ];
                        }
                    }
                }
                return $conflicts;
            }
        }
        return [];
    } catch (Exception $e) {
        error_log(\'Google Calendar Konfliktprüfung Fehler: \' . $e->getMessage());
        return [];
    }
}

/**
 * Google Kalender API - Event erstellen
 */
function create_google_calendar_event($vehicle_name, $reason, $start_datetime, $end_datetime, $reservation_id = null, $location = null) {
    global $db;
    
    try {
        // Zusätzliche aggressive Timeouts
        ini_set(\'max_input_time\', 180);
        ini_set(\'memory_limit\', \'256M\');
        ini_set(\'default_socket_timeout\', 60);
        ini_set(\'user_agent\', \'FeuerwehrApp/1.0\');
        
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE \'google_calendar_%\'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings[\'google_calendar_auth_type\'] ?? \'service_account\';
        $calendar_id = $settings[\'google_calendar_id\'] ?? \'primary\';
        
        if ($auth_type === \'service_account\') {
            // Service Account verwenden
            $service_account_file = $settings[\'google_calendar_service_account_file\'] ?? \'\';
            $service_account_json = $settings[\'google_calendar_service_account_json\'] ?? \'\';
            
            // Prüfe ob Service Account Klasse verfügbar ist
            if (class_exists(\'GoogleCalendarServiceAccount\')) {
                // JSON-Inhalt hat Priorität über Datei
                if (!empty($service_account_json)) {
                    // JSON-Inhalt verwenden
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                } elseif (!empty($service_account_file) && file_exists($service_account_file)) {
                    // Datei verwenden
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_file, $calendar_id, false);
                } else {
                    error_log(\'Google Calendar Service Account nicht konfiguriert (weder Datei noch JSON-Inhalt)\');
                    return false;
                }
            } else {
                error_log(\'Google Calendar Service Account Klasse nicht verfügbar - Google Calendar deaktiviert\');
                return false;
            }
        } else {
            // API Key verwenden (Fallback)
            $api_key = $settings[\'google_calendar_api_key\'] ?? \'\';
            
            if (empty($api_key)) {
                error_log(\'Google Calendar API Key nicht konfiguriert\');
                return false;
            }
            
            if (class_exists(\'GoogleCalendar\')) {
                $google_calendar = new GoogleCalendar($api_key, $calendar_id);
            } else {
                error_log(\'Google Calendar Klasse nicht verfügbar - Google Calendar deaktiviert\');
                return false;
            }
        }
        
        // Event-Details erstellen
        $title = $vehicle_name . \' - \' . $reason;
        $description = "Fahrzeugreservierung über Feuerwehr App\\nFahrzeug: $vehicle_name\\nGrund: $reason\\nOrt: " . ($location ?? \'Nicht angegeben\');
        
        // Setze aggressive Timeouts für die API-Anfrage
        set_time_limit(180); // 180 Sekunden Timeout
        ini_set(\'default_socket_timeout\', 60); // 60 Sekunden Socket-Timeout
        ini_set(\'max_execution_time\', 180); // 180 Sekunden Max Execution Time
        
        // Event erstellen
        $event_id = $google_calendar->createEvent($title, $start_datetime, $end_datetime, $description);
        
        if ($event_id && $reservation_id) {
            // Event ID in der Datenbank speichern
            $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$reservation_id, $event_id, $title, $start_datetime, $end_datetime]);
        }
        
        return $event_id;
    } catch (Exception $e) {
        error_log(\'Google Calendar Fehler: \' . $e->getMessage());
        return false;
    }
}
?>';
    
    if (file_put_contents('includes/functions.php', $new_functions_content)) {
        echo "✅ Neue functions.php erstellt<br>";
    } else {
        echo "❌ Fehler beim Erstellen der neuen functions.php<br>";
    }
    
    echo "<h2>3. Teste neue functions.php</h2>";
    
    try {
        require_once 'includes/functions.php';
        echo "✅ includes/functions.php erfolgreich geladen<br>";
        
        if (function_exists('create_google_calendar_event')) {
            echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        } else {
            echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
        }
        
        if (function_exists('check_calendar_conflicts')) {
            echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
        } else {
            echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Fehler beim Laden: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    
    echo "<h2>4. Zusammenfassung</h2>";
    echo "✅ Backup erstellt: includes/functions_backup.php<br>";
    echo "✅ Neue functions.php erstellt<br>";
    echo "✅ Aggressive Timeouts gesetzt<br>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
