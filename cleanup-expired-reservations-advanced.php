<?php
/**
 * Erweiterte Version des Cleanup-Scripts für abgelaufene Reservierungen
 * 
 * Features:
 * - E-Mail-Benachrichtigungen an Admins
 * - Detailliertes Logging
 * - Konfigurierbare Einstellungen
 * - Trockenlauf-Modus
 * 
 * Verwendung:
 * - Normal: php cleanup-expired-reservations-advanced.php
 * - Trockenlauf: php cleanup-expired-reservations-advanced.php --dry-run
 * - Cron-Job: 0 2 * * * php /path/to/cleanup-expired-reservations-advanced.php
 */

// Fehlerbehandlung aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Kommandozeilen-Argumente prüfen
$dry_run = in_array('--dry-run', $argv);

// Session starten (falls nötig)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datenbankverbindung
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Google Calendar Funktionen laden
if (file_exists('includes/google_calendar_functions.php')) {
    require_once 'includes/google_calendar_functions.php';
}

// Konfiguration
$config = [
    'dry_run' => $dry_run,
    'send_email_notifications' => true,
    'log_file' => 'logs/cleanup-reservations.log',
    'max_cleanup_age_days' => 30, // Nur Reservierungen löschen, die mindestens X Tage abgelaufen sind
    'admin_emails' => []
];

// Log-Verzeichnis erstellen
$log_dir = dirname($config['log_file']);
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Logging-Funktion
function writeLog($message, $level = 'INFO') {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}\n";
    
    // Console-Ausgabe
    echo $log_entry;
    
    // Datei-Logging
    file_put_contents($config['log_file'], $log_entry, FILE_APPEND | LOCK_EX);
}

