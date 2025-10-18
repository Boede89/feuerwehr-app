<?php
// Vereinfachte PDF-Generierung mit HTML-to-PDF Ansatz
function generatePDFForDownload($results, $params) {
    $uebungsDatum = $params['uebungsDatum'] ?? '';
    $anzahl = $params['anzahlPaTraeger'] ?? 'alle';
    $statusFilter = $params['statusFilter'] ?? [];
    
    // HTML für PDF generieren
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PA-Träger Liste</title>
    <style>
        @page {
            margin: 20mm;
            size: A4;
        }
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 0;
            line-height: 1.4;
            font-size: 12px;
        }
        .header { 
            text-align: center; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #dc3545;
            padding-bottom: 15px;
        }
        .header h1 { 
            color: #dc3545; 
            margin: 0 0 10px 0; 
            font-size: 24px;
        }
        .header h2 { 
            color: #6c757d; 
            font-size: 16px; 
            margin: 0; 
        }
        .summary { 
            background: #f8f9fa; 
            padding: 12px; 
            border-radius: 5px; 
            margin-bottom: 15px; 
            border-left: 4px solid #dc3545;
        }
        .summary h3 { 
            margin: 0 0 8px 0; 
            color: #495057; 
            font-size: 14px;
        }
        .summary p { 
            margin: 3px 0; 
            font-size: 12px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px; 
            font-size: 10px;
        }
        th, td { 
            border: 1px solid #dee2e6; 
            padding: 6px; 
            text-align: left; 
            vertical-align: top;
        }
        th { 
            background-color: #e9ecef; 
            font-weight: bold; 
            font-size: 11px;
        }
        .status-badge { 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-size: 9px; 
            font-weight: bold; 
            display: inline-block;
        }
        .status-tauglich { background-color: #d4edda; color: #155724; }
        .status-warnung { background-color: #fff3cd; color: #856404; }
        .status-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .status-uebung-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .footer { 
            margin-top: 20px; 
            text-align: center; 
            color: #6c757d; 
            font-size: 10px; 
            border-top: 1px solid #dee2e6;
            padding-top: 10px;
        }
    </style>
</head>
<body>
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
                <th style="width: 40px;">#</th>
                <th>Name</th>
                <th>Status</th>
                <th>Strecke</th>
                <th>G26.3</th>
                <th>Übung/Einsatz</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($results as $index => $traeger) {
        // Debug: Prüfe ob Daten vorhanden sind
        $firstName = $traeger['first_name'] ?? $traeger['name'] ?? '';
        $lastName = $traeger['last_name'] ?? '';
        $name = trim($firstName . ' ' . $lastName);
        
        // Fallback: Wenn name leer ist, versuche 'name' Feld
        if (empty($name)) {
            $name = $traeger['name'] ?? 'Unbekannt';
        }
        
        $status = $traeger['status'] ?? 'Unbekannt';
        $statusClass = 'status-' . strtolower(str_replace([' ', 'ü'], ['-', 'ue'], $status));
        
        $html .= '<tr>
            <td>' . ($index + 1) . '</td>
            <td><strong>' . htmlspecialchars($name) . '</strong></td>
            <td><span class="status-badge ' . $statusClass . '">' . htmlspecialchars($status) . '</span></td>
            <td>' . date('d.m.Y', strtotime($traeger['strecke_am'] ?? 'now')) . '</td>
            <td>' . date('d.m.Y', strtotime($traeger['g263_am'] ?? 'now')) . '</td>
            <td>' . date('d.m.Y', strtotime($traeger['uebung_am'] ?? 'now')) . '<br><small>bis ' . date('d.m.Y', strtotime($traeger['uebung_bis'] ?? 'now')) . '</small></td>
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
?>
