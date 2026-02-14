<?php
/**
 * Gemeinsame Hilfsfunktionen für Anwesenheitslisten (Feld-Konfiguration etc.)
 */

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
