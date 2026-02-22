<?php
/**
 * Admin Reservations - Vereinfachte Version nur für bearbeitete Reservierungen
 */

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once '../config/divera.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Reservierungen-Rechte hat
// Fallback auf alte Rolle-Prüfung falls neue Permissions nicht verfügbar
if (!has_permission('reservations') && !can_approve_reservations()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

/**
 * Löscht eine bearbeitete Reservierung inkl. Divera- und Google-Calendar-Sync.
 * @return bool true bei Erfolg, false sonst
 */
function delete_reservation_with_cleanup($db, $reservation_id) {
    global $divera_config;
    $reservation_id = (int) $reservation_id;
    if ($reservation_id <= 0) return false;
    try {
        try {
            $db->exec("ALTER TABLE reservations ADD COLUMN divera_event_id INT NULL DEFAULT NULL");
        } catch (Exception $e) { /* Spalte existiert bereits */ }
        $stmt = $db->prepare("SELECT status, divera_event_id, approved_by FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        if (!$reservation || !in_array($reservation['status'], ['approved', 'rejected', 'cancelled'])) return false;

        $divera_reservation_enabled = true;
        $google_calendar_reservation_enabled = true;
        $stmt_set = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_reservation_enabled', 'google_calendar_reservation_enabled')");
        $stmt_set->execute();
        while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_reservation_enabled') $divera_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
            if ($row['setting_key'] === 'google_calendar_reservation_enabled') $google_calendar_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
        }

        if ($divera_reservation_enabled) {
            $divera_event_id = (int) ($reservation['divera_event_id'] ?? 0);
            $divera_key = '';
            $approved_by = (int) ($reservation['approved_by'] ?? 0);
            if ($approved_by > 0) {
                $stmt_u = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                $stmt_u->execute([$approved_by]);
                $uk = $stmt_u->fetch(PDO::FETCH_ASSOC);
                $divera_key = trim((string) ($uk['divera_access_key'] ?? ''));
            }
            if ($divera_key === '' && isset($_SESSION['user_id'])) {
                $stmt_u = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                $stmt_u->execute([$_SESSION['user_id']]);
                $uk = $stmt_u->fetch(PDO::FETCH_ASSOC);
                $divera_key = trim((string) ($uk['divera_access_key'] ?? ''));
            }
            if ($divera_key === '') $divera_key = trim((string) ($divera_config['access_key'] ?? ''));
            $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
            if ($divera_event_id <= 0 && $divera_key !== '' && function_exists('find_divera_event_by_foreign_id')) {
                $divera_event_id = find_divera_event_by_foreign_id($reservation_id, $divera_key, $api_base) ?? 0;
            }
            if ($divera_event_id > 0 && $divera_key !== '' && function_exists('delete_divera_event')) {
                delete_divera_event($divera_event_id, $divera_key, $api_base);
            }
        }

        $stmt = $db->prepare("SELECT google_event_id, start_datetime, end_datetime, title FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        $calendar_event = $stmt->fetch();
        $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);

        $remaining_links = 0;
        if ($google_calendar_reservation_enabled && $calendar_event && !empty($calendar_event['google_event_id'])) {
            $google_event_id = $calendar_event['google_event_id'];
            $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE google_event_id = ?");
            $stmt->execute([$google_event_id]);
            $remaining_links = (int)$stmt->fetchColumn();
            if ($remaining_links === 0 && function_exists('delete_google_calendar_event')) {
                delete_google_calendar_event($google_event_id);
            } elseif ($remaining_links > 0 && function_exists('update_google_calendar_event_title')) {
                $stmt = $db->prepare("SELECT r.reason, v.name as vehicle_name FROM calendar_events ce JOIN reservations r ON ce.reservation_id = r.id JOIN vehicles v ON r.vehicle_id = v.id WHERE ce.google_event_id = ? ORDER BY v.name");
                $stmt->execute([$google_event_id]);
                $remaining_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($remaining_reservations)) {
                    $vehicles = array_unique(array_column($remaining_reservations, 'vehicle_name'));
                    $reasons = array_unique(array_column($remaining_reservations, 'reason'));
                    $new_title = implode(', ', $vehicles) . (count($reasons) === 1 ? ' - ' . $reasons[0] : '');
                    if (update_google_calendar_event_title($google_event_id, $new_title)) {
                        $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
                        $stmt->execute([$new_title, $google_event_id]);
                    }
                }
            }
        }

        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);
        return true;
    } catch (Exception $e) {
        error_log("delete_reservation_with_cleanup: " . $e->getMessage());
        return false;
    }
}

