<?php
/**
 * Gerätewartmitteilung als PDF.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

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

$gwm = null;
try {
    $stmt = $db->prepare("
        SELECT g.*, COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM geraetewartmitteilungen g
        LEFT JOIN users u ON u.id = g.user_id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $gwm = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $gwm = null;
}
if (!$gwm) {
    header('HTTP/1.1 404 Not Found');
    echo 'Gerätewartmitteilung nicht gefunden';
    exit;
}

$einsatzleiter = '';
if (!empty($gwm['einsatzleiter_member_id'])) {
    try {
        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
        $stmt->execute([$gwm['einsatzleiter_member_id']]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) $einsatzleiter = trim($m['last_name'] . ', ' . $m['first_name']);
    } catch (Exception $e) {}
}
if ($einsatzleiter === '' && !empty($gwm['einsatzleiter_freitext'])) {
    $einsatzleiter = $gwm['einsatzleiter_freitext'];
}

$fahrzeuge = [];
try {
    $stmt = $db->prepare("
        SELECT gf.*, v.name AS vehicle_name,
               m1.first_name AS masch_first, m1.last_name AS masch_last,
               m2.first_name AS einh_first, m2.last_name AS einh_last
        FROM geraetewartmitteilung_fahrzeuge gf
        LEFT JOIN vehicles v ON v.id = gf.vehicle_id
        LEFT JOIN members m1 ON m1.id = gf.maschinist_member_id
        LEFT JOIN members m2 ON m2.id = gf.einheitsfuehrer_member_id
        WHERE gf.geraetewartmitteilung_id = ?
        ORDER BY v.name
    ");
    $stmt->execute([$id]);
    $fahrzeuge = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$typ_label = $gwm['typ'] === 'einsatz' ? 'Einsatz' : 'Übung';
$eb_label = $gwm['einsatzbereitschaft'] === 'hergestellt' ? 'Einsatzbereitschaft wurde hergestellt' : 'Einsatzbereitschaft wurde nicht hergestellt';

$fahrzeuge_html = '';
foreach ($fahrzeuge as $f) {
    $masch = trim(($f['masch_last'] ?? '') . ', ' . ($f['masch_first'] ?? ''));
    $einh = trim(($f['einh_last'] ?? '') . ', ' . ($f['einh_first'] ?? ''));
    $equipment_used = json_decode($f['equipment_used'] ?? '[]', true) ?: [];
    $defective = json_decode($f['defective_equipment'] ?? '[]', true) ?: [];
    $eq_names = [];
    foreach ($equipment_used as $eqid) {
        $st = $db->prepare("SELECT name FROM vehicle_equipment WHERE id = ?");
        $st->execute([$eqid]);
        $n = $st->fetchColumn();
        if ($n) $eq_names[] = $n;
    }
    $def_names = [];
    foreach ($defective as $eqid) {
        $st = $db->prepare("SELECT name FROM vehicle_equipment WHERE id = ?");
        $st->execute([$eqid]);
        $n = $st->fetchColumn();
        if ($n) $def_names[] = $n;
    }
    if (!empty($f['defective_freitext'])) $def_names[] = $f['defective_freitext'];
    $def_str = '';
    if (!empty($def_names)) {
        $def_str = 'Gerät(e): ' . implode(', ', $def_names);
        if (!empty($f['defective_mangel'])) {
            $def_str .= ' – Defekt: ' . $f['defective_mangel'];
        }
    } elseif (!empty($f['defective_mangel'])) {
        $def_str = 'Defekt: ' . $f['defective_mangel'];
    }

    $fahrzeuge_html .= '<tr><td class="label-cell">' . htmlspecialchars($f['vehicle_name'] ?? '-') . '</td><td class="value-cell">' . htmlspecialchars($masch ?: '-') . '</td><td class="value-cell">' . htmlspecialchars($einh ?: '-') . '</td><td class="value-cell">' . htmlspecialchars(implode(', ', $eq_names) ?: '-') . '</td><td class="value-cell">' . htmlspecialchars($def_str ?: '-') . '</td></tr>';
}

$html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gerätewartmitteilung – ' . date('d.m.Y', strtotime($gwm['datum'])) . '</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.3; color: #333; margin: 0; padding: 6px 12px; }
        .header { text-align: center; margin-bottom: 8px; }
        .section { margin-bottom: 8px; }
        .section-title { font-weight: bold; font-size: 10pt; margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        .label-cell { width: 120px; background: #f5f5f5; font-weight: bold; padding: 3px 6px; border: 1px solid #ddd; }
        .value-cell { padding: 3px 6px; border: 1px solid #ddd; }
        @media print { .section { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="header">' . get_pdf_logo_html() . '</div>
    <div class="section">
        <div class="section-title">Gerätewartmitteilung</div>
        <table>
            <tr><td class="label-cell">Typ</td><td class="value-cell">' . htmlspecialchars($typ_label) . '</td></tr>
            <tr><td class="label-cell">Einsatz-/Übungsart</td><td class="value-cell">' . htmlspecialchars($gwm['einsatz_uebungsart']) . '</td></tr>
            <tr><td class="label-cell">Datum</td><td class="value-cell">' . date('d.m.Y', strtotime($gwm['datum'])) . '</td></tr>
            <tr><td class="label-cell">Einsatzbereitschaft</td><td class="value-cell">' . htmlspecialchars($eb_label) . '</td></tr>
            <tr><td class="label-cell">Einsatzleiter</td><td class="value-cell">' . htmlspecialchars($einsatzleiter ?: '-') . '</td></tr>
            ' . (!empty($gwm['mangel_beschreibung']) ? '<tr><td class="label-cell">Mangel</td><td class="value-cell">' . nl2br(htmlspecialchars($gwm['mangel_beschreibung'])) . '</td></tr>' : '') . '
        </table>
    </div>
    <div class="section">
        <div class="section-title">Eingesetzte Fahrzeuge</div>
        <table>
            <tr><th class="label-cell">Fahrzeug</th><th class="label-cell">Maschinist</th><th class="label-cell">Einheitsführer</th><th class="label-cell">Geräte</th><th class="label-cell">Defekte</th></tr>
            ' . $fahrzeuge_html . '
        </table>
    </div>
</body>
</html>';

$filename = 'Geraetewartmitteilung_' . $gwm['datum'] . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $gwm['einsatz_uebungsart']) . '.pdf';

$wkhtmltopdfPath = '';
foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', 'wkhtmltopdf'] as $path) {
    if ((strpos($path, '/') !== false && is_executable($path)) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

if ($wkhtmltopdfPath) {
    $pdfPath = tempnam(sys_get_temp_dir(), 'gwm_') . '.pdf';
    $htmlPath = tempnam(sys_get_temp_dir(), 'gwm_') . '.html';
    file_put_contents($htmlPath, $html);
    $cmd = escapeshellarg($wkhtmltopdfPath) . ' --page-size A4 --margin-top 12mm --margin-right 12mm --margin-bottom 12mm --margin-left 12mm --encoding UTF-8 --print-media-type ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath);
    shell_exec($cmd . ' 2>&1');
    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
        $pdf_content = file_get_contents($pdfPath);
        @unlink($pdfPath);
        @unlink($htmlPath);
        if ($return_mode) { $GLOBALS['_gwm_pdf_content'] = $pdf_content; return; }
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
            if ($return_mode) { $GLOBALS['_gwm_pdf_content'] = $pdf_content; return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($for_print ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
            echo $pdf_content;
            exit;
        } catch (Exception $e) {
            error_log('Dompdf Gerätewartmitteilung Fehler: ' . $e->getMessage());
        }
    }
}

$tcpdfPath = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) $tcpdfPath = __DIR__ . '/../tcpdf/tcpdf.php';
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
            if ($return_mode) { $GLOBALS['_gwm_pdf_content'] = $pdf_content; return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($for_print ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
            echo $pdf_content;
            exit;
        } catch (Exception $e) {
            error_log('TCPDF Gerätewartmitteilung Fehler: ' . $e->getMessage());
        }
    }
}

if ($return_mode) {
    $GLOBALS['_gwm_pdf_content'] = null;
    return;
}
header('Content-Type: text/html; charset=UTF-8');
echo $html;
