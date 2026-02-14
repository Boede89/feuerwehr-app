<?php
/**
 * Löscht den Anwesenheitsliste-Entwurf des aktuellen Benutzers.
 */
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$draft_key = 'anwesenheit_draft';
unset($_SESSION[$draft_key]);

try {
    $stmt = $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    echo json_encode(['success' => true, 'message' => 'Entwurf gelöscht']);
} catch (Exception $e) {
    error_log('delete-anwesenheit-draft: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
