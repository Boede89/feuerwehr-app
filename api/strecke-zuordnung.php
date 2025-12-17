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
            // Strategie:
            // - Bereits abgelaufen: Frühestmöglichen Termin
            // - Noch gültig: Spätestmöglichen Termin VOR dem Ablauf (um das Datum so lange wie möglich hinauszuzögern)
            // - ALLE Geräteträger sollen verplant werden
            
            $heute = date('Y-m-d');
            
            // 1. Alle aktiven Geräteträger ohne Zuordnung laden
            $stmt = $db->prepare("
                SELECT at.id, at.first_name, at.last_name,
                       at.strecke_am,
                       DATE_ADD(at.strecke_am, INTERVAL 1 YEAR) as strecke_bis,
                       DATEDIFF(DATE_ADD(at.strecke_am, INTERVAL 1 YEAR), CURDATE()) as tage_bis_ablauf
                FROM atemschutz_traeger at
                LEFT JOIN strecke_zuordnungen sz ON at.id = sz.traeger_id
                WHERE at.status = 'Aktiv' AND sz.id IS NULL
                ORDER BY 
                    CASE WHEN at.strecke_am IS NULL THEN 2 ELSE 0 END,
                    tage_bis_ablauf ASC
            ");
            $stmt->execute();
            $nichtZugeordnet = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 2. Alle zukünftigen Termine laden (aufsteigend sortiert)
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
            
            // Berechne Gesamtkapazität
            $gesamtKapazitaet = array_sum(array_column($termine, 'freie_plaetze'));
            $anzahlTraeger = count($nichtZugeordnet);
            
            // 3. Zuordnung durchführen
            $zugeordnet = 0;
            $nichtVerplant = 0;
            $insertStmt = $db->prepare("INSERT INTO strecke_zuordnungen (termin_id, traeger_id) VALUES (?, ?)");
            
            // Kopie der Termine für die Bearbeitung
            $termineKopie = $termine;
            
            foreach ($nichtZugeordnet as $traeger) {
                $strecke_bis = $traeger['strecke_bis'];
                $tage_bis_ablauf = $traeger['tage_bis_ablauf'];
                $bestTermin = null;
                $bestTerminIndex = null;
                
                // Fall 1: Bereits abgelaufen oder kein Datum vorhanden -> Frühesten Termin
                if ($strecke_bis === null || $tage_bis_ablauf < 0) {
                    // Nimm den frühesten verfügbaren Termin
                    foreach ($termineKopie as $idx => &$termin) {
                        if ($termin['freie_plaetze'] > 0) {
                            $bestTermin = &$termin;
                            $bestTerminIndex = $idx;
                            break;
                        }
                    }
                    unset($termin);
                }
                // Fall 2: Noch gültig -> Spätestmöglichen Termin VOR dem Ablauf
                else {
                    // Suche den spätesten Termin, der noch vor dem Ablaufdatum liegt
                    // Gehe rückwärts durch die Termine (vom spätesten zum frühesten)
                    for ($i = count($termineKopie) - 1; $i >= 0; $i--) {
                        $termin = &$termineKopie[$i];
                        if ($termin['freie_plaetze'] <= 0) {
                            unset($termin);
                            continue;
                        }
                        
                        // Termin liegt vor oder am Ablaufdatum?
                        if ($termin['termin_datum'] <= $strecke_bis) {
                            $bestTermin = &$termin;
                            $bestTerminIndex = $i;
                            break;
                        }
                        unset($termin);
                    }
                    
                    // Falls kein Termin vor dem Ablaufdatum gefunden wurde,
                    // nimm den frühesten verfügbaren (besser zu spät als gar nicht)
                    if (!$bestTermin) {
                        foreach ($termineKopie as $idx => &$termin) {
                            if ($termin['freie_plaetze'] > 0) {
                                $bestTermin = &$termin;
                                $bestTerminIndex = $idx;
                                break;
                            }
                        }
                        unset($termin);
                    }
                }
                
                // Zuordnung durchführen
                if ($bestTermin && $bestTerminIndex !== null) {
                    $insertStmt->execute([$bestTermin['id'], $traeger['id']]);
                    $termineKopie[$bestTerminIndex]['freie_plaetze']--;
                    $zugeordnet++;
                } else {
                    $nichtVerplant++;
                }
            }
            
            // Ergebnismeldung
            $message = "$zugeordnet Geräteträger wurden automatisch zugeordnet.";
            if ($nichtVerplant > 0) {
                $message .= " $nichtVerplant Geräteträger konnten nicht verplant werden (keine freien Plätze).";
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $message,
                'zugeordnet' => $zugeordnet,
                'nicht_verplant' => $nichtVerplant
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

