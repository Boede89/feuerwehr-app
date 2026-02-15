<?php
/**
 * Anwesenheitsliste als PDF herunterladen.
 * Generiert einen druckbaren Bericht mit Unterschriftsfeld Einsatzleiter.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dienstplan-typen.php';
require_once __DIR__ . '/../includes/anwesenheitsliste-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}
if (!has_permission('forms')) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$for_print = !empty($_GET['print']);
$return_mode = !empty($_GET['_return']);
if ($id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Ungültige ID';
    exit;
}

$liste = null;
try {
    $stmt = $db->prepare("
        SELECT a.*, d.bezeichnung AS dienst_bezeichnung, d.typ AS dienst_typ,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM anwesenheitslisten a
        LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $liste = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $liste = null;
}
if (!$liste) {
    header('HTTP/1.1 404 Not Found');
    echo 'Anwesenheitsliste nicht gefunden';
    exit;
}

$liste_members = [];
$liste_vehicles = [];
try {
    $stmt = $db->prepare("
        SELECT am.member_id, am.vehicle_id, m.first_name, m.last_name, v.name AS vehicle_name
        FROM anwesenheitsliste_mitglieder am
        LEFT JOIN members m ON m.id = am.member_id
        LEFT JOIN vehicles v ON v.id = am.vehicle_id
        WHERE am.anwesenheitsliste_id = ?
        ORDER BY v.name, m.last_name, m.first_name
    ");
    $stmt->execute([$id]);
    $liste_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $stmt = $db->prepare("
        SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id,
               v.name AS vehicle_name, m1.first_name AS masch_first, m1.last_name AS masch_last,
               m2.first_name AS einh_first, m2.last_name AS einh_last
        FROM anwesenheitsliste_fahrzeuge af
        LEFT JOIN vehicles v ON v.id = af.vehicle_id
        LEFT JOIN members m1 ON m1.id = af.maschinist_member_id
        LEFT JOIN members m2 ON m2.id = af.einheitsfuehrer_member_id
        WHERE af.anwesenheitsliste_id = ?
    ");
    $stmt->execute([$id]);
    $liste_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$anwesenheitsliste_felder = anwesenheitsliste_felder_laden();
$custom_data = [];
if (!empty($liste['custom_data'])) {
    $dec = json_decode($liste['custom_data'], true);
    $custom_data = is_array($dec) ? $dec : [];
}

function _al_val($liste, $key, $custom_data = []) {
    $builtin = ['uhrzeit_von','uhrzeit_bis','alarmierung_durch','einsatzstelle','einsatzstichwort','einsatzbericht_nummer','objekt','eigentuemer','geschaedigter','klassifizierung','kostenpflichtiger_einsatz','personenschaeden','brandwache','bemerkung'];
    if (in_array($key, $builtin)) return $liste[$key] ?? '';
    return $custom_data[$key] ?? '';
}
function _al_val_formatted($liste, $key, $custom_data = [], $type = '') {
    $v = _al_val($liste, $key, $custom_data);
    if ($v === '' || $v === null) return '-';
    if (($type === 'time' || in_array($key, ['uhrzeit_von','uhrzeit_bis'])) && strlen($v) >= 5) {
        return substr($v, 0, 5);
    }
    return $v;
}
function _calc_einsatzdauer($von, $bis) {
    if (empty($von) || empty($bis)) return '';
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($von), $mv) || !preg_match('/^(\d{1,2}):(\d{2})/', trim($bis), $mb)) return '';
    $min_von = (int)$mv[1] * 60 + (int)$mv[2];
    $min_bis = (int)$mb[1] * 60 + (int)$mb[2];
    if ($min_bis < $min_von) $min_bis += 24 * 60;
    $diff = $min_bis - $min_von;
    if ($diff < 0) return '';
    $h = floor($diff / 60);
    $m = $diff % 60;
    if ($h > 0 && $m > 0) return $h . ' Std ' . $m . ' Min';
    if ($h > 0) return $h . ' Std';
    return $m . ' Min';
}

$custom_data_pdf = !empty($liste['custom_data']) ? (json_decode($liste['custom_data'], true) ?: []) : [];
$uebungsleiter_ids = $custom_data_pdf['uebungsleiter_member_ids'] ?? [];
$is_uebungsdienst_pdf = !empty($uebungsleiter_ids) || (($liste['typ'] ?? '') === 'dienst' && in_array($liste['dienst_typ'] ?? '', ['uebungsdienst', 'jahreshauptversammlung'])) || (($liste['typ'] ?? '') === 'manuell' && in_array($liste['bezeichnung'] ?? '', ['Übungsdienst', 'Jahreshauptversammlung']));
$einsatzleiter_name = '';
if ($is_uebungsdienst_pdf && !empty($uebungsleiter_ids) && is_array($uebungsleiter_ids)) {
    $names = [];
    foreach (array_map('intval', $uebungsleiter_ids) as $mid) {
        if ($mid <= 0) continue;
        try {
            $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
            $stmt->execute([$mid]);
            $m = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($m) $names[] = trim($m['last_name'] . ', ' . $m['first_name']);
        } catch (Exception $e) {}
    }
    $einsatzleiter_name = implode('; ', $names);
} elseif (!empty($liste['einsatzleiter_freitext'])) {
    $einsatzleiter_name = $liste['einsatzleiter_freitext'];
} elseif (!empty($liste['einsatzleiter_member_id'])) {
    try {
        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
        $stmt->execute([$liste['einsatzleiter_member_id']]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) $einsatzleiter_name = trim($m['last_name'] . ', ' . $m['first_name']);
    } catch (Exception $e) {}
}

$vehicle_ids = array_unique(array_merge(array_column($liste_members, 'vehicle_id'), array_column($liste_vehicles, 'vehicle_id')));
$vehicle_ids = array_filter(array_map('intval', $vehicle_ids));
$vehicle_roles = [];
foreach ($liste_vehicles as $lv) {
    $vehicle_roles[$lv['vehicle_id']] = ['maschinist' => $lv['masch_first'] . ' ' . $lv['masch_last'], 'einheitsfuehrer' => $lv['einh_first'] . ' ' . $lv['einh_last']];
}
$vehicle_equipment_data = $custom_data_pdf['vehicle_equipment'] ?? [];
$vehicle_equipment_sonstiges = $custom_data_pdf['vehicle_equipment_sonstiges'] ?? [];
$vehicle_equipment_names = [];
if (!empty($vehicle_equipment_data)) {
    $all_eq_ids = [];
    foreach ($vehicle_equipment_data as $eq_ids) {
        if (is_array($eq_ids)) $all_eq_ids = array_merge($all_eq_ids, array_map('intval', $eq_ids));
    }
    $all_eq_ids = array_unique(array_filter($all_eq_ids));
    $eq_map = [];
    if (!empty($all_eq_ids)) {
        try {
            $ph = implode(',', array_fill(0, count($all_eq_ids), '?'));
            $stmt = $db->prepare("SELECT id, name FROM vehicle_equipment WHERE id IN ($ph)");
            $stmt->execute(array_values($all_eq_ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $eq_map[(int)$r['id']] = $r['name'];
            }
        } catch (Exception $e) {}
    }
    foreach ($vehicle_equipment_data as $vid => $eq_ids) {
        if (!is_array($eq_ids)) continue;
        $names = [];
        foreach (array_map('intval', $eq_ids) as $eid) {
            if ($eid > 0 && isset($eq_map[$eid])) $names[] = $eq_map[$eid];
        }
        $sonst = $vehicle_equipment_sonstiges[$vid] ?? '';
        if (is_array($sonst)) {
            foreach ($sonst as $s) {
                $s = trim((string)$s);
                if ($s !== '') $names[] = $s;
            }
        } elseif (trim((string)$sonst) !== '') {
            $names[] = trim($sonst);
        }
        $vehicle_equipment_names[(int)$vid] = implode(', ', $names);
    }
}
foreach ($vehicle_equipment_sonstiges as $vid => $sonst) {
    $vid = (int)$vid;
    if ($vid <= 0) continue;
    if (isset($vehicle_equipment_names[$vid])) continue;
    $parts = [];
    if (is_array($sonst)) {
        foreach ($sonst as $s) {
            $s = trim((string)$s);
            if ($s !== '') $parts[] = $s;
        }
    } elseif (trim((string)$sonst) !== '') {
        $parts[] = trim($sonst);
    }
    if (!empty($parts)) {
        $vehicle_equipment_names[$vid] = implode(', ', $parts);
    }
}

$titel = $liste['bezeichnung'] ?? $liste['dienst_bezeichnung'] ?? 'Anwesenheit';
$typ_label = ($liste['typ'] ?? '') === 'einsatz' ? 'Einsatz' : (($liste['typ'] ?? '') === 'manuell' ? 'Manuell' : get_dienstplan_typ_label($liste['dienst_typ'] ?? 'uebungsdienst'));

$html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Anwesenheitsliste – ' . htmlspecialchars($titel) . '</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.25; color: #333; margin: 0; padding: 10px; }
        .header { text-align: center; border-bottom: 2px solid #0d6efd; padding-bottom: 8px; margin-bottom: 10px; }
        .header h1 { margin: 0 0 2px 0; font-size: 14pt; color: #0d6efd; }
        .header .sub { color: #666; font-size: 8pt; }
        .section { margin-bottom: 10px; }
        .section-title { font-weight: bold; font-size: 10pt; margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid #dee2e6; }
        .two-cols-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .two-cols-table td { vertical-align: top; padding: 0 8px 0 0; }
        .two-cols-table td:first-child { width: 40%; }
        .two-cols-table td:last-child { width: 60%; padding: 0 0 0 8px; }
        .two-cols-table table { font-size: 9pt; table-layout: auto; width: 100%; }
        .col-fahrzeug { width: 28px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 8pt; }
        .stamm-inline { table-layout: fixed; }
        th, td { border: 1px solid #dee2e6; padding: 4px 6px; text-align: left; }
        th { background: #f8f9fa; font-weight: bold; }
        .label-cell { width: 100px; background: #f8f9fa; font-weight: bold; }
        .stamm-inline .label-cell { width: 90px; }
        .col-staerke { width: 28px; }
        .bottom-row { display: flex; gap: 24px; align-items: flex-end; margin-top: 20px; padding-top: 12px; border-top: 1px solid #333; }
        .bottom-row .einsatzleiter-cell { flex: 1; }
        .bottom-row .signature-cell { flex-shrink: 0; padding-top: 24px; }
        .signature-line { border-bottom: 1px solid #333; width: 160px; height: 22px; }
        .signature-label { font-size: 8pt; color: #666; margin-top: 2px; }
        @media print { body { padding: 0; } .section, .two-cols-table { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Anwesenheitsliste</h1>
        <div class="sub">' . htmlspecialchars(date('d.m.Y', strtotime($liste['datum']))) . ' – ' . htmlspecialchars($titel) . ' (' . htmlspecialchars($typ_label) . ')</div>
        <div class="sub">Eingereicht am ' . format_datetime_berlin($liste['created_at'], 'd.m.Y H:i') . ' von ' . htmlspecialchars(trim($liste['user_first_name'] . ' ' . $liste['user_last_name']) ?: 'Unbekannt') . '</div>
    </div>';

$einsatzbericht_display = 'A' . (trim($liste['einsatzbericht_nummer'] ?? '') !== '' ? $liste['einsatzbericht_nummer'] : '');
$alarmierung = _al_val($liste, 'alarmierung_durch', $custom_data);
$uhrzeit_von = _al_val($liste, 'uhrzeit_von', $custom_data);
$uhrzeit_bis = _al_val($liste, 'uhrzeit_bis', $custom_data);
$einsatzstichwort = _al_val($liste, 'einsatzstichwort', $custom_data);
$klassifizierung = _al_val($liste, 'klassifizierung', $custom_data);
$stichwort_thema_val = $is_uebungsdienst_pdf ? trim((string)($liste['bezeichnung'] ?? '')) : $einsatzstichwort;
$stichwort_thema_label = $is_uebungsdienst_pdf ? 'Thema' : 'Stichwort';
$einsatzdauer = _calc_einsatzdauer($uhrzeit_von, $uhrzeit_bis);
if ($uhrzeit_von !== '' && strlen($uhrzeit_von) >= 5) $uhrzeit_von = substr($uhrzeit_von, 0, 5);
if ($uhrzeit_bis !== '' && strlen($uhrzeit_bis) >= 5) $uhrzeit_bis = substr($uhrzeit_bis, 0, 5);

$uebungsdienst_hide_pdf = ['alarmierung_durch', 'eigentuemer', 'geschaedigter', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache'];
$html .= '<div class="section"><div class="section-title">Stammdaten</div><table class="stamm-inline">';
if (!$is_uebungsdienst_pdf) {
    $html .= '<tr><td class="label-cell">Einsatzbericht Nr.</td><td>' . htmlspecialchars($einsatzbericht_display) . '</td><td class="label-cell">Alarmierung durch</td><td>' . htmlspecialchars($alarmierung ?: '-') . '</td></tr>';
}
$html .= '<tr><td class="label-cell">Uhrzeit von</td><td>' . htmlspecialchars($uhrzeit_von ?: '-') . '</td><td class="label-cell">Uhrzeit bis</td><td>' . htmlspecialchars($uhrzeit_bis ?: '-') . '</td><td class="label-cell">Einsatzdauer</td><td>' . htmlspecialchars($einsatzdauer ?: '-') . '</td></tr>';
$html .= '<tr><td class="label-cell">' . htmlspecialchars($stichwort_thema_label) . '</td><td>' . htmlspecialchars($stichwort_thema_val ?: '-') . '</td><td class="label-cell">Klassifizierung</td><td>' . htmlspecialchars($klassifizierung ?: '-') . '</td></tr>';
$skip_ids = ['einsatzbericht_nummer','alarmierung_durch','uhrzeit_von','uhrzeit_bis','einsatzstichwort','klassifizierung','einsatzleiter'];
foreach ($anwesenheitsliste_felder as $f) {
    if (empty($f['visible'])) continue;
    $fid = $f['id'] ?? '';
    if (in_array($fid, $skip_ids)) continue;
    if ($is_uebungsdienst_pdf && in_array($fid, $uebungsdienst_hide_pdf)) continue;
    $val = _al_val($liste, $fid, $custom_data);
    if ($val === '' || $val === null) continue;
    $type = $f['type'] ?? 'text';
    if (($type === 'time' || in_array($fid, ['uhrzeit_von','uhrzeit_bis'])) && strlen($val) >= 5) $val = substr($val, 0, 5);
    $label = $f['label'] ?? $fid;
    $html .= '<tr><td class="label-cell">' . htmlspecialchars($label) . '</td><td colspan="5">' . htmlspecialchars($val) . '</td></tr>';
}
$html .= '</table></div>';

$html .= '<div class="section"><table class="two-cols-table" width="100%"><tr><td><div class="section-title">Personal</div><table width="100%"><thead><tr><th>Name</th><th class="col-fahrzeug">Fzg</th></tr></thead><tbody>';
foreach ($liste_members as $lm) {
    $name = trim($lm['last_name'] . ', ' . $lm['first_name']);
    $vehicle = $lm['vehicle_name'] ?? '-';
    $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td class="col-fahrzeug">' . htmlspecialchars($vehicle) . '</td></tr>';
}
if (empty($liste_members)) $html .= '<tr><td colspan="2">Keine Einträge</td></tr>';
$html .= '</tbody></table></td><td><div class="section-title">Fahrzeuge (Maschinist / Einheitsführer / Geräte)</div><table width="100%"><thead><tr><th>Fahrzeug</th><th>Maschinist</th><th>Einheitsführer</th><th class="col-staerke">Stärke</th><th>Geräte</th></tr></thead><tbody>';
foreach ($vehicle_ids as $vid) {
    if ($vid <= 0) continue;
    $vname = '';
    foreach (array_merge($liste_members, $liste_vehicles) as $x) {
        if (isset($x['vehicle_id']) && (int)$x['vehicle_id'] === $vid && !empty($x['vehicle_name'])) { $vname = $x['vehicle_name']; break; }
    }
    foreach ($liste_vehicles as $lv) { if ((int)$lv['vehicle_id'] === $vid && !empty($lv['vehicle_name'])) { $vname = $lv['vehicle_name']; break; } }
    if ($vname === '') $vname = 'Fahrzeug ' . $vid;
    $roles = $vehicle_roles[$vid] ?? ['maschinist' => '-', 'einheitsfuehrer' => '-'];
    $crew_ids = array_column(array_filter($liste_members, fn($m) => (int)$m['vehicle_id'] === $vid), 'member_id');
    $besatzungsstaerke = get_besatzungsstaerke($crew_ids, $db);
    $geraete_str = $vehicle_equipment_names[$vid] ?? '-';
    $html .= '<tr><td>' . htmlspecialchars($vname) . '</td><td>' . htmlspecialchars(trim($roles['maschinist']) ?: '-') . '</td><td>' . htmlspecialchars(trim($roles['einheitsfuehrer']) ?: '-') . '</td><td class="col-staerke">' . htmlspecialchars($besatzungsstaerke) . '</td><td>' . htmlspecialchars($geraete_str) . '</td></tr>';
}
if (empty($vehicle_ids) || (count($vehicle_ids) === 1 && in_array(0, $vehicle_ids))) {
    $html .= '<tr><td colspan="5">Keine Fahrzeuge zugeordnet</td></tr>';
}
$html .= '</tbody></table></td></tr></table></div>';

$leiter_label = $is_uebungsdienst_pdf ? 'Übungsleiter' : 'Einsatzleiter';
$unterschrift_label = $is_uebungsdienst_pdf ? 'Unterschrift Übungsleiter' : 'Unterschrift Einsatzleiter';
$html .= '
    <div class="bottom-row">
        <div class="einsatzleiter-cell">
            <strong>' . htmlspecialchars($leiter_label) . ':</strong> ' . htmlspecialchars($einsatzleiter_name ?: '-') . '
        </div>
        <div class="signature-cell">
            <div class="signature-line"></div>
            <div class="signature-label">' . htmlspecialchars($unterschrift_label) . '</div>
        </div>
    </div>
</body>
</html>';

$filename = 'Anwesenheitsliste_' . date('Y-m-d', strtotime($liste['datum'])) . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $titel) . '.pdf';

$wkhtmltopdfPath = '';
foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', 'wkhtmltopdf'] as $path) {
    if ((strpos($path, '/') !== false && is_executable($path)) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

if ($wkhtmltopdfPath) {
    $pdfPath = tempnam(sys_get_temp_dir(), 'al_') . '.pdf';
    $htmlPath = tempnam(sys_get_temp_dir(), 'al_') . '.html';
    file_put_contents($htmlPath, $html);
    $cmd = escapeshellarg($wkhtmltopdfPath) . ' --page-size A4 --margin-top 12mm --margin-right 12mm --margin-bottom 12mm --margin-left 12mm --encoding UTF-8 --print-media-type ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath);
    shell_exec($cmd . ' 2>&1');
    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
        $pdf_content = file_get_contents($pdfPath);
        @unlink($pdfPath);
        @unlink($htmlPath);
        if ($return_mode) { $GLOBALS['_al_pdf_content'] = $pdf_content; return; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($for_print ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;
    }
    @unlink($pdfPath);
    @unlink($htmlPath);
}

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dompdf\Dompdf')) {
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdf_content = $dompdf->output();
            if ($return_mode) { $GLOBALS['_al_pdf_content'] = $pdf_content; return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($for_print ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
            echo $pdf_content;
            exit;
        } catch (Exception $e) {
            error_log('Dompdf Fehler: ' . $e->getMessage());
        }
    }
}

$tcpdfPath = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (file_exists($tcpdfPath)) {
    require_once $tcpdfPath;
    if (class_exists('TCPDF')) {
        try {
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            $pdf->SetCreator('Feuerwehr App');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(12, 12, 12);
            $pdf->SetAutoPageBreak(true, 12);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 10);
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf_content = $pdf->Output('', 'S');
            if ($return_mode) { $GLOBALS['_al_pdf_content'] = $pdf_content; return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($for_print ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
            echo $pdf_content;
            exit;
        } catch (Exception $e) {
            error_log('TCPDF Fehler: ' . $e->getMessage());
        }
    }
}

if ($return_mode) {
    $GLOBALS['_al_pdf_content'] = null;
    return;
}
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: ' . ($for_print ? 'inline' : 'attachment') . '; filename="' . str_replace('.pdf', '.html', $filename) . '"');
echo $html;
