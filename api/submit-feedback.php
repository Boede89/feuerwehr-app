<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Nur POST-Requests erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Requests erlaubt']);
    exit;
}

try {
    // Prüfen ob Feedback-Tabelle existiert
    $table_check = $db->query("SHOW TABLES LIKE 'feedback'");
    if ($table_check->rowCount() == 0) {
        echo json_encode(['success' => false, 'message' => 'Feedback-System ist noch nicht eingerichtet. Bitte wenden Sie sich an den Administrator.']);
        exit;
    }
    
    // Eingabedaten validieren und sanitisieren
    $feedback_type = sanitize_input($_POST['feedback_type'] ?? '');
    $subject = sanitize_input($_POST['subject'] ?? '');
    $message = sanitize_input($_POST['message'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    
    // Validierung
    if (empty($feedback_type) || empty($subject) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Alle Pflichtfelder müssen ausgefüllt werden']);
        exit;
    }
    
    // E-Mail validieren falls angegeben
    if (!empty($email) && !validate_email($email)) {
        echo json_encode(['success' => false, 'message' => 'Ungültige E-Mail-Adresse']);
        exit;
    }
    
    // Feedback-Typ validieren
    $allowed_types = ['bug', 'feature', 'improvement', 'general'];
    if (!in_array($feedback_type, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Ungültiger Feedback-Typ']);
        exit;
    }
    
    // Feedback in Datenbank speichern
    $stmt = $db->prepare("
        INSERT INTO feedback (feedback_type, subject, message, email, user_id, ip_address, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $stmt->execute([$feedback_type, $subject, $message, $email, $user_id, $ip_address]);
    $feedback_id = $db->lastInsertId();
    
    // Admin-E-Mails abrufen
    $admin_emails = get_admin_emails();
    
    if (empty($admin_emails)) {
        error_log('Keine Admin-E-Mails gefunden für Feedback-Benachrichtigung');
        echo json_encode(['success' => false, 'message' => 'Keine Administratoren gefunden']);
        exit;
    }
    
    // E-Mail an alle Admins senden
    $email_sent = send_feedback_notification($admin_emails, $feedback_type, $subject, $message, $email, $feedback_id);
    
    if ($email_sent) {
        echo json_encode(['success' => true, 'message' => 'Feedback erfolgreich gesendet']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Feedback gespeichert, aber E-Mail-Versand fehlgeschlagen']);
    }
    
} catch (Exception $e) {
    error_log('Feedback-Fehler: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ein Fehler ist aufgetreten']);
}

/**
 * Alle Admin-E-Mail-Adressen abrufen
 */
function get_admin_emails() {
    global $db;
    
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT email 
            FROM users 
            WHERE (user_role = 'admin' OR is_admin = 1) 
            AND email IS NOT NULL 
            AND email != ''
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log('Fehler beim Abrufen der Admin-E-Mails: ' . $e->getMessage());
        return [];
    }
}

/**
 * Feedback-Benachrichtigung an Admins senden
 */
function send_feedback_notification($admin_emails, $feedback_type, $subject, $message, $user_email, $feedback_id) {
    global $db;
    
    // Feedback-Typ in deutscher Bezeichnung
    $type_labels = [
        'bug' => 'Fehler melden',
        'feature' => 'Funktionswunsch',
        'improvement' => 'Verbesserungsvorschlag',
        'general' => 'Allgemeines Feedback'
    ];
    
    $type_label = $type_labels[$feedback_type] ?? $feedback_type;
    
    // E-Mail-Betreff
    $email_subject = "[Feuerwehr App] Neues Feedback: " . $subject;
    
    // E-Mail-Inhalt
    $email_message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #0d6efd; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f8f9fa; padding: 20px; }
            .feedback-details { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0d6efd; }
            .footer { background-color: #6c757d; color: white; padding: 15px; text-align: center; font-size: 12px; }
            .label { font-weight: bold; color: #0d6efd; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🔔 Neues Feedback erhalten</h2>
            </div>
            <div class='content'>
                <p>Es wurde neues Feedback über die Feuerwehr App eingereicht:</p>
                
                <div class='feedback-details'>
                    <p><span class='label'>Art:</span> {$type_label}</p>
                    <p><span class='label'>Betreff:</span> " . htmlspecialchars($subject) . "</p>
                    <p><span class='label'>Nachricht:</span></p>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                    <p><span class='label'>Feedback-ID:</span> #{$feedback_id}</p>
                    " . (!empty($user_email) ? "<p><span class='label'>E-Mail für Rückfragen:</span> " . htmlspecialchars($user_email) . "</p>" : "") . "
                    <p><span class='label'>Eingereicht am:</span> " . date('d.m.Y H:i:s') . "</p>
                </div>
                
                <p>Bitte prüfen Sie das Feedback und antworten Sie gegebenenfalls dem Benutzer.</p>
            </div>
            <div class='footer'>
                <p>Diese E-Mail wurde automatisch von der Feuerwehr App generiert.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // E-Mail an alle Admins senden
    $success_count = 0;
    foreach ($admin_emails as $admin_email) {
        if (send_email($admin_email, $email_subject, $email_message, '', true)) {
            $success_count++;
        }
    }
    
    return $success_count > 0;
}
?>
