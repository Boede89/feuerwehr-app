<?php
/**
 * Gemeinsame Hilfsfunktionen für Anwesenheitslisten (Feld-Konfiguration etc.)
 */

/**
 * Gibt den Anzeige-Typ für die Übersicht zurück: Übungsdienst, Einsatz oder Sonstiges.
 * Entspricht der Auswahl beim Ausfüllen des Formulars (nicht dem technischen typ/dienst_typ).
 *
 * @param array $row Anwesenheitslisten-Zeile mit typ, bezeichnung, custom_data, dienst_typ
 * @return string "Übungsdienst", "Einsatz" oder "Sonstiges"
 */
function get_anwesenheitsliste_typ_label($row) {
    $typ = $row['typ'] ?? '';
    $dienst_typ = $row['dienst_typ'] ?? '';
    $bezeichnung = trim($row['bezeichnung'] ?? '');
    $custom_data = !empty($row['custom_data']) ? (is_string($row['custom_data']) ? json_decode($row['custom_data'], true) : $row['custom_data']) : [];
    if (!is_array($custom_data)) $custom_data = [];
    $typ_sonstige = trim($custom_data['typ_sonstige'] ?? '');
    if ($typ === 'einsatz') return 'Einsatz';
    if ($typ === 'dienst') {
        if ($dienst_typ === 'einsatz') return 'Einsatz';
        if ($dienst_typ === 'sonstiges') return 'Sonstiges';
        return 'Übungsdienst';
    }
    if ($typ === 'manuell') {
        if ($typ_sonstige === 'sonstiges' || $bezeichnung === 'Sonstiges') return 'Sonstiges';
        return 'Übungsdienst';
    }
    return 'Übungsdienst';
}

/**
 * Lädt Mitglieder sortiert für Einsatzleiter/Übungsleiter-Auswahl.
 * Reihenfolge: 1) Personal-Auswahl zuerst, 2) nach Qualifikation (Zugführer > Gruppenführer > Truppführer > Mannschaft),
 * 3) nach Name. Verwendet sort_order aus member_qualifications (kleiner = höhere Stufe).
 *
 * @param PDO $db Datenbankverbindung
 * @param int[] $members_selected_ids IDs der im Personal ausgewählten Mitglieder (leeres Array = alle gleich)
 * @return array Mitglieder mit id, first_name, last_name
 */
