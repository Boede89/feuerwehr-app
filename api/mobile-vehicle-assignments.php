<?php
/**
 * Mobile API: Personal <-> Fahrzeug Zuordnungen.
 *
 * GET:
 * - Liefert aktuelle Zuordnungen gruppiert nach Fahrzeug.
 *
 * POST (JSON):
 * {
 *   "action": "assign",
 *   "vehicle_id": 12,
 *   "member_ids": [3, 7, 9]
 * }
 *
 * Regel:
 * - Eine Person kann nur einem Fahrzeug zugeordnet sein.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

function mobile_va_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

function mobile_va_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') {
        return trim($token);
    }
    return mobile_va_bearer_token();
}

function mobile_va_server_token(PDO $db): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mobile_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $value = trim((string)($row['setting_value'] ?? ''));
        if ($value !== '') return $value;
    } catch (Throwable $e) {
    }
    return trim((string)(getenv('MOBILE_API_TOKEN') ?: ''));
}

function mobile_va_einsatzapp_tokens(PDO $db): array {
    $tokens = [];
    try {
        $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE setting_key = 'einsatzapp_api_tokens'");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw = trim((string)($row['setting_value'] ?? ''));
            if ($raw === '') continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $token = trim((string)($entry['token'] ?? ''));
                if ($token !== '') $tokens[] = $token;
            }
        }
    } catch (Throwable $e) {
    }
    return array_values(array_unique($tokens));
}

function mobile_va_einheit_id_for_token(PDO $db, string $requestToken): int {
    if ($requestToken === '') return 0;
    try {
        $stmt = $db->prepare("SELECT einheit_id, setting_value FROM einheit_settings WHERE setting_key = 'einsatzapp_api_tokens'");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $einheitId = (int)($row['einheit_id'] ?? 0);
            if ($einheitId <= 0) continue;
            $raw = trim((string)($row['setting_value'] ?? ''));
            if ($raw === '') continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $token = trim((string)($entry['token'] ?? ''));
                if ($token !== '' && hash_equals($token, $requestToken)) {
                    return $einheitId;
                }
            }
        }
    } catch (Throwable $e) {
    }
    return 0;
}

function mobile_va_ensure_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_vehicle_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL DEFAULT 0,
            vehicle_id INT NOT NULL,
            member_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_einheit_member (einheit_id, member_id),
            UNIQUE KEY unique_einheit_vehicle_member (einheit_id, vehicle_id, member_id),
            KEY idx_einheit_vehicle (einheit_id, vehicle_id),
            KEY idx_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mobile_va_crew_strength(PDO $db, array $memberIds): string {
    $ids = array_values(array_unique(array_map('intval', array_filter($memberIds))));
    if (empty($ids)) return '0/0/0/0';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $db->prepare("
            SELECT LOWER(TRIM(COALESCE(q.name, ''))) AS qual_name
            FROM members m
            LEFT JOIN member_qualifications q ON q.id = m.qualification_id
            WHERE m.id IN ($placeholders)
        ");
        $stmt->execute($ids);
        $zf = 0;
        $gf = 0;
        $mannschaft = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)($row['qual_name'] ?? '');
            if (strpos($name, 'zugführer') !== false || $name === 'zf') {
                $zf++;
            } elseif (strpos($name, 'gruppenführer') !== false || $name === 'gf') {
                $gf++;
            } else {
                $mannschaft++;
            }
        }
        $sum = $zf + $gf + $mannschaft;
        return $zf . '/' . $gf . '/' . $mannschaft . '/' . $sum;
    } catch (Throwable $e) {
        return '0/0/0/0';
    }
}

function mobile_va_load_assignments(PDO $db, int $einheitId): array {
    $sql = "
        SELECT a.vehicle_id, a.member_id, m.first_name, m.last_name
        FROM mobile_vehicle_assignments a
        JOIN members m ON m.id = a.member_id
        WHERE a.einheit_id = ?
        ORDER BY a.vehicle_id ASC, m.last_name ASC, m.first_name ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$einheitId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byVehicle = [];
    $memberIdsByVehicle = [];
    foreach ($rows as $row) {
        $vehicleId = (int)($row['vehicle_id'] ?? 0);
        if ($vehicleId <= 0) continue;
        $memberId = (int)($row['member_id'] ?? 0);
        $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
        if ($name === '') $name = 'Mitglied #' . $memberId;
        if (!isset($byVehicle[(string)$vehicleId])) $byVehicle[(string)$vehicleId] = [];
        if (!isset($memberIdsByVehicle[(string)$vehicleId])) $memberIdsByVehicle[(string)$vehicleId] = [];
        $byVehicle[(string)$vehicleId][] = [
            'member_id' => $memberId,
            'member_name' => $name,
        ];
        if ($memberId > 0) $memberIdsByVehicle[(string)$vehicleId][] = $memberId;
    }
    $crewByVehicle = [];
    foreach ($memberIdsByVehicle as $vehicleId => $memberIds) {
        $crewByVehicle[(string)$vehicleId] = mobile_va_crew_strength($db, $memberIds);
    }
    return [
        'by_vehicle' => $byVehicle,
        'crew_strength_by_vehicle' => $crewByVehicle,
    ];
}

$requestToken = mobile_va_request_token();
$serverToken = mobile_va_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mobile_va_einsatzapp_tokens($db) as $token) {
        if (hash_equals($token, $requestToken)) {
            $valid = true;
            break;
        }
    }
}
if (!$valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert (ungueltiger Mobile-Token).']);
    exit;
}

$einheitId = mobile_va_einheit_id_for_token($db, $requestToken);
if ($einheitId <= 0) $einheitId = 0;

try {
    mobile_va_ensure_table($db);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Zuordnungstabelle konnte nicht vorbereitet werden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungueltiges JSON im Request.']);
        exit;
    }
    $action = trim((string)($payload['action'] ?? ''));
    if (!in_array($action, ['assign', 'remove_member', 'clear_vehicle'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungueltige Aktion.']);
        exit;
    }

    $vehicleId = (int)($payload['vehicle_id'] ?? 0);
    try {
        $db->beginTransaction();

        $stmtVehicle = $db->prepare("SELECT id FROM vehicles WHERE id = ? LIMIT 1");
        $stmtVehicle->execute([$vehicleId]);
        if (!$stmtVehicle->fetchColumn()) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Fahrzeug nicht gefunden.']);
            exit;
        }

        if ($action === 'assign') {
            $memberIdsRaw = $payload['member_ids'] ?? [];
            if (!is_array($memberIdsRaw) || empty($memberIdsRaw)) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'member_ids sind erforderlich.']);
                exit;
            }
            $memberIds = [];
            foreach ($memberIdsRaw as $mid) {
                $id = (int)$mid;
                if ($id > 0) $memberIds[] = $id;
            }
            $memberIds = array_values(array_unique($memberIds));
            if (empty($memberIds)) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Keine gueltigen member_ids uebergeben.']);
                exit;
            }

            $stmtMember = $db->prepare("SELECT id FROM members WHERE id = ? LIMIT 1");
            $stmtDeleteExisting = $db->prepare("DELETE FROM mobile_vehicle_assignments WHERE einheit_id = ? AND member_id = ?");
            $stmtInsert = $db->prepare("INSERT INTO mobile_vehicle_assignments (einheit_id, vehicle_id, member_id) VALUES (?, ?, ?)");

            foreach ($memberIds as $memberId) {
                $stmtMember->execute([$memberId]);
                if (!$stmtMember->fetchColumn()) {
                    continue;
                }
                // 1 Person = 1 Fahrzeug (innerhalb derselben Einheit)
                $stmtDeleteExisting->execute([$einheitId, $memberId]);
                $stmtInsert->execute([$einheitId, $vehicleId, $memberId]);
            }
        } elseif ($action === 'remove_member') {
            $memberId = (int)($payload['member_id'] ?? 0);
            if ($memberId <= 0) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'member_id ist erforderlich.']);
                exit;
            }
            $stmt = $db->prepare("DELETE FROM mobile_vehicle_assignments WHERE einheit_id = ? AND vehicle_id = ? AND member_id = ?");
            $stmt->execute([$einheitId, $vehicleId, $memberId]);
        } elseif ($action === 'clear_vehicle') {
            $stmt = $db->prepare("DELETE FROM mobile_vehicle_assignments WHERE einheit_id = ? AND vehicle_id = ?");
            $stmt->execute([$einheitId, $vehicleId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Zuordnung konnte nicht gespeichert werden.']);
        exit;
    }
}

try {
    $assignmentData = mobile_va_load_assignments($db, $einheitId);
    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'einheit_id' => $einheitId,
            'by_vehicle' => $assignmentData['by_vehicle'] ?? new stdClass(),
            'crew_strength_by_vehicle' => $assignmentData['crew_strength_by_vehicle'] ?? new stdClass(),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Zuordnungen konnten nicht geladen werden.']);
}

