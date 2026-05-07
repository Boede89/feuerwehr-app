<?php

if (!function_exists('einsatz_ensure_table')) {
    function einsatz_ensure_table(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS einsatz_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                einheit_id INT NOT NULL,
                divera_alarm_id INT NOT NULL,
                keyword VARCHAR(255) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL DEFAULT '',
                address VARCHAR(512) NOT NULL DEFAULT '',
                text LONGTEXT NULL,
                alarm_ts BIGINT NULL,
                latitude DOUBLE NULL,
                longitude DOUBLE NULL,
                answered_by_status_json LONGTEXT NULL,
                alarm_json LONGTEXT NULL,
                alarm_detail_json LONGTEXT NULL,
                reach_json LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
            is_sample TINYINT(1) NOT NULL DEFAULT 0,
                last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_einheit_alarm (einheit_id, divera_alarm_id),
                KEY idx_einheit_active (einheit_id, is_active),
                KEY idx_einheit_synced (einheit_id, last_synced_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    try {
        $db->exec("ALTER TABLE einsatz_data ADD COLUMN is_sample TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    } catch (Throwable $e) {
    }
}

if (!function_exists('einsatz_divera_http_json')) {
    function einsatz_divera_http_json(string $url, int $timeout = 12): ?array {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('einsatz_pick_text')) {
    function einsatz_pick_text(array $primary, ?array $secondary, array $keys, string $fallback = ''): string {
        foreach ($keys as $k) {
            $v = trim((string)($primary[$k] ?? ''));
            if ($v !== '') return $v;
        }
        if (is_array($secondary)) {
            foreach ($keys as $k) {
                $v = trim((string)($secondary[$k] ?? ''));
                if ($v !== '') return $v;
            }
        }
        return $fallback;
    }
}

if (!function_exists('einsatz_pick_coord')) {
    function einsatz_pick_coord(array $alarm, ?array $detail, array $keys): ?float {
        $sources = [$detail, $alarm];
        foreach ($sources as $src) {
            if (!is_array($src)) continue;
            foreach ($keys as $k) {
                if (!array_key_exists($k, $src)) continue;
                $raw = $src[$k];
                if (is_numeric($raw)) return (float)$raw;
                $txt = str_replace(',', '.', trim((string)$raw));
                if ($txt !== '' && is_numeric($txt)) return (float)$txt;
            }
        }
        return null;
    }
}

if (!function_exists('einsatz_sync_from_divera')) {
    function einsatz_sync_from_divera(PDO $db, int $einheitId): array {
        if ($einheitId <= 0) {
            return ['success' => false, 'message' => 'Keine gueltige Einheit.', 'active' => null];
        }
        einsatz_ensure_table($db);

        if (!function_exists('load_divera_config_for_einheit') || !function_exists('fetch_divera_alarms')) {
            return ['success' => false, 'message' => 'Divera-Helferfunktionen fehlen.', 'active' => null];
        }

        $cfg = load_divera_config_for_einheit($db, $einheitId);
        $accessKey = trim((string)($cfg['access_key'] ?? ''));
        $apiBase = rtrim(trim((string)($cfg['api_base_url'] ?? 'https://app.divera247.com')), '/');
        if ($accessKey === '') {
            return ['success' => false, 'message' => 'Divera Access Key fehlt.', 'active' => null];
        }

        $err = null;
        $alarms = fetch_divera_alarms($accessKey, $apiBase, $err);
        if (!is_array($alarms)) $alarms = [];
        $activeAlarm = null;
        $sampleStmt = $db->prepare("SELECT * FROM einsatz_data WHERE einheit_id = ? AND is_active = 1 AND is_sample = 1 ORDER BY last_synced_at DESC LIMIT 1");
        $sampleStmt->execute([$einheitId]);
        $activeSample = $sampleStmt->fetch(PDO::FETCH_ASSOC);
        if ($activeSample) {
            return ['success' => true, 'message' => 'Aktiver Beispieleinsatz.', 'active' => $activeSample];
        }

        foreach ($alarms as $a) {
            if (!empty($a['closed'])) continue;
            $id = (int)($a['id'] ?? 0);
            if ($id > 0) {
                $activeAlarm = $a;
                break;
            }
        }

        if ($activeAlarm === null) {
            $deactivate = $db->prepare("UPDATE einsatz_data SET is_active = 0 WHERE einheit_id = ?");
            $deactivate->execute([$einheitId]);
            return ['success' => true, 'message' => 'Kein aktiver Einsatz.', 'active' => null];
        }

        $alarmId = (int)$activeAlarm['id'];
        $detail = einsatz_divera_http_json($apiBase . '/api/v2/alarms/' . $alarmId . '?accesskey=' . urlencode($accessKey), 12);
        $reach = einsatz_divera_http_json($apiBase . '/api/v2/alarms/reach/' . $alarmId . '?accesskey=' . urlencode($accessKey), 10);
        $detailData = is_array($detail['data'] ?? null) ? $detail['data'] : null;
        $reachData = is_array($reach['data'] ?? null) ? $reach['data'] : null;
        $answeredByStatus = is_array($detailData['ucr_answered'] ?? null) ? $detailData['ucr_answered'] : [];

        $keyword = einsatz_pick_text($activeAlarm, $detailData, ['keyword', 'stichwort', 'title'], 'Einsatz');
        $title = trim((string)($activeAlarm['title'] ?? $keyword));
        $address = einsatz_pick_text($activeAlarm, $detailData, ['address', 'adresse', 'location'], '');
        $text = einsatz_pick_text($activeAlarm, $detailData, ['text', 'note', 'message', 'description'], '');
        $alarmTs = (int)($activeAlarm['date'] ?? $activeAlarm['ts_create'] ?? 0);
        if ($alarmTs > 20_000_000_000) $alarmTs = (int)floor($alarmTs / 1000);
        if ($alarmTs <= 0) $alarmTs = null;
        $lat = einsatz_pick_coord($activeAlarm, $detailData, ['lat', 'latitude']);
        $lon = einsatz_pick_coord($activeAlarm, $detailData, ['lng', 'lon', 'longitude']);

        $upsert = $db->prepare("
            INSERT INTO einsatz_data (
                einheit_id, divera_alarm_id, keyword, title, address, text, alarm_ts,
                latitude, longitude, answered_by_status_json, alarm_json, alarm_detail_json, reach_json, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                keyword = VALUES(keyword),
                title = VALUES(title),
                address = VALUES(address),
                text = VALUES(text),
                alarm_ts = VALUES(alarm_ts),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                answered_by_status_json = VALUES(answered_by_status_json),
                alarm_json = VALUES(alarm_json),
                alarm_detail_json = VALUES(alarm_detail_json),
                reach_json = VALUES(reach_json),
                is_active = 1,
                last_synced_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([
            $einheitId,
            $alarmId,
            $keyword,
            $title,
            $address,
            $text,
            $alarmTs,
            $lat,
            $lon,
            json_encode($answeredByStatus, JSON_UNESCAPED_UNICODE),
            json_encode($activeAlarm, JSON_UNESCAPED_UNICODE),
            json_encode($detailData ?? new stdClass(), JSON_UNESCAPED_UNICODE),
            json_encode($reachData ?? new stdClass(), JSON_UNESCAPED_UNICODE)
        ]);

        $deactivateOthers = $db->prepare("
            UPDATE einsatz_data
            SET is_active = 0
            WHERE einheit_id = ? AND divera_alarm_id <> ?
        ");
        $deactivateOthers->execute([$einheitId, $alarmId]);

        $active = einsatz_get_active($db, $einheitId);
        return ['success' => true, 'message' => 'Einsatz synchronisiert.', 'active' => $active];
    }
}

if (!function_exists('einsatz_get_active')) {
    function einsatz_get_active(PDO $db, int $einheitId): ?array {
        $stmt = $db->prepare("
            SELECT *
            FROM einsatz_data
            WHERE einheit_id = ? AND is_active = 1
            ORDER BY last_synced_at DESC
            LIMIT 1
        ");
        $stmt->execute([$einheitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
