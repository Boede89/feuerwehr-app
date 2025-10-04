<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Session-Fix für die App
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    // Lade Admin-Benutzer aus der Datenbank
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['first_name'] = $admin_user['first_name'];
        $_SESSION['last_name'] = $admin_user['last_name'];
        $_SESSION['username'] = $admin_user['username'];
        $_SESSION['email'] = $admin_user['email'];
    }
}

// Nur für eingeloggte Benutzer mit Genehmiger-Zugriff
if (!can_approve_reservations()) {
    redirect('../login.php');
}

$error = '';
$message = '';

// Browser Console Logging für Debugging
echo '<script>';
echo 'console.log("🔍 Admin Dashboard Debug");';
echo 'console.log("Zeitstempel:", new Date().toLocaleString());';
echo 'console.log("Session user_id:", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');';
echo 'console.log("Session role:", ' . json_encode($_SESSION['role'] ?? 'nicht gesetzt') . ');';
echo 'console.log("Message:", ' . json_encode($message ?? '') . ');';
echo 'console.log("Error:", ' . json_encode($error ?? '') . ');';
echo '</script>';


// POST-Verarbeitung für Genehmigung/Ablehnung
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
    // CSRF Token Validierung (optional für interne Admin-Aktionen)
    if (!empty($_POST['csrf_token']) && !validate_csrf_token($_POST['csrf_token'])) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        try {
            if ($action == 'approve') {
                $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $reservation_id]);
                
                $message = "Reservierung erfolgreich genehmigt.";
                
                // Google Calendar Event erstellen
                echo '<script>console.log("🔍 Starte Google Calendar Event Erstellung...");</script>';
                error_log('=== DASHBOARD: Starte Google Calendar Event Erstellung ===');
                
                try {
                    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                    $stmt->execute([$reservation_id]);
                    $reservation = $stmt->fetch();
                    
                    error_log('Dashboard: Reservierung geladen - ID: ' . ($reservation['id'] ?? 'null') . ', Fahrzeug: ' . ($reservation['vehicle_name'] ?? 'null'));
                    
                    if ($reservation) {
                        echo '<script>console.log("✅ Reservierung für Google Calendar geladen:", ' . json_encode($reservation) . ');</script>';
                        
                        // Google Calendar Event sofort erstellen
                        if (function_exists('create_google_calendar_event')) {
                            echo '<script>console.log("✅ create_google_calendar_event Funktion verfügbar");</script>';
                            error_log('Dashboard: create_google_calendar_event Funktion verfügbar');
                            
                            try {
                                echo '<script>console.log("🔍 Rufe create_google_calendar_event auf...");</script>';
                                error_log('Dashboard: Rufe create_google_calendar_event auf...');
                                
                                // Debug: Prüfe Parameter
                                error_log('Dashboard Google Calendar Debug - Parameter:');
                                error_log('vehicle_name: ' . $reservation['vehicle_name']);
                                error_log('reason: ' . $reservation['reason']);
                                error_log('start_datetime: ' . $reservation['start_datetime']);
                                error_log('end_datetime: ' . $reservation['end_datetime']);
                                error_log('reservation_id: ' . $reservation['id']);
                                error_log('location: ' . ($reservation['location'] ?? 'null'));
                                
                                $event_id = create_google_calendar_event(
                                    $reservation['vehicle_name'],
                                    $reservation['reason'],
                                    $reservation['start_datetime'],
                                    $reservation['end_datetime'],
                                    $reservation['id'],
                                    $reservation['location'] ?? null
                                );
                                
                                error_log('Dashboard: create_google_calendar_event Rückgabe: ' . ($event_id ? $event_id : 'false'));
                                
                                if ($event_id) {
                                    $message .= " Google Calendar Event wurde erstellt.";
                                    echo '<script>console.log("✅ Google Calendar Event erfolgreich erstellt:", ' . json_encode($event_id) . ');</script>';
                                } else {
                                    $message .= " Warnung: Google Calendar Event konnte nicht erstellt werden.";
                                    echo '<script>console.log("❌ Google Calendar Event konnte nicht erstellt werden - Funktion gab false zurück");</script>';
                                }
                            } catch (Exception $e) {
                                error_log('Google Calendar Event Fehler in Dashboard: ' . $e->getMessage());
                                $message .= " Warnung: Google Calendar Event konnte nicht erstellt werden. Fehler: " . $e->getMessage();
                                echo '<script>console.log("❌ Google Calendar Event Fehler:", ' . json_encode($e->getMessage()) . ');</script>';
                            }
                        } else {
                            $message .= " Warnung: Google Calendar Funktion nicht verfügbar.";
                            echo '<script>console.log("❌ create_google_calendar_event Funktion nicht verfügbar");</script>';
                            error_log('Dashboard: create_google_calendar_event Funktion NICHT verfügbar');
                        }
                    } else {
                        $message .= " Warnung: Reservierung nicht gefunden für Google Calendar.";
                        echo '<script>console.log("❌ Reservierung nicht gefunden für Google Calendar");</script>';
                        error_log('Dashboard: Reservierung nicht gefunden für Google Calendar');
                    }
                } catch (Exception $e) {
                    error_log('Google Calendar Event Fehler: ' . $e->getMessage());
                    $message .= " Warnung: Google Calendar Event konnte nicht erstellt werden.";
                    echo '<script>console.log("❌ Google Calendar Exception:", ' . json_encode($e->getMessage()) . ');</script>';
                }
                
                error_log('=== DASHBOARD: Google Calendar Event Erstellung beendet ===');
                
            } elseif ($action == 'reject') {
                $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
                
                if (empty($rejection_reason)) {
                    $error = "Bitte geben Sie einen Ablehnungsgrund an.";
                } else {
                    $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$rejection_reason, $_SESSION['user_id'], $reservation_id]);
                    
                    $message = "Reservierung erfolgreich abgelehnt.";
                }
            }
        } catch(PDOException $e) {
            $error = "Fehler beim Verarbeiten der Reservierung: " . $e->getMessage();
        }
    }
}

