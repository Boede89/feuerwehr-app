<?php
/**
 * Mitglieder-Suche für Autocomplete (z.B. Mängelbericht "Aufgenommen durch").
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? $_GET['term'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$limit = min(20, (int)($_GET['limit'] ?? 15));
$q_esc = '%' . $q . '%';
$einheit_filter = get_admin_einheit_filter();
$einheit_where = $einheit_filter ? " AND (einheit_id = " . (int)$einheit_filter . " OR einheit_id IS NULL)" : "";

try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name
        FROM members
        WHERE (first_name LIKE ? OR last_name LIKE ? OR CONCAT(last_name, ', ', first_name) LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?) $einheit_where
        ORDER BY last_name, first_name
        LIMIT ?
    ");
    $stmt->execute([$q_esc, $q_esc, $q_esc, $q_esc, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'label' => trim($r['last_name'] . ', ' . $r['first_name']),
            'value' => trim($r['last_name'] . ', ' . $r['first_name']),
        ];
    }, $rows);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode([]);
}