function anwesenheitsliste_members_for_leiter($db, $members_selected_ids = []) {
    $selected_map = array_flip(array_map('intval', $members_selected_ids));
    try {
        $stmt = $db->query("
            SELECT m.id, m.first_name, m.last_name, COALESCE(q.sort_order, 999) AS qual_sort_order
            FROM members m
            LEFT JOIN member_qualifications q ON q.id = m.qualification_id
            ORDER BY m.last_name, m.first_name
        ");
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        try {
            $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
            $all = array_map(function ($r) { $r['qual_sort_order'] = 999; return $r; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e2) {
            return [];
        }
    }
    $in_personal = [];
    $rest = [];
    foreach ($all as $m) {
        $m['id'] = (int)$m['id'];
        $m['qual_sort_order'] = (int)($m['qual_sort_order'] ?? 999);
        if (isset($selected_map[$m['id']])) {
            $in_personal[] = $m;
        } else {
            $rest[] = $m;
        }
    }
    usort($in_personal, function ($a, $b) {
        $c = $a['qual_sort_order'] - $b['qual_sort_order'];
        if ($c !== 0) return $c;
        $c = strcasecmp($a['last_name'], $b['last_name']);
        return $c !== 0 ? $c : strcasecmp($a['first_name'], $b['first_name']);
    });
    usort($rest, function ($a, $b) {
        $c = $a['qual_sort_order'] - $b['qual_sort_order'];
        if ($c !== 0) return $c;
        $c = strcasecmp($a['last_name'], $b['last_name']);
        return $c !== 0 ? $c : strcasecmp($a['first_name'], $b['first_name']);
    });
    $result = array_merge($in_personal, $rest);
    return array_map(function ($m) {
        return ['id' => $m['id'], 'first_name' => $m['first_name'], 'last_name' => $m['last_name']];
    }, $result);
}

function anwesenheitsliste_felder_laden($settings = null) {
    if ($settings === null) {
        global $db;
        $settings = [];
        try {
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'anwesenheitsliste_%'");
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            $settings = [];
        }
    }
    $raw = $settings['anwesenheitsliste_felder'] ?? '';
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr) && !empty($arr)) {
            usort($arr, function ($a, $b) { return ($a['position'] ?? 999) - ($b['position'] ?? 999); });
            return $arr;
        }
    }
    $std = [
        ['id' => 'einsatzstichwort', 'label' => 'Einsatzstichwort', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 1],
        ['id' => 'einsatzstelle', 'label' => 'Adresse / Einsatzstelle', 'type' => 'einsatzstelle', 'options' => [], 'visible' => true, 'position' => 2],
        ['id' => 'uhrzeit_von', 'label' => 'Uhrzeit von', 'type' => 'time', 'options' => [], 'visible' => true, 'position' => 3],
        ['id' => 'uhrzeit_bis', 'label' => 'Uhrzeit bis', 'type' => 'time', 'options' => [], 'visible' => true, 'position' => 4],
        ['id' => 'einsatzleiter', 'label' => 'Einsatzleiter', 'type' => 'einsatzleiter', 'options' => [], 'visible' => true, 'position' => 5],
        ['id' => 'alarmierung_durch', 'label' => 'Alarmierung durch', 'type' => 'select', 'options' => ['Telefon', 'DME Löschzug', 'DME Kleinhilfe', 'Sirene'], 'visible' => true, 'position' => 6],
        ['id' => 'objekt', 'label' => 'Objekt', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 7],
        ['id' => 'eigentuemer', 'label' => 'Eigentümer', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 8],
        ['id' => 'geschaedigter', 'label' => 'Geschädigter', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 9],
        ['id' => 'klassifizierung', 'label' => 'Klassifizierung / Stichwörter', 'type' => 'select', 'options' => ['Grossbrand', 'Mittelbrand', 'Kleinbrand', 'Gelöschtes Feuer', 'Gefahrenmeldeanlage', 'Menschen in Notlage', 'Tiere in Notlage', 'Verkehrsunfall', 'Techn. Hilfeleistung', 'Wasserrettung', 'CBRN-Einsatz', 'Unterstützung RD', 'Sonstiger Einsatz', 'Fehlalarm', 'Böswill. Alarm'], 'visible' => true, 'position' => 10],
        ['id' => 'kostenpflichtiger_einsatz', 'label' => 'Kostenpflichtiger Einsatz', 'type' => 'radio', 'options' => ['Ja', 'Nein'], 'visible' => true, 'position' => 11],
        ['id' => 'personenschaeden', 'label' => 'Personenschäden', 'type' => 'select', 'options' => ['Ja', 'Nein', 'Person gerettet', 'Person verstorben'], 'visible' => true, 'position' => 12],
        ['id' => 'brandwache', 'label' => 'Brandwache', 'type' => 'radio', 'options' => ['Ja', 'Nein'], 'visible' => true, 'position' => 13],
        ['id' => 'bemerkung', 'label' => 'Einsatzkurzbericht', 'type' => 'textarea', 'options' => [], 'visible' => true, 'position' => 14],
    ];
    $opts_map = ['alarmierung_durch' => 'alarmierung_optionen', 'klassifizierung' => 'klassifizierung_optionen', 'personenschaeden' => 'personenschaeden_optionen', 'kostenpflichtiger_einsatz' => 'kostenpflichtiger_optionen', 'brandwache' => 'brandwache_optionen'];
    $labels = json_decode($settings['anwesenheitsliste_feld_labels'] ?? '{}', true) ?: [];
    $sichtbar = json_decode($settings['anwesenheitsliste_felder_sichtbar'] ?? '{}', true) ?: [];
    foreach ($std as &$f) {
        if (isset($labels[$f['id']])) $f['label'] = $labels[$f['id']];
        if (isset($sichtbar[$f['id']])) $f['visible'] = $sichtbar[$f['id']] === '1';
        $ok = $opts_map[$f['id']] ?? null;
        if ($ok && !empty($settings['anwesenheitsliste_' . $ok])) {
            $o = json_decode($settings['anwesenheitsliste_' . $ok], true);
            if (is_array($o)) $f['options'] = $o;
        }
    }
    return $std;
}

