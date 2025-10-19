<?php
require_once '../includes/functions.php';

// Session starten
session_start();

// Prüfen ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert']);
    exit;
}

// Prüfen ob POST-Daten vorhanden sind
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Requests erlaubt']);
    exit;
}

// Daten aus POST-Request lesen
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['results']) || !isset($input['params'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Daten']);
    exit;
}

$results = $input['results'];
$params = $input['params'];

// HTML für PDF generieren
$uebungsDatum = $params['uebungsDatum'] ?? '';
$anzahl = $params['anzahlPaTraeger'] ?? 'alle';
$statusFilter = $params['statusFilter'] ?? [];

// Status-Filter Text generieren
$statusText = '';
if (!empty($statusFilter)) {
    $statusLabels = [
        'Verfügbar' => 'Verfügbar',
        'Übung geplant' => 'Übung geplant',
        'Übung abgelaufen' => 'Übung abgelaufen'
    ];
    $statusNames = array_map(function($status) use ($statusLabels) {
        return $statusLabels[$status] ?? $status;
    }, $statusFilter);
    $statusText = implode(', ', $statusNames);
}

// HTML-Content generieren
$html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PA-Träger Liste - ' . htmlspecialchars($uebungsDatum) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #dc3545;
            padding-bottom: 15px;
        }
        .header h1 {
            color: #dc3545;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .header h2 {
            color: #666;
            margin: 0;
            font-size: 18px;
            font-weight: normal;
        }
        .info-section {
            margin-bottom: 25px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        .info-label {
            font-weight: bold;
            width: 150px;
            color: #495057;
        }
        .info-value {
            color: #212529;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
        }
        th, td {
            border: 1px solid #dee2e6;
            padding: 12px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #dc3545;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        td strong {
            font-weight: bold;
            color: #212529;
        }
        .status-verfügbar {
            color: #28a745;
            font-weight: bold;
        }
        .status-übung-geplant {
            color: #ffc107;
            font-weight: bold;
        }
        .status-übung-abgelaufen {
            color: #6c757d;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
        }
        @media print {
            body { 
                margin: 0; 
                padding: 10mm;
                font-size: 12px;
                line-height: 1.4;
            }
            .header { 
                page-break-inside: avoid; 
                margin-bottom: 20px;
            }
            .info-section {
                page-break-inside: avoid;
                margin-bottom: 15px;
            }
            table { 
                page-break-inside: auto; 
                font-size: 11px;
                margin-top: 10px;
            }
            th, td {
                padding: 8px 6px;
                font-size: 11px;
            }
            tr { 
                page-break-inside: avoid; 
                page-break-after: auto; 
            }
            .footer {
                page-break-inside: avoid;
                margin-top: 20px;
            }
        }
        
        /* Zusätzliche PDF-optimierte Styles */
        @page {
            margin: 15mm;
            size: A4;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>PA-Träger Liste</h1>
        <h2>Übungsdatum: ' . htmlspecialchars($uebungsDatum) . '</h2>
    </div>

    <div class="info-section">
        <div class="info-row">
            <div class="info-label">Anzahl PA-Träger:</div>
            <div class="info-value">' . htmlspecialchars($anzahl) . '</div>
        </div>';

if (!empty($statusText)) {
    $html .= '
        <div class="info-row">
            <div class="info-label">Status-Filter:</div>
            <div class="info-value">' . htmlspecialchars($statusText) . '</div>
        </div>';
}

$html .= '
        <div class="info-row">
            <div class="info-label">Anzahl Ergebnisse:</div>
            <div class="info-value">' . count($results) . ' PA-Träger</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">Nr.</th>
                <th style="width: 25%;">Name</th>
                <th style="width: 20%;">E-Mail</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 15%;">Strecke am</th>
                <th style="width: 10%;">G26.3 am</th>
                <th style="width: 10%;">Übung am</th>
            </tr>
        </thead>
        <tbody>';

foreach ($results as $index => $traeger) {
    $statusClass = 'status-' . strtolower(str_replace(' ', '-', $traeger['status']));
    $streckeAm = $traeger['strecke_am'] ? date('d.m.Y', strtotime($traeger['strecke_am'])) : '-';
    $g263Am = $traeger['g263_am'] ? date('d.m.Y', strtotime($traeger['g263_am'])) : '-';
    $uebungAm = $traeger['uebung_am'] ? date('d.m.Y', strtotime($traeger['uebung_am'])) : '-';
    
    $html .= '
            <tr>
                <td style="text-align: center;">' . ($index + 1) . '</td>
                <td><strong>' . htmlspecialchars($traeger['name']) . '</strong></td>
                <td>' . htmlspecialchars($traeger['email']) . '</td>
                <td class="' . $statusClass . '">' . htmlspecialchars($traeger['status']) . '</td>
                <td style="text-align: center;">' . $streckeAm . '</td>
                <td style="text-align: center;">' . $g263Am . '</td>
                <td style="text-align: center;">' . $uebungAm . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>

    <div class="footer">
        <p>Erstellt am ' . date('d.m.Y H:i') . ' | Feuerwehr Amern</p>
    </div>
    
    <script>
        // Automatisch Druckdialog öffnen beim Laden der Datei
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        };
        
        // Nach dem Drucken Fenster schließen (optional)
        window.onafterprint = function() {
            // Fenster schließen nur wenn es ein Popup ist
            if (window.opener) {
                window.close();
            }
        };
    </script>
</body>
</html>';

// PDF mit wkhtmltopdf generieren (falls verfügbar)
$pdfPath = tempnam(sys_get_temp_dir(), 'pa_traeger_') . '.pdf';
$htmlPath = tempnam(sys_get_temp_dir(), 'pa_traeger_') . '.html';

// HTML in temporäre Datei schreiben
file_put_contents($htmlPath, $html);

// Prüfen ob wkhtmltopdf verfügbar ist
$wkhtmltopdfPath = '';
$possiblePaths = [
    '/usr/bin/wkhtmltopdf',
    '/usr/local/bin/wkhtmltopdf',
    'wkhtmltopdf'
];

foreach ($possiblePaths as $path) {
    if (is_executable($path) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

if ($wkhtmltopdfPath) {
    // PDF mit wkhtmltopdf generieren
    $command = escapeshellarg($wkhtmltopdfPath) . ' --page-size A4 --margin-top 10mm --margin-right 10mm --margin-bottom 10mm --margin-left 10mm --encoding UTF-8 ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath);
    
    $output = shell_exec($command . ' 2>&1');
    
    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
        // PDF erfolgreich generiert
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="PA_Traeger_Liste_' . date('Y-m-d_H-i') . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        
        readfile($pdfPath);
        
        // Temporäre Dateien löschen
        unlink($pdfPath);
        unlink($htmlPath);
        exit;
    }
}

// Fallback: HTML mit PDF-optimiertem Styling
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="PA_Traeger_Liste_' . date('Y-m-d_H-i') . '.html"');

// HTML mit verbessertem Styling für PDF-Druck
echo $html;

// Temporäre Dateien löschen
if (file_exists($htmlPath)) {
    unlink($htmlPath);
}
?>
