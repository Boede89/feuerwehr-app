<?php
/**
 * Dashboard - Reparierte Version
 */

// Session-Fix: Session vor allem anderen starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Google Calendar Klassen explizit laden
require_once '../includes/google_calendar_service_account.php';
require_once '../includes/google_calendar.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Dashboard ist für alle eingeloggten Benutzer sichtbar; Inhalte werden per Berechtigung gesteuert
// WICHTIG: Berechtigungen einmal ermitteln und wiederverwenden (verhindert Inkonsistenzen)
$canAtemschutz = has_permission('atemschutz');
$canReservations = has_permission('reservations');

$error = '';
$message = '';

// POST: Atemschutz-Träger aktualisieren (vom Dashboard-Modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_atemschutz_traeger') {
    if (!has_permission('atemschutz')) {
        $error = 'Keine Berechtigung.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $streckeAm = trim($_POST['strecke_am'] ?? '');
        $g263Am = trim($_POST['g263_am'] ?? '');
        $uebungAm = trim($_POST['uebung_am'] ?? '');
        if ($id <= 0 || $firstName === '' || $lastName === '' || $birthdate === '' || $streckeAm === '' || $g263Am === '' || $uebungAm === '') {
            $error = 'Bitte alle Pflichtfelder ausfüllen.';
        } else {
            try {
                $stmtU = $db->prepare("UPDATE atemschutz_traeger SET first_name=?, last_name=?, email=?, birthdate=?, strecke_am=?, g263_am=?, uebung_am=? WHERE id=?");
                $stmtU->execute([$firstName, $lastName, ($email !== '' ? $email : null), $birthdate, $streckeAm, $g263Am, $uebungAm, $id]);
                $message = 'Geräteträger aktualisiert.';
            } catch (Exception $e) {
                $error = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// POST-Verarbeitung für Genehmigung/Ablehnung
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
        try {
            if ($action == 'approve') {
                // Reservierung genehmigen
                $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $reservation_id]);
                
                // E-Mail an Antragsteller senden
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation && !empty($reservation['requester_email'])) {
                    $subject = "Fahrzeugreservierung genehmigt - " . $reservation['vehicle_name'];
                    $message_html = "
                    <h2>Fahrzeugreservierung genehmigt</h2>
                    <p>Ihre Fahrzeugreservierung wurde genehmigt.</p>
                    <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                    <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                    <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                    <p>Vielen Dank für Ihre Reservierung!</p>
                    ";
                    
                    try {
                        send_email($reservation['requester_email'], $subject, $message_html);
                        error_log("APPROVE EMAIL: E-Mail an Antragsteller gesendet: " . $reservation['requester_email']);
                    } catch (Exception $e) {
                        error_log("APPROVE EMAIL: Fehler beim Senden der E-Mail: " . $e->getMessage());
                    }
                }
                
                // Google Calendar Event erstellen
                try {
                    // Reservierungsdaten für Google Calendar laden
                    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                    $stmt->execute([$reservation_id]);
                    $reservation = $stmt->fetch();
                    
                    if ($reservation && function_exists('create_or_update_google_calendar_event')) {
                        $event_id = create_or_update_google_calendar_event(
                            $reservation['vehicle_name'],
                            $reservation['reason'],
                            $reservation['start_datetime'],
                            $reservation['end_datetime'],
                            $reservation['id'],
                            $reservation['location'] ?? null
                        );

                        if ($event_id) {
                            $message = "Reservierung erfolgreich genehmigt und in Google Calendar eingetragen.";
                        } else {
                            // Intelligenter Fallback: vorhandenes Event finden und Titel erweitern, erst dann Neu-Erstellung
                            error_log('DASHBOARD APPROVE: create_or_update_google_calendar_event fehlgeschlagen für Reservierung ' . $reservation['id']);

                            try {
                                // 1) Suche vorhandenes Event mit gleichem Zeitraum + Grund
                                $stmt = $db->prepare("SELECT ce.google_event_id, ce.title FROM calendar_events ce JOIN reservations r ON ce.reservation_id = r.id WHERE r.start_datetime = ? AND r.end_datetime = ? AND r.reason = ? LIMIT 1");
                                $stmt->execute([$reservation['start_datetime'], $reservation['end_datetime'], $reservation['reason']]);
                                $existing = $stmt->fetch();

                                if ($existing && !empty($existing['google_event_id'])) {
                                    $currentTitle = $existing['title'] ?? '';
                                    $needsAppend = stripos($currentTitle, $reservation['vehicle_name']) === false;
                                    if ($needsAppend) {
                                        // Titel in Format "Fahrzeuge, ... - Grund" normalisieren und Fahrzeugliste vorne pflegen
                                        $canonicalVehicles = $currentTitle;
                                        $needle = ' - ' . $reservation['reason'];
                                        if (stripos($currentTitle, $needle) !== false) {
                                            $canonicalVehicles = trim(str_ireplace($needle, '', $currentTitle));
                                        }
                                        $vehicleParts = array_filter(array_map('trim', explode(',', $canonicalVehicles)));
                                        if (!in_array($reservation['vehicle_name'], $vehicleParts, true)) {
                                            $vehicleParts[] = $reservation['vehicle_name'];
                                        }
                                        $vehiclesJoined = implode(', ', $vehicleParts);
                                        $newTitle = $vehiclesJoined . ' - ' . $reservation['reason'];
                                    } else {
                                        $newTitle = $currentTitle;
                                    }

                                    // 2) Falls nötig, Titel im Google Kalender aktualisieren
                                    if ($needsAppend && function_exists('update_google_calendar_event_title')) {
                                        $updateOk = update_google_calendar_event_title($existing['google_event_id'], $newTitle);
                                        if ($updateOk) {
                                            // 3) Lokale Titel aktualisieren
                                            $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
                                            $stmt->execute([$newTitle, $existing['google_event_id']]);
                                        } else {
                                            error_log('DASHBOARD APPROVE: update_google_calendar_event_title fehlgeschlagen für ' . $existing['google_event_id']);
                                        }
                                    }

                                    // 4) Verknüpfung für diese Reservierung in calendar_events sicherstellen
                                    $stmt = $db->prepare("SELECT id FROM calendar_events WHERE reservation_id = ?");
                                    $stmt->execute([$reservation['id']]);
                                    $existsLink = $stmt->fetch();
                                    if (!$existsLink) {
                                        $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                                        $stmt->execute([$reservation['id'], $existing['google_event_id'], $newTitle, $reservation['start_datetime'], $reservation['end_datetime']]);
                                    }

                                    $event_id = $existing['google_event_id'];
                                    $message = "Reservierung erfolgreich genehmigt und in Google Calendar eingetragen.";
                                } else {
                                    // 5) Kein vorhandenes Event gefunden: als letzter Schritt neu erstellen
                                    if (function_exists('create_google_calendar_event')) {
                                        $direct_title = $reservation['vehicle_name'] . ' - ' . $reservation['reason'];
                                        error_log('DASHBOARD APPROVE: Kein vorhandenes Event gefunden – versuche create_google_calendar_event: title=' . $direct_title);
                                        $retry_event_id = create_google_calendar_event(
                                            $direct_title,
                                            $reservation['reason'],
                                            $reservation['start_datetime'],
                                            $reservation['end_datetime'],
                                            $reservation['id'],
                                            $reservation['location'] ?? null
                                        );
                                        if ($retry_event_id) {
                                            $message = "Reservierung erfolgreich genehmigt und in Google Calendar eingetragen.";
                                            $event_id = $retry_event_id;
                                        } else {
                                            error_log('DASHBOARD APPROVE: create_google_calendar_event Fallback ebenfalls fehlgeschlagen für Reservierung ' . $reservation['id']);
                                            $message = "Reservierung genehmigt, aber Google Calendar Event konnte nicht erstellt werden.";
                                        }
                                    } else {
                                        error_log('DASHBOARD APPROVE: create_google_calendar_event Funktion nicht verfügbar');
                                        $message = "Reservierung genehmigt, aber Google Calendar Event konnte nicht erstellt werden.";
                                    }
                                }
                            } catch (Exception $ex) {
                                error_log('DASHBOARD APPROVE: Fallback-Exception: ' . $ex->getMessage());
                                $message = "Reservierung genehmigt, aber Google Calendar Event konnte nicht erstellt werden.";
                            }
                        }
                    } else {
                        $message = "Reservierung genehmigt.";
                    }
                } catch (Exception $e) {
                    error_log('Google Calendar Fehler: ' . $e->getMessage());
                    $message = "Reservierung genehmigt, aber Google Calendar Fehler: " . $e->getMessage();
                }
            } elseif ($action == 'reject') {
            $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
            if (!empty($rejection_reason)) {
                $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$rejection_reason, $_SESSION['user_id'], $reservation_id]);
                
                // E-Mail an Antragsteller senden
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation && !empty($reservation['requester_email'])) {
                    $subject = "Fahrzeugreservierung abgelehnt - " . $reservation['vehicle_name'];
                    $message_html = "
                    <h2>Fahrzeugreservierung abgelehnt</h2>
                    <p>Ihre Fahrzeugreservierung wurde leider abgelehnt.</p>
                    <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                    <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                    <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                    <p><strong>Ablehnungsgrund:</strong> " . htmlspecialchars($rejection_reason) . "</p>
                    <p>Bei Rückfragen wenden Sie sich bitte an die Verwaltung.</p>
                    ";
                    
                    try {
                        send_email($reservation['requester_email'], $subject, $message_html);
                        error_log("REJECT EMAIL: E-Mail an Antragsteller gesendet: " . $reservation['requester_email']);
                    } catch (Exception $e) {
                        error_log("REJECT EMAIL: Fehler beim Senden der E-Mail: " . $e->getMessage());
                    }
                }
                
                $message = "Reservierung erfolgreich abgelehnt.";
            } else {
                $error = "Bitte geben Sie einen Ablehnungsgrund an.";
            }
        } elseif ($action == 'approve_replace_conflict') {
            // Konfliktreservierung löschen und aktuellen Antrag genehmigen
            $conflict_reservation_id = isset($_POST['conflict_reservation_id']) ? (int)$_POST['conflict_reservation_id'] : 0;
            if ($conflict_reservation_id <= 0) {
                throw new Exception('Konflikt-Reservierungs-ID fehlt.');
            }
            
            // Hole Daten der konfliktverursachenden Reservierung (für E-Mail)
            $stmt = $db->prepare("SELECT r.*, v.name AS vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$conflict_reservation_id]);
            $conflict_res = $stmt->fetch();
            
            if (!$conflict_res) {
                throw new Exception('Konflikt-Reservierung nicht gefunden.');
            }
            
            // Lösche die konfliktverursachende Reservierung aus Google Calendar und Datenbank
            // 1. Google Calendar Event löschen
            $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$conflict_reservation_id]);
            $calendar_event = $stmt->fetch();
            
            if ($calendar_event && !empty($calendar_event['google_event_id'])) {
                $google_event_id = $calendar_event['google_event_id'];
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE google_event_id = ?");
                $stmt->execute([$google_event_id]);
                $remaining_links = (int)$stmt->fetchColumn();

                if ($remaining_links === 1) {
                    // Nur löschen, wenn keine weitere Reservierung dieses Event nutzt
                    $google_deleted = delete_google_calendar_event($google_event_id);
                    if ($google_deleted) {
                        error_log("CONFLICT RESOLVE: Google Calendar Event gelöscht: " . $google_event_id);
                    } else {
                        error_log("CONFLICT RESOLVE: Google Calendar Event konnte nicht gelöscht werden: " . $google_event_id);
                    }
                } else {
                    error_log("CONFLICT RESOLVE: Google Event nicht gelöscht, es existieren noch " . $remaining_links . " weitere Verknüpfung(en) für " . $google_event_id);
                }
            }
            
            // 2. Calendar Events Verknüpfung löschen
            $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$conflict_reservation_id]);
            
            // 3. Reservierung löschen
            $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
            $stmt->execute([$conflict_reservation_id]);
            
            // E-Mail an ursprünglichen Antragsteller senden
            if (!empty($conflict_res['requester_email'])) {
                $subject = 'Ihre Fahrzeugreservierung wurde storniert - ' . ($conflict_res['vehicle_name'] ?? 'Fahrzeug');
                $message_html = '';
                $message_html .= '<h2>Reservierung storniert</h2>';
                $message_html .= '<p>Ihre bestehende Reservierung wurde storniert, da ein neuer Antrag priorisiert bearbeitet wurde.</p>';
                $message_html .= '<p><strong>Fahrzeug:</strong> ' . htmlspecialchars($conflict_res['vehicle_name'] ?? '') . '</p>';
                $message_html .= '<p><strong>Grund:</strong> ' . htmlspecialchars($conflict_res['reason'] ?? '') . '</p>';
                $message_html .= '<p><strong>Zeitraum:</strong> ' . htmlspecialchars($conflict_res['start_datetime'] ?? '') . ' - ' . htmlspecialchars($conflict_res['end_datetime'] ?? '') . '</p>';
                $message_html .= '<p>Bei Rückfragen wenden Sie sich bitte an die Verwaltung.</p>';
                
                try { send_email($conflict_res['requester_email'], $subject, $message_html); } catch (Exception $e) { /* still proceed */ }
            }
            
            // Aktuelle Reservierung genehmigen (wie in 'approve')
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $reservation_id]);
            
            // Optional: Google Calendar Event für aktuelle Reservierung erstellen
            try {
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                if ($reservation && function_exists('create_or_update_google_calendar_event')) {
                    create_or_update_google_calendar_event(
                        $reservation['vehicle_name'],
                        $reservation['reason'],
                        $reservation['start_datetime'],
                        $reservation['end_datetime'],
                        $reservation['id'],
                        $reservation['location'] ?? null
                    );
                }
            } catch (Exception $e) { /* ignore calendar errors for this action */ }
            
            $message = 'Konflikt gelöscht und aktueller Antrag genehmigt.';
        }
    } catch(PDOException $e) {
        $error = "Fehler beim Verarbeiten der Reservierung: " . $e->getMessage();
    }
}

