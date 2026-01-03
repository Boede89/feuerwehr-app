<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

// Prüfe ob Benutzer Mitgliederverwaltungs-Berechtigung hat
if (!has_permission('members')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

$member_id = (int)($_GET['member_id'] ?? 0);

if ($member_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ungültige Mitglieds-ID']);
    exit;
}

try {
    // RIC-Zuweisungen für das Mitglied laden
    $stmt = $db->prepare("
        SELECT mr.id, mr.ric_id, mr.status, mr.action,
               rc.kurztext, rc.beschreibung
        FROM member_ric mr
        JOIN ric_codes rc ON mr.ric_id = rc.id
        WHERE mr.member_id = ?
        ORDER BY rc.kurztext ASC
    ");
    $stmt->execute([$member_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Nur bestätigte 'add' Einträge zurückgeben (für Anzeige)
    $rics = [];
    foreach ($assignments as $assignment) {
        if ($assignment['status'] === 'confirmed' && $assignment['action'] === 'add') {
            $rics[] = [
                'id' => $assignment['id'],
                'ric_id' => $assignment['ric_id'],
                'kurztext' => $assignment['kurztext'],
                'beschreibung' => $assignment['beschreibung'],
                'status' => $assignment['status'],
                'action' => $assignment['action']
            ];
        } elseif ($assignment['status'] === 'pending') {
            // Pending Einträge auch zurückgeben (für gelbe Markierung)
            $rics[] = [
                'id' => $assignment['id'],
                'ric_id' => $assignment['ric_id'],
                'kurztext' => $assignment['kurztext'],
                'beschreibung' => $assignment['beschreibung'],
                'status' => $assignment['status'],
                'action' => $assignment['action']
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'rics' => $rics]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

