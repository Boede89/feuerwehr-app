<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !hasAdminPermission()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert.']);
    exit;
}
if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

$einheitId = function_exists('get_current_einheit_id') ? (int)get_current_einheit_id() : 0;
if ($einheitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Keine aktive Einheit.']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST ?: [];

$lat = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
$lon = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
$label = trim((string)($payload['label'] ?? 'Einsatzstelle'));

if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Ungueltige Koordinaten.']);
    exit;
}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_incident_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            latitude DOUBLE NOT NULL,
            longitude DOUBLE NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unit_incident (einheit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmt = $db->prepare("
        INSERT INTO mobile_incident_locations (einheit_id, label, latitude, longitude)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$einheitId, $label, $lat, $lon]);
    echo json_encode(['success' => true, 'message' => 'Einsatzstelle aktualisiert.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Einsatzstelle konnte nicht gespeichert werden.']);
}