// Reservierungen laden
try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
    $all_reservations = [];
    $pending_reservations = [];
    $processed_reservations = [];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%94%A5%3C/text%3E%3C/svg%3E">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <style>
        .bis-badge { padding: .25rem .5rem; border-radius: .375rem; display: inline-block; }
        .bis-warn { background-color: #fff3cd; color: #664d03; }
        .bis-expired { background-color: #dc3545; color: #fff; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                    <small class="text-muted">Willkommen zurück, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</small>
                </h1>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canAtemschutz): ?>
        <!-- Atemschutz Abschnitt -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-lungs"></i> Atemschutz</h6>
                        <a href="atemschutz.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-lungs"></i> Atemschutz öffnen</a>
                    </div>
                    <div class="card-body">
                        <?php
                        // Warn-/Abgelaufen-Liste berechnen
                        $warnDays = 90;
                        try {
                            $s = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='atemschutz_warn_days' LIMIT 1");
                            $s->execute();
                            $val = $s->fetchColumn();
                            if ($val !== false && is_numeric($val)) { $warnDays = (int)$val; }
                        } catch (Exception $e) {}

                        // Sicherstellen, dass Spalte last_notified_at existiert
                        try {
                            $col = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atemschutz_traeger' AND COLUMN_NAME = 'last_notified_at'");
                            $col->execute();
                            $exists = (int)$col->fetchColumn() > 0;
                            if (!$exists) {
                                $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN last_notified_at DATETIME NULL");
                            }
                        } catch (Exception $e) { /* ignore */ }

                        $now = new DateTime('today');
                        $stmta = $db->prepare("SELECT id, first_name, last_name, birthdate, strecke_am, g263_am, uebung_am, last_notified_at FROM atemschutz_traeger");
                        $stmta->execute();
                        $rows = $stmta->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        $items = [];
                        foreach ($rows as $r) {
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

                            $status = 'tauglich';
                            if ($streckeExpired || $g263Expired) { $status = 'abgelaufen'; }
                            elseif ($uebungExpired && !$streckeExpired && !$g263Expired) { $status = 'uebung_abgelaufen'; }
                            elseif ($anyWarn) { $status = 'warnung'; }

                            if (in_array($status, ['warnung','uebung_abgelaufen','abgelaufen'], true)) {
                                $items[] = [
                                    'id' => (int)$r['id'],
                                    'name' => trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '')),
                                    'strecke_bis' => $streckeBis ? $streckeBis->format('Y-m-d') : '',
                                    'g263_bis' => $g263Bis ? $g263Bis->format('Y-m-d') : '',
                                    'uebung_bis' => $uebungBis ? $uebungBis->format('Y-m-d') : '',
                                    'status' => $status,
                                    'last_notified_at' => $r['last_notified_at'] ?? '',
                                ];
                            }
                        }
                        ?>

                        <?php if (empty($items)): ?>
                            <div class="text-center text-muted py-4">Keine Auffälligkeiten</div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0 text-danger"><i class="fas fa-triangle-exclamation"></i> Auffällige Geräteträger</h6>
                                <a class="btn btn-sm btn-outline-secondary" href="#" onclick="notifyAllAtemschutz(); return false;"><i class="fas fa-paper-plane"></i> Alle Benachrichtigen</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Strecke Bis</th>
                                            <th>G26.3 Bis</th>
                                            <th>Übung/Einsatz Bis</th>
                                            <th>Status</th>
                                            <th>Benachrichtigt am</th>
                                            <th class="text-end">Aktion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $it): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($it['name']); ?></td>
                                                <td>
                                                    <?php
                                                        $cls = '';
                                                        if ($it['strecke_bis']) {
                                                            $d = new DateTime($it['strecke_bis']);
                                                            $diff = (int)$now->diff($d)->format('%r%a');
                                                            if ($diff < 0) $cls = 'bis-expired';
                                                            elseif ($diff <= $warnDays) $cls = 'bis-warn';
                                                        }
                                                    ?>
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($it['strecke_bis'] ? date('d.m.Y', strtotime($it['strecke_bis'])) : ''); ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                        $cls = '';
                                                        if ($it['g263_bis']) {
                                                            $d = new DateTime($it['g263_bis']);
                                                            $diff = (int)$now->diff($d)->format('%r%a');
                                                            if ($diff < 0) $cls = 'bis-expired';
                                                            elseif ($diff <= $warnDays) $cls = 'bis-warn';
                                                        }
                                                    ?>
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($it['g263_bis'] ? date('d.m.Y', strtotime($it['g263_bis'])) : ''); ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                        $cls = '';
                                                        if ($it['uebung_bis']) {
                                                            $d = new DateTime($it['uebung_bis']);
                                                            $diff = (int)$now->diff($d)->format('%r%a');
                                                            if ($diff < 0) $cls = 'bis-expired';
                                                            elseif ($diff <= $warnDays) $cls = 'bis-warn';
                                                        }
                                                    ?>
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($it['uebung_bis'] ? date('d.m.Y', strtotime($it['uebung_bis'])) : ''); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($it['status']==='abgelaufen'): ?>
                                                        <span class="badge bg-danger">Abgelaufen</span>
                                                    <?php elseif ($it['status']==='uebung_abgelaufen'): ?>
                                                        <span class="badge bg-danger">Übung abgelaufen</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Warnung</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($it['last_notified_at'] ? date('d.m.Y', strtotime($it['last_notified_at'])) : '-'); ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="btn-group" role="group">
                                                        <a class="btn btn-sm btn-outline-primary" href="atemschutz-liste.php?edit_id=<?php echo (int)$it['id']; }?>">
                                                            <i class="fas fa-pen"></i> Bearbeiten
                                                        </a>
                                                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="notifyAtemschutz(<?php echo (int)$it['id']; ?>); return false;" title="Benachrichtigen">
                                                            <i class="fas fa-paper-plane"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($canReservations): ?>
        <div class="row">
            <!-- Fahrzeugreservierungen -->
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-truck"></i> Fahrzeugreservierungen
                        </h6>
                            <a href="reservations.php" class="btn btn-sm btn-primary">
                                <i class="fas fa-calendar-check"></i> Reservierungen verwalten
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <h6 class="text-warning mb-3"><i class="fas fa-clock"></i> Offene Anträge (<?php echo count($pending_reservations); ?>)</h6>
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine ausstehenden Anträge</h5>
                                <p class="text-muted">Alle Anträge wurden bearbeitet.</p>
                            </div>
                        <?php else: ?>
                            <!-- Mobile-optimierte Karten-Ansicht -->
                            <div class="d-md-none">
                                <?php foreach ($pending_reservations as $reservation): ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="fas fa-truck text-primary"></i>
                                                    <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                                </h6>
                                                <span class="badge bg-warning">Ausstehend</span>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-calendar-alt text-success"></i>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-user text-info"></i>
                                                <span><?php echo htmlspecialchars($reservation['requester_name']); ?></span>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <i class="fas fa-clipboard-list text-warning"></i>
                                                <span><?php echo htmlspecialchars($reservation['reason']); ?></span>
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                    <i class="fas fa-info-circle"></i> Details anzeigen
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Desktop-Tabellen-Ansicht -->
                            <div class="d-none d-md-block">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Fahrzeug</th>
                                                <th>Antragsteller</th>
                                                <th>Datum/Zeit</th>
                                                <th>Grund</th>
                                                <th>Aktion</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_reservations as $reservation): ?>
                                                <tr>
                                                    <td>
                                                        <i class="fas fa-truck text-primary"></i>
                                                        <strong><?php echo htmlspecialchars($reservation['vehicle_name']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <i class="fas fa-user text-info"></i>
                                                        <?php echo htmlspecialchars($reservation['requester_name']); ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong><br>
                                                        <small class="text-muted">
                                                            <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                            <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?php echo htmlspecialchars($reservation['reason']); ?>">
                                                            <?php echo htmlspecialchars($reservation['reason']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                            <i class="fas fa-info-circle"></i> Details
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
        
        <!-- Details-Modals nur für ausstehende Reservierungen -->
        <?php if (!empty($pending_reservations)): ?>
            <?php foreach ($pending_reservations as $modal_reservation): ?>
        <div class="modal fade" id="detailsModal<?php echo $modal_reservation['id']; ?>" tabindex="-1" 
             data-vehicle-name="<?php echo htmlspecialchars($modal_reservation['vehicle_name']); ?>"
             data-start-datetime="<?php echo $modal_reservation['start_datetime']; ?>"
             data-end-datetime="<?php echo $modal_reservation['end_datetime']; ?>">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-info-circle"></i> Reservierungsdetails #<?php echo $modal_reservation['id']; ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-truck text-primary"></i> Fahrzeug</h6>
                                <p><?php echo htmlspecialchars($modal_reservation['vehicle_name']); ?></p>
                                
                                <h6><i class="fas fa-user text-info"></i> Antragsteller</h6>
                                <p>
                                    <strong><?php echo htmlspecialchars($modal_reservation['requester_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($modal_reservation['requester_email']); ?></small>
                                </p>
                                
                                <h6><i class="fas fa-calendar-alt text-success"></i> Zeitraum</h6>
                                <p>
                                    <strong>Von:</strong> <?php echo date('d.m.Y H:i', strtotime($modal_reservation['start_datetime'])); ?><br>
                                    <strong>Bis:</strong> <?php echo date('d.m.Y H:i', strtotime($modal_reservation['end_datetime'])); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-clipboard-list text-warning"></i> Grund</h6>
                                <p><?php echo htmlspecialchars($modal_reservation['reason']); ?></p>
                                
                                <h6><i class="fas fa-map-marker-alt text-info"></i> Ort</h6>
                                <p><?php echo htmlspecialchars($modal_reservation['location'] ?? 'Nicht angegeben'); ?></p>
                                
                                <h6><i class="fas fa-info-circle text-secondary"></i> Status</h6>
                                <p>
                                    <span class="badge bg-warning">
                                        <i class="fas fa-clock"></i> Ausstehend
                                    </span>
                                </p>
                                
                                <h6><i class="fas fa-clock text-muted"></i> Erstellt</h6>
                                <p><small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($modal_reservation['created_at'])); ?></small></p>
                                
                                <h6><i class="fas fa-calendar-check text-info"></i> Kalender-Prüfung</h6>
                                <div id="calendar-check-<?php echo $modal_reservation['id']; ?>">
                                    <div class="text-center">
                                        <div class="spinner-border spinner-border-sm text-info" role="status" id="spinner-<?php echo $modal_reservation['id']; ?>">
                                            <span class="visually-hidden">Lädt...</span>
                                        </div>
                                        <p class="text-muted mt-2" id="loading-text-<?php echo $modal_reservation['id']; ?>">Prüfe Kalender-Konflikte...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <!-- Genehmigen/Ablehnen für ausstehende Reservierungen -->
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="reservation_id" value="<?php echo $modal_reservation['id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-success" onclick="return confirm('Reservierung genehmigen?')">
                                <i class="fas fa-check"></i> Genehmigen
                            </button>
                        </form>
                            <form method="POST" class="d-inline conflict-replace-form d-none" id="approveReplaceForm<?php echo $modal_reservation['id']; ?>" onsubmit="return confirm('Konfliktreservierung löschen und aktuellen Antrag genehmigen?');" hidden>
                                <input type="hidden" name="reservation_id" value="<?php echo $modal_reservation['id']; ?>">
                                <input type="hidden" name="action" value="approve_replace_conflict">
                                <input type="hidden" name="conflict_reservation_id" value="">
                                <button type="submit" class="btn btn-warning" id="approveReplaceBtn<?php echo $modal_reservation['id']; ?>" disabled>
                                    <i class="fas fa-exchange-alt"></i> Konflikt löschen & genehmigen
                            </button>
                        </form>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $modal_reservation['id']; ?>" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Ablehnen
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Ablehnungs-Modals für ausstehende Reservierungen -->
        <?php if (!empty($pending_reservations)): ?>
            <?php foreach ($pending_reservations as $reject_reservation): ?>
        <div class="modal fade" id="rejectModal<?php echo $reject_reservation['id']; ?>" tabindex="-1" data-reservation-id="<?php echo $reject_reservation['id']; ?>" data-vehicle-name="<?php echo htmlspecialchars($reject_reservation['vehicle_name']); ?>" data-start-datetime="<?php echo htmlspecialchars($reject_reservation['start_datetime']); ?>" data-end-datetime="<?php echo htmlspecialchars($reject_reservation['end_datetime']); ?>">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Reservierung ablehnen</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Reservierung #<?php echo $reject_reservation['id']; ?> - <?php echo htmlspecialchars($reject_reservation['vehicle_name']); ?></p>
                            <div class="mb-3">
                                <label for="rejection_reason<?php echo $reject_reservation['id']; ?>" class="form-label">Ablehnungsgrund</label>
                                <textarea class="form-control" id="rejection_reason<?php echo $reject_reservation['id']; ?>" name="rejection_reason" rows="3" placeholder="Begründung eingeben..." required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                            <button type="submit" class="btn btn-danger">Ablehnen</button>
                        </div>
                        <input type="hidden" name="reservation_id" value="<?php echo $reject_reservation['id']; ?>">
                        <input type="hidden" name="action" value="reject">
                    </form>
                </div>
            </div>
        </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
    </div>

    <!-- Modal: Atemschutz-Träger bearbeiten (vom Dashboard) -->
    <?php if ($canAtemschutz): ?>
    <div class="modal fade" id="editTraegerDashModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-pen me-2"></i> Geräteträger bearbeiten</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="dashboard.php">
                    <input type="hidden" name="action" value="update_atemschutz_traeger">
                    <input type="hidden" name="id" id="dash_edit_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="border rounded p-3 bg-light">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Name</label>
                                            <input type="text" class="form-control" id="dash_edit_name" disabled>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Vorname <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                                <input type="text" class="form-control" name="first_name" id="dash_edit_first_name" placeholder="Max" required>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Nachname <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                                <input type="text" class="form-control" name="last_name" id="dash_edit_last_name" placeholder="Mustermann" required>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">E-Mail (optional)</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                                <input type="email" class="form-control" name="email" id="dash_edit_email" placeholder="name@beispiel.de">
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Geburtsdatum <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-cake-candles"></i></span>
                                                <input type="date" class="form-control" name="birthdate" id="dash_edit_birthdate" required>
                                            </div>
                                            <div class="form-text">Alter wird automatisch berechnet.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="border rounded p-3">
                                    <h6 class="mb-3"><i class="fas fa-road me-2"></i> Strecke</h6>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Am <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="strecke_am" id="dash_edit_strecke_am" required>
                                            <div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="border rounded p-3">
                                    <h6 class="mb-3"><i class="fas fa-stethoscope me-2"></i> G26.3</h6>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Am <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="g263_am" id="dash_edit_g263_am" required>
                                            <div class="form-text">Bis-Datum: unter 50 Jahre +3 Jahre, ab 50 +1 Jahr.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="border rounded p-3">
                                    <h6 class="mb-3"><i class="fas fa-dumbbell me-2"></i> Übung/Einsatz</h6>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Am <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="uebung_am" id="dash_edit_uebung_am" required>
                                            <div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: E-Mail eintragen (schöne Variante statt Browser-Prompt) -->
    <?php if ($canAtemschutz): ?>
    <div class="modal fade" id="emailEntryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="fas fa-envelope me-2"></i> E-Mail eintragen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="emailEntryForm">
                    <input type="hidden" id="email_entry_id">
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label">Geräteträger</label>
                            <input type="text" class="form-control" id="email_entry_name" disabled>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">E-Mail Adresse</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-at"></i></span>
                                <input type="email" class="form-control" id="email_entry_email" placeholder="name@beispiel.de" required>
                            </div>
                            <div class="form-text">Diese Adresse wird gespeichert und für Benachrichtigungen verwendet.</div>
                        </div>
                        <div class="alert alert-danger d-none" id="email_entry_error"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-secondary">Speichern & Benachrichtigen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Wenn ein Ablehnungs-Modal geöffnet wird, Konflikt prüfen und ggf. Grund vorausfüllen
        document.addEventListener('shown.bs.modal', function (event) {
            const modal = event.target;
            if (!modal.id || !modal.id.startsWith('rejectModal')) return;

            const reservationId = modal.getAttribute('data-reservation-id');
            const vehicleName = modal.getAttribute('data-vehicle-name');
            const startDateTime = modal.getAttribute('data-start-datetime');
            const endDateTime = modal.getAttribute('data-end-datetime');
            const textarea = modal.querySelector('textarea[name="rejection_reason"]');
            if (!textarea) return;

            // JSON-Variante verwenden
            fetch('check-calendar-conflicts-simple.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    vehicle_name: vehicleName || '',
                    start_datetime: startDateTime || '',
                    end_datetime: endDateTime || ''
                })
            })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                const hasConflictsArray = data && data.success === true && Array.isArray(data.conflicts) && data.conflicts.length > 0;
                const hasConflictFlag = false;
                if (hasConflictFlag || hasConflictsArray) {
                    if (!textarea.value.trim()) {
                        textarea.value = 'Das Fahrzeug ist bereits belegt!';
                    }
                }
            })
            .catch(() => {});
        });
        function checkCalendarConflicts(reservationId, vehicleName, startDateTime, endDateTime) {
            const container = document.getElementById('calendar-check-' + reservationId);
            
            // Zeige Lade-Status
            container.innerHTML = '<button class="btn btn-outline-info btn-sm" disabled><i class="fas fa-spinner fa-spin"></i> Prüfe Kalender...</button>';
            
            // AJAX-Anfrage an den Server
            fetch('check-calendar-conflicts-simple.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    vehicle_name: vehicleName,
                    start_datetime: startDateTime,
                    end_datetime: endDateTime
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.conflicts && data.conflicts.length > 0) {
                        // Konflikte gefunden
                        let conflictsHtml = '<div class="alert alert-warning mt-2"><strong>Warnung:</strong> Für dieses Fahrzeug existieren bereits genehmigte Reservierungen:<ul class="mb-0 mt-2">';
                        data.conflicts.forEach(conflict => {
                            conflictsHtml += '<li data-conflict-id="' + (conflict.reservation_id || '') + '"><strong>' + conflict.title + '</strong><br><small class="text-muted">' + 
                                new Date(conflict.start).toLocaleString('de-DE') + ' - ' + 
                                new Date(conflict.end).toLocaleString('de-DE') + '</small></li>';
                        });
                        conflictsHtml += '</ul></div>';
                        container.innerHTML = conflictsHtml;
                        // Falls vorhanden, setze die erste Konflikt-ID und zeige den Button an
                        try {
                            const conflictId = data.conflicts[0] && data.conflicts[0].reservation_id ? String(data.conflicts[0].reservation_id) : '';
                            const form = document.getElementById('approveReplaceForm' + reservationId);
                            const btn = document.getElementById('approveReplaceBtn' + reservationId);
                            if (form && btn && conflictId) {
                                const hiddenInput = form.querySelector('input[name="conflict_reservation_id"]');
                                if (hiddenInput) hiddenInput.value = conflictId;
                                form.classList.remove('d-none');
                                form.removeAttribute('hidden');
                                btn.disabled = false;
                            }
                        } catch (e) { /* ignore */ }
                    } else {
                        // Kein Konflikt
                        container.innerHTML = '<div class="alert alert-success mt-2"><strong>Kein Konflikt:</strong> Der beantragte Zeitraum ist frei.</div>';
                        // Disable Replace-Button
                        try {
                            const btn = document.getElementById('approveReplaceBtn' + reservationId);
                            if (btn) btn.disabled = true;
                            const form = document.getElementById('approveReplaceForm' + reservationId);
                            if (form) { form.classList.add('d-none'); form.setAttribute('hidden', ''); }
                        } catch (e) { /* ignore */ }
                    }
                } else {
                    // Fehler
                    container.innerHTML = '<div class="alert alert-danger mt-2"><strong>Fehler:</strong> ' + (data.error || 'Kalender-Prüfung fehlgeschlagen') + '</div>';
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                container.innerHTML = '<div class="alert alert-danger mt-2"><strong>Fehler:</strong> Verbindung zum Server fehlgeschlagen</div>';
            });
        }
        
        // Automatische Kalender-Prüfung für alle Details-Modals
        document.addEventListener('DOMContentLoaded', function() {
            // Event Listener für alle Details-Buttons
            document.querySelectorAll('[data-bs-toggle="modal"][data-bs-target^="#detailsModal"]').forEach(function(button) {
                button.addEventListener('click', function() {
                    const targetModalId = this.getAttribute('data-bs-target');
                    const reservationId = targetModalId.replace('#detailsModal', '');
                    
                    console.log('🔍 Details-Button geklickt, Modal ID:', targetModalId);
                    console.log('Reservierungs-ID:', reservationId);
                    
                    // Button vor jeder Prüfung sicher verstecken
                    try {
                        const form = document.getElementById('approveReplaceForm' + reservationId);
                        const btn = document.getElementById('approveReplaceBtn' + reservationId);
                        if (form) { form.classList.add('d-none'); form.setAttribute('hidden', ''); }
                        if (btn) { btn.disabled = true; }
                    } catch (e) { /* ignore */ }
                    
                    // Starte Kalender-Prüfung automatisch nach Modal-Öffnung
                    setTimeout(function() {
                        // Hole die Reservierungsdaten aus dem Modal
                        const modal = document.querySelector(targetModalId);
                        if (modal) {
                            const vehicleName = modal.getAttribute('data-vehicle-name') || '';
                            const startDateTime = modal.getAttribute('data-start-datetime') || '';
                            const endDateTime = modal.getAttribute('data-end-datetime') || '';
                            
                            console.log('📊 Modal-Daten:', { vehicleName, startDateTime, endDateTime });
                            
                            if (vehicleName && startDateTime && endDateTime) {
                                console.log('✅ Starte Kalender-Prüfung...');
                                checkCalendarConflicts(reservationId, vehicleName, startDateTime, endDateTime);
                            } else {
                                console.error('❌ Modal-Daten unvollständig');
                                // Fallback: Zeige Fehler
                                const container = document.getElementById('calendar-check-' + reservationId);
                                if (container) {
                                    container.innerHTML = '<div class="alert alert-danger mt-2"><strong>Fehler:</strong> Reservierungsdaten konnten nicht geladen werden.</div>';
                                }
                                try {
                                    const form = document.getElementById('approveReplaceForm' + reservationId);
                                    if (form) { form.classList.add('d-none'); form.setAttribute('hidden', ''); }
                                } catch (e) { /* ignore */ }
                            }
                        } else {
                            console.error('❌ Modal nicht gefunden:', targetModalId);
                        }
                    }, 500); // Kurze Verzögerung damit Modal vollständig geöffnet ist
                });
            });
        });

        // Benachrichtigung einzelner Geräteträger (mit E-Mail-Fallback)
        window.notifyAtemschutz = async function(id){
            try {
                const r = await fetch('/admin/atemschutz-get.php?id='+encodeURIComponent(id));
                const data = await r.json();
                if (!data || data.success !== true) { alert('Konnte Geräteträger nicht laden.'); return; }
                let { email, first_name, last_name } = data.data || {};
                if (!email) {
                    // schönes Modal öffnen
                    const modalEl = document.getElementById('emailEntryModal');
                    if (!modalEl || !(window.bootstrap && bootstrap.Modal)) { alert('Modal nicht verfügbar. Bitte Seite neu laden.'); return; }
                    const m = bootstrap.Modal.getOrCreateInstance(modalEl);
                    document.getElementById('email_entry_id').value = String(id);
                    document.getElementById('email_entry_name').value = `${last_name || ''}, ${first_name || ''}`.trim();
                    document.getElementById('email_entry_email').value = '';
                    document.getElementById('email_entry_error').classList.add('d-none');
                    m.show();
                    return;
                }
                // Mail auslösen
                const res = await fetch('/admin/atemschutz-notify.php', {
                    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ ids:[id] })
                });
                const j = await res.json();
                if (j && j.success) { alert('E-Mail gesendet.'); } else { alert('Senden fehlgeschlagen.'); }
            } catch(e){ alert('Fehler: '+e.message); }
        }
        // Benachrichtigung aller mit E-Mail
        window.notifyAllAtemschutz = async function(){
            try {
                const res = await fetch('/admin/atemschutz-notify.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ all:true }) });
                const j = await res.json();
                if (j && j.success) { alert('E-Mails versendet: '+(j.sent||0)); } else { alert('Versand fehlgeschlagen.'); }
            } catch(e){ alert('Fehler: '+e.message); }
        }

        // Submit für E-Mail-Eintragsmodal
        const emailForm = document.getElementById('emailEntryForm');
        if (emailForm) {
            emailForm.addEventListener('submit', async function(ev){
                ev.preventDefault();
                const id = document.getElementById('email_entry_id').value;
                const email = document.getElementById('email_entry_email').value.trim();
                const err = document.getElementById('email_entry_error');
                err.classList.add('d-none'); err.textContent='';
                if (!email) { err.textContent = 'Bitte E-Mail angeben.'; err.classList.remove('d-none'); return; }
                try {
                    // bestehende Daten holen
                    const r = await fetch('/admin/atemschutz-get.php?id='+encodeURIComponent(id));
                    const data = await r.json();
                    if (!data || data.success !== true) { throw new Error('Datensatz nicht gefunden'); }
                    const d = data.data || {};
                    const form = new FormData();
                    form.append('action','update_atemschutz_traeger');
                    form.append('id', String(id));
                    form.append('first_name', d.first_name || '');
                    form.append('last_name', d.last_name || '');
                    form.append('email', email);
                    form.append('birthdate', d.birthdate || '');
                    form.append('strecke_am', d.strecke_am || '');
                    form.append('g263_am', d.g263_am || '');
                    form.append('uebung_am', d.uebung_am || '');
                    await fetch('dashboard.php', { method:'POST', body: form });
                    // danach senden
                    const res = await fetch('/admin/atemschutz-notify.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ ids:[Number(id)] }) });
                    const j = await res.json();
                    if (!j || !j.success) throw new Error('Versand fehlgeschlagen');
                    bootstrap.Modal.getInstance(document.getElementById('emailEntryModal'))?.hide();
                    alert('E-Mail gesendet.');
                    // optional: Seite neu laden um "Benachrichtigt am" zu aktualisieren
                    location.reload();
                } catch(e){ err.textContent = e.message; err.classList.remove('d-none'); }
            });
        }
        // Bootstrap Dropdowns sicher initialisieren (nach bundle load)
        document.addEventListener('DOMContentLoaded', function(){
            try {
                document.querySelectorAll('.dropdown-toggle').forEach(function(el){
                    try { bootstrap.Dropdown.getOrCreateInstance(el); } catch(_){}
                });
            } catch(_) {}
        });

        // Prefill für Atemschutz-Dashboard-Edit-Modal
        // Kein JS für Bearbeiten nötig – direkter Link zur Liste mit ?edit_id
    </script>
    <?php endif; ?>
</body>
</html>