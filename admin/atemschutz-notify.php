<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    error_log("ATEMSCHUTZ NOTIFY: Access denied - user_id: " . ($_SESSION['user_id'] ?? 'null') . ", atemschutz: " . (has_permission('atemschutz') ? 'true' : 'false') . ", admin: " . (hasAdminPermission() ? 'true' : 'false'));
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

// JSON Body einlesen
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) { $payload = []; }

$sendAll = !empty($payload['all']);
$ids = [];
if (!$sendAll) {
    if (isset($payload['ids']) && is_array($payload['ids'])) {
        foreach ($payload['ids'] as $v) {
            $v = (int)$v; if ($v > 0) { $ids[] = $v; }
        }
        $ids = array_values(array_unique($ids));
    }
    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'no_ids']);
        exit;
    }
}

try {
    $now = new DateTime('now');

    // Sicherstellen, dass Spalte last_notified_at existiert
    try {
        $col = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atemschutz_traeger' AND COLUMN_NAME = 'last_notified_at'");
        $col->execute();
        $exists = (int)$col->fetchColumn() > 0;
        if (!$exists) {
            $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN last_notified_at DATETIME NULL");
        }
    } catch (Exception $e) { /* ignore */ }

    // Warnschwelle laden
    $warnDays = 90;
    try {
        $s = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='atemschutz_warn_days' LIMIT 1");
        $s->execute();
        $val = $s->fetchColumn();
        if ($val !== false && is_numeric($val)) { $warnDays = (int)$val; }
    } catch (Exception $e) {}

    // Kandidaten ermitteln
    if ($sendAll) {
        $stmt = $db->prepare("SELECT id, first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am FROM atemschutz_traeger");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Filtern auf auffällige (wie im Dashboard)
        $nowDate = new DateTime('today');
        $ids = [];
        foreach ($rows as $r) {
            $age = 0; if (!empty($r['birthdate'])) { try { $age = (new DateTime($r['birthdate']))->diff($nowDate)->y; } catch (Exception $e) {} }
            $streckeBis = !empty($r['strecke_am']) ? (new DateTime($r['strecke_am']))->modify('+1 year') : null;
            $g263Bis = null; if (!empty($r['g263_am'])) { $g = new DateTime($r['g263_am']); $g->modify(($age < 50 ? '+3 year' : '+1 year')); $g263Bis = $g; }
            $uebungBis = !empty($r['uebung_am']) ? (new DateTime($r['uebung_am']))->modify('+1 year') : null;
            $streckeDiff = $streckeBis ? (int)$nowDate->diff($streckeBis)->format('%r%a') : null;
            $g263Diff = $g263Bis ? (int)$nowDate->diff($g263Bis)->format('%r%a') : null;
            $uebungDiff = $uebungBis ? (int)$nowDate->diff($uebungBis)->format('%r%a') : null;
            $streckeExpired = $streckeDiff !== null && $streckeDiff < 0;
            $g263Expired = $g263Diff !== null && $g263Diff < 0;
            $uebungExpired = $uebungDiff !== null && $uebungDiff < 0;
            $anyWarn = ($streckeDiff !== null && $streckeDiff <= $warnDays && $streckeDiff >= 0)
                     || ($g263Diff !== null && $g263Diff <= $warnDays && $g263Diff >= 0)
                     || ($uebungDiff !== null && $uebungDiff <= $warnDays && $uebungDiff >= 0);
            $status = 'tauglich';
            if ($streckeExpired || $g263Expired) { $status = 'abgelaufen'; }
            elseif ($uebungExpired && !$streckeExpired && !$g263Expired) { $status = 'uebung_abgelaufen'; }
            elseif ($anyWarn) { $status = 'warnung'; }
            if (in_array($status, ['warnung','uebung_abgelaufen','abgelaufen'], true)) {
                $ids[] = (int)$r['id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, fn($v)=>$v>0)));
    }

    if (empty($ids)) {
        echo json_encode(['success' => true, 'sent' => 0]);
        exit;
    }

    // Empfängerdaten holen
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM atemschutz_traeger WHERE id IN ($place)");
    $stmt->execute($ids);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sent = 0;
    foreach ($targets as $t) {
        $to = trim((string)($t['email'] ?? ''));
        if ($to === '') { continue; }
        
        // Detaillierte Zertifikats-Informationen für diesen Geräteträger laden
        $stmt = $db->prepare("
            SELECT 
                id, first_name, last_name, email,
                strecke_am, g263_am, uebung_am,
                CASE 
                    WHEN strecke_am IS NULL THEN NULL
                    ELSE DATEDIFF(strecke_am, CURDATE())
                END as strecke_diff,
                CASE 
                    WHEN g263_am IS NULL THEN NULL
                    ELSE DATEDIFF(g263_am, CURDATE())
                END as g263_diff,
                CASE 
                    WHEN uebung_am IS NULL THEN NULL
                    ELSE DATEDIFF(uebung_am, CURDATE())
                END as uebung_diff
            FROM atemschutz_traeger 
            WHERE id = ?
        ");
        $stmt->execute([$t['id']]);
        $traeger = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$traeger) { continue; }
        
        // Problematic certificates sammeln
        $certificates = [];
        $warnDays = 90; // Standard-Warnung
        
        // Warnschwelle aus Einstellungen laden
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) { 
                $warnDays = (int)$val; 
            }
        } catch (Exception $e) { /* ignore */ }
        
        // Strecke prüfen
        if ($traeger['strecke_diff'] !== null) {
            if ($traeger['strecke_diff'] < 0) {
                $certificates[] = [
                    'type' => 'strecke',
                    'name' => 'Strecke-Zertifikat',
                    'expiry_date' => $traeger['strecke_am'],
                    'urgency' => 'abgelaufen',
                    'days' => abs($traeger['strecke_diff'])
                ];
            } elseif ($traeger['strecke_diff'] <= $warnDays) {
                $certificates[] = [
                    'type' => 'strecke',
                    'name' => 'Strecke-Zertifikat',
                    'expiry_date' => $traeger['strecke_am'],
                    'urgency' => 'warnung',
                    'days' => $traeger['strecke_diff']
                ];
            }
        }
        
        // G26.3 prüfen
        if ($traeger['g263_diff'] !== null) {
            if ($traeger['g263_diff'] < 0) {
                $certificates[] = [
                    'type' => 'g263',
                    'name' => 'G26.3-Zertifikat',
                    'expiry_date' => $traeger['g263_am'],
                    'urgency' => 'abgelaufen',
                    'days' => abs($traeger['g263_diff'])
                ];
            } elseif ($traeger['g263_diff'] <= $warnDays) {
                $certificates[] = [
                    'type' => 'g263',
                    'name' => 'G26.3-Zertifikat',
                    'expiry_date' => $traeger['g263_am'],
                    'urgency' => 'warnung',
                    'days' => $traeger['g263_diff']
                ];
            }
        }
        
        // Übung prüfen
        if ($traeger['uebung_diff'] !== null) {
            if ($traeger['uebung_diff'] < 0) {
                $certificates[] = [
                    'type' => 'uebung',
                    'name' => 'Übung/Einsatz',
                    'expiry_date' => $traeger['uebung_am'],
                    'urgency' => 'abgelaufen',
                    'days' => abs($traeger['uebung_diff'])
                ];
            } elseif ($traeger['uebung_diff'] <= $warnDays) {
                $certificates[] = [
                    'type' => 'uebung',
                    'name' => 'Übung/Einsatz',
                    'expiry_date' => $traeger['uebung_am'],
                    'urgency' => 'warnung',
                    'days' => $traeger['uebung_diff']
                ];
            }
        }
        
        if (empty($certificates)) { continue; }
        
        // E-Mail-Vorlagen laden und kombinieren
        $templates = [];
        foreach ($certificates as $cert) {
            $templateKey = $cert['type'] . '_' . $cert['urgency'];
            $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
            $stmt->execute([$templateKey]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                $templates[] = $template;
            }
        }
        
        if (empty($templates)) { continue; }
        
        // E-Mail erstellen (sowohl für einzelne als auch mehrere Zertifikate)
        $hasExpired = in_array('abgelaufen', array_column($certificates, 'urgency'));
        
        if (count($certificates) === 1) {
            // Einzelnes Zertifikat - verwende schönes Design
            $cert = $certificates[0];
            $subjectPrefix = $hasExpired ? 'ACHTUNG: Zertifikat abgelaufen' : 'Erinnerung: Zertifikat läuft bald ab';
            $subject = $subjectPrefix . ' - ' . $cert['name'];
        } else {
            // Mehrere Zertifikate
            $subjectPrefix = $hasExpired ? 'ACHTUNG: Mehrere Zertifikate sind abgelaufen' : 'Erinnerung: Mehrere Zertifikate laufen bald ab';
            $subject = $subjectPrefix;
        }
        
        // Verwende immer das schöne Design
        $body = createCombinedAtemschutzEmail($traeger, $certificates, $hasExpired);
        
        // Platzhalter ersetzen
        $subject = str_replace(
            ['{first_name}', '{last_name}', '{expiry_date}'],
            [$traeger['first_name'], $traeger['last_name'], $certificates[0]['expiry_date'] ?? ''],
            $subject
        );
        
        $body = str_replace(
            ['{first_name}', '{last_name}', '{expiry_date}'],
            [$traeger['first_name'], $traeger['last_name'], $certificates[0]['expiry_date'] ?? ''],
            $body
        );
        
        try {
            send_email($to, $subject, $body, '', true); // HTML-E-Mail aktivieren
            $sent++;
        } catch (Exception $e) {
            error_log("E-Mail-Versand fehlgeschlagen für {$traeger['first_name']} {$traeger['last_name']}: " . $e->getMessage());
        }
    }

    // last_notified_at aktualisieren
    try {
        if ($sent > 0) {
            $stmtU = $db->prepare("UPDATE atemschutz_traeger SET last_notified_at = ? WHERE id IN ($place)");
            $params = array_merge([$now->format('Y-m-d H:i:s')], $ids);
            $stmtU->execute($params);
        }
    } catch (Exception $e) { /* ignore */ }

    echo json_encode(['success' => true, 'sent' => $sent]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Erstellt eine kombinierte E-Mail für mehrere abgelaufene/bald ablaufende Zertifikate
 */
function createCombinedAtemschutzEmail($traeger, $certificates, $hasExpired) {
    $name = $traeger['first_name'] . ' ' . $traeger['last_name'];
    
    $html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atemschutz-Zertifikat Benachrichtigung</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; 
            line-height: 1.6; 
            color: #2c3e50; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .email-container { 
            max-width: 650px; 
            margin: 0 auto; 
            background: #ffffff; 
            border-radius: 16px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header { 
            background: linear-gradient(135deg, #e74c3c, #c0392b); 
            color: white; 
            padding: 40px 30px; 
            text-align: center;
            position: relative;
        }
        .header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-rule="evenodd"%3E%3Cg fill="%23ffffff" fill-opacity="0.1"%3E%3Ccircle cx="30" cy="30" r="2"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
        }
        .header-content { position: relative; z-index: 1; }
        .header h1 { 
            margin: 0; 
            font-size: 28px; 
            font-weight: 700; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .header .subtitle { 
            margin: 12px 0 0 0; 
            font-size: 16px; 
            opacity: 0.95; 
            font-weight: 300;
        }
        .content { padding: 40px 30px; }
        .greeting {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 25px;
            font-weight: 500;
        }
        .alert { 
            padding: 25px; 
            border-radius: 12px; 
            margin: 25px 0; 
            border-left: 6px solid;
            position: relative;
            overflow: hidden;
        }
        .alert::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        }
        .alert-warning { 
            background: linear-gradient(135deg, #fff3cd, #ffeaa7); 
            border-color: #f39c12; 
            color: #8b6914;
        }
        .alert-danger { 
            background: linear-gradient(135deg, #f8d7da, #fab1a0); 
            border-color: #e74c3c; 
            color: #721c24;
        }
        .alert h3 {
            font-size: 18px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        .certificate-list { 
            background: linear-gradient(135deg, #f8f9fa, #e9ecef); 
            border-radius: 12px; 
            padding: 25px; 
            margin: 25px 0;
            border: 1px solid #dee2e6;
        }
        .certificate-list h4 {
            color: #495057;
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: 600;
        }
        .certificate-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 15px 0; 
            border-bottom: 1px solid #dee2e6;
            transition: background-color 0.3s ease;
        }
        .certificate-item:hover {
            background-color: rgba(255,255,255,0.5);
            border-radius: 8px;
            padding-left: 10px;
            padding-right: 10px;
        }
        .certificate-item:last-child { border-bottom: none; }
        .certificate-name { 
            font-weight: 600; 
            color: #2c3e50;
            font-size: 15px;
        }
        .certificate-status { 
            padding: 8px 16px; 
            border-radius: 25px; 
            font-size: 13px; 
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-expired { 
            background: linear-gradient(135deg, #e74c3c, #c0392b); 
            color: white;
            box-shadow: 0 2px 8px rgba(231, 76, 60, 0.3);
        }
        .status-warning { 
            background: linear-gradient(135deg, #f39c12, #e67e22); 
            color: white;
            box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3);
        }
        .certificate-details {
            font-size: 13px; 
            color: #6c757d; 
            margin-top: 8px;
            font-style: italic;
        }
        .urgent-notice { 
            background: linear-gradient(135deg, #e74c3c, #c0392b); 
            color: white; 
            padding: 20px; 
            border-radius: 12px; 
            margin: 25px 0; 
            text-align: center; 
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
        }
        .action-list {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .action-list h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .action-list ul {
            list-style: none;
            padding: 0;
        }
        .action-list li {
            padding: 8px 0;
            position: relative;
            padding-left: 25px;
        }
        .action-list li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #27ae60;
            font-weight: bold;
        }
        .contact-info { 
            background: linear-gradient(135deg, #e9ecef, #f8f9fa); 
            padding: 25px; 
            border-radius: 12px; 
            margin: 25px 0;
            border: 1px solid #dee2e6;
        }
        .contact-info h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .contact-info ul {
            list-style: none;
            padding: 0;
        }
        .contact-info li {
            padding: 5px 0;
            position: relative;
            padding-left: 20px;
        }
        .contact-info li::before {
            content: "📞";
            position: absolute;
            left: 0;
        }
        .footer { 
            background: linear-gradient(135deg, #34495e, #2c3e50); 
            color: #bdc3c7; 
            padding: 25px; 
            text-align: center; 
            font-size: 13px;
        }
        .footer p {
            margin: 5px 0;
        }
        .icon { 
            font-size: 20px; 
            margin-right: 10px; 
        }
        .signature {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
            font-size: 16px;
        }
        .signature strong {
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="header-content">
                <h1>🔥 Feuerwehr Atemschutz</h1>
                <div class="subtitle">Zertifikat Benachrichtigung</div>
            </div>
        </div>
        
        <div class="content">
            <div class="greeting">Hallo ' . htmlspecialchars($name) . ',</div>';
    
    $certCount = count($certificates);
    $isMultiple = $certCount > 1;
    
    if ($hasExpired) {
        if ($isMultiple) {
            $html .= '<div class="alert alert-danger">
                        <h3><i class="icon">🚨</i> ACHTUNG: Zertifikate abgelaufen</h3>
                        <p>Folgende Atemschutz-Zertifikate benötigen Ihre <strong>sofortige</strong> Aufmerksamkeit:</p>
                    </div>';
        } else {
            $html .= '<div class="alert alert-danger">
                        <h3><i class="icon">🚨</i> ACHTUNG: Zertifikat abgelaufen</h3>
                        <p>Ihr Atemschutz-Zertifikat benötigt Ihre <strong>sofortige</strong> Aufmerksamkeit:</p>
                    </div>';
        }
    } else {
        if ($isMultiple) {
            $html .= '<div class="alert alert-warning">
                        <h3><i class="icon">⏰</i> Erinnerung: Zertifikate laufen bald ab</h3>
                        <p>Folgende Atemschutz-Zertifikate benötigen Ihre Aufmerksamkeit:</p>
                    </div>';
        } else {
            $html .= '<div class="alert alert-warning">
                        <h3><i class="icon">⏰</i> Erinnerung: Zertifikat läuft bald ab</h3>
                        <p>Ihr Atemschutz-Zertifikat benötigt Ihre Aufmerksamkeit:</p>
                    </div>';
        }
    }
    
    $html .= '<div class="certificate-list">
                <h4>' . ($isMultiple ? '📋 Zertifikat-Status Übersicht' : '📋 Zertifikat-Details') . '</h4>';
    
    foreach ($certificates as $cert) {
        $status = $cert['urgency'] === 'abgelaufen' ? 'Abgelaufen' : 'Läuft bald ab';
        $statusClass = $cert['urgency'] === 'abgelaufen' ? 'status-expired' : 'status-warning';
        $days = $cert['days'];
        $icon = $cert['urgency'] === 'abgelaufen' ? '🚨' : '⚠️';
        
        // Bestimme das passende Icon und die Beschreibung für den Zertifikatstyp
        $certIcon = '';
        $certDescription = '';
        switch ($cert['type']) {
            case 'strecke':
                $certIcon = '🏃‍♂️';
                $certDescription = 'Strecke-Zertifikat';
                break;
            case 'g263':
                $certIcon = '🎯';
                $certDescription = 'G26.3-Zertifikat';
                break;
            case 'uebung':
                $certIcon = '🔥';
                $certDescription = 'Übung/Einsatz-Zertifikat';
                break;
            default:
                $certIcon = '📜';
                $certDescription = $cert['name'];
        }
        
        $html .= '<div class="certificate-item">
                    <div class="certificate-name">' . $icon . ' ' . $certIcon . ' ' . $certDescription . '</div>
                    <div class="certificate-status ' . $statusClass . '">' . $status . '</div>
                </div>
                <div class="certificate-details">
                    <strong>Ablaufdatum:</strong> ' . date('d.m.Y', strtotime($cert['expiry_date'])) . 
                    ($cert['urgency'] === 'abgelaufen' ? 
                        ' - <strong style="color: #e74c3c;">Seit ' . $days . ' Tag' . ($days !== 1 ? 'en' : '') . ' abgelaufen!</strong>' : 
                        ' - <strong style="color: #f39c12;">Noch ' . $days . ' Tag' . ($days !== 1 ? 'e' : '') . ' gültig</strong>') . '
                </div>';
    }
    
    $html .= '</div>';
    
    if ($hasExpired) {
        $html .= '<div class="urgent-notice">
                    🚨 ACHTUNG: Sie dürfen bis zur Verlängerung/Untersuchung nicht am Atemschutz teilnehmen!
                </div>
                <div class="action-list">
                    <h4>🚨 Sofortige Maßnahmen erforderlich:</h4>
                    <ul>
                        <li>Vereinbaren Sie <strong>SOFORT</strong> die notwendigen Termine</li>
                        <li>Kontaktieren Sie die zuständigen Stellen</li>
                        <li>Informieren Sie Ihre Vorgesetzten über die Situation</li>
                        ' . ($isMultiple ? '<li>Melden Sie sich von geplanten Atemschutz-Einsätzen ab</li>' : '<li>Melden Sie sich von geplanten Atemschutz-Einsätzen ab</li>') . '
                    </ul>
                </div>';
    } else {
        $html .= '<div class="action-list">
                    <h4>📅 Rechtzeitige Maßnahmen erforderlich:</h4>
                    <ul>
                        <li>Vereinbaren Sie rechtzeitig die notwendigen Termine</li>
                        <li>Kontaktieren Sie die zuständigen Stellen</li>
                        ' . ($isMultiple ? '<li>Planen Sie die Verlängerung der Zertifikate</li>' : '<li>Planen Sie die Verlängerung des Zertifikats</li>') . '
                        <li>Informieren Sie sich über verfügbare Termine</li>
                    </ul>
                </div>';
    }
    
    $html .= '<div class="contact-info">
                <h4>📞 Kontakt & Unterstützung</h4>
                <p>Bei Fragen oder Problemen wenden Sie sich bitte an:</p>
                <ul>
                    <li>Ihre direkten Vorgesetzten</li>
                    <li>Die Atemschutz-Abteilung</li>
                    <li>Die Verwaltung</li>
                    <li>Den zuständigen Ausbilder</li>
                </ul>
            </div>
            
            <div class="signature">
                <p>Mit freundlichen Grüßen,<br>
                <strong>Ihre Feuerwehr</strong></p>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>Diese E-Mail wurde automatisch generiert</strong></p>
            <p>Bitte antworten Sie nicht direkt auf diese E-Mail</p>
            <p>© 2025 Feuerwehr - Atemschutz Management System</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}


