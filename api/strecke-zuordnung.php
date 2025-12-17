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

try {
    switch ($action) {
        case 'zuordnen':
            $traegerId = (int)($input['traeger_id'] ?? 0);
            $terminId = (int)($input['termin_id'] ?? 0);
            
            if ($traegerId <= 0 || $terminId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
                break;
            }
            
            // Prüfen ob Termin noch Plätze frei hat
            $stmt = $db->prepare("
                SELECT t.max_teilnehmer, COUNT(z.id) as aktuelle
                FROM strecke_termine t
                LEFT JOIN strecke_zuordnungen z ON t.id = z.termin_id
                WHERE t.id = ?
                GROUP BY t.id
            ");
            $stmt->execute([$terminId]);
            $termin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$termin) {
                echo json_encode(['success' => false, 'message' => 'Termin nicht gefunden']);
                break;
            }
            
            if ($termin['aktuelle'] >= $termin['max_teilnehmer']) {
                echo json_encode(['success' => false, 'message' => 'Termin ist bereits voll']);
                break;
            }
            
            // Bestehende Zuordnung entfernen (falls vorhanden)
            $stmt = $db->prepare("DELETE FROM strecke_zuordnungen WHERE traeger_id = ?");
            $stmt->execute([$traegerId]);
            
            // Neue Zuordnung erstellen
            $stmt = $db->prepare("INSERT INTO strecke_zuordnungen (termin_id, traeger_id) VALUES (?, ?)");
            $stmt->execute([$terminId, $traegerId]);
            
            echo json_encode(['success' => true, 'message' => 'Zuordnung gespeichert']);
            break;
            
        case 'entfernen':
            $traegerId = (int)($input['traeger_id'] ?? 0);
            $terminId = (int)($input['termin_id'] ?? 0);
            
            $stmt = $db->prepare("DELETE FROM strecke_zuordnungen WHERE traeger_id = ? AND termin_id = ?");
            $stmt->execute([$traegerId, $terminId]);
            
            echo json_encode(['success' => true, 'message' => 'Zuordnung entfernt']);
            break;
            
        case 'auto_zuordnung':
            // Automatische Zuordnung basierend auf Ablaufdatum
            
            // 1. Alle aktiven Geräteträger ohne Zuordnung laden, sortiert nach Ablaufdatum (dringendste zuerst)
            $stmt = $db->prepare("
                SELECT at.id, at.first_name, at.last_name,
                       DATE_ADD(at.strecke_am, INTERVAL 1 YEAR) as strecke_bis,
                       DATEDIFF(DATE_ADD(at.strecke_am, INTERVAL 1 YEAR), CURDATE()) as tage_bis_ablauf
                FROM atemschutz_traeger at
                LEFT JOIN strecke_zuordnungen sz ON at.id = sz.traeger_id
                WHERE at.status = 'Aktiv' AND sz.id IS NULL
                ORDER BY 
                    CASE WHEN at.strecke_am IS NULL THEN 1 ELSE 0 END,
                    tage_bis_ablauf ASC
            ");
            $stmt->execute();
            $nichtZugeordnet = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 2. Alle zukünftigen Termine laden
            $stmt = $db->prepare("
                SELECT t.id, t.termin_datum, t.max_teilnehmer, 
                       COUNT(z.id) as aktuelle_teilnehmer,
                       (t.max_teilnehmer - COUNT(z.id)) as freie_plaetze
                FROM strecke_termine t
                LEFT JOIN strecke_zuordnungen z ON t.id = z.termin_id
                WHERE t.termin_datum >= CURDATE()
                GROUP BY t.id
                HAVING freie_plaetze > 0
                ORDER BY t.termin_datum ASC
            ");
            $stmt->execute();
            $termine = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($termine)) {
                echo json_encode(['success' => false, 'message' => 'Keine Termine mit freien Plätzen verfügbar']);
                break;
            }
            
            if (empty($nichtZugeordnet)) {
                echo json_encode(['success' => true, 'message' => 'Alle Geräteträger sind bereits zugeordnet']);
                break;
            }
            
            // 3. Zuordnung durchführen
            $zugeordnet = 0;
            $insertStmt = $db->prepare("INSERT INTO strecke_zuordnungen (termin_id, traeger_id) VALUES (?, ?)");
            
            foreach ($nichtZugeordnet as $traeger) {
                // Besten Termin für diesen Geräteträger finden
                // Regel: Termin sollte VOR dem Ablaufdatum liegen, aber so spät wie möglich
                $bestTermin = null;
                $strecke_bis = $traeger['strecke_bis'];
                
                foreach ($termine as &$termin) {
                    if ($termin['freie_plaetze'] <= 0) continue;
                    
                    // Wenn Strecke_bis bekannt ist, versuche einen Termin davor zu finden
                    if ($strecke_bis) {
                        $terminDatum = $termin['termin_datum'];
                        
                        // Termin sollte vor dem Ablaufdatum liegen
                        if ($terminDatum <= $strecke_bis) {
                            $bestTermin = &$termin;
                            break; // Erster passender Termin (frühester)
                        }
                    } else {
                        // Kein Ablaufdatum bekannt, nimm einfach den ersten verfügbaren
                        $bestTermin = &$termin;
                        break;
                    }
                }
                
                // Falls kein Termin vor dem Ablaufdatum gefunden wurde, nimm den ersten verfügbaren
                if (!$bestTermin) {
                    foreach ($termine as &$termin) {
                        if ($termin['freie_plaetze'] > 0) {
                            $bestTermin = &$termin;
                            break;
                        }
                    }
                }
                
                if ($bestTermin) {
                    $insertStmt->execute([$bestTermin['id'], $traeger['id']]);
                    $bestTermin['freie_plaetze']--;
                    $zugeordnet++;
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "$zugeordnet Geräteträger wurden automatisch zugeordnet"
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

