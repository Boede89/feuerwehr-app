<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !has_permission('atemschutz')) {
    echo json_encode(['success'=>false,'error'=>'forbidden']);
    exit;
}

// Body lesen
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];

try {
    $warnDays = 90;
    try {
        $s = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='atemschutz_warn_days' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && is_numeric($v)) { $warnDays = (int)$v; }
    } catch (Exception $e) {}

    $ids = [];
    if (!empty($payload['all'])) {
        // Alle auffälligen mit E-Mail
        $stmt = $db->prepare("SELECT * FROM atemschutz_traeger WHERE email IS NOT NULL AND email <> ''");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ids = array_map('intval', $payload['ids'] ?? []);
        if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'no_ids']); exit; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM atemschutz_traeger WHERE id IN ($in)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $now = new DateTime('today');
    $sent = 0; $errors = [];
    foreach ($rows as $r) {
        $email = trim($r['email'] ?? '');
        if ($email === '') continue;

        // Alter und Bis-Daten
        $age = 0; if (!empty($r['birthdate'])) { try { $age = (new DateTime($r['birthdate']))->diff($now)->y; } catch (Exception $e) {} }
        $streckeBis = !empty($r['strecke_am']) ? (new DateTime($r['strecke_am']))->modify('+1 year') : null;
        $g263Bis = null; if (!empty($r['g263_am'])) { $g = new DateTime($r['g263_am']); $g->modify(($age < 50 ? '+3 year' : '+1 year')); $g263Bis = $g; }
        $uebungBis = !empty($r['uebung_am']) ? (new DateTime($r['uebung_am']))->modify('+1 year') : null;
        $streckeDiff = $streckeBis ? (int)$now->diff($streckeBis)->format('%r%a') : null;
        $g263Diff = $g263Bis ? (int)$now->diff($g263Bis)->format('%r%a') : null;
        $uebungDiff = $uebungBis ? (int)$now->diff($uebungBis)->format('%r%a') : null;

        $streckeExpired = $streckeDiff !== null && $streckeDiff < 0;
        $g263Expired = $g263Diff !== null && $g263Diff < 0;
        $uebungExpired = $uebungDiff !== null && $uebungDiff < 0;
        $anyWarn = ($streckeDiff !== null && $streckeDiff <= $warnDays && $streckeDiff >= 0)
                 || ($g263Diff !== null && $g263Diff <= $warnDays && $g263Diff >= 0)
                 || ($uebungDiff !== null && $uebungDiff <= $warnDays && $uebungDiff >= 0);

        // Nachricht je nach Zustand
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $parts = [];
        if ($streckeExpired) { $parts[] = 'Strecke abgelaufen (Bis: '.($streckeBis?$streckeBis->format('d.m.Y'):'unbekannt').') – bitte zeitnah die Atemschutzstrecke absolvieren.'; }
        elseif ($streckeDiff !== null && $streckeDiff <= $warnDays) { $parts[] = 'Strecke läuft bald ab (Bis: '.$streckeBis->format('d.m.Y').') – bitte zeitnah die Atemschutzstrecke absolvieren.'; }

        if ($g263Expired) { $parts[] = 'G26.3 abgelaufen (Bis: '.($g263Bis?$g263Bis->format('d.m.Y'):'unbekannt').') – bitte Arzttermin (G26.3) vereinbaren.'; }
        elseif ($g263Diff !== null && $g263Diff <= $warnDays) { $parts[] = 'G26.3 läuft bald ab (Bis: '.$g263Bis->format('d.m.Y').') – bitte Arzttermin (G26.3) vereinbaren.'; }

        if ($uebungExpired) { $parts[] = 'Übung/Einsatz abgelaufen (Bis: '.($uebungBis?$uebungBis->format('d.m.Y'):'unbekannt').') – bitte bei den FwDV7-Ausbildern melden.'; }
        elseif ($uebungDiff !== null && $uebungDiff <= $warnDays) { $parts[] = 'Übung/Einsatz läuft bald ab (Bis: '.$uebungBis->format('d.m.Y').') – bitte bei den FwDV7-Ausbildern melden.'; }

        if (empty($parts)) continue; // nichts zu melden

        $subject = 'Hinweis Atemschutz – Fristen';
        $message_html = '<p>Hallo '.htmlspecialchars($name).',</p>';
        $message_html .= '<p>bitte beachten Sie folgende Hinweise:</p><ul>';
        foreach ($parts as $p) { $message_html .= '<li>'.htmlspecialchars($p).'</li>'; }
        $message_html .= '</ul><p>Vielen Dank.</p>';

        try {
            if (send_email($email, $subject, $message_html)) { $sent++; }
        } catch(Exception $e) { $errors[] = $e->getMessage(); }
    }

    echo json_encode(['success'=>true,'sent'=>$sent,'errors'=>$errors]);
} catch (Exception $ex) {
    echo json_encode(['success'=>false,'error'=>$ex->getMessage()]);
}


