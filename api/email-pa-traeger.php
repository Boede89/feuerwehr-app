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
        // Detaillierte Fehlermeldung
        $errorMessage = 'E-Mail konnte an keinen Empfänger gesendet werden.';
        if (!empty($errors)) {
            $errorMessage .= ' Details: ' . implode(', ', $errors);
        }
        
        // Prüfe SMTP-Einstellungen
        $smtp_host = '';
        $smtp_username = '';
        $smtp_password = '';
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_username', 'smtp_password')");
            $stmt->execute();
            $smtp_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $smtp_host = $smtp_settings['smtp_host'] ?? '';
            $smtp_username = $smtp_settings['smtp_username'] ?? '';
            $smtp_password = $smtp_settings['smtp_password'] ?? '';
        } catch (Exception $e) {
            error_log("Fehler beim Laden der SMTP-Einstellungen: " . $e->getMessage());
        }
        
        if (empty($smtp_password)) {
            $errorMessage .= ' SMTP-Passwort ist nicht konfiguriert. Bitte gehen Sie zu den Einstellungen und setzen Sie das Gmail App-Passwort.';
        } elseif (empty($smtp_host) || empty($smtp_username)) {
            $errorMessage .= ' SMTP-Einstellungen sind unvollständig. Bitte überprüfen Sie die Einstellungen.';
        }
        
        echo json_encode([
            'success' => false,
            'error' => $errorMessage,
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
        $name = trim(($traeger['first_name'] ?? '') . ' ' . ($traeger['last_name'] ?? ''));
        $text .= sprintf("%-3d | %-20s | %-15s | %-10s | %-10s | %s\n",
            $index + 1,
            substr($name, 0, 20),
            substr($traeger['status'] ?? '', 0, 15),
            date('d.m.Y', strtotime($traeger['strecke_am'] ?? '')),
            date('d.m.Y', strtotime($traeger['g263_am'] ?? '')),
            date('d.m.Y', strtotime($traeger['uebung_am'] ?? ''))
        );
    }
    
    $text .= "\n\n---\n";
    $text .= "Diese E-Mail wurde automatisch von der Feuerwehr App generiert.\n";
    $text .= "Erstellt am " . date('d.m.Y H:i') . " | Feuerwehr App v2.1";
    
    return $text;
}

