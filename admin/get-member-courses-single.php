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

$member_id = (int)($_GET['member_id'] ?? 0);

if ($member_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige Mitglieds-ID']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.name,
            c.description,
            mc.completed_date
        FROM member_courses mc
        INNER JOIN courses c ON c.id = mc.course_id
        WHERE mc.member_id = ?
        ORDER BY c.name
    ");
    $stmt->execute([$member_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Jahr extrahieren für Anzeige
    foreach ($courses as &$course) {
        if (!empty($course['completed_date'])) {
            $date_parts = explode('-', $course['completed_date']);
            $course['year'] = $date_parts[0] ?? '';
        } else {
            $course['year'] = '';
        }
    }
    unset($course);
    
    echo json_encode(['success' => true, 'courses' => $courses]);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgänge: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

