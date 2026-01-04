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

try {
    $stmt = $db->query("SELECT id, name, description FROM courses ORDER BY name");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'courses' => $courses]);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgänge: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

