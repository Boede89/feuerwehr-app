<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !has_permission('courses')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert']);
    exit;
}

header('Content-Type: application/json');

try {
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    
    $einheit_filter = get_admin_einheit_filter();
    $einheit_where = $einheit_filter ? " AND (m.einheit_id = " . (int)$einheit_filter . " OR m.einheit_id IS NULL)" : "";
    
    if ($course_id > 0) {
        // Nur Mitglieder laden, die diesen Lehrgang noch nicht haben
        $stmt = $db->prepare("
            SELECT 
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) as name,
                m.first_name,
                m.last_name,
                m.email
            FROM members m
            WHERE m.id NOT IN (
                SELECT mc.member_id 
                FROM member_courses mc 
                WHERE mc.course_id = ?
            ) $einheit_where
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute([$course_id]);
    } else {
        // Alle Mitglieder laden (für andere Zwecke)
        $stmt = $db->prepare("
            SELECT 
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) as name,
                m.first_name,
                m.last_name,
                m.email
            FROM members m
            WHERE 1=1 $einheit_where
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute();
    }
    
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'members' => $members]);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Mitglieder: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

