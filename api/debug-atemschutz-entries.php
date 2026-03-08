<?php
/**
 * Debug: Zeigt alle pending Atemschutzeinträge und deren einheit_id/unit_id.
 * Nur für Admins, zum Diagnostizieren warum Einträge nicht im Dashboard erscheinen.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 1;

try {
    try { $db->exec("ALTER TABLE atemschutz_entries ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE atemschutz_entries ADD COLUMN unit_id INT NULL"); } catch (Exception $e) {}
    
    $all = $db->query("SELECT id, entry_type, entry_date, status, einheit_id, unit_id, created_at FROM atemschutz_entries WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    $filtered = [];
    foreach ($all as $row) {
        $match = (int)($row['einheit_id'] ?? 0) === $einheit_id 
            || (int)($row['unit_id'] ?? 0) === $einheit_id 
            || ((empty($row['einheit_id']) && empty($row['unit_id'])) && $einheit_id === 1);
        $filtered[] = array_merge($row, ['matches_filter' => $match]);
    }
    
    echo json_encode([
        'success' => true,
        'effective_unit_id' => $einheit_id,
        'all_pending_count' => count($all),
        'all_pending' => $all,
        'filtered' => $filtered,
        'query_used' => 'COALESCE(einheit_id, unit_id, 1) = ' . $einheit_id,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
