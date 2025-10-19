<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Berechtigung prüfen
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    http_response_code(403);
    echo json_encode(['error' => 'Zugriff verweigert']);
    exit;
}

// POST-Daten empfangen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST-Requests erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige JSON-Daten']);
    exit;
}

$results = $input['results'] ?? [];
$params = $input['params'] ?? [];

// Suchergebnisse in Session speichern
$_SESSION['pa_traeger_search_results'] = $results;
$_SESSION['pa_traeger_search_params'] = $params;

echo json_encode(['success' => true]);
?>



