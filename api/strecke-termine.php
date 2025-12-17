<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

// GET: Einzelnen Termin laden
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM strecke_termine WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $termin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($termin) {
            echo json_encode(['success' => true, 'termin' => $termin]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Termin nicht gefunden']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// POST: Termin erstellen/aktualisieren/löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $stmt = $db->prepare("
                    INSERT INTO strecke_termine (termin_datum, termin_zeit, ort, max_teilnehmer, bemerkung, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $input['termin_datum'],
                    $input['termin_zeit'] ?? '09:00',
                    $input['ort'] ?? '',
                    (int)($input['max_teilnehmer'] ?? 6),
                    $input['bemerkung'] ?? '',
                    $_SESSION['user_id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Termin erstellt', 'id' => $db->lastInsertId()]);
                break;
                
            case 'create_multiple':
                $termine = $input['termine'] ?? [];
                if (empty($termine)) {
                    echo json_encode(['success' => false, 'message' => 'Keine Termine übergeben']);
                    break;
                }
                
                $stmt = $db->prepare("
                    INSERT INTO strecke_termine (termin_datum, termin_zeit, ort, max_teilnehmer, bemerkung, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $created = 0;
                foreach ($termine as $termin) {
                    $stmt->execute([
                        $termin['termin_datum'],
                        $termin['termin_zeit'] ?? '09:00',
                        $termin['ort'] ?? '',
                        (int)($termin['max_teilnehmer'] ?? 6),
                        $termin['bemerkung'] ?? '',
                        $_SESSION['user_id']
                    ]);
                    $created++;
                }
                
                $msg = $created === 1 ? '1 Termin erstellt' : $created . ' Termine erstellt';
                echo json_encode(['success' => true, 'message' => $msg, 'count' => $created]);
                break;
                
            case 'update':
                $stmt = $db->prepare("
                    UPDATE strecke_termine 
                    SET termin_datum = ?, termin_zeit = ?, ort = ?, max_teilnehmer = ?, bemerkung = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $input['termin_datum'],
                    $input['termin_zeit'] ?? '09:00',
                    $input['ort'] ?? '',
                    (int)($input['max_teilnehmer'] ?? 6),
                    $input['bemerkung'] ?? '',
                    (int)$input['termin_id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Termin aktualisiert']);
                break;
                
            case 'delete':
                $terminId = (int)($input['termin_id'] ?? 0);
                if ($terminId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Ungültige Termin-ID']);
                    break;
                }
                
                // Zuordnungen werden durch CASCADE automatisch gelöscht
                $stmt = $db->prepare("DELETE FROM strecke_termine WHERE id = ?");
                $stmt->execute([$terminId]);
                echo json_encode(['success' => true, 'message' => 'Termin gelöscht']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);

