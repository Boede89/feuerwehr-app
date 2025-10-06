<?php
/**
 * Admin-Interface für Cleanup abgelaufener Reservierungen
 */

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/google_calendar_functions.php';

// Admin-Berechtigung prüfen
if (!isset($_SESSION['user_id']) || !is_admin($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';


// Cleanup ausführen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'cleanup') {
    try {
        // 1. Abgelaufene Reservierungen finden
        $stmt = $db->prepare("
            SELECT r.id, r.requester_name, r.requester_email, r.reason, r.start_datetime, r.end_datetime,
                   v.name as vehicle_name, ce.google_event_id
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
            WHERE r.end_datetime < NOW() 
            AND r.status IN ('approved', 'pending')
            ORDER BY r.end_datetime ASC
        ");
        $stmt->execute();
        $expired_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count = count($expired_reservations);
        
        if ($count === 0) {
            $message = "Keine abgelaufenen Reservierungen gefunden.";
        } else {
            $deleted_count = 0;
            $google_deleted_count = 0;
            $errors = [];
            
            foreach ($expired_reservations as $reservation) {
                try {
                    // Google Calendar Event löschen (falls vorhanden)
                    if (!empty($reservation['google_event_id'])) {
                        // Prüfen ob noch andere Reservierungen dieses Event nutzen
                        $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE google_event_id = ?");
                        $stmt->execute([$reservation['google_event_id']]);
                        $remaining_links = (int)$stmt->fetchColumn();
                        
                        if ($remaining_links === 1) {
                            // Nur löschen, wenn keine weitere Reservierung dieses Event nutzt
                            if (function_exists('delete_google_calendar_event')) {
                                $google_deleted = delete_google_calendar_event($reservation['google_event_id']);
                                if ($google_deleted) {
                                    $google_deleted_count++;
                                }
                            }
                        }
                    }
                    
                    // Calendar Events Verknüpfung löschen
                    $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation['id']]);
                    
                    // Reservierung löschen
                    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                    $stmt->execute([$reservation['id']]);
                    
                    $deleted_count++;
                    
                } catch (Exception $e) {
                    $errors[] = "Fehler bei Reservierung ID {$reservation['id']}: " . $e->getMessage();
                }
            }
            
            $message = "Cleanup abgeschlossen! {$deleted_count} Reservierungen und {$google_deleted_count} Google Calendar Events gelöscht.";
            
            if (!empty($errors)) {
                $error = "Es traten " . count($errors) . " Fehler auf. Details in den Logs.";
            }
        }
        
    } catch (Exception $e) {
        $error = "Fehler beim Cleanup: " . $e->getMessage();
    }
}

// Aktuelle abgelaufene Reservierungen anzeigen
$stmt = $db->prepare("
    SELECT r.id, r.requester_name, r.requester_email, r.reason, r.start_datetime, r.end_datetime,
           v.name as vehicle_name, r.status, ce.google_event_id
    FROM reservations r 
    JOIN vehicles v ON r.vehicle_id = v.id 
    LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
    WHERE r.end_datetime < NOW() 
    AND r.status IN ('approved', 'pending')
    ORDER BY r.end_datetime ASC
");
$stmt->execute();
$expired_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-house"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reservations.php">
                                <i class="bi bi-calendar-check"></i> Reservierungen
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="users.php">
                                <i class="bi bi-people"></i> Benutzer
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="vehicles.php">
                                <i class="bi bi-truck"></i> Fahrzeuge
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="cleanup.php">
                                <i class="bi bi-trash"></i> Cleanup
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="settings.php">
                                <i class="bi bi-gear"></i> Einstellungen
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php">
                                <i class="bi bi-box-arrow-right"></i> Abmelden
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Cleanup abgelaufener Reservierungen</h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Cleanup Info -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle"></i> Cleanup-Informationen
                                </h5>
                            </div>
                            <div class="card-body">
                                <p>Dieses Tool löscht automatisch abgelaufene Reservierungen aus der Datenbank und dem Google Calendar.</p>
                                <p><strong>Gefunden:</strong> <?php echo count($expired_reservations); ?> abgelaufene Reservierungen</p>
                                <p><strong>Kriterien:</strong> Reservierungen mit <code>end_datetime < NOW()</code> und Status <code>approved</code> oder <code>pending</code></p>
                                <p><strong>Automatisierung:</strong> Läuft täglich um 3:00 Uhr automatisch im Hintergrund (keine Benachrichtigungen an Antragsteller)</p>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Cleanup Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-tools"></i> Cleanup-Aktionen
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($expired_reservations) > 0): ?>
                                    <form method="POST" onsubmit="return confirm('Sind Sie sicher, dass Sie alle abgelaufenen Reservierungen löschen möchten? Diese Aktion kann nicht rückgängig gemacht werden!');">
                                        <input type="hidden" name="action" value="cleanup">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="bi bi-trash"></i> Cleanup ausführen (<?php echo count($expired_reservations); ?> Reservierungen)
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <p class="text-muted">Keine abgelaufenen Reservierungen gefunden. Cleanup nicht erforderlich.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Abgelaufene Reservierungen -->
                <?php if (count($expired_reservations) > 0): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list"></i> Abgelaufene Reservierungen
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Fahrzeug</th>
                                                <th>Antragsteller</th>
                                                <th>Grund</th>
                                                <th>Zeitraum</th>
                                                <th>Status</th>
                                                <th>Google Calendar</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($expired_reservations as $reservation): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($reservation['vehicle_name']); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['requester_name']); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['reason']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($reservation['start_datetime']); ?><br>
                                                    <small class="text-muted">bis: <?php echo htmlspecialchars($reservation['end_datetime']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $reservation['status'] === 'approved' ? 'success' : 'warning'; ?>">
                                                        <?php echo htmlspecialchars($reservation['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($reservation['google_event_id'])): ?>
                                                        <span class="badge bg-info">Event vorhanden</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Kein Event</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
