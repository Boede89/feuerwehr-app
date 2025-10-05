<?php
/**
 * Admin Reservations - Vereinfachte Version nur für bearbeitete Reservierungen
 */

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Admin-Rechte hat
if (!has_admin_access()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Nur noch Löschen von bearbeiteten Reservierungen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    $reservation_id = (int)$_POST['reservation_id'];
    
    try {
        // Nur bearbeitete Reservierungen können gelöscht werden
        $stmt = $db->prepare("SELECT status FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        
        if ($reservation && in_array($reservation['status'], ['approved', 'rejected'])) {
            // Hole Google Calendar Event ID vor dem Löschen
            $stmt = $db->prepare("SELECT google_event_id, start_datetime, end_datetime, title FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$reservation_id]);
            $calendar_event = $stmt->fetch();

            // Zuerst die lokale Verknüpfung löschen
            $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$reservation_id]);

            // Wenn es eine Google Event ID gibt, prüfen ob noch weitere Verknüpfungen existieren
            if ($calendar_event && !empty($calendar_event['google_event_id'])) {
                $google_event_id = $calendar_event['google_event_id'];
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE google_event_id = ?");
                $stmt->execute([$google_event_id]);
                $remaining_links = (int)$stmt->fetchColumn();

                if ($remaining_links === 0) {
                    // Nur löschen, wenn keine weitere Reservierung dieses Event nutzt
                    $google_deleted = delete_google_calendar_event($google_event_id);
                    if ($google_deleted) {
                        error_log("RESERVATIONS DELETE: Google Calendar Event endgültig gelöscht: " . $google_event_id);
                    } else {
                        error_log("RESERVATIONS DELETE: Google Calendar Event konnte nicht gelöscht werden: " . $google_event_id);
                    }
                } else {
                    error_log("RESERVATIONS DELETE: Google Event nicht gelöscht, es existieren noch " . $remaining_links . " weitere Verknüpfung(en) für " . $google_event_id);
                }
            }
            
            $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
            $stmt->execute([$reservation_id]);
            
                // Erfolgreiche Löschung - Google Calendar wurde ggf. gelöscht, wenn keine Verknüpfungen mehr bestanden
                $message = "Reservierung erfolgreich gelöscht. Falls keine weiteren Reservierungen an diesem Termin hängen, wurde der Google Calendar Eintrag entfernt.";
        } else {
            $error = "Nur bearbeitete Reservierungen können gelöscht werden.";
        }
    } catch (Exception $e) {
        $error = "Fehler beim Löschen der Reservierung: " . $e->getMessage();
    }
}

// Nur bearbeitete Reservierungen laden
try {
    $sql = "
        SELECT r.*, v.name as vehicle_name, u.first_name, u.last_name
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN users u ON r.approved_by = u.id
        WHERE r.status IN ('approved', 'rejected')
        ORDER BY r.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
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
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-calendar-check"></i> Bearbeitete Reservierungen
                    </h1>
                    <button type="button" class="btn btn-outline-primary" id="btnTransfer">
                        <i class="fas fa-right-left"></i> Termine übertragen
                    </button>
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
                                                <span class="badge <?php echo $reservation['status'] === 'approved' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $reservation['status'] === 'approved' ? 'Genehmigt' : 'Abgelehnt'; ?>
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
                                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $reservation['id']; ?>">
                                                    <i class="fas fa-eye"></i> Details
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $reservation['id']; ?>">
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
                                                        <span class="badge <?php echo $reservation['status'] === 'approved' ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo $reservation['status'] === 'approved' ? 'Genehmigt' : 'Abgelehnt'; ?>
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
                                    <span class="badge <?php echo $reservation['status'] === 'approved' ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo $reservation['status'] === 'approved' ? 'Genehmigt' : 'Abgelehnt'; ?>
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
                    </div>
                    <div class="modal-footer">
                        <span class="badge <?php echo $reservation['status'] === 'approved' ? 'bg-success' : 'bg-danger'; ?> fs-6">
                            <?php echo $reservation['status'] === 'approved' ? 'Genehmigt' : 'Abgelehnt'; ?>
                        </span>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Lösch-Modals -->
    <?php foreach ($reservations as $reservation): ?>
        <div class="modal fade" id="deleteModal<?php echo $reservation['id']; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Reservierung löschen</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Möchten Sie die Reservierung #<?php echo $reservation['id']; ?> wirklich löschen?</p>
                            <p><strong>Fahrzeug:</strong> <?php echo htmlspecialchars($reservation['vehicle_name']); ?></p>
                            <p><strong>Antragsteller:</strong> <?php echo htmlspecialchars($reservation['requester_name']); ?></p>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Hinweis:</strong> Der Google Calendar Eintrag bleibt erhalten.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                            <button type="submit" class="btn btn-danger">Löschen</button>
                        </div>
                        <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                        <input type="hidden" name="action" value="delete">
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Modal: Termine übertragen -->
    <div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-right-left"></i> Termine übertragen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php
                        $transferText = '';
                        $transferUrl = '';
                        try {
                            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('vehicle_transfer_text','vehicle_transfer_url')");
                            $stmt->execute();
                            while ($row = $stmt->fetch()) {
                                if ($row['setting_key'] === 'vehicle_transfer_text') $transferText = $row['setting_value'];
                                if ($row['setting_key'] === 'vehicle_transfer_url') $transferUrl = $row['setting_value'];
                            }
                        } catch (Exception $e) { }
                    ?>
                    <div class="mb-3">
                        <label class="form-label">Text</label>
                        <textarea class="form-control" id="transferText" rows="6" readonly><?php echo htmlspecialchars($transferText); ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" id="btnCopyText"><i class="fas fa-copy"></i> In Zwischenablage</button>
                        <a href="<?php echo htmlspecialchars($transferUrl); ?>" class="btn btn-success" id="btnGoLink" target="_blank"><i class="fas fa-arrow-right"></i> Weiter</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const btnTransfer = document.getElementById('btnTransfer');
            const modalEl = document.getElementById('transferModal');
            const transferModal = modalEl ? new bootstrap.Modal(modalEl) : null;
            const btnCopy = document.getElementById('btnCopyText');
            const ta = document.getElementById('transferText');

            if (btnTransfer && transferModal) {
                btnTransfer.addEventListener('click', function (e) {
                    e.preventDefault();
                    transferModal.show();
                });
            }

            if (btnCopy && ta) {
                btnCopy.addEventListener('click', async function () {
                    try {
                        await navigator.clipboard.writeText(ta.value);
                        btnCopy.classList.remove('btn-primary');
                        btnCopy.classList.add('btn-success');
                        btnCopy.innerHTML = '<i class="fas fa-check"></i> Kopiert';
                        setTimeout(() => {
                            btnCopy.classList.remove('btn-success');
                            btnCopy.classList.add('btn-primary');
                            btnCopy.innerHTML = '<i class="fas fa-copy"></i> In Zwischenablage';
                        }, 1500);
                    } catch (err) {
                        alert('Kopieren fehlgeschlagen');
                    }
                });
            }
        });
    </script>
</body>
</html>
