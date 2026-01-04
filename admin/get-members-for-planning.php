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

$course_id = (int)($_GET['course_id'] ?? 0);
$check_requirements = isset($_GET['check_requirements']) && $_GET['check_requirements'] === '1';

if ($course_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige Lehrgangs-ID']);
    exit;
}

try {
    // Voraussetzungen für diesen Lehrgang laden
    $stmt_req = $db->prepare("
        SELECT cr.required_course_id, c.name
        FROM course_requirements cr
        JOIN courses c ON c.id = cr.required_course_id
        WHERE cr.course_id = ?
    ");
    $stmt_req->execute([$course_id]);
    $requirements = $stmt_req->fetchAll(PDO::FETCH_ASSOC);
    $required_course_ids = array_column($requirements, 'required_course_id');
    
    error_log("DEBUG: Voraussetzungen für Lehrgang $course_id: " . print_r($required_course_ids, true));
    
    // Alle Mitglieder laden, die diesen Lehrgang noch nicht haben
    $stmt = $db->prepare("
        SELECT 
            m.id,
            CONCAT(m.first_name, ' ', m.last_name) as name
        FROM members m
        WHERE m.id NOT IN (
            SELECT mc.member_id 
            FROM member_courses mc 
            WHERE mc.course_id = ?
        )
        ORDER BY m.last_name, m.first_name
    ");
    $stmt->execute([$course_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Anzahl Mitglieder ohne Lehrgang $course_id: " . count($members));
    
    // Für jedes Mitglied prüfen, ob es die Voraussetzungen erfüllt
    $result = [];
    foreach ($members as $member) {
        $requirements_met = true;
        $missing_courses = [];
        
        if (!empty($required_course_ids)) {
            // Prüfe welche Voraussetzungen das Mitglied erfüllt
            $placeholders = implode(',', array_fill(0, count($required_course_ids), '?'));
            $stmt_check = $db->prepare("
                SELECT course_id
                FROM member_courses
                WHERE member_id = ? AND course_id IN ($placeholders)
            ");
            $params = array_merge([$member['id']], $required_course_ids);
            $stmt_check->execute($params);
            $completed_courses = $stmt_check->fetchAll(PDO::FETCH_COLUMN);
            
            // Finde fehlende Lehrgänge
            foreach ($requirements as $req) {
                if (!in_array($req['required_course_id'], $completed_courses)) {
                    $requirements_met = false;
                    $missing_courses[] = [
                        'id' => $req['required_course_id'],
                        'name' => $req['name']
                    ];
                }
            }
        }
        
        // Nur hinzufügen, wenn Checkbox aktiv ist UND Voraussetzungen erfüllt sind, ODER wenn Checkbox nicht aktiv ist
        if (!$check_requirements || $requirements_met) {
            $result[] = [
                'id' => $member['id'],
                'name' => $member['name'],
                'requirements_met' => $requirements_met,
                'missing_courses' => $missing_courses
            ];
        }
    }
    
    error_log("DEBUG: Anzahl Mitglieder nach Filterung: " . count($result));
    
    echo json_encode(['success' => true, 'members' => $result]);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Mitglieder für Planung: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

