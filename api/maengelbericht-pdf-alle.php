<?php
/**
 * Alle Mängelberichte (gefiltert) als ein PDF.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}
if (!has_form_fill_permission()) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}

$filter_datum_von = trim($_GET['filter_datum_von'] ?? '');
$filter_datum_bis = trim($_GET['filter_datum_bis'] ?? '');
$ids_param = trim($_GET['ids'] ?? '');
$return_mode = !empty($_GET['_return']);
$einheit_filter = isset($_GET['einheit_id']) && (int)$_GET['einheit_id'] > 0 ? (int)$_GET['einheit_id'] : (function_exists('get_admin_einheit_filter') ? get_admin_einheit_filter() : null);
// Bei expliziten IDs keinen Einheitenfilter – alle angeforderten Berichte liefern
if ($ids_param !== '') $einheit_filter = null;

$sql = "
    SELECT m.*, COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
    FROM maengelberichte m
    LEFT JOIN users u ON u.id = m.user_id
    WHERE 1=1
";
$params = [];
if ($einheit_filter) {
    $sql .= " AND u.einheit_id = ?";
    $params[] = $einheit_filter;
}
if ($ids_param !== '') {
    $ids = array_filter(array_map('intval', explode(',', $ids_param)), function($x) { return $x > 0; });
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND m.id IN ($ph)";
        $params = array_merge($params, $ids);
    }
}
if ($filter_datum_von !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_von) && empty($ids_param)) {
    $sql .= " AND m.aufgenommen_am >= ?";
    $params[] = $filter_datum_von;
}
if ($filter_datum_bis !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_bis) && empty($ids_param)) {
    $sql .= " AND m.aufgenommen_am <= ?";
    $params[] = $filter_datum_bis;
}
$sql .= " ORDER BY m.created_at DESC";

$stmt = $params ? $db->prepare($sql) : $db->query($sql);
if ($params) $stmt->execute($params);
$berichte = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: bei expliziten IDs prüfen, ob alle Berichte gefunden wurden
if ($ids_param !== '' && $return_mode) {
    $ids = array_filter(array_map('intval', explode(',', $ids_param)), function($x) { return $x > 0; });
    if (count($berichte) < count($ids)) {
        error_log('maengelbericht-pdf-alle: ids_param=' . $ids_param . ', ids_count=' . count($ids) . ', berichte_count=' . count($berichte) . ', bericht_ids=' . implode(',', array_column($berichte, 'id')));
    }
}

if (empty($berichte)) {
    if ($return_mode) {
        $GLOBALS['_mb_pdf_content'] = null;
        return;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Keine Mängelberichte gefunden.';
    exit;
}

$html_parts = [];
$berichte_count = count($berichte);
foreach ($berichte as $idx => $bericht) {
    // page-break-before für 2.+ Seiten (zuverlässiger als page-break-after bei Dompdf/wkhtmltopdf)
    $page_break_style = ($idx > 0) ? 'page-break-before: always;' : '';
    $aufgenommen_durch = '';
    if (!empty($bericht['aufgenommen_durch_member_id'])) {
        try {
            $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
            $stmt->execute([$bericht['aufgenommen_durch_member_id']]);
            $m = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($m) $aufgenommen_durch = trim($m['last_name'] . ', ' . $m['first_name']);
        } catch (Exception $e) {}
    }
    if ($aufgenommen_durch === '' && !empty($bericht['aufgenommen_durch_text'])) {
        $aufgenommen_durch = $bericht['aufgenommen_durch_text'];
    }
    $aufgenommen_am_display = !empty($bericht['aufgenommen_am']) ? date('d.m.Y', strtotime($bericht['aufgenommen_am'])) : '-';

    $vehicle_name = '';
    if (!empty($bericht['vehicle_id'])) {
        try {
            $st2 = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
            $st2->execute([$bericht['vehicle_id']]);
            $vehicle_name = $st2->fetchColumn() ?: '';
        } catch (Exception $e) {}
    }

    $html_parts[] = '
    <div class="mb-page" style="' . $page_break_style . ' page-break-inside: avoid;">
        <div class="section">
            <div class="section-title">Mängelbericht – ' . htmlspecialchars($bericht['standort'] ?? '') . ' (' . date('d.m.Y', strtotime($bericht['aufgenommen_am'])) . ')</div>
            <table>
                <tr><td class="label-cell">Standort</td><td class="value-cell">' . htmlspecialchars($bericht['standort'] ?? '-') . '</td></tr>
                <tr><td class="label-cell">Mangel/Wartung an</td><td class="value-cell">' . htmlspecialchars($bericht['mangel_an'] ?? '-') . '</td></tr>
                <tr><td class="label-cell">Bezeichnung, ggf. Gerätenummer</td><td class="value-cell">' . htmlspecialchars($bericht['bezeichnung'] ?? '-') . '</td></tr>
                <tr><td class="label-cell">Fahrzeug</td><td class="value-cell">' . htmlspecialchars($vehicle_name ?: '-') . '</td></tr>
                <tr><td class="label-cell">Mangel Beschreibung</td><td class="value-cell">' . nl2br(htmlspecialchars($bericht['mangel_beschreibung'] ?? '-')) . '</td></tr>
                <tr><td class="label-cell">Ursache</td><td class="value-cell">' . htmlspecialchars($bericht['ursache'] ?? '-') . '</td></tr>
                <tr><td class="label-cell">Verbleib</td><td class="value-cell">' . htmlspecialchars($bericht['verbleib'] ?? '-') . '</td></tr>
                <tr><td class="label-cell">Aufgenommen durch</td><td class="value-cell">' . htmlspecialchars($aufgenommen_durch ?: '-') . '</td></tr>
                <tr><td class="label-cell">Aufgenommen am</td><td class="value-cell">' . htmlspecialchars($aufgenommen_am_display) . '</td></tr>
            </table>
            <div class="signature-section"><div class="signature-line"></div><div class="signature-label">Unterschrift</div></div>
        </div>
        <div class="section geraetewart-section">
            <div class="geraetewart-title">Gerätewart:</div>
            <div class="line-field"><label>Kenntniss erhalten am:</label><div class="line"></div></div>
            <div class="prioritaet-row"><span>Priorität:</span><span class="box"></span><span>Hoch</span><span class="box"></span><span>Mittel</span><span class="box"></span><span>Gering</span></div>
            <div style="margin-top: 8px;"><label style="font-weight: bold;">Weitere Veranlassung</label><div class="veranlassung-line"></div><div class="veranlassung-line"></div><div class="veranlassung-line"></div></div>
            <div class="line-field" style="margin-top: 8px;"><label>Mangel beseitigt am:</label><div class="line"></div></div>
            <div class="line-field"><label>durch:</label><div class="line"></div></div>
            <div class="line-field"><label>ggf. Dauer:</label><div class="line"></div></div>
            <div class="signature-section"><div class="signature-line"></div><div class="signature-label">Unterschrift Gerätewart</div></div>
        </div>
    </div>';
}

$html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mängelberichte</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; line-height: 1.3; color: #333; margin: 0; padding: 6px 12px; }
        .header { text-align: center; margin-bottom: 8px; }
        .section { margin-bottom: 8px; }
        .section-title { font-weight: bold; font-size: 10pt; margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        .label-cell { width: 140px; background: #f5f5f5; font-weight: bold; padding: 3px 6px; border: 1px solid #ddd; }
        .value-cell { padding: 3px 6px; border: 1px solid #ddd; }
        .geraetewart-section { margin-top: 10px; padding-top: 8px; border-top: 2px solid #333; }
        .geraetewart-title { font-weight: bold; font-size: 11pt; margin-bottom: 6px; }
        .line-field { display: flex; align-items: flex-end; margin-bottom: 6px; min-height: 24px; }
        .line-field label { min-width: 140px; font-weight: bold; padding-bottom: 2px; }
        .line-field .line { flex: 1; border-bottom: 1px solid #333; margin-left: 8px; min-height: 18px; padding-top: 4px; }
        .prioritaet-row { display: flex; align-items: center; margin: 6px 0; }
        .prioritaet-row .box { width: 12px; height: 12px; border: 1px solid #333; margin-right: 4px; display: inline-block; }
        .prioritaet-row span { margin-right: 16px; }
        .veranlassung-line { border-bottom: 1px solid #333; min-height: 18px; margin: 5px 0; padding-top: 4px; }
        .signature-section { margin-top: 10px; padding-top: 8px; border-top: 1px solid #333; }
        .signature-line { border-bottom: 1px solid #333; width: 160px; min-height: 22px; margin-top: 10px; }
        .signature-label { font-size: 8pt; color: #666; margin-top: 2px; }
        .mb-page:last-child { page-break-after: auto; }
    </style>
</head>
<body>
    <div class="header">' . get_pdf_logo_html() . '</div>
    ' . implode('', $html_parts) . '
</body>
</html>';

$filename = 'Maengelberichte_' . date('Y-m-d') . '.pdf';

$wkhtmltopdfPath = '';
foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', 'wkhtmltopdf'] as $path) {
    if ((strpos($path, '/') !== false && is_executable($path)) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

if ($wkhtmltopdfPath) {
    $pdfPath = tempnam(sys_get_temp_dir(), 'mb_all_') . '.pdf';
    $htmlPath = tempnam(sys_get_temp_dir(), 'mb_all_') . '.html';
    file_put_contents($htmlPath, $html);
    $cmd = escapeshellarg($wkhtmltopdfPath) . ' --page-size A4 --margin-top 12mm --margin-right 12mm --margin-bottom 12mm --margin-left 12mm --encoding UTF-8 --print-media-type ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath);
    shell_exec($cmd . ' 2>&1');
    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
        $pdf_content = file_get_contents($pdfPath);
        @unlink($pdfPath);
        @unlink($htmlPath);
        if ($return_mode) { $GLOBALS['_mb_pdf_content'] = $pdf_content; return; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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
            if ($return_mode) { $GLOBALS['_mb_pdf_content'] = $pdf_content; return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $pdf_content;
            exit;
        } catch (Exception $e) {
            error_log('Dompdf Mängelbericht-alle Fehler: ' . $e->getMessage());
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
            $pdf->SetFont('helvetica', '', 10);
            // Pro Mängelbericht eine eigene Seite – zuverlässig für mehrere Berichte
            $logo_html = '<div class="header">' . get_pdf_logo_html() . '</div>';
            $page_html = '<style>table{width:100%;border-collapse:collapse;font-size:9pt}.label-cell{width:140px;background:#f5f5f5;font-weight:bold;padding:3px 6px;border:1px solid #ddd}.value-cell{padding:3px 6px;border:1px solid #ddd}.section{margin-bottom:8px}.section-title{font-weight:bold;font-size:10pt;margin-bottom:4px}.geraetewart-section{margin-top:10px;padding-top:8px;border-top:2px solid #333}.signature-section{margin-top:10px;padding-top:8px;border-top:1px solid #333}.signature-line{border-bottom:1px solid #333;width:160px;min-height:22px;margin-top:10px;padding-top:4px}</style>';
            foreach ($html_parts as $part) {
                $pdf->AddPage();
                $pdf->writeHTML($page_html . $logo_html . $part, true, false, true, false, '');
            }
            $pdf_content = $pdf->Output('', 'S');
            if ($return_mode) { $GLOBALS['_mb_pdf_content'] = $pdf_content; return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $pdf_content;
            exit;
        } catch (Exception $e) {
            error_log('TCPDF Mängelbericht-alle Fehler: ' . $e->getMessage());
        }
    }
}

if ($return_mode) {
    $GLOBALS['_mb_pdf_content'] = null;
    return;
}
header('Content-Type: text/html; charset=UTF-8');
echo $html;
