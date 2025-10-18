<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Berechtigung prüfen
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    http_response_code(403);
    echo json_encode(['error' => 'Zugriff verweigert']);
    exit;
}

// POST-Daten empfangen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST-Requests erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige JSON-Daten']);
    exit;
}

$format = $input['format'] ?? '';
$results = $input['results'] ?? [];
$params = $input['params'] ?? [];

if (empty($format) || empty($results)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Parameter']);
    exit;
}

try {
    if ($format === 'pdf') {
        generatePDF($results, $params);
    } elseif ($format === 'excel') {
        generateExcel($results, $params);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unbekanntes Format']);
        exit;
    }
} catch (Exception $e) {
    error_log("Export-Fehler: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Export fehlgeschlagen: ' . $e->getMessage()]);
}

function generatePDF($results, $params) {
    // Einfache PDF-Generierung mit HTML (Browser kann als PDF drucken)
    $html = generatePDFHTML($results, $params);
    
    // HTML-Header setzen
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="pa-traeger-liste-' . date('Y-m-d') . '.html"');
    
    // HTML mit Druck-Styles ausgeben
    echo $html;
}

function generateExcel($results, $params) {
    // CSV-Format für Excel-Kompatibilität
    $csv = generateCSV($results, $params);
    
    // CSV-Header setzen (Excel kann CSV öffnen)
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pa-traeger-liste-' . date('Y-m-d') . '.csv"');
    
    // BOM für UTF-8 hinzufügen (für korrekte Zeichen in Excel)
    echo "\xEF\xBB\xBF";
    echo $csv;
}

function generatePDFHTML($results, $params) {
    $uebungsDatum = $params['uebungsDatum'] ?? '';
    $anzahl = $params['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $params['statusFilter'] ?? [];
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PA-Träger Liste</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.4;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #dc3545;
            padding-bottom: 20px;
        }
        .header h1 { 
            color: #dc3545; 
            margin-bottom: 10px; 
            font-size: 28px;
        }
        .header h2 { 
            color: #6c757d; 
            font-size: 18px; 
            margin-bottom: 20px; 
        }
        .summary { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            border-left: 4px solid #dc3545;
        }
        .summary h3 { 
            margin-top: 0; 
            color: #495057; 
            font-size: 16px;
        }
        .summary p { 
            margin: 5px 0; 
            font-size: 14px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
            font-size: 12px;
        }
        th, td { 
            border: 1px solid #dee2e6; 
            padding: 8px; 
            text-align: left; 
        }
        th { 
            background-color: #e9ecef; 
            font-weight: bold; 
            font-size: 13px;
        }
        .status-badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 11px; 
            font-weight: bold; 
            display: inline-block;
        }
        .status-tauglich { background-color: #d4edda; color: #155724; }
        .status-warnung { background-color: #fff3cd; color: #856404; }
        .status-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .status-uebung-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .footer { 
            margin-top: 30px; 
            text-align: center; 
            color: #6c757d; 
            font-size: 12px; 
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
        }
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .print-button:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        🖨️ Als PDF drucken
    </button>
    
    <div class="header">
        <h1>🔥 Feuerwehr App</h1>
        <h2>PA-Träger Liste für Übung</h2>
    </div>
    
    <div class="summary">
        <h3>Suchkriterien</h3>
        <p><strong>Übungsdatum:</strong> ' . date('d.m.Y', strtotime($uebungsDatum)) . '</p>
        <p><strong>Anzahl:</strong> ' . ($anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger') . '</p>
        <p><strong>Status-Filter:</strong> ' . implode(', ', $statusFilter) . '</p>
        <p><strong>Gefunden:</strong> ' . count($results) . ' PA-Träger</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>Name</th>
                <th>Status</th>
                <th>Strecke</th>
                <th>G26.3</th>
                <th>Übung/Einsatz</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($results as $index => $traeger) {
        $statusClass = 'status-' . strtolower(str_replace([' ', 'ü'], ['-', 'ue'], $traeger['status']));
        $html .= '<tr>
            <td>' . ($index + 1) . '</td>
            <td><strong>' . htmlspecialchars($traeger['name']) . '</strong></td>
            <td><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($traeger['status']) . '</span></td>
            <td>' . date('d.m.Y', strtotime($traeger['strecke_am'])) . '</td>
            <td>' . date('d.m.Y', strtotime($traeger['g263_am'])) . '</td>
            <td>' . date('d.m.Y', strtotime($traeger['uebung_am'])) . '<br><small>bis ' . date('d.m.Y', strtotime($traeger['uebung_bis'])) . '</small></td>
        </tr>';
    }
    
    $html .= '</tbody>
    </table>
    
    <div class="footer">
        <p>Erstellt am ' . date('d.m.Y H:i') . ' | Feuerwehr App v2.1</p>
    </div>
</body>
</html>';
    
    return $html;
}

function generateCSV($results, $params) {
    $uebungsDatum = $params['uebungsDatum'] ?? '';
    $anzahl = $params['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $params['statusFilter'] ?? [];
    
    $csv = "PA-Träger Liste für Übung\n";
    $csv .= "Erstellt am: " . date('d.m.Y H:i') . "\n";
    $csv .= "Übungsdatum: " . date('d.m.Y', strtotime($uebungsDatum)) . "\n";
    $csv .= "Anzahl: " . ($anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger') . "\n";
    $csv .= "Status-Filter: " . implode(', ', $statusFilter) . "\n";
    $csv .= "Gefunden: " . count($results) . " PA-Träger\n\n";
    
    $csv .= "#,Name,Status,Strecke,G26.3,Übung/Einsatz,Gültig bis\n";
    
    foreach ($results as $index => $traeger) {
        $csv .= ($index + 1) . ',';
        $csv .= '"' . $traeger['name'] . '",';
        $csv .= '"' . $traeger['status'] . '",';
        $csv .= date('d.m.Y', strtotime($traeger['strecke_am'])) . ',';
        $csv .= date('d.m.Y', strtotime($traeger['g263_am'])) . ',';
        $csv .= date('d.m.Y', strtotime($traeger['uebung_am'])) . ',';
        $csv .= date('d.m.Y', strtotime($traeger['uebung_bis'])) . "\n";
    }
    
    return $csv;
}
?>
