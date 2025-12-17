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
            
        case 'get_ausbilder':
            // Nur Ausbilder-Liste zurückgeben (für Modal-Auswahl)
            // Berechtigungen sind in der users-Tabelle als Spalten gespeichert
            $stmt = $db->prepare("
                SELECT id, email, first_name, last_name
                FROM users
                WHERE (is_admin = 1 OR can_atemschutz = 1)
                AND email IS NOT NULL AND email != ''
                ORDER BY last_name, first_name
            ");
            $stmt->execute();
            $ausbilder = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'ausbilder' => $ausbilder]);
            break;
            
        case 'ausbilder_informieren':
            // Ausgewählte Ausbilder laden
            $ausbilderIds = $input['ausbilder_ids'] ?? [];
            
            if (empty($ausbilderIds)) {
                echo json_encode(['success' => false, 'message' => 'Keine Ausbilder ausgewählt']);
                break;
            }
            
            // Nur ausgewählte Ausbilder laden
            $placeholders = implode(',', array_fill(0, count($ausbilderIds), '?'));
            $stmt = $db->prepare("
                SELECT u.id, u.email, u.first_name, u.last_name
                FROM users u
                WHERE u.id IN ($placeholders)
                AND u.email IS NOT NULL AND u.email != ''
            ");
            $stmt->execute($ausbilderIds);
            $ausbilder = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($ausbilder)) {
                echo json_encode(['success' => false, 'message' => 'Keine gültigen Ausbilder gefunden']);
                break;
            }
            
            // Alle zukünftigen Termine mit Zuordnungen laden
            $stmt = $db->prepare("
                SELECT t.*, 
                       GROUP_CONCAT(CONCAT(at.first_name, ' ', at.last_name) ORDER BY at.last_name SEPARATOR ', ') as teilnehmer,
                       COUNT(sz.id) as anzahl_teilnehmer
                FROM strecke_termine t
                LEFT JOIN strecke_zuordnungen sz ON t.id = sz.termin_id
                LEFT JOIN atemschutz_traeger at ON sz.traeger_id = at.id
                WHERE t.termin_datum >= CURDATE()
                GROUP BY t.id
                ORDER BY t.termin_datum ASC, t.termin_zeit ASC
            ");
            $stmt->execute();
            $termine = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Nicht zugeordnete Geräteträger
            $stmt = $db->prepare("
                SELECT at.first_name, at.last_name, 
                       DATE_ADD(at.strecke_am, INTERVAL 1 YEAR) as strecke_bis,
                       DATEDIFF(DATE_ADD(at.strecke_am, INTERVAL 1 YEAR), CURDATE()) as tage_bis_ablauf
                FROM atemschutz_traeger at
                LEFT JOIN strecke_zuordnungen sz ON at.id = sz.traeger_id
                WHERE at.status = 'Aktiv' AND sz.id IS NULL
                ORDER BY tage_bis_ablauf ASC
            ");
            $stmt->execute();
            $nichtZugeordnet = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // E-Mail erstellen
            $html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Übungsstrecke - Planungsübersicht</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        tr:hover { background: #f5f5f5; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.8em; }
        .badge-success { background: #28a745; color: white; }
        .badge-warning { background: #ffc107; color: #333; }
        .badge-danger { background: #dc3545; color: white; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 0.9em; color: #666; }
        .summary { background: #e8f4fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔥 Übungsstrecke - Planungsübersicht</h1>
            <p style="margin:10px 0 0 0;">Stand: ' . date('d.m.Y H:i') . ' Uhr</p>
        </div>
        <div class="content">
            <div class="summary">
                <strong>📊 Zusammenfassung:</strong> ' . count($termine) . ' Termine geplant, ' . count($nichtZugeordnet) . ' Geräteträger noch nicht zugeordnet
            </div>
            
            <h2>📅 Geplante Termine</h2>';
            
            if (empty($termine)) {
                $html .= '<p><em>Keine zukünftigen Termine vorhanden.</em></p>';
            } else {
                $html .= '<table>
                <tr>
                    <th>Datum</th>
                    <th>Uhrzeit</th>
                    <th>Ort</th>
                    <th>Teilnehmer</th>
                </tr>';
                
                foreach ($termine as $t) {
                    $datum = date('d.m.Y', strtotime($t['termin_datum']));
                    $zeit = date('H:i', strtotime($t['termin_zeit']));
                    $ort = $t['ort'] ?: '-';
                    $teilnehmer = $t['teilnehmer'] ?: '<em>Noch keine Zuordnungen</em>';
                    $anzahl = $t['anzahl_teilnehmer'] . '/' . $t['max_teilnehmer'];
                    
                    $html .= "<tr>
                        <td><strong>$datum</strong></td>
                        <td>$zeit Uhr</td>
                        <td>$ort</td>
                        <td>$teilnehmer <span class=\"badge badge-success\">$anzahl</span></td>
                    </tr>";
                }
                $html .= '</table>';
            }
            
            $html .= '<h2>⏳ Nicht zugeordnete Geräteträger</h2>';
            
            if (empty($nichtZugeordnet)) {
                $html .= '<p><em>Alle aktiven Geräteträger sind zugeordnet! ✅</em></p>';
            } else {
                $html .= '<table>
                <tr>
                    <th>Name</th>
                    <th>Strecke gültig bis</th>
                    <th>Status</th>
                </tr>';
                
                foreach ($nichtZugeordnet as $gt) {
                    $name = htmlspecialchars($gt['first_name'] . ' ' . $gt['last_name']);
                    $bis = $gt['strecke_bis'] ? date('d.m.Y', strtotime($gt['strecke_bis'])) : '-';
                    $tage = $gt['tage_bis_ablauf'];
                    
                    if ($tage === null) {
                        $status = '<span class="badge badge-warning">Kein Datum</span>';
                    } elseif ($tage < 0) {
                        $status = '<span class="badge badge-danger">Abgelaufen</span>';
                    } elseif ($tage <= 90) {
                        $status = '<span class="badge badge-warning">' . $tage . ' Tage</span>';
                    } else {
                        $status = '<span class="badge badge-success">' . $tage . ' Tage</span>';
                    }
                    
                    $html .= "<tr>
                        <td>$name</td>
                        <td>$bis</td>
                        <td>$status</td>
                    </tr>";
                }
                $html .= '</table>';
            }
            
            $html .= '
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert.</p>
        </div>
    </div>
</body>
</html>';
            
            $gesendet = 0;
            $fehler = 0;
            $subject = 'Übungsstrecke - Planungsübersicht vom ' . date('d.m.Y');
            
            foreach ($ausbilder as $a) {
                if (send_email($a['email'], $subject, $html, '', true)) {
                    $gesendet++;
                } else {
                    $fehler++;
                }
            }
            
            $message = "Planungsübersicht an $gesendet Ausbilder gesendet";
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

