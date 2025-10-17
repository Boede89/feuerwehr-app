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
$certificateType = $_POST['certificate_type'] ?? '';
$urgency = $_POST['urgency'] ?? ''; // 'warnung' oder 'abgelaufen'

if (!$traegerId || !$certificateType || !$urgency) {
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
    
    if (empty($traeger['email'])) {
        echo json_encode(['success' => false, 'message' => 'Keine E-Mail-Adresse hinterlegt']);
        exit;
    }
    
    // E-Mail-Vorlage laden
    $templateKey = $certificateType . '_' . $urgency;
    $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
    $stmt->execute([$templateKey]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        echo json_encode(['success' => false, 'message' => 'E-Mail-Vorlage nicht gefunden']);
        exit;
    }
    
    // Ablaufdatum berechnen
    $now = new DateTime('today');
    $expiryDate = '';
    
    switch ($certificateType) {
        case 'strecke':
            $streckeAm = new DateTime($traeger['strecke_am']);
            $expiryDate = $streckeAm->add(new DateInterval('P1Y'))->format('d.m.Y');
            break;
        case 'g263':
            $g263Am = new DateTime($traeger['g263_am']);
            $birthdate = new DateTime($traeger['birthdate']);
            $age = $birthdate->diff(new DateTime())->y;
            if ($age < 50) {
                $expiryDate = $g263Am->add(new DateInterval('P3Y'))->format('d.m.Y');
            } else {
                $expiryDate = $g263Am->add(new DateInterval('P1Y'))->format('d.m.Y');
            }
            break;
        case 'uebung':
            $uebungAm = new DateTime($traeger['uebung_am']);
            $expiryDate = $uebungAm->add(new DateInterval('P1Y'))->format('d.m.Y');
            break;
    }
    
    // Platzhalter ersetzen
    $subject = str_replace(
        ['{first_name}', '{last_name}', '{expiry_date}'],
        [$traeger['first_name'], $traeger['last_name'], $expiryDate],
        $template['subject']
    );
    
    $body = str_replace(
        ['{first_name}', '{last_name}', '{expiry_date}'],
        [$traeger['first_name'], $traeger['last_name'], $expiryDate],
        $template['body']
    );
    
    // E-Mail senden (hier würde normalerweise PHPMailer oder ähnliches verwendet werden)
    // Für Demo-Zwecke simulieren wir den Versand
    $headers = [
        'From: ' . ($_SESSION['email'] ?? 'noreply@feuerwehr.local'),
        'Reply-To: ' . ($_SESSION['email'] ?? 'noreply@feuerwehr.local'),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: Feuerwehr App'
    ];
    
    $success = mail($traeger['email'], $subject, $body, implode("\r\n", $headers));
    
    if ($success) {
        // E-Mail-Versand in der Datenbank protokollieren
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                traeger_id INT NOT NULL,
                template_key VARCHAR(50) NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_by INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $stmt = $db->prepare("INSERT INTO email_log (traeger_id, template_key, recipient_email, subject, sent_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$traegerId, $templateKey, $traeger['email'], $subject, $_SESSION['user_id']]);
        } catch (Exception $e) {
            // Logging-Fehler ignorieren
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'E-Mail erfolgreich gesendet an ' . $traeger['email']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'E-Mail konnte nicht gesendet werden']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
}
?>
