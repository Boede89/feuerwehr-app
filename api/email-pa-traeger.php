<?php
// Fehlerbehandlung aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// JSON-Header setzen
header('Content-Type: application/json; charset=UTF-8');

// Berechtigung prüfen
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert']);
    exit;
}

// POST-Daten empfangen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Nur POST-Requests erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige JSON-Daten']);
    exit;
}

$recipients = $input['recipients'] ?? [];
$subject = $input['subject'] ?? '';
$message = $input['message'] ?? '';
$results = $input['results'] ?? [];
$params = $input['params'] ?? [];

if (empty($recipients) || empty($subject)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empfänger und Betreff sind erforderlich']);
    exit;
}

try {
    // Absender aus SMTP-Einstellungen laden
    $sender = '';
    $senderName = '';
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('smtp_from_email', 'smtp_from_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $sender = $settings['smtp_from_email'] ?? '';
        $senderName = $settings['smtp_from_name'] ?? 'Feuerwehr App';
    } catch (Exception $e) {
        // Fallback auf angemeldeten User
        $sender = $_SESSION['email'] ?? '';
        $senderName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
    }
    // PDF-Anhang generieren
    $pdfContent = generatePDFForEmail($results, $params);
    
    // E-Mail-Inhalt generieren (nur Text, PDF als Anhang)
    $emailBody = generateEmailText($results, $params, $message);
    
    // E-Mail an alle Empfänger senden
    $successCount = 0;
    $errors = [];
    
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            if (sendEmailWithAttachment($recipient, $sender, $senderName, $subject, $emailBody, $pdfContent)) {
                $successCount++;
            } else {
                $errors[] = "Fehler beim Senden an $recipient";
            }
        } else {
            $errors[] = "Ungültige E-Mail-Adresse: $recipient";
        }
    }
    
    if ($successCount > 0) {
        echo json_encode([
            'success' => true,
            'message' => "E-Mail wurde an $successCount Empfänger gesendet.",
            'errors' => $errors
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'E-Mail konnte an keinen Empfänger gesendet werden.',
            'errors' => $errors
        ]);
    }
    
} catch (Exception $e) {
    error_log("E-Mail-Versand Fehler: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'E-Mail-Versand fehlgeschlagen: ' . $e->getMessage()]);
}

function generatePDFForEmail($results, $params) {
    // PDF-Inhalt für E-Mail-Anhang generieren
    $html = generatePDFHTML($results, $params);
    return $html; // In einer echten Implementierung würde hier eine PDF-Bibliothek verwendet
}

function generateEmailText($results, $params, $message) {
    $uebungsDatum = $params['uebungsDatum'] ?? '';
    $anzahl = $params['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $params['statusFilter'] ?? [];
    
    $text = $message . "\n\n";
    $text .= "=== PA-Träger Liste für Übung ===\n";
    $text .= "Übungsdatum: " . date('d.m.Y', strtotime($uebungsDatum)) . "\n";
    $text .= "Anzahl: " . ($anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger') . "\n";
    $text .= "Status-Filter: " . implode(', ', $statusFilter) . "\n";
    $text .= "Gefunden: " . count($results) . " PA-Träger\n\n";
    
    $text .= "Nr. | Name | Status | Strecke | G26.3 | Übung/Einsatz\n";
    $text .= "----|------|--------|---------|-------|---------------\n";
    
    foreach ($results as $index => $traeger) {
        $text .= sprintf("%-3d | %-20s | %-15s | %-10s | %-10s | %s\n",
            $index + 1,
            $traeger['name'],
            $traeger['status'],
            date('d.m.Y', strtotime($traeger['strecke_am'])),
            date('d.m.Y', strtotime($traeger['g263_am'])),
            date('d.m.Y', strtotime($traeger['uebung_am']))
        );
    }
    
    $text .= "\n\n---\n";
    $text .= "Diese E-Mail wurde automatisch von der Feuerwehr App generiert.\n";
    $text .= "Erstellt am " . date('d.m.Y H:i') . " | Feuerwehr App v2.1";
    
    return $text;
}

function sendEmailWithAttachment($to, $from, $fromName, $subject, $message, $pdfContent) {
    global $db;
    
    try {
        // SMTP-Einstellungen laden
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $smtp_host = $settings['smtp_host'] ?? '';
        $smtp_port = $settings['smtp_port'] ?? 587;
        $smtp_username = $settings['smtp_username'] ?? '';
        $smtp_password = $settings['smtp_password'] ?? '';
        $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
        $smtp_from_email = $settings['smtp_from_email'] ?? $from;
        $smtp_from_name = $settings['smtp_from_name'] ?? $fromName;
        
        if (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password)) {
            return sendEmailWithAttachmentSMTP($to, $smtp_from_email, $smtp_from_name, $subject, $message, $pdfContent, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption);
        } else {
            // Fallback auf mail() Funktion
            return sendEmailWithAttachmentMail($to, $smtp_from_email, $smtp_from_name, $subject, $message, $pdfContent);
        }
    } catch (Exception $e) {
        error_log('E-Mail mit Anhang Fehler: ' . $e->getMessage());
        return false;
    }
}

function sendEmailWithAttachmentMail($to, $from, $fromName, $subject, $message, $pdfContent) {
    $boundary = md5(uniqid(time()));
    
    $headers = "From: $fromName <$from>\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $message . "\r\n\r\n";
    
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n";
    $body .= "Content-Disposition: attachment; filename=\"pa-traeger-liste.html\"\r\n\r\n";
    $body .= $pdfContent . "\r\n\r\n";
    
    $body .= "--$boundary--\r\n";
    
    return mail($to, $subject, $body, $headers);
}

function sendEmailWithAttachmentSMTP($to, $from, $fromName, $subject, $message, $pdfContent, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption) {
    // Vereinfachte SMTP-Implementierung mit Anhang
    // In einer echten Implementierung würde hier eine vollständige SMTP-Bibliothek verwendet
    
    $boundary = md5(uniqid(time()));
    
    $email_data = "To: $to\r\n";
    $email_data .= "From: $fromName <$from>\r\n";
    $email_data .= "Reply-To: $from\r\n";
    $email_data .= "Subject: $subject\r\n";
    $email_data .= "MIME-Version: 1.0\r\n";
    $email_data .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    $email_data .= "\r\n";
    
    $email_data .= "--$boundary\r\n";
    $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $email_data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $email_data .= $message . "\r\n\r\n";
    
    $email_data .= "--$boundary\r\n";
    $email_data .= "Content-Type: text/html; charset=UTF-8\r\n";
    $email_data .= "Content-Transfer-Encoding: 8bit\r\n";
    $email_data .= "Content-Disposition: attachment; filename=\"pa-traeger-liste.html\"\r\n\r\n";
    $email_data .= $pdfContent . "\r\n\r\n";
    
    $email_data .= "--$boundary--\r\n";
    
    // Hier würde die SMTP-Verbindung und der Versand stattfinden
    // Für Demo-Zwecke verwenden wir die mail() Funktion
    return sendEmailWithAttachmentMail($to, $from, $subject, $message, $pdfContent);
}

function generateEmailHTML($results, $params, $message) {
    $uebungsDatum = $params['uebungsDatum'] ?? '';
    $anzahl = $params['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $params['statusFilter'] ?? [];
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PA-Träger Liste</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f8f9fa; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .header h1 { margin: 0; font-size: 28px; }
        .header h2 { margin: 10px 0 0 0; font-size: 18px; opacity: 0.9; }
        .content { padding: 30px; }
        .message { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .summary { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .summary h3 { margin-top: 0; color: #495057; }
        .summary p { margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #dee2e6; padding: 12px; text-align: left; }
        th { background-color: #e9ecef; font-weight: bold; color: #495057; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .status-tauglich { background-color: #d4edda; color: #155724; }
        .status-warnung { background-color: #fff3cd; color: #856404; }
        .status-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .status-uebung-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-radius: 0 0 10px 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔥 Feuerwehr App</h1>
            <h2>PA-Träger Liste für Übung</h2>
        </div>
        
        <div class="content">
            <div class="message">
                <strong>Nachricht:</strong><br>
                ' . nl2br(htmlspecialchars($message)) . '
            </div>
            
            <div class="summary">
                <h3>📋 Suchkriterien</h3>
                <p><strong>📅 Übungsdatum:</strong> ' . date('d.m.Y', strtotime($uebungsDatum)) . '</p>
                <p><strong>👥 Anzahl:</strong> ' . ($anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger') . '</p>
                <p><strong>🔍 Status-Filter:</strong> ' . implode(', ', $statusFilter) . '</p>
                <p><strong>✅ Gefunden:</strong> ' . count($results) . ' PA-Träger</p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Strecke</th>
                        <th>G26.3</th>
                        <th>Übung/Einsatz</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($results as $index => $traeger) {
        $statusClass = 'status-' . strtolower(str_replace([' ', 'ü'], ['-', 'ue'], $traeger['status']));
        $html .= '<tr>
            <td style="font-weight: bold; color: #6c757d;">' . ($index + 1) . '</td>
            <td><strong>' . htmlspecialchars($traeger['name']) . '</strong></td>
            <td><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($traeger['status']) . '</span></td>
            <td>' . date('d.m.Y', strtotime($traeger['strecke_am'])) . '</td>
            <td>' . date('d.m.Y', strtotime($traeger['g263_am'])) . '</td>
            <td>' . date('d.m.Y', strtotime($traeger['uebung_am'])) . '<br><small style="color: #6c757d;">bis ' . date('d.m.Y', strtotime($traeger['uebung_bis'])) . '</small></td>
        </tr>';
    }
    
    $html .= '</tbody>
            </table>
        </div>
        
        <div class="footer">
            <p>📧 Diese E-Mail wurde automatisch von der Feuerwehr App generiert</p>
            <p>Erstellt am ' . date('d.m.Y H:i') . ' | Feuerwehr App v2.1</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}
?>