// Abgelaufene Reservierungen automatisch löschen (end_datetime < jetzt)
try {
    $stmt = $db->prepare("SELECT id FROM reservations WHERE status IN ('approved', 'rejected', 'cancelled') AND end_datetime < NOW()");
    $stmt->execute();
    $expired_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $deleted_count = 0;
    foreach ($expired_ids as $eid) {
        if (delete_reservation_with_cleanup($db, $eid)) $deleted_count++;
    }
    if ($deleted_count > 0 && empty($message)) {
        $message = $deleted_count === 1
            ? "1 abgelaufene Reservierung wurde automatisch entfernt."
            : $deleted_count . " abgelaufene Reservierungen wurden automatisch entfernt.";
    }
} catch (Exception $e) {
    error_log("Auto-cleanup abgelaufener Reservierungen: " . $e->getMessage());
}

// Manuelles Löschen einer bearbeiteten Reservierung
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    $reservation_id = (int)$_POST['reservation_id'];
    try {
        if (delete_reservation_with_cleanup($db, $reservation_id)) {
            $message = "Reservierung erfolgreich gelöscht.";
        } else {
            $error = "Nur bearbeitete Reservierungen können gelöscht werden.";
        }
    } catch (Exception $e) {
        $error = "Fehler beim Löschen der Reservierung: " . $e->getMessage();
    }
}

// Nur aus Google Kalender löschen (Kalender-Event entfernen, Reservierung behalten)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_calendar_event') {
    $reservation_id = (int)$_POST['reservation_id'];
    try {
        // Alle Kalender-Verknüpfungen laden
        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        $calendar_events = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($calendar_events)) {
            foreach ($calendar_events as $geid) {
                if (!empty($geid) && function_exists('delete_google_calendar_event')) {
                    $ok = delete_google_calendar_event($geid);
                    if (!$ok) {
                        error_log('Kalender-Event konnte nicht via API gelöscht werden: ' . $geid);
                    }
                }
            }
        } else {
            error_log('Keine calendar_events Verknüpfungen für reservation_id=' . $reservation_id);
        }

        // Fallback: Versuche anhand Titel/Zeitraum im Kalender zu löschen
        // Hole Reservierungsdaten
        try {
            $stmt = $db->prepare("SELECT v.name as vehicle_name, r.reason, r.start_datetime, r.end_datetime FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$reservation_id]);
            $res = $stmt->fetch();
            if ($res) {
                $title = ($res['vehicle_name'] ?? '') . ' - ' . ($res['reason'] ?? '');
                if (function_exists('delete_google_calendar_event_by_hint')) {
                    $hintOk = delete_google_calendar_event_by_hint($title, $res['start_datetime'], $res['end_datetime']);
                    if ($hintOk) {
                        error_log('Kalender-Event per Fallback (Hint) entfernt für Reservation ' . $reservation_id);
                    }
                }
            }
        } catch (Exception $ie) {
            error_log('Fallback-Hint-Query fehlgeschlagen: ' . $ie->getMessage());
        }

        // Lokale Verknüpfungen entfernen
        $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);

        $message = 'Kalender-Eintrag(e) (Google) wurden entfernt. Die Reservierung bleibt erhalten.';
    } catch (Exception $e) {
        $error = 'Fehler beim Entfernen des Kalender-Eintrags: ' . $e->getMessage();
    }
}

// Fahrzeug aus Kalendereintrag entfernen (nur Titel anpassen, Event bleibt)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'remove_vehicle_from_calendar') {
    try {
        $reservation_id = (int)($_POST['reservation_id'] ?? 0);
        if ($reservation_id <= 0) {
            throw new Exception('Ungültige Reservierungs-ID');
        }

        // Reservierungsdaten laden
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        
        if (!$reservation) {
            throw new Exception('Reservierung nicht gefunden');
        }

        // Google Event ID aus calendar_events laden
        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        $calendar_event = $stmt->fetch();
        
        if (!$calendar_event || empty($calendar_event['google_event_id'])) {
            // Keine Google Event ID gefunden - Reservierung wurde wahrscheinlich abgelehnt
            // Lösche die Reservierung direkt aus der Datenbank
            $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
            $stmt->execute([$reservation_id]);
            $message = "Reservierung wurde gelöscht (kein Google Calendar Eintrag vorhanden - wahrscheinlich abgelehnt).";
        } else {
            // Fahrzeug aus Kalendereintrag entfernen
            $success = remove_vehicle_from_calendar_event($calendar_event['google_event_id'], $reservation['vehicle_name'], $reservation_id);
            
            if ($success) {
                $message = "Fahrzeug '{$reservation['vehicle_name']}' wurde aus dem Kalendereintrag entfernt.";
            } else {
                $error = "Fehler beim Entfernen des Fahrzeugs aus dem Kalendereintrag.";
            }
        }
        
    } catch (Exception $e) {
        $error = 'Fehler beim Entfernen des Fahrzeugs: ' . $e->getMessage();
    }
}

