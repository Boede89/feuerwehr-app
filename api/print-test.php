<?php
/**
 * Testdruck für Druckereinstellungen einer Einheit.
 * Sendet eine einfache Testseite an den konfigurierten Drucker.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/print-helper.inc.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Bitte eine Einheit auswählen.']);
    exit;
}

$config = print_get_printer_config($db, $einheit_id);
if (empty($config['printer'])) {
    echo json_encode(['success' => false, 'message' => 'Kein Drucker konfiguriert. Bitte zuerst Druckername und ggf. CUPS-Server eintragen und speichern.']);
    exit;
}

$einheit_name = '';
try {
    $stmt = $db->prepare("SELECT name FROM einheiten WHERE id = ?");
    $stmt->execute([$einheit_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_name = $row['name'] ?? 'Einheit';
} catch (Exception $e) {}

$html = '<div style="font-family:sans-serif;padding:20px;text-align:center;">
<h1>Testdruck</h1>
<p><strong>Feuerwehr-App</strong></p>
<p>Einheit: ' . htmlspecialchars($einheit_name) . '</p>
<p>Datum: ' . date('d.m.Y') . ' ' . date('H:i') . ' Uhr</p>
<p style="margin-top:40px;font-size:12px;color:#666;">Wenn dieser Ausdruck erscheint, ist die Druckerkonfiguration korrekt.</p>
</div>';

$pdf_content = null;

$tcpdfPath = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) $tcpdfPath = __DIR__ . '/../tcpdf/tcpdf.php';
if (file_exists($tcpdfPath) && class_exists('TCPDF', false) === false) {
    require_once $tcpdfPath;
}
if (class_exists('TCPDF')) {
    try {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Feuerwehr App');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 11);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf_content = $pdf->Output('', 'S');
    } catch (Exception $e) {
        error_log('TCPDF Testdruck Fehler: ' . $e->getMessage());
    }
}

if (empty($pdf_content) || strlen($pdf_content) < 100) {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        if (class_exists('Dompdf\Dompdf')) {
            try {
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
                $dompdf->loadHtml('<html><body>' . $html . '</body></html>', 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                $pdf_content = $dompdf->output();
            } catch (Exception $e) {
                error_log('Dompdf Testdruck Fehler: ' . $e->getMessage());
            }
        }
    }
}

if (empty($pdf_content) || strlen($pdf_content) < 100) {
    echo json_encode([
        'success' => false,
        'message' => 'PDF konnte nicht erzeugt werden. Bitte TCPDF oder Dompdf installieren (z.B. composer require tecnickcom/tcpdf).',
    ]);
    exit;
}

$result = print_send_pdf($pdf_content, $config, true);
if (!isset($result['debug'])) {
    $result['debug'] = [
        'printer' => $config['printer'],
        'cups_server' => $config['cups_server'] ?: '(Standard)',
    ];
}
echo json_encode($result);
