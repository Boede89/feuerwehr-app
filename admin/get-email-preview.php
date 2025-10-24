<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Funktion für schöne HTML-E-Mails
function createAtemschutzEmailHTML($traeger, $certificates, $hasExpired) {
    $urgencyClass = $hasExpired ? 'danger' : 'warning';
    $urgencyIcon = $hasExpired ? 'exclamation-triangle' : 'clock';
    $urgencyTitle = $hasExpired ? 'ACHTUNG: Zertifikate abgelaufen' : 'Erinnerung: Zertifikate laufen bald ab';
    
    $html = '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Atemschutz-Zertifikat Benachrichtigung</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8f9fa; }
            .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; font-weight: bold; }
            .header .subtitle { margin: 10px 0 0 0; font-size: 16px; opacity: 0.9; }
            .content { padding: 30px; }
            .alert { padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid; }
            .alert-warning { background-color: #fff3cd; border-color: #ffc107; color: #856404; }
            .alert-danger { background-color: #f8d7da; border-color: #dc3545; color: #721c24; }
            .certificate-list { background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .certificate-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #dee2e6; }
            .certificate-item:last-child { border-bottom: none; }
            .certificate-name { font-weight: bold; color: #495057; }
            .certificate-status { padding: 6px 12px; border-radius: 20px; font-size: 14px; font-weight: bold; }
            .status-expired { background-color: #dc3545; color: white; }
            .status-warning { background-color: #ffc107; color: #212529; }
            .footer { background-color: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; font-size: 14px; }
            .icon { font-size: 20px; margin-right: 10px; }
            .urgent-notice { background-color: #dc3545; color: white; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1><i class="icon">🔥</i> Feuerwehr Atemschutz</h1>
                <div class="subtitle">Zertifikat Benachrichtigung</div>
            </div>
            
            <div class="content">
                <h2>Hallo ' . htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']) . ',</h2>
                
                <div class="alert alert-' . $urgencyClass . '">
                    <h3><i class="icon">⚠️</i> ' . $urgencyTitle . '</h3>
                    <p>Folgende Atemschutz-Zertifikate benötigen Ihre Aufmerksamkeit:</p>
                </div>
                
                <div class="certificate-list">
                    <h4>📋 Zertifikat-Status:</h4>';
    
    foreach ($certificates as $cert) {
        $certName = '';
        $certIcon = '';
        switch ($cert['type']) {
            case 'strecke':
                $certName = 'Strecke-Zertifikat';
                $certIcon = '🏃‍♂️';
                break;
            case 'g263':
                $certName = 'G26.3-Untersuchung';
                $certIcon = '🏥';
                break;
            case 'uebung':
                $certName = 'Übung/Einsatz-Zertifikat';
                $certIcon = '🎯';
                break;
        }
        
        $statusClass = $cert['urgency'] === 'abgelaufen' ? 'status-expired' : 'status-warning';
        $statusText = $cert['urgency'] === 'abgelaufen' ? 'Abgelaufen' : 'Läuft bald ab';
        
        $html .= '
                    <div class="certificate-item">
                        <div class="certificate-name">' . $certIcon . ' ' . $certName . '</div>
                        <div class="certificate-status ' . $statusClass . '">' . $statusText . '</div>
                    </div>
                    <div style="font-size: 14px; color: #6c757d; margin-top: 5px;">
                        Ablaufdatum: ' . htmlspecialchars($cert['expiry_date']) . '
                    </div>';
    }
    
    $html .= '
                </div>';
    
    if ($hasExpired) {
        $html .= '
                <div class="urgent-notice">
                    ⚠️ ACHTUNG: Sie dürfen bis zur Verlängerung/Untersuchung nicht am Atemschutz teilnehmen!
                </div>
                <div class="alert alert-danger">
                    <h4>🚨 Sofortige Maßnahmen erforderlich:</h4>
                    <ul>
                        <li>Vereinbaren Sie <strong>SOFORT</strong> die notwendigen Termine</li>
                        <li>Kontaktieren Sie die zuständigen Stellen</li>
                        <li>Informieren Sie Ihre Vorgesetzten über die Situation</li>
                    </ul>
                </div>';
    } else {
        $html .= '
                <div class="alert alert-warning">
                    <h4>📅 Rechtzeitige Verlängerung empfohlen:</h4>
                    <ul>
                        <li>Vereinbaren Sie rechtzeitig die notwendigen Termine</li>
                        <li>Planen Sie ausreichend Zeit für die Bearbeitung ein</li>
                        <li>Kontaktieren Sie bei Fragen die zuständigen Stellen</li>
                    </ul>
                </div>';
    }
    
    $html .= '
                <div style="background-color: #e9ecef; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h4>📞 Kontakt & Unterstützung:</h4>
                    <p>Bei Fragen oder Problemen wenden Sie sich bitte an:</p>
                    <ul>
                        <li>Ihre direkten Vorgesetzten</li>
                        <li>Die Atemschutz-Abteilung</li>
                        <li>Die Verwaltung</li>
                    </ul>
                </div>
                
                <p>Mit freundlichen Grüßen,<br>
                <strong>Ihre Feuerwehr</strong></p>
            </div>
            
            <div class="footer">
                <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht direkt auf diese E-Mail.</p>
                <p>© ' . date('Y') . ' Feuerwehr - Atemschutz Management System</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

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
$certificates = json_decode($_POST['certificates'] ?? '[]', true);
$urgency = $_POST['urgency'] ?? 'warnung';

if (!$traegerId || empty($certificates)) {
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
    
    // E-Mail-Vorlagen für alle problematischen Zertifikate laden
    $templates = [];
    foreach ($certificates as $cert) {
        $templateKey = $cert['type'] . '_' . $cert['urgency'];
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
        $stmt->execute([$templateKey]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            $templates[] = $template;
        }
    }
    
    if (empty($templates)) {
        echo json_encode(['success' => false, 'message' => 'Keine E-Mail-Vorlagen gefunden']);
        exit;
    }
    
    // Kombinierte E-Mail erstellen
    if (count($templates) === 1) {
        // Nur eine Vorlage
        $template = $templates[0];
        $subject = $template['subject'];
        $body = $template['body'];
    } else {
        // Mehrere Vorlagen kombinieren
        $hasExpired = $urgency === 'abgelaufen';
        $subjectPrefix = $hasExpired ? 'ACHTUNG: Mehrere Zertifikate sind abgelaufen' : 'Erinnerung: Mehrere Zertifikate laufen bald ab';
        
        $subject = $subjectPrefix;
        $body = createAtemschutzEmailHTML($traeger, $certificates, $hasExpired);
    }
    
    // Bestimme das Ablaufdatum für {expiry_date}
    $expiryDate = '';
    if (!empty($certificates)) {
        // Verwende das erste (oder wichtigste) Ablaufdatum
        $firstCert = $certificates[0];
        $expiryDate = $firstCert['expiry_date'] ?? '';
    }
    
    // Platzhalter ersetzen
    $subject = str_replace(
        ['{first_name}', '{last_name}', '{expiry_date}'],
        [$traeger['first_name'], $traeger['last_name'], $expiryDate],
        $subject
    );
    
    $body = str_replace(
        ['{first_name}', '{last_name}', '{expiry_date}'],
        [$traeger['first_name'], $traeger['last_name'], $expiryDate],
        $body
    );
    
    echo json_encode([
        'success' => true,
        'subject' => $subject,
        'body' => $body
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
}
?>