// Komplett löschen: erst Kalender-Einträge löschen (Divera/Google je nach Einstellung), danach Reservierung + Verknüpfungen entfernen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_complete') {
    try {
        $reservation_id = (int)($_POST['reservation_id'] ?? 0);
        if ($reservation_id <= 0) {
            throw new Exception('Ungültige Reservierungs-ID');
        }

        // Terminübergabe-Einstellungen laden
        $divera_reservation_enabled = true;
        $google_calendar_reservation_enabled = true;
        $stmt_set = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_reservation_enabled', 'google_calendar_reservation_enabled')");
        $stmt_set->execute();
        while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_reservation_enabled') $divera_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
            if ($row['setting_key'] === 'google_calendar_reservation_enabled') $google_calendar_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
        }

        // 1a) Divera-Termin löschen (wenn aktiviert) – Access Key: zuerst Genehmiger, dann aktueller User, dann Einheits-Key
        if ($divera_reservation_enabled) {
            $stmt = $db->prepare("SELECT divera_event_id, approved_by FROM reservations WHERE id = ?");
            $stmt->execute([$reservation_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $divera_event_id = (int) ($row['divera_event_id'] ?? 0);
            $divera_key = '';
            $approved_by = (int) ($row['approved_by'] ?? 0);
            if ($approved_by > 0) {
                $stmt_u = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                $stmt_u->execute([$approved_by]);
                $uk = $stmt_u->fetch(PDO::FETCH_ASSOC);
                $divera_key = trim((string) ($uk['divera_access_key'] ?? ''));
            }
            if ($divera_key === '' && isset($_SESSION['user_id'])) {
                $stmt_u = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                $stmt_u->execute([$_SESSION['user_id']]);
                $uk = $stmt_u->fetch(PDO::FETCH_ASSOC);
                $divera_key = trim((string) ($uk['divera_access_key'] ?? ''));
            }
            if ($divera_key === '') {
                $divera_key = trim((string) ($divera_config['access_key'] ?? ''));
            }
            $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
            if ($divera_event_id <= 0 && $divera_key !== '' && function_exists('find_divera_event_by_foreign_id')) {
                $divera_event_id = find_divera_event_by_foreign_id($reservation_id, $divera_key, $api_base) ?? 0;
                if ($divera_event_id > 0) error_log('DELETE-COMPLETE: Divera Event-ID per foreign_id ermittelt: ' . $divera_event_id);
            }
            if ($divera_event_id > 0 && $divera_key !== '' && delete_divera_event($divera_event_id, $divera_key, $api_base)) {
                error_log('DELETE-COMPLETE: Divera Event gelöscht: ' . $divera_event_id);
            }
        }

        // 1b) Google-Kalender-Einträge löschen (wenn aktiviert)
        if ($google_calendar_reservation_enabled) {
            $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$reservation_id]);
            $eventIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($eventIds)) {
                foreach ($eventIds as $geid) {
                    if (!empty($geid) && function_exists('delete_google_calendar_event')) {
                        $ok = delete_google_calendar_event($geid);
                        if (!$ok) {
                            error_log('DELETE-COMPLETE: Kalender-Event konnte nicht via API gelöscht werden: ' . $geid);
                        }
                    }
                }
            } else {
                // Fallback: Versuch per Titel/Zeitraum
                try {
                    $stmt = $db->prepare('SELECT v.name as vehicle_name, r.reason, r.start_datetime, r.end_datetime FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?');
                    $stmt->execute([$reservation_id]);
                    $res = $stmt->fetch();
                    if ($res && function_exists('delete_google_calendar_event_by_hint')) {
                        $title = ($res['vehicle_name'] ?? '') . ' - ' . ($res['reason'] ?? '');
                        delete_google_calendar_event_by_hint($title, $res['start_datetime'], $res['end_datetime']);
                    }
                } catch (Exception $ie) {
                    error_log('DELETE-COMPLETE: Fallback-Query Fehler: ' . $ie->getMessage());
                }
            }
        }

        // 2) Lokale Verknüpfungen entfernen
        $stmt = $db->prepare('DELETE FROM calendar_events WHERE reservation_id = ?');
        $stmt->execute([$reservation_id]);

        // 3) Reservierung entfernen
        $stmt = $db->prepare('DELETE FROM reservations WHERE id = ?');
        $stmt->execute([$reservation_id]);

        $message = 'Reservierung und zugehörige Kalender-Einträge wurden vollständig gelöscht.';
    } catch (Exception $e) {
        $error = 'Fehler beim vollständigen Löschen: ' . $e->getMessage();
    }
}

