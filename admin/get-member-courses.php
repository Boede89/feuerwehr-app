<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !has_permission('courses')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert']);
    exit;
}

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

try {
    if ($type === 'by_name') {
        // Liste nach Namen: Alle Mitglieder mit ihren Lehrgängen
        // Einzelne Zeilen pro Mitglied-Lehrgang (kein GROUP_CONCAT, kein Truncation-Problem)
        $stmt = $db->prepare("
            SELECT 
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) as name,
                c.name as course_name,
                mc.completed_date
            FROM members m
            LEFT JOIN member_courses mc ON mc.member_id = m.id
            LEFT JOIN courses c ON c.id = mc.course_id
            ORDER BY m.last_name, m.first_name, c.name
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Nach Mitglied gruppieren
        $result = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!isset($result[$id])) {
                $result[$id] = [
                    'id' => $id,
                    'name' => $row['name'],
                    'courses' => []
                ];
            }
            if (!empty($row['course_name'])) {
                $year = '';
                if (!empty($row['completed_date'])) {
                    $date_parts = explode('-', $row['completed_date']);
                    $year = $date_parts[0] ?? '';
                }
                $result[$id]['courses'][] = [
                    'name' => $row['course_name'],
                    'completed_date' => $row['completed_date'] ?? '',
                    'year' => $year
                ];
            }
        }
        $result = array_values($result);
        
        echo json_encode(['success' => true, 'members' => $result]);
        
    } elseif ($type === 'by_course') {
        // Liste nach Lehrgang: Mitglieder die einen bestimmten Lehrgang haben
        $course_id = (int)($_GET['course_id'] ?? 0);
        
        if ($course_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ungültige Lehrgangs-ID']);
            exit;
        }
        
        $stmt = $db->prepare("
            SELECT 
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) as name,
                mc.completed_date
            FROM member_courses mc
            INNER JOIN members m ON m.id = mc.member_id
            WHERE mc.course_id = ?
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute([$course_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Jahr extrahieren für Anzeige
        foreach ($members as &$member) {
            if (!empty($member['completed_date'])) {
                $date_parts = explode('-', $member['completed_date']);
                $member['year'] = $date_parts[0] ?? '';
            } else {
                $member['year'] = '';
            }
        }
        unset($member);
        
        error_log("DEBUG: Anzahl Mitglieder mit Lehrgang ID $course_id: " . count($members));
        
        echo json_encode(['success' => true, 'members' => $members]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Ungültiger Typ']);
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgangsliste: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

