<?php
/**
 * Alle Anwesenheitslisten (gefiltert) als ein PDF herunterladen.
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

$filter_typ = trim($_GET['filter_typ'] ?? '');
$filter_datum_von = trim($_GET['filter_datum_von'] ?? '');
$filter_datum_bis = trim($_GET['filter_datum_bis'] ?? '');

$sql = "
    SELECT a.id, a.datum, a.bezeichnung, a.typ, a.created_at, a.*,
           d.bezeichnung AS dienst_bezeichnung, d.typ AS dienst_typ,
           COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
    FROM anwesenheitslisten a
    LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
    LEFT JOIN users u ON u.id = a.user_id
    WHERE 1=1
";
$params = [];
if ($filter_typ !== '') {
    if ($filter_typ === 'einsatz') $sql .= " AND a.typ = 'einsatz'";
    elseif ($filter_typ === 'manuell') $sql .= " AND a.typ = 'manuell'";
    elseif ($filter_typ === 'dienst') $sql .= " AND a.typ = 'dienst'";
    elseif (in_array($filter_typ, ['uebungsdienst', 'jahreshauptversammlung', 'sonstiges'])) {
        $sql .= " AND a.typ = 'dienst' AND d.typ = ?";
        $params[] = $filter_typ;
    }
}
if ($filter_datum_von !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_von)) {
    $sql .= " AND a.datum >= ?";
    $params[] = $filter_datum_von;
}
if ($filter_datum_bis !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_bis)) {
    $sql .= " AND a.datum <= ?";
    $params[] = $filter_datum_bis;
}
$sql .= " ORDER BY a.created_at DESC";

$stmt = $params ? $db->prepare($sql) : $db->query($sql);
if ($params) $stmt->execute($params);
$listen = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($listen)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Keine Anwesenheitslisten gefunden.';
    exit;
}

function _al_val($liste, $key, $custom_data = []) {
    $builtin = ['uhrzeit_von','uhrzeit_bis','alarmierung_durch','einsatzstelle','einsatzstichwort','einsatzbericht_nummer','objekt','eigentuemer','geschaedigter','klassifizierung','kostenpflichtiger_einsatz','personenschaeden','brandwache','bemerkung'];
    if (in_array($key, $builtin)) return $liste[$key] ?? '';
    return $custom_data[$key] ?? '';
}
function _al_val_formatted($liste, $key, $custom_data = [], $type = '') {
    $v = _al_val($liste, $key, $custom_data);
    if ($v === '' || $v === null) return '-';
    if (($type === 'time' || in_array($key, ['uhrzeit_von','uhrzeit_bis'])) && strlen($v) >= 5) return substr($v, 0, 5);
    return $v;
}

$html_parts = [];
$anwesenheitsliste_felder = anwesenheitsliste_felder_laden();

foreach ($listen as $idx => $liste) {
    $id = (int)$liste['id'];
    $liste_members = [];
    $liste_vehicles = [];
    try {
        $stmt = $db->prepare("SELECT am.member_id, am.vehicle_id, m.first_name, m.last_name, v.name AS vehicle_name FROM anwesenheitsliste_mitglieder am LEFT JOIN members m ON m.id = am.member_id LEFT JOIN vehicles v ON v.id = am.vehicle_id WHERE am.anwesenheitsliste_id = ? ORDER BY v.name, m.last_name, m.first_name");
        $stmt->execute([$id]);
        $liste_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    try {
        $stmt = $db->prepare("SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id, v.name AS vehicle_name, m1.first_name AS masch_first, m1.last_name AS masch_last, m2.first_name AS einh_first, m2.last_name AS einh_last FROM anwesenheitsliste_fahrzeuge af LEFT JOIN vehicles v ON v.id = af.vehicle_id LEFT JOIN members m1 ON m1.id = af.maschinist_member_id LEFT JOIN members m2 ON m2.id = af.einheitsfuehrer_member_id WHERE af.anwesenheitsliste_id = ?");
        $stmt->execute([$id]);
        $liste_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $custom_data = [];
    if (!empty($liste['custom_data'])) {
        $dec = json_decode($liste['custom_data'], true);
        $custom_data = is_array($dec) ? $dec : [];
    }

    $einsatzleiter_name = '';
    if (!empty($liste['einsatzleiter_freitext'])) $einsatzleiter_name = $liste['einsatzleiter_freitext'];
    elseif (!empty($liste['einsatzleiter_member_id'])) {
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
    foreach ($liste_vehicles as $lv) $vehicle_roles[$lv['vehicle_id']] = ['maschinist' => trim($lv['masch_first'] . ' ' . $lv['masch_last']), 'einheitsfuehrer' => trim($lv['einh_first'] . ' ' . $lv['einh_last'])];

    $titel = $liste['bezeichnung'] ?? $liste['dienst_bezeichnung'] ?? 'Anwesenheit';
    $typ_label = ($liste['typ'] ?? '') === 'einsatz' ? 'Einsatz' : (($liste['typ'] ?? '') === 'manuell' ? 'Manuell' : get_dienstplan_typ_label($liste['dienst_typ'] ?? 'uebungsdienst'));

    $part = ($idx > 0 ? '<div style="page-break-before: always;"></div>' : '');
    $part .= '
    <div class="report-page">
    <div class="header">
        <h1>Anwesenheitsliste</h1>
        <div class="sub">' . htmlspecialchars(date('d.m.Y', strtotime($liste['datum']))) . ' – ' . htmlspecialchars($titel) . ' (' . htmlspecialchars($typ_label) . ')</div>
        <div class="sub">Eingereicht am ' . format_datetime_berlin($liste['created_at'], 'd.m.Y H:i') . ' von ' . htmlspecialchars(trim($liste['user_first_name'] . ' ' . $liste['user_last_name']) ?: 'Unbekannt') . '</div>
    </div>';

    $einsatzbericht_display = 'A' . (trim($liste['einsatzbericht_nummer'] ?? '') !== '' ? $liste['einsatzbericht_nummer'] : '');
    $part .= '<div class="section"><div class="section-title">Stammdaten</div><table>';
    $part .= '<tr><td class="label-cell">Einsatzbericht Nummer</td><td>' . htmlspecialchars($einsatzbericht_display) . '</td></tr>';
    foreach ($anwesenheitsliste_felder as $f) {
        if (empty($f['visible'])) continue;
        $fid = $f['id'] ?? '';
        $type = $f['type'] ?? 'text';
        if ($fid === 'einsatzleiter') $val = $einsatzleiter_name ?: '-';
        else $val = _al_val_formatted($liste, $fid, $custom_data, $type);
        $label = $f['label'] ?? $fid;
        $part .= '<tr><td class="label-cell">' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($val) . '</td></tr>';
    }
    $part .= '</table></div>';

    $part .= '<div class="section"><div class="section-title">Personal</div><table><thead><tr><th>Name</th><th>Fahrzeug</th></tr></thead><tbody>';
    foreach ($liste_members as $lm) {
        $part .= '<tr><td>' . htmlspecialchars(trim($lm['last_name'] . ', ' . $lm['first_name'])) . '</td><td>' . htmlspecialchars($lm['vehicle_name'] ?? '-') . '</td></tr>';
    }
    if (empty($liste_members)) $part .= '<tr><td colspan="2">Keine Einträge</td></tr>';
    $part .= '</tbody></table></div>';

    $part .= '<div class="section"><div class="section-title">Fahrzeuge (Maschinist / Einheitsführer / Besatzung)</div><table><thead><tr><th>Fahrzeug</th><th>Maschinist</th><th>Einheitsführer</th><th>Besatzung</th><th>Besatzungsstärke</th></tr></thead><tbody>';
    foreach ($vehicle_ids as $vid) {
        if ($vid <= 0) continue;
        $vname = '';
        foreach (array_merge($liste_members, $liste_vehicles) as $x) {
            if (isset($x['vehicle_id']) && (int)$x['vehicle_id'] === $vid && !empty($x['vehicle_name'])) { $vname = $x['vehicle_name']; break; }
        }
        foreach ($liste_vehicles as $lv) { if ((int)$lv['vehicle_id'] === $vid && !empty($lv['vehicle_name'])) { $vname = $lv['vehicle_name']; break; } }
        if ($vname === '') $vname = 'Fahrzeug ' . $vid;
        $roles = $vehicle_roles[$vid] ?? ['maschinist' => '-', 'einheitsfuehrer' => '-'];
        $crew_names = [];
        foreach ($liste_members as $lm) { if ((int)$lm['vehicle_id'] === $vid) $crew_names[] = trim($lm['last_name'] . ', ' . $lm['first_name']); }
        $crew_ids = array_column(array_filter($liste_members, fn($m) => (int)$m['vehicle_id'] === $vid), 'member_id');
        $besatzungsstaerke = get_besatzungsstaerke($crew_ids, $db);
        $crew_str = implode(', ', $crew_names) ?: '-';
        $part .= '<tr><td>' . htmlspecialchars($vname) . '</td><td>' . htmlspecialchars(trim($roles['maschinist']) ?: '-') . '</td><td>' . htmlspecialchars(trim($roles['einheitsfuehrer']) ?: '-') . '</td><td>' . htmlspecialchars($crew_str) . '</td><td>' . htmlspecialchars($besatzungsstaerke) . '</td></tr>';
    }
    if (empty($vehicle_ids) || (count($vehicle_ids) === 1 && in_array(0, $vehicle_ids))) $part .= '<tr><td colspan="5">Keine Fahrzeuge zugeordnet</td></tr>';
    $part .= '</tbody></table></div>';

    $part .= '<div class="signature-block"><div class="signature-line"></div><div class="signature-label">Unterschrift Einsatzleiter</div></div></div>';
    $html_parts[] = $part;
}

$html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Anwesenheitslisten</title><style>
@page{size:A4;margin:15mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:10pt;line-height:1.35;color:#333;margin:0;padding:15px}
.header{text-align:center;border-bottom:2px solid #0d6efd;padding-bottom:12px;margin-bottom:18px}.header h1{margin:0 0 4px 0;font-size:18pt;color:#0d6efd}.header .sub{color:#666;font-size:9pt}
.section{margin-bottom:16px}.section-title{font-weight:bold;font-size:11pt;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #dee2e6}
table{width:100%;border-collapse:collapse;margin-bottom:12px}th,td{border:1px solid #dee2e6;padding:6px 8px;text-align:left}th{background:#f8f9fa;font-weight:bold}
.label-cell{width:140px;background:#f8f9fa;font-weight:bold}
.signature-block{margin-top:40px;padding-top:20px;border-top:1px solid #333}.signature-line{margin-top:50px;border-bottom:1px solid #333;width:200px;height:24px}.signature-label{font-size:9pt;color:#666;margin-top:4px}
@media print{body{padding:0}.report-page{page-break-after:always}.report-page:last-child{page-break-after:auto}}
</style></head><body>' . implode('', $html_parts) . '</body></html>';

$wkhtmltopdfPath = '';
foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', 'wkhtmltopdf'] as $path) {
    if ((strpos($path, '/') !== false && is_executable($path)) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

$filename = 'Anwesenheitslisten_alle_' . date('Y-m-d') . '.pdf';

if ($wkhtmltopdfPath) {
    $pdfPath = tempnam(sys_get_temp_dir(), 'al_all_') . '.pdf';
    $htmlPath = tempnam(sys_get_temp_dir(), 'al_all_') . '.html';
    file_put_contents($htmlPath, $html);
    $cmd = escapeshellarg($wkhtmltopdfPath) . ' --page-size A4 --margin-top 12mm --margin-right 12mm --margin-bottom 12mm --margin-left 12mm --encoding UTF-8 --print-media-type ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath);
    shell_exec($cmd . ' 2>&1');
    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        @unlink($pdfPath);
        @unlink($htmlPath);
        exit;
    }
    @unlink($pdfPath);
    @unlink($htmlPath);
}

header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"');
echo $html;
