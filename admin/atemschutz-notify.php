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
        if (count($templates) === 1) {
            // Nur eine Vorlage
            $template = $templates[0];
            $subject = $template['subject'];
            $body = $template['body'];
        } else {
            // Mehrere Vorlagen kombinieren
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
    
    $html = '<h2>Atemschutz-Hinweis</h2>';
    $html .= '<p>Hallo ' . htmlspecialchars($name) . ',</p>';
    
    if ($hasExpired) {
        $html .= '<p><strong style="color: #dc3545;">ACHTUNG: Mehrere Ihrer Atemschutz-Zertifikate sind abgelaufen!</strong></p>';
    } else {
        $html .= '<p><strong style="color: #ffc107;">Erinnerung: Mehrere Ihrer Atemschutz-Zertifikate laufen bald ab!</strong></p>';
    }
    
    $html .= '<p>Folgende Zertifikate benötigen Ihre Aufmerksamkeit:</p>';
    $html .= '<ul>';
    
    foreach ($certificates as $cert) {
        $status = $cert['urgency'] === 'abgelaufen' ? 'ABGELAUFEN' : 'Läuft bald ab';
        $color = $cert['urgency'] === 'abgelaufen' ? '#dc3545' : '#ffc107';
        $days = $cert['days'];
        
        $html .= '<li>';
        $html .= '<strong>' . htmlspecialchars($cert['name']) . '</strong> - ';
        $html .= '<span style="color: ' . $color . '; font-weight: bold;">' . $status . '</span>';
        $html .= ' (Ablaufdatum: ' . date('d.m.Y', strtotime($cert['expiry_date'])) . ')';
        
        if ($cert['urgency'] === 'abgelaufen') {
            $html .= ' - <strong>Seit ' . $days . ' Tag' . ($days !== 1 ? 'en' : '') . ' abgelaufen!</strong>';
        } else {
            $html .= ' - <strong>Noch ' . $days . ' Tag' . ($days !== 1 ? 'e' : '') . ' gültig</strong>';
        }
        
        $html .= '</li>';
    }
    
    $html .= '</ul>';
    
    if ($hasExpired) {
        $html .= '<p><strong style="color: #dc3545;">WICHTIG: Sie dürfen bis zur Verlängerung nicht am Atemschutz teilnehmen!</strong></p>';
        $html .= '<p>Bitte vereinbaren Sie <strong>SOFORT</strong> einen Termin für die Verlängerung aller abgelaufenen Zertifikate.</p>';
    } else {
        $html .= '<p>Bitte vereinbaren Sie rechtzeitig einen Termin für die Verlängerung der bald ablaufenden Zertifikate.</p>';
    }
    
    $html .= '<p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>';
    
    return $html;
}