function sendEmailWithAttachment($to, $from, $fromName, $subject, $message, $htmlContent) {
    try {
        // Erstelle schöne HTML-E-Mail
        $htmlBody = generateBeautifulEmailHTML($htmlContent['results'] ?? [], $htmlContent, $message);
        
        // Verwende die konfigurierte send_email Funktion mit HTML
        $result = send_email($to, $subject, $htmlBody, '', true);
        
        if ($result) {
            error_log("PA-Träger E-Mail erfolgreich gesendet an: $to");
        } else {
            error_log("PA-Träger E-Mail fehlgeschlagen an: $to");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log('E-Mail-Versand Fehler: ' . $e->getMessage());
        return false;
    }
}


function generateBeautifulEmailHTML($results, $htmlContent, $message) {
    $uebungsDatum = $htmlContent['uebungsDatum'] ?? '';
    $anzahl = $htmlContent['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $htmlContent['statusFilter'] ?? [];
    
    $html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PA-Träger Liste</title>
    <style>
        body { 
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 0; 
            background-color: #f8f9fa; 
            line-height: 1.6;
        }
        .email-container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: white; 
            border-radius: 12px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.1); 
            overflow: hidden;
        }
        .header { 
            background: linear-gradient(135deg, #dc3545, #c82333); 
            color: white; 
            padding: 30px; 
            text-align: center; 
        }
        .header h1 { 
            margin: 0; 
            font-size: 32px; 
            font-weight: bold;
        }
        .header h2 { 
            margin: 10px 0 0 0; 
            font-size: 20px; 
            opacity: 0.9; 
            font-weight: 300;
        }
        .content { 
            padding: 30px; 
        }
        .message-box { 
            background: #e3f2fd; 
            border-left: 4px solid #2196f3; 
            padding: 20px; 
            margin-bottom: 25px; 
            border-radius: 8px;
            font-style: italic;
        }
        .summary { 
            background: #f8f9fa; 
            padding: 25px; 
            border-radius: 10px; 
            margin-bottom: 30px; 
            border: 1px solid #e9ecef;
        }
        .summary h3 { 
            margin-top: 0; 
            color: #495057; 
            font-size: 18px;
            margin-bottom: 15px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .summary-item {
            display: flex;
            align-items: center;
        }
        .summary-icon {
            font-size: 20px;
            margin-right: 10px;
        }
        .summary-text {
            font-size: 14px;
            color: #6c757d;
        }
        .summary-value {
            font-weight: bold;
            color: #495057;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
            font-size: 14px;
            background: white;
        }
        th, td { 
            border: 1px solid #dee2e6; 
            padding: 15px 12px; 
            text-align: left; 
        }
        th { 
            background: linear-gradient(135deg, #e9ecef, #dee2e6); 
            font-weight: bold; 
            color: #495057; 
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        tr:hover {
            background-color: #e3f2fd;
        }
        .status-badge { 
            padding: 8px 16px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: bold; 
            display: inline-block;
            text-align: center;
            min-width: 80px;
        }
        .status-tauglich { 
            background-color: #d4edda; 
            color: #155724; 
        }
        .status-warnung { 
            background-color: #fff3cd; 
            color: #856404; 
        }
        .status-abgelaufen { 
            background-color: #f8d7da; 
            color: #721c24; 
        }
        .status-uebung-abgelaufen { 
            background-color: #f8d7da; 
            color: #721c24; 
        }
        .name-cell {
            font-weight: bold;
            color: #495057;
        }
        .date-cell {
            font-family: "Courier New", monospace;
            font-size: 13px;
            color: #6c757d;
        }
        .footer { 
            background: #f8f9fa; 
            padding: 25px; 
            text-align: center; 
            color: #6c757d; 
            font-size: 13px; 
            border-top: 1px solid #dee2e6; 
        }
        .footer p {
            margin: 5px 0;
        }
        .count-badge {
            background: #dc3545;
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>🔥 Feuerwehr App</h1>
            <h2>PA-Träger Liste für Übung</h2>
        </div>
        
        <div class="content">
            <div class="message-box">
                <strong>📧 Nachricht:</strong><br>
                ' . nl2br(htmlspecialchars($message)) . '
            </div>
            
            <div class="summary">
                <h3>📋 Suchkriterien</h3>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-icon">📅</span>
                        <div>
                            <div class="summary-text">Übungsdatum</div>
                            <div class="summary-value">' . date('d.m.Y', strtotime($uebungsDatum)) . '</div>
                        </div>
                    </div>
                    <div class="summary-item">
                        <span class="summary-icon">👥</span>
                        <div>
                            <div class="summary-text">Anzahl</div>
                            <div class="summary-value">' . ($anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger') . '</div>
                        </div>
                    </div>
                    <div class="summary-item">
                        <span class="summary-icon">🔍</span>
                        <div>
                            <div class="summary-text">Status-Filter</div>
                            <div class="summary-value">' . implode(', ', $statusFilter) . '</div>
                        </div>
                    </div>
                    <div class="summary-item">
                        <span class="summary-icon">✅</span>
                        <div>
                            <div class="summary-text">Gefunden</div>
                            <div class="summary-value">' . count($results) . ' PA-Träger <span class="count-badge">' . count($results) . '</span></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th>Name</th>
                        <th style="width: 120px;">Status</th>
                        <th style="width: 100px;">Strecke</th>
                        <th style="width: 100px;">G26.3</th>
                        <th style="width: 120px;">Übung/Einsatz</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($results as $index => $traeger) {
        $name = trim(($traeger['first_name'] ?? '') . ' ' . ($traeger['last_name'] ?? ''));
        $statusClass = 'status-' . strtolower(str_replace([' ', 'ü'], ['-', 'ue'], $traeger['status'] ?? ''));
        
        $html .= '<tr>
            <td style="font-weight: bold; color: #6c757d; text-align: center;">' . ($index + 1) . '</td>
            <td class="name-cell">' . htmlspecialchars($name) . '</td>
            <td><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($traeger['status'] ?? '') . '</span></td>
            <td class="date-cell">' . date('d.m.Y', strtotime($traeger['strecke_am'] ?? '')) . '</td>
            <td class="date-cell">' . date('d.m.Y', strtotime($traeger['g263_am'] ?? '')) . '</td>
            <td class="date-cell">' . date('d.m.Y', strtotime($traeger['uebung_am'] ?? '')) . '<br><small style="color: #adb5bd;">bis ' . date('d.m.Y', strtotime($traeger['uebung_bis'] ?? '')) . '</small></td>
        </tr>';
    }
    
    $html .= '</tbody>
            </table>
        </div>
        
        <div class="footer">
            <p><strong>📧 Diese E-Mail wurde automatisch von der Feuerwehr App generiert</strong></p>
            <p>Erstellt am ' . date('d.m.Y H:i') . ' | Feuerwehr App v2.1</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
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
