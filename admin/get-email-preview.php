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
        $body = "Hallo {first_name} {last_name},\n\n";
        
        if ($hasExpired) {
            $body .= "Folgende Zertifikate sind abgelaufen:\n\n";
        } else {
            $body .= "Folgende Zertifikate laufen bald ab:\n\n";
        }
        
        foreach ($certificates as $cert) {
            $certName = '';
            switch ($cert['type']) {
                case 'strecke':
                    $certName = 'Strecke-Zertifikat';
                    break;
                case 'g263':
                    $certName = 'G26.3-Untersuchung';
                    break;
                case 'uebung':
                    $certName = 'Übung/Einsatz-Zertifikat';
                    break;
            }
            
            $statusText = $cert['urgency'] === 'abgelaufen' ? 'ist abgelaufen' : 'läuft bald ab';
            $body .= "• {$certName}: {$statusText} am {$cert['expiry_date']}\n";
        }
        
        $body .= "\n";
        
        if ($hasExpired) {
            $body .= "Sie dürfen bis zur Verlängerung/Untersuchung nicht am Atemschutz teilnehmen.\n\n";
            $body .= "Bitte vereinbaren Sie SOFORT die notwendigen Termine.\n\n";
        } else {
            $body .= "Bitte vereinbaren Sie rechtzeitig die notwendigen Termine.\n\n";
        }
        
        $body .= "Mit freundlichen Grüßen\nIhre Feuerwehr";
    }
    
    // Platzhalter ersetzen
    $subject = str_replace(
        ['{first_name}', '{last_name}'],
        [$traeger['first_name'], $traeger['last_name']],
        $subject
    );
    
    $body = str_replace(
        ['{first_name}', '{last_name}'],
        [$traeger['first_name'], $traeger['last_name']],
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
