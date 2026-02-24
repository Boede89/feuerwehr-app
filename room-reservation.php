<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/einheit-settings-helper.php';
require_once __DIR__ . '/includes/rooms-setup.php';

$message = '';
$error = '';
$selectedRoom = null;
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_id = $einheit_id > 0 ? $einheit_id : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';

$room_id = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
if ($room_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$room_id]);
        $selectedRoom = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$selectedRoom) {
            $error = "Raum nicht gefunden oder nicht verfügbar.";
            $selectedRoom = null;
        }
    } catch (PDOException $e) {
        $error = "Fehler beim Laden des Raums.";
        $selectedRoom = null;
    }
} else {
    $error = "Bitte wählen Sie zuerst einen Raum aus.";
    $selectedRoom = null;
}

$redirect_to_home = false;
$conflict_found = false;
$conflict_timeframe = null;

// Konflikt-Verarbeitung (wenn Benutzer trotz Konflikt fortfahren möchte)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['force_submit_room_reservation']) && $selectedRoom && isset($selectedRoom['id'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrf_token)) {
        $error = "Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.";
    } else {
        $requester_name = sanitize_input($_POST['requester_name'] ?? '');
        $requester_email = sanitize_input($_POST['requester_email'] ?? '');
        $reason = sanitize_input($_POST['reason'] ?? '');
        $location = sanitize_input($_POST['location'] ?? '');
        $room_id = (int)($selectedRoom['id'] ?? $_POST['room_id'] ?? 0);

        $date_times = [];
        $i = 0;
        while (isset($_POST["start_datetime_$i"]) && isset($_POST["end_datetime_$i"])) {
            $start_datetime = sanitize_input($_POST["start_datetime_$i"] ?? '');
            $end_datetime = sanitize_input($_POST["end_datetime_$i"] ?? '');
            if (!empty($start_datetime) && !empty($end_datetime)) {
                $date_times[] = ['start' => $start_datetime, 'end' => $end_datetime];
            }
            $i++;
        }

        if ($room_id && $requester_name && $requester_email && $reason && !empty($date_times)) {
            $success_count = 0;
            foreach ($date_times as $dt) {
                try {
                    $res_einheit = (int)($_POST['einheit_id'] ?? $einheit_id);
                    if ($res_einheit <= 0 && !empty($selectedRoom['einheit_id'])) {
                        $res_einheit = (int)$selectedRoom['einheit_id'];
                    }
                    $stmt = $db->prepare("INSERT INTO room_reservations (room_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts, status, einheit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$room_id, $requester_name, $requester_email, $reason, $location, $dt['start'], $dt['end'], json_encode([]), 'pending', $res_einheit > 0 ? $res_einheit : null]);
                    $success_count++;
                } catch (PDOException $e) {
                    $error = "Fehler beim Speichern: " . $e->getMessage();
                    break;
                }
            }
            if ($success_count > 0) {
                $admin_emails = [];
                $einheit_for_mail = 0;
                try {
                    $einheit_for_mail = (int)($_POST['einheit_id'] ?? $einheit_id);
                    $einheit_for_mail = $einheit_for_mail > 0 ? $einheit_for_mail : (int)($selectedRoom['einheit_id'] ?? 0);
                    if ($einheit_for_mail > 0) {
                        $settings = load_settings_for_einheit($db, $einheit_for_mail);
                        $ids_json = $settings['room_reservation_notification_user_ids'] ?? $settings['reservation_notification_user_ids'] ?? '';
                        if ($ids_json !== '') {
                            $ids = json_decode($ids_json, true);
                            if (is_array($ids) && !empty($ids)) {
                                $amern_id = function_exists('get_einheit_amern_id') ? get_einheit_amern_id($db) : 0;
                                $ph = implode(',', array_fill(0, count($ids), '?'));
                                $unit_filter = ($amern_id > 0 && $einheit_for_mail === $amern_id)
                                    ? " AND (einheit_id = ? OR einheit_id IS NULL)"
                                    : " AND einheit_id = ?";
                                $stmt = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND is_active = 1 AND email IS NOT NULL AND email != ''" . $unit_filter);
                                $params = array_merge(array_map('intval', $ids), [$einheit_for_mail]);
                                $stmt->execute($params);
                                $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            }
                        }
                    }
                } catch (Exception $e) {}
                $room_name = $selectedRoom['name'] ?? 'Unbekannt';
                $subject = "Neue Raumreservierung (mit Konflikt) - " . $room_name;
                try {
                    $stmtApp = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_url'");
                    $stmtApp->execute();
                    $appUrl = $stmtApp->fetchColumn();
                    if (!$appUrl) $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                } catch (Exception $e) {
                    $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                }
                $manageUrl = $appUrl . '/admin/reservations.php';
                $message_content = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2>Neue Raumreservierung (mit Konflikt)</h2>
                    <p><strong>Raum:</strong> " . htmlspecialchars($room_name) . "</p>
                    <p><strong>Antragsteller:</strong> " . htmlspecialchars($requester_name) . "</p>
                    <p><strong>E-Mail:</strong> " . htmlspecialchars($requester_email) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reason) . "</p>
                    <p><strong>Zeiträume:</strong><br>";
                foreach ($date_times as $dt) {
                    $message_content .= date('d.m.Y H:i', strtotime($dt['start'])) . " - " . date('d.m.Y H:i', strtotime($dt['end'])) . "<br>";
                }
                $message_content .= "</p>
                    <p><em>Hinweis: Diese Reservierung überschneidet sich mit bestehenden Reservierungen.</em></p>
                    <p><a href='" . htmlspecialchars($manageUrl) . "'>Reservierungen verwalten</a></p>
                </div>";
                if (!empty($admin_emails) && $einheit_for_mail > 0) {
                    foreach ($admin_emails as $admin_email) {
                        send_email_for_einheit($admin_email, $subject, $message_content, $einheit_for_mail, true);
                    }
                }
                $message = $success_count === 1 ? "Raumreservierung wurde erfolgreich eingereicht (trotz Konflikt)." : "$success_count Raumreservierungen wurden erfolgreich eingereicht (trotz Konflikt).";
                $redirect_to_home = true;
            }
        } else {
            $error = "Bitte füllen Sie alle Felder aus und geben Sie mindestens einen Zeitraum an.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_room_reservation']) && !isset($_POST['force_submit_room_reservation']) && $selectedRoom && isset($selectedRoom['id'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($csrf_token)) {
        $error = "Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.";
    } else {
        $requester_name = sanitize_input($_POST['requester_name'] ?? '');
        $requester_email = sanitize_input($_POST['requester_email'] ?? '');
        $reason = sanitize_input($_POST['reason'] ?? '');
        $location = sanitize_input($_POST['location'] ?? '');
        $room_id = (int)($selectedRoom['id'] ?? $_POST['room_id'] ?? 0);

        $date_times = [];
        $i = 0;
        while (isset($_POST["start_datetime_$i"]) && isset($_POST["end_datetime_$i"])) {
            $start_datetime = sanitize_input($_POST["start_datetime_$i"] ?? '');
            $end_datetime = sanitize_input($_POST["end_datetime_$i"] ?? '');
            if (!empty($start_datetime) && !empty($end_datetime)) {
                $date_times[] = ['start' => $start_datetime, 'end' => $end_datetime];
            }
            $i++;
        }

        if (empty($requester_name) || empty($requester_email) || empty($reason) || empty($date_times)) {
            $error = "Bitte füllen Sie alle Felder aus und geben Sie mindestens einen Zeitraum an.";
        } elseif (!validate_email($requester_email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            $success_count = 0;
            $errors = [];
            foreach ($date_times as $index => $dt) {
                $start_datetime = $dt['start'];
                $end_datetime = $dt['end'];
                if (!validate_datetime($start_datetime) || !validate_datetime($end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Bitte geben Sie gültige Datum und Uhrzeit ein.";
                    continue;
                }
                if (strtotime($start_datetime) >= strtotime($end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das Enddatum muss nach dem Startdatum liegen.";
                    continue;
                }
                if (strtotime($start_datetime) < time()) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das Startdatum darf nicht in der Vergangenheit liegen.";
                    continue;
                }
                if (check_room_conflict($room_id, $start_datetime, $end_datetime)) {
                    $conflicts = get_room_conflicts($room_id, $start_datetime, $end_datetime);
                    $existing = $conflicts[0] ?? null;
                    $conflict_found = true;
                    $conflict_timeframe = [
                        'index' => $index + 1,
                        'start' => $start_datetime,
                        'end' => $end_datetime,
                        'room_id' => $room_id,
                        'room_name' => $existing['room_name'] ?? ($selectedRoom['name'] ?? 'Unbekannt'),
                        'existing_reservation' => $existing,
                        'date_times' => $date_times,
                    ];
                    break;
                }
                try {
                    $res_einheit = (int)($_POST['einheit_id'] ?? $einheit_id);
                    if ($res_einheit <= 0 && !empty($selectedRoom['einheit_id'])) {
                        $res_einheit = (int)$selectedRoom['einheit_id'];
                    }
                    $stmt = $db->prepare("INSERT INTO room_reservations (room_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts, status, einheit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$room_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode([]), 'pending', $res_einheit > 0 ? $res_einheit : null]);
                    $success_count++;
                } catch (PDOException $e) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Fehler beim Speichern - " . $e->getMessage();
                }
            }

            if ($success_count > 0) {
                $admin_emails = [];
                $einheit_for_mail = 0;
                try {
                    $einheit_for_mail = $einheit_id > 0 ? $einheit_id : (int)($selectedRoom['einheit_id'] ?? 0);
                    if ($einheit_for_mail > 0) {
                        $settings = load_settings_for_einheit($db, $einheit_for_mail);
                        $ids_json = $settings['room_reservation_notification_user_ids'] ?? $settings['reservation_notification_user_ids'] ?? '';
                        if ($ids_json !== '') {
                            $ids = json_decode($ids_json, true);
                            if (is_array($ids) && !empty($ids)) {
                                $amern_id = function_exists('get_einheit_amern_id') ? get_einheit_amern_id($db) : 0;
                                $ph = implode(',', array_fill(0, count($ids), '?'));
                                $unit_filter = ($amern_id > 0 && $einheit_for_mail === $amern_id)
                                    ? " AND (einheit_id = ? OR einheit_id IS NULL)"
                                    : " AND einheit_id = ?";
                                $stmt = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND is_active = 1 AND email IS NOT NULL AND email != ''" . $unit_filter);
                                $params = array_merge(array_map('intval', $ids), [$einheit_for_mail]);
                                $stmt->execute($params);
                                $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            }
                        }
                    }
                } catch (Exception $e) {}

                $room_name = $selectedRoom['name'] ?? 'Unbekannt';
                $subject = "Neue Raumreservierung - " . $room_name;
                try {
                    $stmtApp = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_url'");
                    $stmtApp->execute();
                    $appUrl = $stmtApp->fetchColumn();
                    if (!$appUrl) $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                } catch (Exception $e) {
                    $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                }
                $manageUrl = $appUrl . '/admin/reservations.php';

                $message_content = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2>Neue Raumreservierung</h2>
                    <p><strong>Raum:</strong> " . htmlspecialchars($room_name) . "</p>
                    <p><strong>Antragsteller:</strong> " . htmlspecialchars($requester_name) . "</p>
                    <p><strong>E-Mail:</strong> " . htmlspecialchars($requester_email) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reason) . "</p>
                    <p><strong>Zeiträume:</strong><br>";
                foreach ($date_times as $dt) {
                    $message_content .= date('d.m.Y H:i', strtotime($dt['start'])) . " - " . date('d.m.Y H:i', strtotime($dt['end'])) . "<br>";
                }
                $message_content .= "</p>
                    <p><a href='" . htmlspecialchars($manageUrl) . "'>Reservierungen verwalten</a></p>
                </div>";

                if (!empty($admin_emails) && $einheit_for_mail > 0) {
                    foreach ($admin_emails as $admin_email) {
                        send_email_for_einheit($admin_email, $subject, $message_content, $einheit_for_mail, true);
                    }
                }

                $message = $success_count === 1 ? "Raumreservierung wurde erfolgreich eingereicht." : "$success_count Raumreservierungen wurden erfolgreich eingereicht.";
                if (!empty($errors)) $message .= " Hinweis: " . implode(' ', $errors);
                $redirect_to_home = true;
            } elseif (!$conflict_found) {
                $error = !empty($errors) ? implode(' ', $errors) : "Keine Reservierungen konnten gespeichert werden.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raum Reservierung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-door-open"></i> Raum Reservierung
                        </h3>
                        <p class="text-muted mb-0">Ausgewählter Raum: <strong><?php echo isset($selectedRoom['name']) ? htmlspecialchars($selectedRoom['name']) : 'Kein Raum ausgewählt'; ?></strong></p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($message): echo show_success($message); endif; ?>
                        <?php if ($error): echo show_error($error); endif; ?>

                        <?php if ($selectedRoom && isset($selectedRoom['id'])): ?>
                        <form method="POST" action="" id="roomReservationForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <?php 
                            $form_einheit = $einheit_id > 0 ? $einheit_id : (int)($selectedRoom['einheit_id'] ?? 0);
                            if ($form_einheit > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$form_einheit; ?>"><?php endif; ?>
                            <input type="hidden" name="room_id" value="<?php echo (int)$selectedRoom['id']; ?>">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="requester_name" class="form-label">Ihr Name *</label>
                                    <input type="text" class="form-control" id="requester_name" name="requester_name" value="<?php echo htmlspecialchars($_POST['requester_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="requester_email" class="form-label">E-Mail Adresse *</label>
                                    <input type="email" class="form-control" id="requester_email" name="requester_email" value="<?php echo htmlspecialchars($_POST['requester_email'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="reason" class="form-label">Grund der Reservierung *</label>
                                <input type="text" class="form-control" id="reason" name="reason" value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>" placeholder="z.B. Übung, Sitzung, Veranstaltung" required>
                            </div>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6><i class="fas fa-calendar"></i> Zeiträume *</h6>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-timeframe">
                                        <i class="fas fa-plus"></i> Weitere Zeit hinzufügen
                                    </button>
                                </div>
                                <div id="timeframes">
                                    <div class="timeframe-row row mb-3 g-3">
                                        <div class="col-md-5">
                                            <label class="form-label">Von (Datum & Uhrzeit) *</label>
                                            <input type="datetime-local" class="form-control start-datetime" name="start_datetime_0" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label">Bis (Datum & Uhrzeit) *</label>
                                            <input type="datetime-local" class="form-control end-datetime" name="end_datetime_0" required>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-timeframe w-100" style="display: none;"> <i class="fas fa-trash"></i> </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="reservation-choice.php<?php echo $einheit_param; ?>" class="btn btn-outline-secondary me-md-2"> <i class="fas fa-arrow-left"></i> Zurück </a>
                                <button type="submit" name="submit_room_reservation" value="1" class="btn btn-primary" id="submitRoomBtn">
                                    <i class="fas fa-paper-plane"></i> Reservierung beantragen
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-exclamation-triangle"></i> Kein Raum ausgewählt</h6>
                            <p class="mb-0">Bitte wählen Sie zuerst einen Raum aus.</p>
                            <a href="room-selection.php<?php echo $einheit_param; ?>" class="btn btn-primary btn-sm mt-2"> <i class="fas fa-door-open"></i> Raum auswählen </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let timeframeCount = 1;
        document.getElementById('add-timeframe')?.addEventListener('click', function() {
            const timeframesDiv = document.getElementById('timeframes');
            const newRow = document.createElement('div');
            newRow.className = 'timeframe-row row mb-3 g-3';
            newRow.innerHTML = '<div class="col-md-5"><label class="form-label">Von (Datum & Uhrzeit) *</label><input type="datetime-local" class="form-control start-datetime" name="start_datetime_' + timeframeCount + '" required></div><div class="col-md-5"><label class="form-label">Bis (Datum & Uhrzeit) *</label><input type="datetime-local" class="form-control end-datetime" name="end_datetime_' + timeframeCount + '" required></div><div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-timeframe w-100"> <i class="fas fa-trash"></i> </button></div>';
            timeframesDiv.appendChild(newRow);
            timeframeCount++;
            document.querySelectorAll('.remove-timeframe').forEach(btn => btn.style.display = 'block');
        });
        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-timeframe')) {
                e.target.closest('.timeframe-row').remove();
                if (document.querySelectorAll('.timeframe-row').length === 1) {
                    document.querySelectorAll('.remove-timeframe').forEach(btn => btn.style.display = 'none');
                }
            }
        });
        <?php if ($redirect_to_home): ?>
        setTimeout(function() { window.location.href = <?php echo json_encode('index.php' . $einheit_param); ?>; }, 3000);
        <?php endif; ?>

        <?php if (isset($conflict_found) && $conflict_found && $conflict_timeframe): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const conflictModal = new bootstrap.Modal(document.getElementById('roomConflictModal'));
            conflictModal.show();
            document.getElementById('cancelRoomReservationBtn')?.addEventListener('click', function() {
                conflictModal.hide();
            });
        });
        <?php endif; ?>
    </script>

    <?php if (isset($conflict_found) && $conflict_found && $conflict_timeframe): ?>
    <!-- Konflikt-Modal für Raumreservierung -->
    <div class="modal fade" id="roomConflictModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Raum bereits reserviert
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6><strong>Konflikt erkannt!</strong></h6>
                        <p>Der ausgewählte Raum <strong><?php echo htmlspecialchars($conflict_timeframe['room_name']); ?></strong> ist bereits für den gewünschten Zeitraum reserviert:</p>
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-calendar-alt text-danger"></i> Ihr gewünschter Zeitraum (Zeitraum <?php echo (int)$conflict_timeframe['index']; ?>):</h6>
                                <p class="mb-2">
                                    <strong><?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['start'])); ?> - <?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['end'])); ?></strong>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-exclamation-triangle text-warning"></i> Bereits reserviert:</h6>
                                <?php if (!empty($conflict_timeframe['existing_reservation'])): ?>
                                    <p class="mb-1">
                                        <strong>Zeitraum:</strong> <?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['existing_reservation']['start_datetime'])); ?> - <?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['existing_reservation']['end_datetime'])); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Antragsteller:</strong> <?php echo htmlspecialchars($conflict_timeframe['existing_reservation']['requester_name']); ?>
                                    </p>
                                    <p class="mb-0">
                                        <strong>Grund:</strong> <?php echo htmlspecialchars($conflict_timeframe['existing_reservation']['reason']); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="mb-0 text-muted">Details der bestehenden Reservierung konnten nicht geladen werden.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <p>Möchten Sie den Antrag trotzdem einreichen? Der Administrator wird über den Konflikt informiert und kann entscheiden, ob beide Reservierungen möglich sind.</p>
                    <div class="alert alert-info">
                        <small><i class="fas fa-info-circle"></i> <strong>Hinweis:</strong> Bei einer Überschneidung kann es zu Problemen bei der Raumverfügbarkeit kommen.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                        <input type="hidden" name="room_id" value="<?php echo (int)$conflict_timeframe['room_id']; ?>">
                        <input type="hidden" name="requester_name" value="<?php echo htmlspecialchars($_POST['requester_name'] ?? ''); ?>">
                        <input type="hidden" name="requester_email" value="<?php echo htmlspecialchars($_POST['requester_email'] ?? ''); ?>">
                        <input type="hidden" name="reason" value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>">
                        <input type="hidden" name="location" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                        <?php foreach ($conflict_timeframe['date_times'] ?? [] as $i => $dt): ?>
                        <input type="hidden" name="start_datetime_<?php echo $i; ?>" value="<?php echo htmlspecialchars($dt['start']); ?>">
                        <input type="hidden" name="end_datetime_<?php echo $i; ?>" value="<?php echo htmlspecialchars($dt['end']); ?>">
                        <?php endforeach; ?>
                        <button type="submit" name="force_submit_room_reservation" class="btn btn-warning">
                            <i class="fas fa-exclamation-triangle"></i> Antrag trotzdem versenden
                        </button>
                    </form>
                    <button type="button" id="cancelRoomReservationBtn" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Antrag abbrechen
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