/**
 * Speichert den Anwesenheitsliste-Entwurf in die Datenbank (anwesenheitsliste_drafts).
 * Wird z. B. nach dem Absenden der Geräte-Seite aufgerufen, damit Sonstiges-Geräte
 * sofort in der Fahrzeug-Geräteverwaltung sichtbar sind.
 *
 * @param PDO $db Datenbankverbindung
 * @param array $draft Der Draft aus $_SESSION['anwesenheit_draft']
 * @param int $user_id Benutzer-ID
 * @return bool true bei Erfolg
 */
function anwesenheitsliste_draft_persist($db, $draft, $user_id) {
    if (!is_array($draft) || empty($draft)) return false;
    if (!function_exists('get_dienstplan_typen_auswahl')) {
        require_once __DIR__ . '/dienstplan-typen.php';
    }
    $has_members = !empty($draft['members']);
    $has_vehicles = !empty($draft['vehicles']);
    $text_fields = ['alarmierung_durch', 'einsatzstelle', 'objekt', 'eigentuemer', 'geschaedigter', 'klassifizierung', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache', 'bemerkung', 'einsatzleiter_freitext'];
    $has_text = false;
    foreach ($text_fields as $f) {
        if (!empty(trim((string)($draft[$f] ?? '')))) { $has_text = true; break; }
    }
    $bez = trim((string)($draft['bezeichnung_sonstige'] ?? ''));
    if ($bez !== '' && !in_array($bez, array_values(get_dienstplan_typen_auswahl()), true)) $has_text = true;
    $has_einsatzleiter = !empty($draft['einsatzleiter_member_id']) || !empty($draft['uebungsleiter_member_ids']);
    $has_custom = false;
    if (!empty($draft['custom_data']) && is_array($draft['custom_data'])) {
        foreach ($draft['custom_data'] as $v) {
            if (!empty(trim((string)$v))) { $has_custom = true; break; }
        }
    }
    $has_vehicle_equipment = (!empty($draft['vehicle_equipment']) && is_array($draft['vehicle_equipment'])) || (!empty($draft['vehicle_equipment_sonstiges']) && is_array($draft['vehicle_equipment_sonstiges']));
    if (!$has_members && !$has_vehicles && !$has_text && !$has_einsatzleiter && !$has_custom && !$has_vehicle_equipment) {
        try {
            $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ?")->execute([$draft['datum'] ?? '', $draft['auswahl'] ?? '']);
        } catch (Exception $e) {}
        return true;
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS anwesenheitsliste_drafts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                datum DATE NOT NULL,
                auswahl VARCHAR(50) NOT NULL,
                dienstplan_id INT NULL,
                typ VARCHAR(50) NOT NULL DEFAULT 'dienst',
                bezeichnung VARCHAR(255) NULL,
                draft_data JSON NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_datum_auswahl (datum, auswahl)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) { /* ignore */ }
    try {
        $draft_data = json_encode($draft);
        $datum = $draft['datum'] ?? date('Y-m-d');
        $auswahl = $draft['auswahl'] ?? '';
        $dienstplan_id = isset($draft['dienstplan_id']) ? ($draft['dienstplan_id'] ?: null) : null;
        $typ = $draft['typ'] ?? 'dienst';
        $bezeichnung = $draft['bezeichnung_sonstige'] ?? null;
        $stmt = $db->prepare("
            INSERT INTO anwesenheitsliste_drafts (user_id, datum, auswahl, dienstplan_id, typ, bezeichnung, draft_data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), dienstplan_id = VALUES(dienstplan_id), typ = VALUES(typ), bezeichnung = VALUES(bezeichnung), draft_data = VALUES(draft_data), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([(int)$user_id, $datum, $auswahl, $dienstplan_id, $typ, $bezeichnung, $draft_data]);
        return true;
    } catch (Exception $e) {
        error_log('anwesenheitsliste_draft_persist: ' . $e->getMessage());
        return false;
    }
}
