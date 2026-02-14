<?php
/**
 * Löscht einen Anwesenheitsliste-Entwurf (per datum+auswahl).
 * Entwürfe sind für alle Benutzer sichtbar.
 */
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

$datum = trim($_REQUEST['datum'] ?? '');
$auswahl = trim($_REQUEST['auswahl'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $auswahl === '') {
    echo json_encode(['success' => false, 'message' => 'datum und auswahl erforderlich']);
    exit;
}

$draft_key = 'anwesenheit_draft';
if (isset($_SESSION[$draft_key]) && $_SESSION[$draft_key]['datum'] === $datum && $_SESSION[$draft_key]['auswahl'] === $auswahl) {
    unset($_SESSION[$draft_key]);
}

try {
    $stmt = $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ?");
    $stmt->execute([$datum, $auswahl]);
    echo json_encode(['success' => true, 'message' => 'Entwurf gelöscht']);
} catch (Exception $e) {
    error_log('delete-anwesenheit-draft: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
