<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für Benutzer mit Atemschutz-Recht oder Admin-Berechtigung
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$traegerId = (int)($_POST['traeger_id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$body = trim($_POST['body'] ?? '');
$certificates = json_decode($_POST['certificates'] ?? '[]', true);

if (!$traegerId || !$email || !$subject || !$body) {
    echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
    exit;
}

try {
    // Geräteträger-Daten laden
    $stmt = $db->prepare("SELECT * FROM atemschutz_traeger WHERE id = ? AND status = 'Aktiv'");
    $stmt->execute([$traegerId]);
    $traeger = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$traeger) {
        echo json_encode(['success' => false, 'message' => 'Geräteträger nicht gefunden']);
        exit;
    }
    
    // CC-Empfänger laden
    $ccRecipientEmails = [];
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='atemschutz_cc_recipients' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        error_log("send-atemschutz-email: CC-Empfänger Einstellung: " . ($val !== false ? $val : 'NICHT GEFUNDEN'));
        
        if ($val !== false && $val !== null && $val !== '') {
            $ccRecipientIds = json_decode($val, true);
            if (!empty($ccRecipientIds) && is_array($ccRecipientIds)) {
                $placeholders = implode(',', array_fill(0, count($ccRecipientIds), '?'));
                $stmt = $db->prepare("SELECT email FROM users WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''");
                $stmt->execute($ccRecipientIds);
                $ccRecipientEmails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                error_log("send-atemschutz-email: CC-Empfänger E-Mails: " . implode(', ', $ccRecipientEmails));
            }
        }
    } catch (Exception $e) {
        error_log("send-atemschutz-email: Fehler beim Laden der CC-Empfänger: " . $e->getMessage());
    }
    
    // E-Mail-Adresse aktualisieren
    $stmt = $db->prepare("UPDATE atemschutz_traeger SET email = ? WHERE id = ?");
    $stmt->execute([$email, $traegerId]);
    
    // Bestimme das Ablaufdatum für {expiry_date}
    $expiryDate = '';
    if (!empty($certificates)) {
        // Verwende das erste (oder wichtigste) Ablaufdatum
        $firstCert = $certificates[0];
        $expiryDate = $firstCert['expiry_date'] ?? '';
    }
    
    // Platzhalter ersetzen
    $finalSubject = str_replace(
        ['{first_name}', '{last_name}', '{expiry_date}'],
        [$traeger['first_name'], $traeger['last_name'], $expiryDate],
        $subject
    );
    
    $finalBody = str_replace(
        ['{first_name}', '{last_name}', '{expiry_date}'],
        [$traeger['first_name'], $traeger['last_name'], $expiryDate],
        $body
    );
    
    // Prüfe ob es sich um eine HTML-E-Mail handelt (mehrere Zertifikate)
    $isHtmlEmail = strpos($finalBody, '<!DOCTYPE html>') !== false;
    
    // E-Mail über die globale send_email() Funktion senden (verwendet SMTP-Einstellungen)
    $success = send_email($email, $finalSubject, $finalBody, '', $isHtmlEmail);
    
    // CC-Empfänger benachrichtigen
    $ccSentCount = 0;
    if ($success && !empty($ccRecipientEmails)) {
        $ccSubject = "[Kopie] " . $finalSubject . " - " . $traeger['first_name'] . " " . $traeger['last_name'];
        foreach ($ccRecipientEmails as $ccEmail) {
            if ($ccEmail && strtolower($ccEmail) !== strtolower($email)) { // Nicht an den ursprünglichen Empfänger senden
                try {
                    $ccResult = send_email($ccEmail, $ccSubject, $finalBody, '', $isHtmlEmail);
                    if ($ccResult) {
                        $ccSentCount++;
                        error_log("send-atemschutz-email: CC-E-Mail gesendet an: $ccEmail");
                    } else {
                        error_log("send-atemschutz-email: CC-E-Mail FEHLGESCHLAGEN an: $ccEmail");
                    }
                } catch (Exception $ccEx) {
                    error_log("send-atemschutz-email: CC-E-Mail-Versand Fehler an $ccEmail: " . $ccEx->getMessage());
                }
            }
        }
    }
    
    if ($success) {
        // E-Mail-Versand in der Datenbank protokollieren
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                traeger_id INT NOT NULL,
                template_keys TEXT NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_by INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $templateKeys = 'custom';
            
            $stmt = $db->prepare("INSERT INTO email_log (traeger_id, template_keys, recipient_email, subject, sent_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$traegerId, $templateKeys, $email, $finalSubject, $_SESSION['user_id']]);
            
            // CC-Empfänger auch protokollieren
            if ($ccSentCount > 0) {
                $ccEmailList = implode(', ', $ccRecipientEmails);
                $stmt->execute([$traegerId, 'custom_cc', $ccEmailList, "[CC] " . $finalSubject, $_SESSION['user_id']]);
            }
        } catch (Exception $e) {
            // Logging-Fehler ignorieren
        }
        
        $ccMessage = $ccSentCount > 0 ? " (+ $ccSentCount CC-Kopien)" : "";
        echo json_encode([
            'success' => true, 
            'message' => 'E-Mail erfolgreich gesendet an ' . $email . $ccMessage
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'E-Mail konnte nicht gesendet werden']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
}
?>
