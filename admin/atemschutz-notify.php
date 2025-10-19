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
        
        // Kombinierte E-Mail erstellen
        if (count($certificates) === 1) {
            // Nur ein Zertifikat - verwende die entsprechende Vorlage
            $templateKey = $certificates[0]['type'] . '_' . $certificates[0]['urgency'];
            $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
            $stmt->execute([$templateKey]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                $subject = $template['subject'];
                $body = $template['body'];
            } else {
                // Fallback
                $subject = 'Atemschutz-Zertifikat Benachrichtigung';
                $body = createCombinedAtemschutzEmail($traeger, $certificates, $certificates[0]['urgency'] === 'abgelaufen');
            }
        } else {
            // Mehrere Zertifikate - verwende kombinierte E-Mail
            $hasExpired = in_array('abgelaufen', array_column($certificates, 'urgency'));
            $subjectPrefix = $hasExpired ? 'ACHTUNG: Mehrere Zertifikate sind abgelaufen' : 'Erinnerung: Mehrere Zertifikate laufen bald ab';
            
            $subject = $subjectPrefix;
            $body = createCombinedAtemschutzEmail($traeger, $certificates, $hasExpired);
        }
        
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
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8f9fa; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; font-weight: bold; }
        .header .subtitle { margin: 10px 0 0 0; font-size: 16px; opacity: 0.9; }
        .content { padding: 30px; }
        .alert { padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid; }
        .alert-warning { background-color: #fff3cd; border-color: #ffc107; color: #856404; }
        .alert-danger { background-color: #f8d7da; border-color: #dc3545; color: #721c24; }
        .certificate-list { background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .certificate-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #dee2e6; }
        .certificate-item:last-child { border-bottom: none; }
        .certificate-name { font-weight: bold; color: #495057; }
        .certificate-status { padding: 6px 12px; border-radius: 20px; font-size: 14px; font-weight: bold; }
        .status-expired { background-color: #dc3545; color: white; }
        .status-warning { background-color: #ffc107; color: #212529; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; font-size: 14px; }
        .icon { font-size: 20px; margin-right: 10px; }
        .urgent-notice { background-color: #dc3545; color: white; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="icon">🔥</i> Feuerwehr Atemschutz</h1>
            <div class="subtitle">Zertifikat Benachrichtigung</div>
        </div>
        
        <div class="content">
            <h2>Hallo ' . htmlspecialchars($name) . ',</h2>';
    
    if ($hasExpired) {
        $html .= '<div class="alert alert-danger">
                    <h3><i class="icon">⚠️</i> ACHTUNG: Zertifikate abgelaufen</h3>
                    <p>Folgende Atemschutz-Zertifikate benötigen Ihre Aufmerksamkeit:</p>
                </div>';
    } else {
        $html .= '<div class="alert alert-warning">
                    <h3><i class="icon">⚠️</i> Erinnerung: Zertifikate laufen bald ab</h3>
                    <p>Folgende Atemschutz-Zertifikate benötigen Ihre Aufmerksamkeit:</p>
                </div>';
    }
    
    $html .= '<div class="certificate-list">
                <h4>📋 Zertifikat-Status:</h4>';
    
    foreach ($certificates as $cert) {
        $status = $cert['urgency'] === 'abgelaufen' ? 'Abgelaufen' : 'Läuft bald ab';
        $statusClass = $cert['urgency'] === 'abgelaufen' ? 'status-expired' : 'status-warning';
        $days = $cert['days'];
        
        $html .= '<div class="certificate-item">
                    <div class="certificate-name">' . htmlspecialchars($cert['name']) . '</div>
                    <div class="certificate-status ' . $statusClass . '">' . $status . '</div>
                </div>
                <div style="font-size: 14px; color: #6c757d; margin-top: 5px;">
                    Ablaufdatum: ' . date('d.m.Y', strtotime($cert['expiry_date'])) . '
                </div>';
    }
    
    $html .= '</div>';
    
    if ($hasExpired) {
        $html .= '<div class="urgent-notice">
                    ⚠️ ACHTUNG: Sie dürfen bis zur Verlängerung/Untersuchung nicht am Atemschutz teilnehmen!
                </div>
                <div class="alert alert-danger">
                    <h4>🚨 Sofortige Maßnahmen erforderlich:</h4>
                    <ul>
                        <li>Vereinbaren Sie <strong>SOFORT</strong> die notwendigen Termine</li>
                        <li>Kontaktieren Sie die zuständigen Stellen</li>
                        <li>Informieren Sie Ihre Vorgesetzten über die Situation</li>
                    </ul>
                </div>';
    } else {
        $html .= '<div class="alert alert-warning">
                    <h4>📅 Rechtzeitige Maßnahmen erforderlich:</h4>
                    <ul>
                        <li>Vereinbaren Sie rechtzeitig die notwendigen Termine</li>
                        <li>Kontaktieren Sie die zuständigen Stellen</li>
                        <li>Planen Sie die Verlängerung der Zertifikate</li>
                    </ul>
                </div>';
    }
    
    $html .= '<div style="background-color: #e9ecef; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h4>📞 Kontakt & Unterstützung:</h4>
                <p>Bei Fragen oder Problemen wenden Sie sich bitte an:</p>
                <ul>
                    <li>Ihre direkten Vorgesetzten</li>
                    <li>Die Atemschutz-Abteilung</li>
                    <li>Die Verwaltung</li>
                </ul>
            </div>
            
            <p>Mit freundlichen Grüßen,<br>
            <strong>Ihre Feuerwehr</strong></p>
        </div>
        
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht direkt auf diese E-Mail.</p>
            <p>© 2025 Feuerwehr - Atemschutz Management System</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}