try {
    // Nur offene Anträge (ausstehend)
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
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
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reservations.php">
                            <i class="fas fa-calendar-check"></i> Reservierungen
                        </a>
                    </li>
                    <?php if (has_admin_access()): ?>
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
                    <?php endif; ?>
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
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                    <small class="text-muted">Willkommen zurück, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</small>
                </h1>
                
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
            <!-- Offene Anträge -->
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-warning">
                            <i class="fas fa-clock"></i> Offene Anträge (<?php echo count($pending_reservations); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine ausstehenden Anträge</h5>
                                <p class="text-muted">Alle Anträge wurden bearbeitet.</p>
                                <a href="reservations.php" class="btn btn-primary">
                                    <i class="fas fa-calendar-check"></i> Alle Reservierungen anzeigen
                                </a>
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
                                            
                                            <?php 
                                            // Prüfe Kalender-Konflikte
                                            $conflicts = [];
                                            if (function_exists('check_calendar_conflicts')) {
                                                $conflicts = check_calendar_conflicts($reservation['vehicle_name'], $reservation['start_datetime'], $reservation['end_datetime']);
                                            }
                                            ?>
                                            <?php if (!empty($conflicts)): ?>
                                                <div class="mb-3">
                                                    <i class="fas fa-exclamation-triangle text-danger"></i>
                                                    <small class="text-danger">
                                                        <strong>Kalender-Konflikt!</strong><br>
                                                        <?php foreach ($conflicts as $conflict): ?>
                                                            • <?php echo htmlspecialchars($conflict['title']); ?><br>
                                                        <?php endforeach; ?>
                                                    </small>
                                                </div>
                                            <?php else: ?>
                                                <div class="mb-3">
                                                    <i class="fas fa-check-circle text-success"></i>
                                                    <small class="text-success">
                                                        <strong>Kein Kalender-Konflikt</strong><br>
                                                        Zeitraum ist frei
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-grid">
                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                    <i class="fas fa-edit"></i> Bearbeiten
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
                                                        <?php 
                                                        // Prüfe Kalender-Konflikte
                                                        $conflicts = [];
                                                        if (function_exists('check_calendar_conflicts')) {
                                                            $conflicts = check_calendar_conflicts($reservation['vehicle_name'], $reservation['start_datetime'], $reservation['end_datetime']);
                                                        }
                                                        ?>
                                                        <?php if (!empty($conflicts)): ?>
                                                            <br><small class="text-danger">
                                                                <i class="fas fa-exclamation-triangle"></i> 
                                                                Kalender-Konflikt: <?php echo htmlspecialchars($conflicts[0]['title']); ?>
                                                                <?php if (count($conflicts) > 1): ?>
                                                                    (+<?php echo count($conflicts) - 1; ?> weitere)
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <br><small class="text-success">
                                                                <i class="fas fa-check-circle"></i> 
                                                                Kein Konflikt
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                            <i class="fas fa-edit"></i> Bearbeiten
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
    </div>

    <!-- Details-Modals -->
    <?php foreach ($pending_reservations as $reservation): ?>
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
                                    <span class="badge bg-warning">
                                        <i class="fas fa-clock"></i> Ausstehend
                                    </span>
                                </p>
                                
                                <h6><i class="fas fa-clock text-muted"></i> Erstellt</h6>
                                <p><small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($reservation['created_at'])); ?></small></p>
                                
                                <?php if (!empty($reservation['calendar_conflicts'])): ?>
                                    <?php $conflicts = json_decode($reservation['calendar_conflicts'], true); ?>
                                    <?php if (!empty($conflicts)): ?>
                                        <h6><i class="fas fa-exclamation-triangle text-danger"></i> Kalender-Konflikte</h6>
                                        <div class="alert alert-warning">
                                            <strong>Warnung:</strong> Für dieses Fahrzeug existieren bereits Kalender-Einträge im beantragten Zeitraum:
                                            <ul class="mb-0 mt-2">
                                                <?php foreach ($conflicts as $conflict): ?>
                                                    <li>
                                                        <strong><?php echo htmlspecialchars($conflict['title']); ?></strong><br>
                                                        <small class="text-muted">
                                                            <?php echo date('d.m.Y H:i', strtotime($conflict['start'])); ?> - 
                                                            <?php echo date('d.m.Y H:i', strtotime($conflict['end'])); ?>
                                                        </small>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Ablehnungs-Modals für Dashboard -->
    <?php foreach ($pending_reservations as $reservation): ?>
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
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
<script>
    // Debug-Logging für Dashboard
    console.log('🔍 Admin Dashboard geladen');
    console.log('Zeitstempel:', new Date().toLocaleString('de-DE'));
    
    // Prüfe PHP-Funktionen
    <?php if (function_exists('check_calendar_conflicts')): ?>
        console.log('✅ check_calendar_conflicts Funktion verfügbar');
    <?php else: ?>
        console.error('❌ check_calendar_conflicts Funktion NICHT verfügbar');
    <?php endif; ?>
    
    <?php if (function_exists('create_google_calendar_event')): ?>
        console.log('✅ create_google_calendar_event Funktion verfügbar');
    <?php else: ?>
        console.error('❌ create_google_calendar_event Funktion NICHT verfügbar');
    <?php endif; ?>
    
    // Prüfe ausstehende Reservierungen
    console.log('Anzahl ausstehende Reservierungen:', <?php echo count($pending_reservations); ?>);
    
    // Prüfe Google Calendar Einstellungen
    <?php
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>
    console.log('Google Calendar Einstellungen:', {
        auth_type: '<?php echo $settings['google_calendar_auth_type'] ?? 'Nicht gesetzt'; ?>',
        calendar_id: '<?php echo $settings['google_calendar_id'] ?? 'Nicht gesetzt'; ?>',
        service_account_json: '<?php echo isset($settings['google_calendar_service_account_json']) ? 'Gesetzt (' . strlen($settings['google_calendar_service_account_json']) . ' Zeichen)' : 'Nicht gesetzt'; ?>'
    });
    
    // Event-Listener für Formulare
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('form[method="POST"]');
        console.log('Anzahl Formulare:', forms.length);
        
        forms.forEach(function(form, index) {
            form.addEventListener('submit', function(e) {
                const action = form.querySelector('input[name="action"]').value;
                const reservationId = form.querySelector('input[name="reservation_id"]').value;
                console.log('Dashboard Formular abgesendet:', {
                    action: action,
                    reservationId: reservationId,
                    formIndex: index
                });
            });
        });
        
    });
    
    // Prüfe Modals
    const modals = document.querySelectorAll('.modal');
    console.log('Anzahl Modals:', modals.length);
    
    modals.forEach(function(modal, index) {
        modal.addEventListener('show.bs.modal', function() {
            const modalId = modal.id;
            console.log('Dashboard Modal geöffnet:', modalId);
        });
    });
    
</script>
</body>
</html>
