<?php
// Fehlerbehandlung aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'pdf-generator.php';

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
    
    // E-Mail-Inhalt generieren (nur Text mit Liste)
    $emailBody = generateEmailText($results, $params, $message);
    
    // E-Mail an alle Empfänger senden
    $successCount = 0;
    $errors = [];
    
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            // Daten für E-Mail zusammenstellen
            $emailData = [
                'results' => $results,
                'params' => $params,
                'uebungsDatum' => $params['uebungsDatum'] ?? '',
                'anzahlPaTraeger' => $params['anzahlPaTraeger'] ?? 'alle',
                'statusFilter' => $params['statusFilter'] ?? []
            ];
            
            if (sendEmailWithAttachment($recipient, $sender, $senderName, $subject, $message, $emailData)) {
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
    $uebungsDatum = $params['uebungsDatum'] ?? '';
    $anzahl = $params['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $params['statusFilter'] ?? [];
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PA-Träger Liste</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.4; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #dc3545; padding-bottom: 20px; }
        .header h1 { color: #dc3545; margin-bottom: 10px; font-size: 28px; }
        .header h2 { color: #6c757d; font-size: 18px; margin-bottom: 20px; }
        .summary { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #dc3545; }
        .summary h3 { margin-top: 0; color: #495057; font-size: 16px; }
        .summary p { margin: 5px 0; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        th { background-color: #e9ecef; font-weight: bold; font-size: 13px; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; display: inline-block; }
        .status-tauglich { background-color: #d4edda; color: #155724; }
        .status-warnung { background-color: #fff3cd; color: #856404; }
        .status-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .status-uebung-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .footer { margin-top: 30px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔥 Feuerwehr App</h1>
        <h2>PA-Träger Liste für Übung</h2>
    </div>
    
    <div class="summary">
        <h3>Suchkriterien</h3>
        <p><strong>Übungsdatum:</strong> ' . date('d.m.Y', strtotime($uebungsDatum)) . '</p>
        <p><strong>Anzahl:</strong> ' . ($anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger') . '</p>
        <p><strong>Status-Filter:</strong> ' . implode(', ', $statusFilter) . '</p>
        <p><strong>Gefunden:</strong> ' . count($results) . ' PA-Träger</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Status</th>
                <th>Strecke</th>
                <th>G26.3</th>
                <th>Übung/Einsatz</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($results as $index => $traeger) {
        $statusClass = getStatusClass($traeger['status']);
        $html .= '
            <tr>
                <td>' . ($index + 1) . '</td>
                <td>' . htmlspecialchars(($traeger['first_name'] ?? '') . ' ' . ($traeger['last_name'] ?? '')) . '</td>
                <td><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($traeger['status']) . '</span></td>
                <td>' . date('d.m.Y', strtotime($traeger['strecke_am'])) . '</td>
                <td>' . date('d.m.Y', strtotime($traeger['g263_am'])) . '</td>
                <td>' . date('d.m.Y', strtotime($traeger['uebung_am'])) . ' (bis ' . date('d.m.Y', strtotime($traeger['uebung_bis'])) . ')</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div class="footer">
        <p>Erstellt am ' . date('d.m.Y H:i') . ' | Feuerwehr App v2.1</p>
    </div>
</body>
</html>';
    
    return $html;
}

function getStatusClass($status) {
    $statusClasses = [
        'Tauglich' => 'status-tauglich',
        'Warnung' => 'status-warnung',
        'Abgelaufen' => 'status-abgelaufen',
        'Übung abgelaufen' => 'status-uebung-abgelaufen'
    ];
    return $statusClasses[$status] ?? 'status-tauglich';
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

function sendEmailWithAttachment($to, $from, $fromName, $subject, $message, $htmlContent) {
    try {
        // Vereinfachte E-Mail ohne Anhang - nur Text mit Liste
        $emailBody = $message . "\n\n";
        $emailBody .= "=== PA-Träger Liste für Übung ===\n\n";
        
        // Liste direkt in E-Mail einbetten
        $emailBody .= "Übungsdatum: " . date('d.m.Y', strtotime($htmlContent['uebungsDatum'] ?? '')) . "\n";
        $emailBody .= "Anzahl: " . ($htmlContent['anzahlPaTraeger'] === 'alle' ? 'Alle verfügbaren' : $htmlContent['anzahlPaTraeger'] . ' PA-Träger') . "\n";
        $emailBody .= "Status-Filter: " . implode(', ', $htmlContent['statusFilter'] ?? []) . "\n";
        $emailBody .= "Gefunden: " . count($htmlContent['results'] ?? []) . " PA-Träger\n\n";
        
        $emailBody .= "Nr. | Name | Status | Strecke | G26.3 | Übung/Einsatz\n";
        $emailBody .= str_repeat("-", 80) . "\n";
        
        foreach ($htmlContent['results'] ?? [] as $index => $traeger) {
            $name = ($traeger['first_name'] ?? '') . ' ' . ($traeger['last_name'] ?? '');
            $emailBody .= sprintf("%-3d | %-20s | %-15s | %-10s | %-10s | %s\n",
                $index + 1,
                substr($name, 0, 20),
                substr($traeger['status'] ?? '', 0, 15),
                date('d.m.Y', strtotime($traeger['strecke_am'] ?? '')),
                date('d.m.Y', strtotime($traeger['g263_am'] ?? '')),
                date('d.m.Y', strtotime($traeger['uebung_am'] ?? ''))
            );
        }
        
        $emailBody .= "\nErstellt am " . date('d.m.Y H:i') . " | Feuerwehr App v2.1\n";
        
        // Headers
        $headers = "From: $fromName <$from>\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        // E-Mail senden
        $result = mail($to, $subject, $emailBody, $headers);
        
        return $result;
        
    } catch (Exception $e) {
        error_log('E-Mail-Versand Fehler: ' . $e->getMessage());
        return false;
    }
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
