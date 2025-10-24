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
 * Benutzer ist Admin
 */
function is_admin() {
    return is_logged_in() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

/**
 * Prüfen ob Benutzer Genehmiger oder Admin ist
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
 * Unterstützt sowohl alte role-basierte als auch neue permission-basierte Berechtigungen
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
        $stmt = $db->prepare("SELECT user_role, is_admin, can_settings FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
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
        $stmt = $db->prepare("SELECT is_admin, can_reservations, can_users, can_settings, can_vehicles, can_atemschutz FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // Admin hat alle Berechtigungen
        if ($user['is_admin']) {
            return true;
        }
        
        // Spezifische Berechtigung prüfen
        switch ($permission) {
            case 'admin':
                return (bool)$user['is_admin'];
            case 'reservations':
                return (bool)$user['can_reservations'];
            case 'users':
                return (bool)$user['can_users'];
            case 'settings':
                return (bool)$user['can_settings'];
            case 'vehicles':
                return (bool)$user['can_vehicles'];
            case 'atemschutz':
                return (bool)($user['can_atemschutz'] ?? 0);
            default:
                return false;
        }
    } catch (Exception $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
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
?>
