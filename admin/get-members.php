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
    // Alle Mitglieder laden
    $stmt = $db->prepare("
        SELECT 
            m.id,
            CONCAT(m.first_name, ' ', m.last_name) as name
        FROM members m
        ORDER BY m.last_name, m.first_name
    ");
    $stmt->execute();
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'members' => $members]);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Mitglieder: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

