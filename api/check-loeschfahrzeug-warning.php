<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
    exit;
}

$vehicle_ids = array_values(array_filter(array_map('intval', (array)($input['vehicle_ids'] ?? [])), function($v) {
    return $v > 0;
}));
$timeframes = (array)($input['timeframes'] ?? []);
$einheit_id = (int)($input['einheit_id'] ?? 0);

if (empty($vehicle_ids) || empty($timeframes)) {
    echo json_encode(['success' => true, 'has_warning' => false, 'warnings' => []]);
    exit;
}

$warnings = [];
foreach ($timeframes as $idx => $tf) {
    $start = trim((string)($tf['start'] ?? ''));
    $end = trim((string)($tf['end'] ?? ''));
    if ($start === '' || $end === '') {
        continue;
    }
    $w = check_loeschfahrzeug_availability_warning($vehicle_ids, $start, $end, $einheit_id > 0 ? $einheit_id : null);
    if (!empty($w['warning'])) {
        $w['index'] = (int)$idx + 1;
        $w['start'] = $start;
        $w['end'] = $end;
        $warnings[] = $w;
    }
}

echo json_encode([
    'success' => true,
    'has_warning' => !empty($warnings),
    'warnings' => $warnings
], JSON_UNESCAPED_UNICODE);
exit;
