<?php
/**
 * Uebernimmt Fahrzeug-Personal-Zuordnungen aus der Einsatzapp (mobile_vehicle_assignments)
 * in den Anwesenheitslisten-Entwurf, wenn dieselbe Divera-Einsatz-ID verwendet wird.
 *
 * Wird von anwesenheitsliste-eingaben-main.inc.php nach dem Divera-Vorschlag aufgerufen.
 */

if (!function_exists('mobile_anwesenheit_ensure_assignment_alarm_column')) {
    function mobile_anwesenheit_ensure_assignment_alarm_column(PDO $db): void {
        try {
            $db->exec("ALTER TABLE mobile_vehicle_assignments ADD COLUMN divera_alarm_id INT NOT NULL DEFAULT 0 AFTER member_id");
        } catch (Throwable $e) {
        }
        try {
            $db->exec("ALTER TABLE mobile_vehicle_assignments ADD KEY idx_einheit_alarm (einheit_id, divera_alarm_id)");
        } catch (Throwable $e) {
        }
    }
}

/**
 * @param array<string,mixed> $draft Referenz auf Session-Entwurf (member_vehicle, members)
 */
if (!function_exists('anwesenheitsliste_merge_mobile_vehicle_assignments')) {
    function anwesenheitsliste_merge_mobile_vehicle_assignments(PDO $db, int $einheitId, int $diveraAlarmId, array &$draft): void {
        if ($einheitId <= 0 || $diveraAlarmId <= 0) {
            return;
        }
        mobile_anwesenheit_ensure_assignment_alarm_column($db);
        try {
            $stmt = $db->prepare("
                SELECT member_id, vehicle_id
                FROM mobile_vehicle_assignments
                WHERE einheit_id = ? AND divera_alarm_id = ?
            ");
            $stmt->execute([$einheitId, $diveraAlarmId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return;
        }
        if ($rows === []) {
            return;
        }
        if (!is_array($draft['members'])) {
            $draft['members'] = [];
        }
        if (!is_array($draft['member_vehicle'])) {
            $draft['member_vehicle'] = [];
        }
        foreach ($rows as $row) {
            $mid = (int)($row['member_id'] ?? 0);
            $vid = (int)($row['vehicle_id'] ?? 0);
            if ($mid <= 0 || $vid <= 0) {
                continue;
            }
            $draft['member_vehicle'][$mid] = $vid;
            if (!in_array($mid, $draft['members'], true)) {
                $draft['members'][] = $mid;
            }
        }
        $draft['members'] = array_values(array_unique(array_map('intval', $draft['members'])));
    }
}
