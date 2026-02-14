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

$einsatzleiter_name = '';
if (!empty($liste['einsatzleiter_freitext'])) {
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

$titel = $liste['bezeichnung'] ?? $liste['dienst_bezeichnung'] ?? 'Anwesenheit';
$typ_label = ($liste['typ'] ?? '') === 'einsatz' ? 'Einsatz' : (($liste['typ'] ?? '') === 'manuell' ? 'Manuell' : get_dienstplan_typ_label($liste['dienst_typ'] ?? 'uebungsdienst'));

$html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Anwesenheitsliste – ' . htmlspecialchars($titel) . '</title>
    <style>
        @page { size: A4; margin: 15mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; color: #333; margin: 0; padding: 15px; }
        .header { text-align: center; border-bottom: 2px solid #0d6efd; padding-bottom: 12px; margin-bottom: 18px; }
        .header h1 { margin: 0 0 4px 0; font-size: 18pt; color: #0d6efd; }
        .header .sub { color: #666; font-size: 9pt; }
        .section { margin-bottom: 16px; }
        .section-title { font-weight: bold; font-size: 11pt; margin-bottom: 8px; padding-bottom: 4px; border-bottom: 1px solid #dee2e6; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #dee2e6; padding: 6px 8px; text-align: left; }
        th { background: #f8f9fa; font-weight: bold; }
        .label-cell { width: 140px; background: #f8f9fa; font-weight: bold; }
        .signature-block { margin-top: 40px; padding-top: 20px; border-top: 1px solid #333; }
        .signature-line { margin-top: 50px; border-bottom: 1px solid #333; width: 200px; height: 24px; }
        .signature-label { font-size: 9pt; color: #666; margin-top: 4px; }
        @media print { body { padding: 0; } .section { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Anwesenheitsliste</h1>
        <div class="sub">' . htmlspecialchars(date('d.m.Y', strtotime($liste['datum']))) . ' – ' . htmlspecialchars($titel) . ' (' . htmlspecialchars($typ_label) . ')</div>
        <div class="sub">Eingereicht am ' . format_datetime_berlin($liste['created_at'], 'd.m.Y H:i') . ' von ' . htmlspecialchars(trim($liste['user_first_name'] . ' ' . $liste['user_last_name']) ?: 'Unbekannt') . '</div>
    </div>';

$einsatzbericht_display = 'A' . (trim($liste['einsatzbericht_nummer'] ?? '') !== '' ? $liste['einsatzbericht_nummer'] : '');
$html .= '<div class="section"><div class="section-title">Stammdaten</div><table>';
$html .= '<tr><td class="label-cell">Einsatzbericht Nummer</td><td>' . htmlspecialchars($einsatzbericht_display) . '</td></tr>';
foreach ($anwesenheitsliste_felder as $f) {
    if (empty($f['visible'])) continue;
    $fid = $f['id'] ?? '';
    $type = $f['type'] ?? 'text';
    if ($fid === 'einsatzleiter') {
        $val = $einsatzleiter_name ?: '-';
    } else {
        $val = _al_val_formatted($liste, $fid, $custom_data, $type);
    }
    $label = $f['label'] ?? $fid;
    $html .= '<tr><td class="label-cell">' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($val) . '</td></tr>';
}
$html .= '</table></div>';

$html .= '<div class="section"><div class="section-title">Personal</div><table><thead><tr><th>Name</th><th>Fahrzeug</th></tr></thead><tbody>';
foreach ($liste_members as $lm) {
    $name = trim($lm['last_name'] . ', ' . $lm['first_name']);
    $vehicle = $lm['vehicle_name'] ?? '-';
    $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td>' . htmlspecialchars($vehicle) . '</td></tr>';
}
if (empty($liste_members)) $html .= '<tr><td colspan="2">Keine Einträge</td></tr>';
$html .= '</tbody></table></div>';

$html .= '<div class="section"><div class="section-title">Fahrzeuge (Maschinist / Einheitsführer / Besatzung)</div><table><thead><tr><th>Fahrzeug</th><th>Maschinist</th><th>Einheitsführer</th><th>Besatzung</th><th>Besatzungsstärke</th></tr></thead><tbody>';
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
    foreach ($liste_members as $lm) {
        if ((int)$lm['vehicle_id'] === $vid) $crew_names[] = trim($lm['last_name'] . ', ' . $lm['first_name']);
    }
    $crew_ids = array_column(array_filter($liste_members, fn($m) => (int)$m['vehicle_id'] === $vid), 'member_id');
    $besatzungsstaerke = get_besatzungsstaerke($crew_ids, $db);
    $crew_str = implode(', ', $crew_names) ?: '-';
    $html .= '<tr><td>' . htmlspecialchars($vname) . '</td><td>' . htmlspecialchars(trim($roles['maschinist']) ?: '-') . '</td><td>' . htmlspecialchars(trim($roles['einheitsfuehrer']) ?: '-') . '</td><td>' . htmlspecialchars($crew_str) . '</td><td>' . htmlspecialchars($besatzungsstaerke) . '</td></tr>';
}
if (empty($vehicle_ids) || (count($vehicle_ids) === 1 && in_array(0, $vehicle_ids))) {
    $html .= '<tr><td colspan="5">Keine Fahrzeuge zugeordnet</td></tr>';
}
$html .= '</tbody></table></div>';

$html .= '
    <div class="signature-block">
        <div class="signature-line"></div>
        <div class="signature-label">Unterschrift Einsatzleiter</div>
    </div>
</body>
</html>';

$wkhtmltopdfPath = '';
foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', 'wkhtmltopdf'] as $path) {
    if ((strpos($path, '/') !== false && is_executable($path)) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

$filename = 'Anwesenheitsliste_' . date('Y-m-d', strtotime($liste['datum'])) . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $titel) . '.pdf';

if ($wkhtmltopdfPath) {
    $pdfPath = tempnam(sys_get_temp_dir(), 'al_') . '.pdf';
    $htmlPath = tempnam(sys_get_temp_dir(), 'al_') . '.html';
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
