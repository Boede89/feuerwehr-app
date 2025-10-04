<?php
/**
 * Admin Reservations - Vollständige Version mit schönem Layout
 */

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Lade Google Calendar Klassen explizit
if (file_exists('../includes/google_calendar_service_account.php')) {
    require_once '../includes/google_calendar_service_account.php';
}

if (file_exists('../includes/google_calendar.php')) {
    require_once '../includes/google_calendar.php';
}

// Nur für eingeloggte Benutzer mit Admin-Zugriff
if (!has_admin_access()) {
    redirect('../login.php');
}

$message = '';
$error = '';

// Status ändern
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        try {
            if ($action == 'approve') {
                $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $reservation_id]);
                
                $message = "Reservierung erfolgreich genehmigt.";
                
                // Google Calendar Event erstellen
                try {
                    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                    $stmt->execute([$reservation_id]);
                    $reservation = $stmt->fetch();
                    
                    if ($reservation) {
                        if (function_exists('create_google_calendar_event')) {
                            $event_id = create_google_calendar_event(
                                $reservation['vehicle_name'],
                                $reservation['reason'],
                                $reservation['start_datetime'],
                                $reservation['end_datetime'],
                                $reservation['id'],
                                $reservation['location'] ?? null
                            );
                            
                            if ($event_id) {
                                $message .= " Google Calendar Event wurde erstellt.";
                            } else {
                                $message .= " Warnung: Google Calendar Event konnte nicht erstellt werden.";
                            }
                        } else {
                            $message .= " Warnung: Google Calendar Funktion nicht verfügbar.";
                        }
                    } else {
                        $message .= " Warnung: Reservierung nicht gefunden für Google Calendar.";
                    }
                } catch (Exception $e) {
                    error_log('ADMIN RESERVATIONS: Google Calendar Fehler: ' . $e->getMessage());
                    error_log('ADMIN RESERVATIONS: Google Calendar Stack Trace: ' . $e->getTraceAsString());
                    $message .= " Warnung: Google Calendar Fehler - " . $e->getMessage();
                }
                
                // E-Mail an Antragsteller senden
                try {
                    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                    $stmt->execute([$reservation_id]);
                    $reservation = $stmt->fetch();
                    
                    if ($reservation) {
                        $subject = "Fahrzeugreservierung genehmigt - " . $reservation['vehicle_name'];
                        $message_content = "
                        <h2>Fahrzeugreservierung genehmigt</h2>
                        <p>Ihre Fahrzeugreservierung wurde genehmigt.</p>
                        <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                        <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                        <p><strong>Ort:</strong> " . htmlspecialchars($reservation['location'] ?? 'Nicht angegeben') . "</p>
                        <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                        <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                        <p>Vielen Dank für Ihre Reservierung!</p>
                        ";
                        
                        send_email($reservation['requester_email'], $subject, $message_content);
                    }
                } catch (Exception $e) {
                    error_log('E-Mail Fehler: ' . $e->getMessage());
                }
                
                log_activity($_SESSION['user_id'], 'reservation_approved', "Reservierung #$reservation_id genehmigt");
                
            } elseif ($action == 'reject') {
                $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
                
                if (empty($rejection_reason)) {
                    $error = "Bitte geben Sie einen Ablehnungsgrund an.";
                } else {
                    $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $rejection_reason, $reservation_id]);
                    
                    $message = "Reservierung wurde abgelehnt.";
                    
                    // E-Mail an Antragsteller senden
                    try {
                        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                        $stmt->execute([$reservation_id]);
                        $reservation = $stmt->fetch();
                        
                        if ($reservation) {
                            $subject = "Fahrzeugreservierung abgelehnt - " . $reservation['vehicle_name'];
                            $message_content = "
                            <h2>Fahrzeugreservierung abgelehnt</h2>
                            <p>Ihre Fahrzeugreservierung wurde leider abgelehnt.</p>
                            <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                            <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                            <p><strong>Ort:</strong> " . htmlspecialchars($reservation['location'] ?? 'Nicht angegeben') . "</p>
                            <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                            <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                            <p><strong>Ablehnungsgrund:</strong> " . htmlspecialchars($rejection_reason) . "</p>
                            <p>Bitte wenden Sie sich an den Administrator für weitere Informationen.</p>
                            ";
                            
                            send_email($reservation['requester_email'], $subject, $message_content);
                        }
                    } catch (Exception $e) {
                        error_log('E-Mail Fehler: ' . $e->getMessage());
                    }
                    
                    log_activity($_SESSION['user_id'], 'reservation_rejected', "Reservierung #$reservation_id abgelehnt");
                }
            } elseif ($action == 'delete') {
                // Prüfe ob Reservierung gelöscht werden kann (nur bearbeitete)
                $stmt = $db->prepare("SELECT status FROM reservations WHERE id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation && in_array($reservation['status'], ['approved', 'rejected'])) {
                    // Google Calendar Event löschen falls vorhanden
                    try {
                        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
                        $stmt->execute([$reservation_id]);
                        $event = $stmt->fetch();
                        
                        if ($event && $event['google_event_id']) {
                            require_once '../includes/google_calendar_service_account.php';
                            
                            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                            $stmt->execute();
                            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                            
                            $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                            $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                            
                            if (!empty($service_account_json)) {
                                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                                $google_calendar->deleteEvent($event['google_event_id']);
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Google Calendar Lösch-Fehler: ' . $e->getMessage());
                    }
                    
                    // Reservierung löschen
                    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                    $stmt->execute([$reservation_id]);
                    
                    $message = "Reservierung wurde gelöscht.";
                    log_activity($_SESSION['user_id'], 'reservation_deleted', "Reservierung #$reservation_id gelöscht");
                } else {
                    $error = "Nur bearbeitete Reservierungen (genehmigt/abgelehnt) können gelöscht werden.";
                }
            }
        } catch(PDOException $e) {
            $error = "Fehler beim Verarbeiten der Reservierung: " . $e->getMessage();
        }
    }
}