// Divera-Einstellungen für „Erneut übermitteln“-Button und Empfängergruppen-Auswahl
$divera_reservation_enabled = true;
$divera_reservation_groups = [];
$divera_reservation_default_group_id = '';
try {
    $stmt_set = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_reservation_enabled', 'divera_reservation_groups', 'divera_reservation_default_group_id')");
    $stmt_set->execute();
    while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
        if ($row['setting_key'] === 'divera_reservation_enabled') {
            $divera_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
        } elseif ($row['setting_key'] === 'divera_reservation_groups' && $row['setting_value'] !== '') {
            $dec = json_decode($row['setting_value'], true);
            $divera_reservation_groups = is_array($dec) ? $dec : [];
        } elseif ($row['setting_key'] === 'divera_reservation_default_group_id') {
            $divera_reservation_default_group_id = trim((string)$row['setting_value']);
        }
    }
} catch (Exception $e) { /* ignore */ }

// Nur bearbeitete Reservierungen laden (gefiltert nach Einheit für Superadmin/Einheitsadmin)
$einheit_filter = get_admin_einheit_filter();
try {
    $sql = "
        SELECT r.*, v.name as vehicle_name, u.first_name, u.last_name
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN users u ON r.approved_by = u.id
        WHERE r.status IN ('approved', 'rejected', 'cancelled')
    ";
    $params = [];
    if ($einheit_filter) {
        $sql .= " AND (v.einheit_id = ? OR v.einheit_id IS NULL)";
        $params[] = $einheit_filter;
    }
    $sql .= " ORDER BY r.start_datetime ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservierungen - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-calendar-check"></i> Bearbeitete Reservierungen
                    </h1>
                </div>
                
                <!-- Nur bearbeitete Reservierungen -->
                <div class="text-center mb-4">
                    <h6 class="text-muted">
                        <i class="fas fa-check-circle"></i> Alle genehmigten und abgelehnten Anträge
                    </h6>
                    <p class="text-muted small">
                        Für neue Anträge verwenden Sie das <a href="dashboard.php">Dashboard</a>
                    </p>
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

        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-success">
                            <i class="fas fa-check-circle"></i> Bearbeitete Reservierungen (<?php echo count($reservations); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reservations)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Keine bearbeiteten Reservierungen</h5>
                                <p class="text-muted">Alle Anträge werden über das Dashboard bearbeitet.</p>
                                <a href="dashboard.php" class="btn btn-primary">
                                    <i class="fas fa-tachometer-alt"></i> Zum Dashboard
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Mobile-optimierte Karten-Ansicht -->
                            <div class="d-md-none">
                                <?php foreach ($reservations as $reservation): ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="fas fa-truck text-primary"></i>
                                                    <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                                </h6>
                                                <span class="badge <?php 
                                                    if ($reservation['status'] === 'approved') echo 'bg-success';
                                                    elseif ($reservation['status'] === 'cancelled') echo 'bg-warning text-dark';
                                                    else echo 'bg-danger';
                                                ?>">
                                                    <?php 
                                                    if ($reservation['status'] === 'approved') echo 'Genehmigt';
                                                    elseif ($reservation['status'] === 'cancelled') echo 'Storniert';
                                                    else echo 'Abgelehnt';
                                                    ?>
                                                </span>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-calendar-alt text-success"></i>
                                                <strong><?php echo format_datetime($reservation['start_datetime'], 'd.m.Y'); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo format_datetime($reservation['start_datetime'], 'H:i'); ?> - 
                                                    <?php echo format_datetime($reservation['end_datetime'], 'H:i'); ?>
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
                                            
                                            <div class="d-grid gap-2">
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                    <i class="fas fa-eye"></i> Details
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $reservation['id']; ?>" style="min-height: 38px; min-width: 100px;">
                                                    <i class="fas fa-trash"></i> Löschen
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
                                            <th>Status</th>
                                            <th>Aktionen</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reservations as $reservation): ?>
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
                                                        <strong><?php echo format_datetime($reservation['start_datetime'], 'd.m.Y'); ?></strong><br>
                                                        <small class="text-muted">
                                                            <?php echo format_datetime($reservation['start_datetime'], 'H:i'); ?> - 
                                                            <?php echo format_datetime($reservation['end_datetime'], 'H:i'); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?php echo htmlspecialchars($reservation['reason']); ?>">
                                                            <?php echo htmlspecialchars($reservation['reason']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php 
                                                            if ($reservation['status'] === 'approved') echo 'bg-success';
                                                            elseif ($reservation['status'] === 'cancelled') echo 'bg-warning text-dark';
                                                            else echo 'bg-danger';
                                                        ?>">
                                                            <?php 
                                                            if ($reservation['status'] === 'approved') echo 'Genehmigt';
                                                            elseif ($reservation['status'] === 'cancelled') echo 'Storniert';
                                                            else echo 'Abgelehnt';
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                                <i class="fas fa-eye"></i> Details
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $reservation['id']; ?>">
                                                                <i class="fas fa-trash"></i> Löschen
                                                            </button>
                                                        </div>
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
    </div>

    <!-- Details-Modals -->
    <?php foreach ($reservations as $reservation): ?>
        <div class="modal fade" id="detailsModal<?php echo $reservation['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-info-circle"></i> Reservierungsdetails #<?php echo $reservation['id']; ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-truck text-primary"></i> Fahrzeug</h6>
                                <p><?php echo htmlspecialchars($reservation['vehicle_name']); ?></p>
                                
                                <h6><i class="fas fa-user text-info"></i> Antragsteller</h6>
                                <p>
                                    <strong><?php echo htmlspecialchars($reservation['requester_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($reservation['requester_email']); ?></small>
                                </p>
                                
                                <h6><i class="fas fa-calendar-alt text-success"></i> Zeitraum</h6>
                                <p>
                                    <strong>Von:</strong> <?php echo date('d.m.Y H:i', strtotime($reservation['start_datetime'])); ?><br>
                                    <strong>Bis:</strong> <?php echo date('d.m.Y H:i', strtotime($reservation['end_datetime'])); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-clipboard-list text-warning"></i> Grund</h6>
                                <p><?php echo htmlspecialchars($reservation['reason']); ?></p>
                                
                                <h6><i class="fas fa-map-marker-alt text-info"></i> Ort</h6>
                                <p><?php echo htmlspecialchars($reservation['location'] ?? 'Nicht angegeben'); ?></p>
                                
                                <h6><i class="fas fa-info-circle text-secondary"></i> Status</h6>
                                <p>
                                    <span class="badge <?php 
                                        if ($reservation['status'] === 'approved') echo 'bg-success';
                                        elseif ($reservation['status'] === 'cancelled') echo 'bg-warning text-dark';
                                        else echo 'bg-danger';
                                    ?>">
                                        <?php 
                                        if ($reservation['status'] === 'approved') echo 'Genehmigt';
                                        elseif ($reservation['status'] === 'cancelled') echo 'Storniert';
                                        else echo 'Abgelehnt';
                                        ?>
                                    </span>
                                </p>
                                
                                <h6><i class="fas fa-clock text-muted"></i> Erstellt</h6>
                                <p><small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($reservation['created_at'])); ?></small></p>
                                
                                <?php if ($reservation['status'] === 'rejected' && !empty($reservation['rejection_reason'])): ?>
                                    <h6><i class="fas fa-times-circle text-danger"></i> Ablehnungsgrund</h6>
                                    <p class="text-danger"><?php echo htmlspecialchars($reservation['rejection_reason']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($reservation['status'] === 'approved' && $divera_reservation_enabled && !empty($divera_reservation_groups)): ?>
                        <hr>
                        <div class="mb-3">
                            <label for="resendDiveraGroup<?php echo (int)$reservation['id']; ?>" class="form-label">
                                <i class="fas fa-users me-1"></i>Empfänger-Gruppe (Divera 24/7) – für erneute Übermittlung
                            </label>
                            <select class="form-select resend-divera-group-select" id="resendDiveraGroup<?php echo (int)$reservation['id']; ?>">
                                <option value="" <?php echo $divera_reservation_default_group_id === '' ? 'selected' : ''; ?>>– Standard (aus Einstellungen) –</option>
                                <?php foreach ($divera_reservation_groups as $g): 
                                    $gid = (int)($g['id'] ?? 0);
                                    $gval = $gid > 0 ? (string)$gid : '0';
                                    $gname = htmlspecialchars($g['name'] ?? ($gid > 0 ? 'Gruppe ' . $gid : 'Alle des Standortes'));
                                ?>
                                <option value="<?php echo $gval; ?>" <?php echo $divera_reservation_default_group_id === $gval ? 'selected' : ''; ?>>
                                    <?php echo $gname; ?><?php echo $gid > 0 ? ' (ID: ' . $gid . ')' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">An welche Divera-Gruppe der Termin bei „Erneut übermitteln“ gesendet werden soll.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <span class="badge <?php 
                            if ($reservation['status'] === 'approved') echo 'bg-success';
                            elseif ($reservation['status'] === 'cancelled') echo 'bg-warning text-dark';
                            else echo 'bg-danger';
                        ?> fs-6">
                            <?php 
                            if ($reservation['status'] === 'approved') echo 'Genehmigt';
                            elseif ($reservation['status'] === 'cancelled') echo 'Storniert';
                            else echo 'Abgelehnt';
                            ?>
                        </span>
                        <?php if ($reservation['status'] === 'approved' && $divera_reservation_enabled): ?>
                        <button type="button" class="btn btn-outline-primary btn-resend-divera" data-reservation-id="<?php echo (int)$reservation['id']; ?>" title="Termin erneut an Divera 24/7 senden (z.B. wenn die erste Übermittlung fehlgeschlagen ist)">
                            <i class="fas fa-paper-plane"></i> Erneut an Divera übermitteln
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Lösch-Modals für mobile Ansicht -->
    <?php foreach ($reservations as $reservation): ?>
    <div class="modal fade" id="deleteModal<?php echo $reservation['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Reservierung löschen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Achtung:</strong> Diese Aktion kann nicht rückgängig gemacht werden!
                    </div>
                    
                    <p>Sind Sie sicher, dass Sie diese Reservierung löschen möchten?</p>
                    
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-truck text-primary"></i>
                                <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                            </h6>
                            <p class="card-text mb-1">
                                <strong>Antragsteller:</strong> <?php echo htmlspecialchars($reservation['requester_name']); ?>
                            </p>
                            <p class="card-text mb-1">
                                <strong>Datum:</strong> <?php echo format_datetime($reservation['start_datetime'], 'd.m.Y H:i'); ?>
                            </p>
                            <p class="card-text mb-0">
                                <strong>Grund:</strong> <?php echo htmlspecialchars($reservation['reason']); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>Löschen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.btn-resend-divera').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const btnEl = this;
                const reservationId = btnEl.getAttribute('data-reservation-id');
                if (!reservationId) return;
                const modal = btnEl.closest('.modal');
                const groupSelect = modal ? modal.querySelector('.resend-divera-group-select') : null;
                let diveraGroupIds = [];
                if (groupSelect && groupSelect.value && groupSelect.value !== '0') {
                    diveraGroupIds = [parseInt(groupSelect.value, 10)];
                }
                const originalHtml = btnEl.innerHTML;
                btnEl.disabled = true;
                btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sende...';
                fetch('process-reservation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'resend_divera', reservation_id: parseInt(reservationId, 10), divera_group_ids: diveraGroupIds })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        alert(data.message || 'Termin wurde erneut an Divera 24/7 übermittelt.');
                        location.reload();
                    } else {
                        alert(data.message || 'Fehler bei der Übermittlung.');
                        btnEl.disabled = false;
                        btnEl.innerHTML = originalHtml;
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    alert('Netzwerkfehler. Bitte erneut versuchen.');
                    btnEl.disabled = false;
                    btnEl.innerHTML = originalHtml;
                });
            });
        });
    });
    </script>
</body>
</html>
