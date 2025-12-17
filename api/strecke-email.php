<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

/**
 * E-Mail für Strecken-Termin erstellen
 */
function createStreckeTerminEmail($traeger, $termin) {
    $name = $traeger['first_name'] . ' ' . $traeger['last_name'];
    $terminDatum = date('d.m.Y', strtotime($termin['termin_datum']));
    $terminZeit = date('H:i', strtotime($termin['termin_zeit']));
    $ort = $termin['ort'] ?: 'wird noch bekannt gegeben';
    
    $html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Übungsstrecke - Termineinladung</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .termin-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 20px; margin: 20px 0; border-radius: 0 8px 8px 0; }
        .termin-box h3 { margin-top: 0; color: #667eea; }
        .termin-detail { margin: 10px 0; }
        .termin-detail i { width: 25px; color: #667eea; }
        .wichtig { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔥 Übungsstrecke - Termineinladung</h1>
        </div>
        <div class="content">
            <p>Hallo ' . htmlspecialchars($name) . ',</p>
            
            <p>Sie wurden für folgenden <strong>Übungsstrecken-Termin</strong> eingeplant:</p>
            
            <div class="termin-box">
                <h3>📅 Ihr Termin</h3>
                <div class="termin-detail">
                    <i>📆</i> <strong>Datum:</strong> ' . $terminDatum . '
                </div>
                <div class="termin-detail">
                    <i>🕐</i> <strong>Uhrzeit:</strong> ' . $terminZeit . ' Uhr
                </div>
                <div class="termin-detail">
                    <i>📍</i> <strong>Ort:</strong> ' . htmlspecialchars($ort) . '
                </div>
            </div>
            
            <div class="wichtig">
                <strong>⚠️ Wichtig:</strong><br>
                Bitte bringen Sie Ihre Ausrüstung und gültigen Ausweis mit. 
                Falls Sie den Termin nicht wahrnehmen können, melden Sie sich bitte rechtzeitig ab.
            </div>
            
            <p>Mit freundlichen Grüßen,<br>
            <strong>Ihre Feuerwehr</strong></p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert.<br>
            Bei Fragen wenden Sie sich an Ihre Atemschutz-Abteilung.</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

try {
    switch ($action) {
        case 'einzeln_informieren':
            $traegerId = (int)($input['traeger_id'] ?? 0);
            $terminId = (int)($input['termin_id'] ?? 0);
            
            // Daten laden
            $stmt = $db->prepare("SELECT * FROM atemschutz_traeger WHERE id = ?");
            $stmt->execute([$traegerId]);
            $traeger = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $db->prepare("SELECT * FROM strecke_termine WHERE id = ?");
            $stmt->execute([$terminId]);
            $termin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$traeger || !$termin) {
                echo json_encode(['success' => false, 'message' => 'Geräteträger oder Termin nicht gefunden']);
                break;
            }
            
            if (empty($traeger['email'])) {
                echo json_encode(['success' => false, 'message' => 'Keine E-Mail-Adresse hinterlegt']);
                break;
            }
            
            $subject = 'Übungsstrecke - Ihr Termin am ' . date('d.m.Y', strtotime($termin['termin_datum']));
            $body = createStreckeTerminEmail($traeger, $termin);
            
            $success = send_email($traeger['email'], $subject, $body, '', true);
            
            if ($success) {
                // Benachrichtigung protokollieren
                $stmt = $db->prepare("UPDATE strecke_zuordnungen SET benachrichtigt_am = NOW() WHERE traeger_id = ? AND termin_id = ?");
                $stmt->execute([$traegerId, $terminId]);
                
                echo json_encode(['success' => true, 'message' => 'E-Mail an ' . $traeger['email'] . ' gesendet']);
            } else {
                echo json_encode(['success' => false, 'message' => 'E-Mail konnte nicht gesendet werden']);
            }
            break;
            
        case 'termin_informieren':
            $terminId = (int)($input['termin_id'] ?? 0);
            
            // Termin laden
            $stmt = $db->prepare("SELECT * FROM strecke_termine WHERE id = ?");
            $stmt->execute([$terminId]);
            $termin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$termin) {
                echo json_encode(['success' => false, 'message' => 'Termin nicht gefunden']);
                break;
            }
            
            // Alle zugeordneten Geräteträger laden
            $stmt = $db->prepare("
                SELECT at.* 
                FROM atemschutz_traeger at
                INNER JOIN strecke_zuordnungen sz ON at.id = sz.traeger_id
                WHERE sz.termin_id = ? AND at.email IS NOT NULL AND at.email != ''
            ");
            $stmt->execute([$terminId]);
            $traegerListe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($traegerListe)) {
                echo json_encode(['success' => false, 'message' => 'Keine Geräteträger mit E-Mail-Adresse zugeordnet']);
                break;
            }
            
            $gesendet = 0;
            $fehler = 0;
            
            foreach ($traegerListe as $traeger) {
                $subject = 'Übungsstrecke - Ihr Termin am ' . date('d.m.Y', strtotime($termin['termin_datum']));
                $body = createStreckeTerminEmail($traeger, $termin);
                
                if (send_email($traeger['email'], $subject, $body, '', true)) {
                    $gesendet++;
                    
                    // Benachrichtigung protokollieren
                    $stmt = $db->prepare("UPDATE strecke_zuordnungen SET benachrichtigt_am = NOW() WHERE traeger_id = ? AND termin_id = ?");
                    $stmt->execute([$traeger['id'], $terminId]);
                } else {
                    $fehler++;
                }
            }
            
            $message = "$gesendet E-Mail(s) gesendet";
            if ($fehler > 0) {
                $message .= ", $fehler fehlgeschlagen";
            }
            
            echo json_encode(['success' => $gesendet > 0, 'message' => $message]);
            break;
            
        case 'alle_informieren':
            // Alle Zuordnungen laden
            $stmt = $db->prepare("
                SELECT at.*, t.*, sz.termin_id, sz.traeger_id
                FROM strecke_zuordnungen sz
                INNER JOIN atemschutz_traeger at ON sz.traeger_id = at.id
                INNER JOIN strecke_termine t ON sz.termin_id = t.id
                WHERE at.email IS NOT NULL AND at.email != ''
                AND t.termin_datum >= CURDATE()
            ");
            $stmt->execute();
            $zuordnungen = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($zuordnungen)) {
                echo json_encode(['success' => false, 'message' => 'Keine Zuordnungen mit E-Mail-Adressen gefunden']);
                break;
            }
            
            $gesendet = 0;
            $fehler = 0;
            
            foreach ($zuordnungen as $z) {
                $traeger = [
                    'first_name' => $z['first_name'],
                    'last_name' => $z['last_name'],
                    'email' => $z['email']
                ];
                $termin = [
                    'termin_datum' => $z['termin_datum'],
                    'termin_zeit' => $z['termin_zeit'],
                    'ort' => $z['ort']
                ];
                
                $subject = 'Übungsstrecke - Ihr Termin am ' . date('d.m.Y', strtotime($termin['termin_datum']));
                $body = createStreckeTerminEmail($traeger, $termin);
                
                if (send_email($traeger['email'], $subject, $body, '', true)) {
                    $gesendet++;
                    
                    // Benachrichtigung protokollieren
                    $stmt = $db->prepare("UPDATE strecke_zuordnungen SET benachrichtigt_am = NOW() WHERE traeger_id = ? AND termin_id = ?");
                    $stmt->execute([$z['traeger_id'], $z['termin_id']]);
                } else {
                    $fehler++;
                }
            }
            
            $message = "$gesendet E-Mail(s) gesendet";
            if ($fehler > 0) {
                $message .= ", $fehler fehlgeschlagen";
            }
            
            echo json_encode(['success' => $gesendet > 0, 'message' => $message]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