// Admin-E-Mails laden
try {
    $stmt = $db->prepare("SELECT email FROM users WHERE user_role IN ('admin', 'approver') AND is_active = 1 AND email_notifications = 1");
    $stmt->execute();
    $config['admin_emails'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    writeLog("Fehler beim Laden der Admin-E-Mails: " . $e->getMessage(), 'ERROR');
}

writeLog("🧹 Erweiterte Cleanup abgelaufener Reservierungen gestartet");
writeLog("⏰ Zeitpunkt: " . date('Y-m-d H:i:s'));
writeLog("🔧 Trockenlauf-Modus: " . ($config['dry_run'] ? 'JA' : 'NEIN'));

try {
    // 1. Abgelaufene Reservierungen finden
    writeLog("1. Suche abgelaufene Reservierungen...");
    
    $stmt = $db->prepare("
        SELECT r.id, r.requester_name, r.requester_email, r.reason, r.start_datetime, r.end_datetime,
               v.name as vehicle_name, ce.google_event_id, r.status
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.end_datetime < NOW() 
        AND r.end_datetime < DATE_SUB(NOW(), INTERVAL ? DAY)
        AND r.status IN ('approved', 'pending')
        ORDER BY r.end_datetime ASC
    ");
    $stmt->execute([$config['max_cleanup_age_days']]);
    $expired_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = count($expired_reservations);
    writeLog("   Gefunden: {$count} abgelaufene Reservierungen (älter als {$config['max_cleanup_age_days']} Tage)");
    
    if ($count === 0) {
        writeLog("✅ Keine abgelaufenen Reservierungen gefunden. Cleanup beendet.");
        exit(0);
    }
    
    // 2. Reservierungen verarbeiten
    writeLog("2. Verarbeite abgelaufene Reservierungen...");
    
    $deleted_count = 0;
    $google_deleted_count = 0;
    $errors = [];
    $deleted_reservations = [];
    
    foreach ($expired_reservations as $reservation) {
        writeLog("   Verarbeite: {$reservation['vehicle_name']} - {$reservation['reason']} (bis: {$reservation['end_datetime']})");
        
        try {
            if (!$config['dry_run']) {
                // 2.1 Google Calendar Event löschen (falls vorhanden)
                if (!empty($reservation['google_event_id'])) {
                    writeLog("     - Lösche Google Calendar Event: {$reservation['google_event_id']}");
                    
                    // Prüfen ob noch andere Reservierungen dieses Event nutzen
                    $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE google_event_id = ?");
                    $stmt->execute([$reservation['google_event_id']]);
                    $remaining_links = (int)$stmt->fetchColumn();
                    
                    if ($remaining_links === 1) {
                        // Nur löschen, wenn keine weitere Reservierung dieses Event nutzt
                        if (function_exists('delete_google_calendar_event')) {
                            $google_deleted = delete_google_calendar_event($reservation['google_event_id']);
                            if ($google_deleted) {
                                writeLog("     ✅ Google Calendar Event gelöscht");
                                $google_deleted_count++;
                            } else {
                                writeLog("     ⚠️ Google Calendar Event konnte nicht gelöscht werden");
                                $errors[] = "Google Calendar Event {$reservation['google_event_id']} konnte nicht gelöscht werden";
                            }
                        } else {
                            writeLog("     ⚠️ Google Calendar Funktion nicht verfügbar");
                            $errors[] = "Google Calendar Funktion nicht verfügbar für Event {$reservation['google_event_id']}";
                        }
                    } else {
                        writeLog("     ℹ️ Google Event wird nicht gelöscht (noch {$remaining_links} weitere Verknüpfungen)");
                    }
                } else {
                    writeLog("     ℹ️ Kein Google Calendar Event vorhanden");
                }
                
                // 2.2 Calendar Events Verknüpfung löschen
                $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                $stmt->execute([$reservation['id']]);
                writeLog("     ✅ Calendar Events Verknüpfung gelöscht");
                
                // 2.3 Reservierung löschen
                $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                $stmt->execute([$reservation['id']]);
                writeLog("     ✅ Reservierung gelöscht");
            } else {
                writeLog("     🔍 TROCKENLAUF: Reservierung würde gelöscht werden");
            }
            
            $deleted_count++;
            $deleted_reservations[] = $reservation;
            
        } catch (Exception $e) {
            $error_msg = "Fehler bei Reservierung ID {$reservation['id']}: " . $e->getMessage();
            writeLog("     ❌ {$error_msg}", 'ERROR');
            $errors[] = $error_msg;
        }
        
        writeLog("");
    }
    
    // 3. E-Mail-Benachrichtigung senden
    if ($config['send_email_notifications'] && !empty($config['admin_emails']) && $deleted_count > 0) {
        writeLog("3. Sende E-Mail-Benachrichtigung...");
        
        $subject = "Cleanup abgelaufener Reservierungen - " . date('Y-m-d H:i:s');
        $message_html = "
        <h2>Cleanup abgelaufener Reservierungen</h2>
        <p>Das automatische Cleanup wurde ausgeführt.</p>
        <p><strong>Zeitpunkt:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>Modus:</strong> " . ($config['dry_run'] ? 'Trockenlauf' : 'Produktiv') . "</p>
        <p><strong>Gelöschte Reservierungen:</strong> {$deleted_count}</p>
        <p><strong>Gelöschte Google Calendar Events:</strong> {$google_deleted_count}</p>
        ";
        
        if (!empty($errors)) {
            $message_html .= "<p><strong>Fehler:</strong> " . count($errors) . "</p>";
            $message_html .= "<ul>";
            foreach ($errors as $error) {
                $message_html .= "<li>" . htmlspecialchars($error) . "</li>";
            }
            $message_html .= "</ul>";
        }
        
        if (!empty($deleted_reservations)) {
            $message_html .= "<h3>Gelöschte Reservierungen:</h3><ul>";
            foreach ($deleted_reservations as $res) {
                $message_html .= "<li>{$res['vehicle_name']} - {$res['reason']} (bis: {$res['end_datetime']})</li>";
            }
            $message_html .= "</ul>";
        }
        
        try {
            $admin_emails = implode(',', $config['admin_emails']);
            $email_sent = send_email($admin_emails, $subject, $message_html);
            if ($email_sent) {
                writeLog("     ✅ E-Mail-Benachrichtigung gesendet an: {$admin_emails}");
            } else {
                writeLog("     ⚠️ E-Mail-Benachrichtigung konnte nicht gesendet werden");
            }
        } catch (Exception $e) {
            writeLog("     ❌ Fehler beim Senden der E-Mail: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // 4. Zusammenfassung
    writeLog("4. Cleanup abgeschlossen!");
    writeLog("   ✅ Reservierungen " . ($config['dry_run'] ? 'würden gelöscht werden' : 'gelöscht') . ": {$deleted_count}");
    writeLog("   ✅ Google Calendar Events " . ($config['dry_run'] ? 'würden gelöscht werden' : 'gelöscht') . ": {$google_deleted_count}");
    
    if (!empty($errors)) {
        writeLog("   ⚠️ Fehler aufgetreten: " . count($errors), 'WARN');
        foreach ($errors as $error) {
            writeLog("     - {$error}", 'WARN');
        }
    }
    
    writeLog("🎉 Cleanup erfolgreich abgeschlossen!");
    
} catch (Exception $e) {
    writeLog("❌ Kritischer Fehler beim Cleanup: " . $e->getMessage(), 'ERROR');
    writeLog("Stack Trace:\n" . $e->getTraceAsString(), 'ERROR');
    exit(1);
}
?>