// Reservierungen laden
$reservations = [];
try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, u.first_name, u.last_name
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN users u ON r.approved_by = u.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="reservations.php">
                            <i class="fas fa-calendar-check"></i> Reservierungen
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicles.php">
                            <i class="fas fa-truck"></i> Fahrzeuge
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users"></i> Benutzer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog"></i> Einstellungen
                        </a>
                    </li>
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
                <h1 class="h3 mb-4">
                    <i class="fas fa-calendar-check"></i> Reservierungen verwalten
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> Alle Reservierungen
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reservations)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Keine Reservierungen gefunden.</p>
                            </div>
                        <?php else: ?>
                            <!-- Karten-Ansicht für alle Geräte -->
                            <div class="row">
                                <?php foreach ($reservations as $reservation): ?>
                                    <div class="col-lg-6 col-xl-4 mb-3">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="card-title mb-0">
                                                        <i class="fas fa-truck text-primary"></i>
                                                        <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                                    </h6>
                                                    <?php
                                                    $status_class = '';
                                                    $status_icon = '';
                                                    $status_text = '';
                                                    switch ($reservation['status']) {
                                                        case 'pending':
                                                            $status_class = 'bg-warning';
                                                            $status_icon = 'fas fa-clock';
                                                            $status_text = 'Ausstehend';
                                                            break;
                                                        case 'approved':
                                                            $status_class = 'bg-success';
                                                            $status_icon = 'fas fa-check';
                                                            $status_text = 'Genehmigt';
                                                            break;
                                                        case 'rejected':
                                                            $status_class = 'bg-danger';
                                                            $status_icon = 'fas fa-times';
                                                            $status_text = 'Abgelehnt';
                                                            break;
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $status_class; ?>">
                                                        <i class="<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="mb-2">
                                                    <i class="fas fa-calendar-alt text-success"></i>
                                                    <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                        <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                    </small>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <i class="fas fa-clipboard-list text-warning"></i>
                                                    <span><?php echo htmlspecialchars($reservation['reason']); ?></span>
                                                </div>
                                                
                                                <div class="d-grid">
                                                    <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                        <i class="fas fa-info-circle"></i> Details anzeigen
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
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
                                    <?php
                                    $status_class = '';
                                    $status_icon = '';
                                    $status_text = '';
                                    switch ($reservation['status']) {
                                        case 'pending':
                                            $status_class = 'bg-warning';
                                            $status_icon = 'fas fa-clock';
                                            $status_text = 'Ausstehend';
                                            break;
                                        case 'approved':
                                            $status_class = 'bg-success';
                                            $status_icon = 'fas fa-check';
                                            $status_text = 'Genehmigt';
                                            break;
                                        case 'rejected':
                                            $status_class = 'bg-danger';
                                            $status_icon = 'fas fa-times';
                                            $status_text = 'Abgelehnt';
                                            break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>">
                                        <i class="<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                                    </span>
                                </p>
                                
                                <?php if ($reservation['first_name'] && $reservation['last_name']): ?>
                                    <h6><i class="fas fa-user-shield text-secondary"></i> Genehmigt von</h6>
                                    <p>
                                        <?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?><br>
                                        <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($reservation['approved_at'])); ?></small>
                                    </p>
                                <?php endif; ?>
                                
                                <h6><i class="fas fa-clock text-muted"></i> Erstellt</h6>
                                <p><small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($reservation['created_at'])); ?></small></p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <?php if ($reservation['status'] == 'pending'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Reservierung genehmigen?')">
                                    <i class="fas fa-check"></i> Genehmigen
                                </button>
                            </form>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $reservation['id']; ?>" data-bs-dismiss="modal">
                                <i class="fas fa-times"></i> Ablehnen
                            </button>
                        <?php elseif (in_array($reservation['status'], ['approved', 'rejected'])): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Reservierung wirklich löschen?')">
                                    <i class="fas fa-trash"></i> Löschen
                                </button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Ablehnungs-Modals -->
    <?php foreach ($reservations as $reservation): ?>
        <?php if ($reservation['status'] == 'pending'): ?>
            <div class="modal fade" id="rejectModal<?php echo $reservation['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header">
                                <h5 class="modal-title">Reservierung ablehnen</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>Reservierung #<?php echo $reservation['id']; ?> - <?php echo htmlspecialchars($reservation['vehicle_name']); ?></p>
                                <div class="mb-3">
                                    <label for="rejection_reason<?php echo $reservation['id']; ?>" class="form-label">Ablehnungsgrund</label>
                                    <textarea class="form-control" id="rejection_reason<?php echo $reservation['id']; ?>" name="rejection_reason" rows="3" required></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                <button type="submit" class="btn btn-danger">Ablehnen</button>
                            </div>
                            <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
