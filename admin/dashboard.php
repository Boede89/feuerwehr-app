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

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Admin-Rechte hat
if (!can_approve_reservations()) {
    // Zusätzliche Prüfung: Ist der Benutzer ein Admin?
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../login.php?error=access_denied");
        exit;
    }
}

$error = '';
$message = '';

// POST-Verarbeitung für Genehmigung/Ablehnung
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
    try {
        if ($action == 'approve') {
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $reservation_id]);
            $message = "Reservierung erfolgreich genehmigt.";
        } elseif ($action == 'reject') {
            $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
            if (!empty($rejection_reason)) {
                $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$rejection_reason, $_SESSION['user_id'], $reservation_id]);
                $message = "Reservierung erfolgreich abgelehnt.";
            } else {
                $error = "Bitte geben Sie einen Ablehnungsgrund an.";
            }
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
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                    <small class="text-muted">Willkommen zurück, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</small>
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
                            </div>
                        <?php else: ?>
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
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Reservierung genehmigen?')">
                                                            <i class="fas fa-check"></i> Genehmigen
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-danger" onclick="rejectReservation(<?php echo $reservation['id']; ?>)">
                                                        <i class="fas fa-times"></i> Ablehnen
                                                    </button>
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

            <!-- Bearbeitete Anträge -->
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-success">
                            <i class="fas fa-check-circle"></i> Bearbeitete Anträge (<?php echo count($processed_reservations); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($processed_reservations)): ?>
                            <div class="text-center py-3">
                                <i class="fas fa-info-circle fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">Noch keine bearbeiteten Anträge</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fahrzeug</th>
                                            <th>Antragsteller</th>
                                            <th>Datum/Zeit</th>
                                            <th>Grund</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($processed_reservations as $reservation): ?>
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
                                                    <span class="badge <?php echo $reservation['status'] === 'approved' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $reservation['status'] === 'approved' ? 'Genehmigt' : 'Abgelehnt'; ?>
                                                    </span>
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
    </div>

    <!-- Ablehnungs-Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="rejectForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Reservierung ablehnen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p id="rejectReservationInfo"></p>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Ablehnungsgrund</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Ablehnen</button>
                    </div>
                    <input type="hidden" name="reservation_id" id="rejectReservationId">
                    <input type="hidden" name="action" value="reject">
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function rejectReservation(reservationId) {
            document.getElementById('rejectReservationId').value = reservationId;
            document.getElementById('rejectReservationInfo').textContent = 'Reservierung #' + reservationId;
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }
    </script>
</body>
</html>