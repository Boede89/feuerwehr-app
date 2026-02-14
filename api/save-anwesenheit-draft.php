<?php
/**
 * Speichert den Anwesenheitsliste-Entwurf aus der Session in die Datenbank.
 * Wird per beforeunload von den Anwesenheitsliste-Seiten aufgerufen.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dienstplan-typen.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

$draft_key = 'anwesenheit_draft';
if (empty($_SESSION[$draft_key]) || !is_array($_SESSION[$draft_key])) {
    echo json_encode(['success' => true, 'message' => 'Kein Entwurf vorhanden']);
    exit;
}

$draft = $_SESSION[$draft_key];

// Formulardaten aus POST in Draft übernehmen (z. B. beim Verlassen der Seite)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $form_type = $_POST['form_type'] ?? '';
    if ($form_type === 'personal') {
        $draft['members'] = [];
        $draft['member_vehicle'] = [];
        $draft['vehicle_maschinist'] = $draft['vehicle_maschinist'] ?? [];
        $draft['vehicle_einheitsfuehrer'] = $draft['vehicle_einheitsfuehrer'] ?? [];
        if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
            foreach ($_POST['member_id'] as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) {
                    $draft['members'][] = $mid;
                    $vid = isset($_POST['vehicle'][$mid]) ? (int)$_POST['vehicle'][$mid] : 0;
                    if ($vid > 0) $draft['member_vehicle'][$mid] = $vid;
                }
            }
        }
        foreach ($draft['member_vehicle'] as $mid => $vid) {
            if (!in_array($mid, $draft['members'])) continue;
            $role = isset($_POST['role'][$mid]) ? trim((string)$_POST['role'][$mid]) : '';
            if ($vid > 0 && $role === 'maschinist') $draft['vehicle_maschinist'][$vid] = $mid;
            if ($vid > 0 && $role === 'einheitsfuehrer') $draft['vehicle_einheitsfuehrer'][$vid] = $mid;
        }
    } elseif ($form_type === 'fahrzeuge') {
        $draft['vehicles'] = [];
        $draft['vehicle_maschinist'] = $draft['vehicle_maschinist'] ?? [];
        $draft['vehicle_einheitsfuehrer'] = $draft['vehicle_einheitsfuehrer'] ?? [];
        if (!empty($_POST['vehicle_id']) && is_array($_POST['vehicle_id'])) {
            foreach ($_POST['vehicle_id'] as $vid) {
                $vid = (int)$vid;
                if ($vid > 0) {
                    $draft['vehicles'][] = $vid;
                    $masch = isset($_POST['maschinist'][$vid]) ? (int)$_POST['maschinist'][$vid] : 0;
                    $einh = isset($_POST['einheitsfuehrer'][$vid]) ? (int)$_POST['einheitsfuehrer'][$vid] : 0;
                    if ($masch > 0) {
                        $draft['vehicle_maschinist'][$vid] = $masch;
                        $draft['member_vehicle'][$masch] = $vid;
                        if (!in_array($masch, $draft['members'])) $draft['members'][] = $masch;
                    }
                    if ($einh > 0) {
                        $draft['vehicle_einheitsfuehrer'][$vid] = $einh;
                        $draft['member_vehicle'][$einh] = $vid;
                        if (!in_array($einh, $draft['members'])) $draft['members'][] = $einh;
                    }
                }
            }
        }
    } else {
    $builtin = ['uhrzeit_von', 'uhrzeit_bis', 'alarmierung_durch', 'einsatzstelle', 'objekt', 'eigentuemer', 'geschaedigter', 'klassifizierung', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache', 'bemerkung', 'einsatzstichwort', 'thema'];
    foreach ($builtin as $k) {
        if (isset($_POST[$k])) $draft[$k] = trim((string)$_POST[$k]);
    }
    if (isset($_POST['thema']) && trim((string)$_POST['thema']) === '__neu__' && isset($_POST['thema_neu'])) {
        $draft['thema'] = trim((string)$_POST['thema_neu']);
    }
    if (isset($_POST['einsatzleiter'])) {
        $ev = trim((string)$_POST['einsatzleiter']);
        if ($ev === '__freitext__') {
            $draft['einsatzleiter_member_id'] = null;
            $draft['einsatzleiter_freitext'] = trim((string)($_POST['einsatzleiter_freitext'] ?? ''));
        } elseif ($ev !== '' && ctype_digit($ev)) {
            $draft['einsatzleiter_member_id'] = (int)$ev;
            $draft['einsatzleiter_freitext'] = '';
        }
    }
    if (isset($_POST['typ_sonstige']) && ($draft['typ'] ?? '') === 'einsatz') {
        $ts = trim((string)$_POST['typ_sonstige']);
        $tsf = trim((string)($_POST['typ_sonstige_freitext'] ?? ''));
        if ($ts === '__custom__') {
            $draft['bezeichnung_sonstige'] = $tsf !== '' ? $tsf : 'Sonstiges';
        } else {
            $typen = get_dienstplan_typen_auswahl();
            $draft['bezeichnung_sonstige'] = $typen[$ts] ?? 'Einsatz';
        }
    }
    // Custom-Felder
    if (!isset($draft['custom_data'])) $draft['custom_data'] = [];
    foreach ($_POST as $k => $v) {
        if (!in_array($k, array_merge($builtin, ['einsatzleiter', 'einsatzleiter_freitext', 'typ_sonstige', 'typ_sonstige_freitext', 'thema_neu', 'save_final', 'form_type']), true) && !preg_match('/^(member_id|vehicle|role|vehicle_id|maschinist|einheitsfuehrer)\b/', $k)) {
            $draft['custom_data'][$k] = trim((string)$v);
        }
    }
    }
}
$user_id = (int)$_SESSION['user_id'];

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS anwesenheitsliste_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            datum DATE NOT NULL,
            auswahl VARCHAR(50) NOT NULL,
            dienstplan_id INT NULL,
            typ VARCHAR(50) NOT NULL DEFAULT 'dienst',
            bezeichnung VARCHAR(255) NULL,
            draft_data JSON NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_draft (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('anwesenheitsliste_drafts Tabelle: ' . $e->getMessage());
}

// Leeren Entwurf nicht speichern (keine Daten eingegeben)
$has_members = !empty($draft['members']);
$has_vehicles = !empty($draft['vehicles']);
// uhrzeit_von/uhrzeit_bis ausgenommen – uhrzeit_bis hat Standardwert (aktuelle Uhrzeit)
$text_fields = ['alarmierung_durch', 'einsatzstelle', 'objekt', 'eigentuemer', 'geschaedigter', 'klassifizierung', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache', 'bemerkung', 'einsatzleiter_freitext'];
$has_text = false;
foreach ($text_fields as $f) {
    if (!empty(trim((string)($draft[$f] ?? '')))) { $has_text = true; break; }
}
// bezeichnung_sonstige nur zählen wenn nicht Standardwert (z.B. "Einsatz" bei Einsatz-Typ)
$bez = trim((string)($draft['bezeichnung_sonstige'] ?? ''));
if ($bez !== '' && !in_array($bez, array_values(get_dienstplan_typen_auswahl()), true)) {
    $has_text = true;
}
$has_einsatzleiter = !empty($draft['einsatzleiter_member_id']);
$has_custom = false;
if (!empty($draft['custom_data']) && is_array($draft['custom_data'])) {
    foreach ($draft['custom_data'] as $v) {
        if (!empty(trim((string)$v))) { $has_custom = true; break; }
    }
}
$draft_has_content = $has_members || $has_vehicles || $has_text || $has_einsatzleiter || $has_custom;

if (!$draft_has_content) {
    try {
        $stmt = $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE user_id = ?");
        $stmt->execute([$user_id]);
    } catch (Exception $e) { /* ignore */ }
    echo json_encode(['success' => true, 'message' => 'Leerer Entwurf nicht gespeichert']);
    exit;
}

$draft_data = json_encode($draft);
$datum = $draft['datum'] ?? date('Y-m-d');
$auswahl = $draft['auswahl'] ?? '';
$dienstplan_id = isset($draft['dienstplan_id']) ? ($draft['dienstplan_id'] ?: null) : null;
$typ = $draft['typ'] ?? 'dienst';
$bezeichnung = $draft['bezeichnung_sonstige'] ?? null;

try {
    $stmt = $db->prepare("
        INSERT INTO anwesenheitsliste_drafts (user_id, datum, auswahl, dienstplan_id, typ, bezeichnung, draft_data)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            datum = VALUES(datum),
            auswahl = VALUES(auswahl),
            dienstplan_id = VALUES(dienstplan_id),
            typ = VALUES(typ),
            bezeichnung = VALUES(bezeichnung),
            draft_data = VALUES(draft_data),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$user_id, $datum, $auswahl, $dienstplan_id, $typ, $bezeichnung, $draft_data]);
    echo json_encode(['success' => true, 'message' => 'Entwurf gespeichert']);
} catch (Exception $e) {
    error_log('save-anwesenheit-draft: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
