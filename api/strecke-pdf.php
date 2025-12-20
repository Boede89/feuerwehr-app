<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}

// Alle zukünftigen Termine mit Zuordnungen laden
$stmt = $db->prepare("
    SELECT t.*, 
           GROUP_CONCAT(CONCAT(at.first_name, ' ', at.last_name) ORDER BY at.last_name SEPARATOR '||') as teilnehmer_liste,
           COUNT(sz.id) as anzahl_teilnehmer
    FROM strecke_termine t
    LEFT JOIN strecke_zuordnungen sz ON t.id = sz.termin_id
    LEFT JOIN atemschutz_traeger at ON sz.traeger_id = at.id
    WHERE t.termin_datum >= CURDATE()
    GROUP BY t.id
    ORDER BY t.termin_datum ASC, t.termin_zeit ASC
");
$stmt->execute();
$termine = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nicht zugeordnete Geräteträger
$stmt = $db->prepare("
    SELECT at.first_name, at.last_name, 
           DATE_ADD(at.strecke_am, INTERVAL 1 YEAR) as strecke_bis,
           DATEDIFF(DATE_ADD(at.strecke_am, INTERVAL 1 YEAR), CURDATE()) as tage_bis_ablauf
    FROM atemschutz_traeger at
    LEFT JOIN strecke_zuordnungen sz ON at.id = sz.traeger_id
    WHERE at.status = 'Aktiv' AND sz.id IS NULL
    ORDER BY tage_bis_ablauf ASC
");
$stmt->execute();
$nichtZugeordnet = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Warnschwelle laden
$warnDays = 90;
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false && is_numeric($val)) { $warnDays = (int)$val; }
} catch (Exception $e) {}

// HTML für PDF (Browser-Druck)
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Übungsstrecke - Planungsübersicht</title>
    <style>
        @page {
            size: A4;
            margin: 15mm;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            margin: 0 0 5px 0;
            font-size: 22pt;
            color: #333;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 10pt;
        }
        
        .summary {
            background: #f0f4ff;
            border: 1px solid #667eea;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 20px;
        }
        
        .summary-item {
            display: inline-block;
            margin-right: 30px;
        }
        
        .summary-item strong {
            color: #667eea;
        }
        
        h2 {
            font-size: 14pt;
            color: #667eea;
            border-bottom: 2px solid #eee;
            padding-bottom: 8px;
            margin: 25px 0 15px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10pt;
        }
        
        th {
            background: #667eea;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-weight: bold;
        }
        
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }
        
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 9pt;
            font-weight: bold;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .teilnehmer-liste {
            margin: 0;
            padding-left: 0;
            list-style: none;
        }
        
        .teilnehmer-liste li {
            padding: 2px 0;
        }
        
        .teilnehmer-liste li:before {
            content: "• ";
            color: #667eea;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 9pt;
            color: #999;
        }
        
        .no-data {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .print-btn:hover {
            background: #5a6fd6;
        }
        
        @media print {
            .print-btn {
                display: none !important;
            }
            
            body {
                padding: 0;
            }
            
            .header {
                page-break-after: avoid;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">
        🖨️ Drucken / Als PDF speichern
    </button>
    
    <div class="header">
        <h1>🔥 Übungsstrecke - Planungsübersicht</h1>
        <div class="subtitle">Erstellt am <?php echo date('d.m.Y'); ?> um <?php echo date('H:i'); ?> Uhr</div>
    </div>
    
    <div class="summary">
        <span class="summary-item">
            <strong><?php echo count($termine); ?></strong> Termine geplant
        </span>
        <span class="summary-item">
            <strong><?php echo count($nichtZugeordnet); ?></strong> Geräteträger nicht zugeordnet
        </span>
        <span class="summary-item">
            <strong><?php 
                $gesamt = 0;
                foreach ($termine as $t) $gesamt += $t['anzahl_teilnehmer'];
                echo $gesamt;
            ?></strong> Zuordnungen insgesamt
        </span>
    </div>
    
    <h2>📅 Geplante Termine</h2>
    
    <?php if (empty($termine)): ?>
        <div class="no-data">Keine zukünftigen Termine vorhanden.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 100px;">Datum</th>
                    <th style="width: 70px;">Uhrzeit</th>
                    <th style="width: 150px;">Ort</th>
                    <th>Teilnehmer</th>
                    <th style="width: 60px;">Anzahl</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($termine as $termin): 
                    $teilnehmerArray = $termin['teilnehmer_liste'] ? explode('||', $termin['teilnehmer_liste']) : [];
                ?>
                <tr>
                    <td><strong><?php echo date('d.m.Y', strtotime($termin['termin_datum'])); ?></strong></td>
                    <td><?php echo date('H:i', strtotime($termin['termin_zeit'])); ?> Uhr</td>
                    <td><?php echo htmlspecialchars($termin['ort'] ?: '-'); ?></td>
                    <td>
                        <?php if (empty($teilnehmerArray)): ?>
                            <em style="color: #999;">Noch keine Zuordnungen</em>
                        <?php else: ?>
                            <ul class="teilnehmer-liste">
                                <?php foreach ($teilnehmerArray as $tn): ?>
                                    <li><?php echo htmlspecialchars($tn); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $termin['anzahl_teilnehmer'] >= $termin['max_teilnehmer'] ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $termin['anzahl_teilnehmer']; ?>/<?php echo $termin['max_teilnehmer']; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <h2>⏳ Nicht zugeordnete Geräteträger</h2>
    
    <?php if (empty($nichtZugeordnet)): ?>
        <div class="no-data" style="color: #155724; background: #d4edda; border-radius: 8px;">
            ✅ Alle aktiven Geräteträger sind zugeordnet!
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th style="width: 120px;">Strecke gültig bis</th>
                    <th style="width: 100px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($nichtZugeordnet as $gt): 
                    $tage = $gt['tage_bis_ablauf'];
                    if ($tage === null) {
                        $badgeClass = 'badge-warning';
                        $statusText = 'Kein Datum';
                    } elseif ($tage < 0) {
                        $badgeClass = 'badge-danger';
                        $statusText = 'Abgelaufen';
                    } elseif ($tage <= $warnDays) {
                        $badgeClass = 'badge-warning';
                        $statusText = $tage . ' Tage';
                    } else {
                        $badgeClass = 'badge-success';
                        $statusText = $tage . ' Tage';
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($gt['first_name'] . ' ' . $gt['last_name']); ?></td>
                    <td><?php echo $gt['strecke_bis'] ? date('d.m.Y', strtotime($gt['strecke_bis'])) : '-'; ?></td>
                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <div class="footer">
        Feuerwehr App - Übungsstrecken-Terminplanung<br>
        Dieses Dokument wurde automatisch generiert.
    </div>
    
    <script>
        // Automatisch Druckdialog öffnen
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>


