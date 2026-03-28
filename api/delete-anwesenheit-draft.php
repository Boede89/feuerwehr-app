<?php
/**
 * Löscht einen Anwesenheitsliste-Entwurf (per datum+auswahl).
 * Entwürfe sind für alle Benutzer sichtbar.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/bericht-anhaenge-helper.php';

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

$einheit_id = isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0;
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
    } catch (Exception $e) {}
}
if ($einheit_id <= 0) {
    $einheit_id = 1;
}

$draft_key = 'anwesenheit_draft';
if (isset($_SESSION[$draft_key]) && $_SESSION[$draft_key]['datum'] === $datum && $_SESSION[$draft_key]['auswahl'] === $auswahl) {
    if (is_array($_SESSION[$draft_key])) {
        bericht_anhaenge_draft_cleanup_files($_SESSION[$draft_key]);
    }
    unset($_SESSION[$draft_key]);
}

try {
    $stmtLoad = $db->prepare("SELECT draft_data FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ? AND einheit_id = ?");
    $stmtLoad->execute([$datum, $auswahl, $einheit_id]);
    $row = $stmtLoad->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['draft_data'])) {
        $draftData = json_decode($row['draft_data'], true);
        if (is_array($draftData)) {
            bericht_anhaenge_draft_cleanup_files($draftData);
        }
    }
    $stmt = $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ? AND einheit_id = ?");
    $stmt->execute([$datum, $auswahl, $einheit_id]);
    echo json_encode(['success' => true, 'message' => 'Entwurf gelöscht']);
} catch (Exception $e) {
    error_log('delete-anwesenheit-draft: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
