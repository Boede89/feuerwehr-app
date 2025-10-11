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
        $name = trim(($t['last_name'] ?? '') . ', ' . ($t['first_name'] ?? ''));
        $subject = 'Hinweis Atemschutz – Überprüfung erforderlich';
        $html = '<h2>Atemschutz-Hinweis</h2>'
              . '<p>Guten Tag ' . htmlspecialchars($name) . ',</p>'
              . '<p>Bitte prüfen Sie Ihre Atemschutz-Termine (Strecke, G26.3, Übung/Einsatz). ' 
              . 'Dieser Hinweis wurde automatisch von der Feuerwehr-App versendet.</p>'
              . '<p>Vielen Dank!</p>';
        try {
            send_email($to, $subject, $html);
            $sent++;
        } catch (Exception $e) {
            // Versandfehler ignorieren, nächster Empfänger
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


