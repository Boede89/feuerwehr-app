<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer mit Genehmiger-Zugriff
if (!can_approve_reservations()) {
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
                
                // Google Calendar Event erstellen
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    $event_id = create_google_calendar_event(
                        $reservation['vehicle_name'],
                        $reservation['reason'],
                        $reservation['start_datetime'],
                        $reservation['end_datetime']
                    );
                    
                    if ($event_id) {
                        // Event ID in der Datenbank speichern
                        $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
                        $title = $reservation['vehicle_name'] . ' - ' . $reservation['reason'];
                        $stmt->execute([$reservation_id, $event_id, $title, $reservation['start_datetime'], $reservation['end_datetime']]);
                    }
                }
                
                // E-Mail an Antragsteller senden
                $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    $subject = "Fahrzeugreservierung genehmigt";
                    $message_content = "
                    <h2>Ihre Fahrzeugreservierung wurde genehmigt</h2>
                    <p>Ihr Antrag für die Reservierung wurde genehmigt.</p>
                    <p><strong>Details:</strong></p>
                    <ul>
                        <li>Fahrzeug: " . htmlspecialchars($reservation['requester_name']) . "</li>
                        <li>Von: " . format_datetime($reservation['start_datetime']) . "</li>
                        <li>Bis: " . format_datetime($reservation['end_datetime']) . "</li>
                    </ul>
                    ";
                    send_email($reservation['requester_email'], $subject, $message_content);
                }
                
                $message = "Reservierung wurde genehmigt.";
                log_activity($_SESSION['user_id'], 'reservation_approved', "Reservierung #$reservation_id genehmigt");
                
            } elseif ($action == 'reject') {
                $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? '');
                
                if (empty($rejection_reason)) {
                    $error = "Bitte geben Sie einen Grund für die Ablehnung an.";
                } else {
                    $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$rejection_reason, $_SESSION['user_id'], $reservation_id]);
                    
                    // E-Mail an Antragsteller senden
                    $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
                    $stmt->execute([$reservation_id]);
                    $reservation = $stmt->fetch();
                    
                    if ($reservation) {
                        $subject = "Fahrzeugreservierung abgelehnt";
                        $message_content = "
                        <h2>Ihre Fahrzeugreservierung wurde abgelehnt</h2>
                        <p>Ihr Antrag für die Reservierung wurde leider abgelehnt.</p>
                        <p><strong>Grund:</strong> " . htmlspecialchars($rejection_reason) . "</p>
                        ";
                        send_email($reservation['requester_email'], $subject, $message_content);
                    }
                    
                    $message = "Reservierung wurde abgelehnt.";
                    log_activity($_SESSION['user_id'], 'reservation_rejected', "Reservierung #$reservation_id abgelehnt: $rejection_reason");
                }
            }
        } catch(PDOException $e) {
            $error = "Fehler beim Aktualisieren der Reservierung: " . $e->getMessage();
        }
    }
}

// Filter
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Reservierungen laden
$where_conditions = [];
$params = [];

if ($status_filter != 'all') {
    $where_conditions[] = "r.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(r.requester_name LIKE ? OR r.requester_email LIKE ? OR v.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $sql = "
        SELECT r.*, v.name as vehicle_name, 
               u.username as approved_by_username
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN users u ON r.approved_by = u.id
        $where_sql
        ORDER BY r.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
    $reservations = [];
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-calendar-check"></i> Reservierungen
                    </h1>
                </div>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>Alle</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Ausstehend</option>
                                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Genehmigt</option>
                                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Abgelehnt</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="search" class="form-label">Suche</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Name, E-Mail oder Fahrzeug">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> Filtern
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reservierungen Tabelle -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Fahrzeug</th>
                                        <th>Antragsteller</th>
                                        <th>Zeitraum</th>
                                        <th>Grund</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reservations as $reservation): ?>
                                        <tr>
                                            <td>#<?php echo $reservation['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($reservation['vehicle_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($reservation['requester_name']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($reservation['requester_email']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo format_datetime($reservation['start_datetime']); ?><br>
                                                <small class="text-muted">bis <?php echo format_datetime($reservation['end_datetime']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($reservation['reason']); ?></td>
                                            <td><?php echo get_status_badge($reservation['status']); ?></td>
                                            <td>
                                                <?php echo format_datetime($reservation['created_at']); ?>
                                                <?php if ($reservation['approved_by_username']): ?>
                                                    <br><small class="text-muted">von <?php echo htmlspecialchars($reservation['approved_by_username']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($reservation['status'] == 'pending'): ?>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-success btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#approveModal" 
                                                                data-reservation-id="<?php echo $reservation['id']; ?>">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#rejectModal" 
                                                                data-reservation-id="<?php echo $reservation['id']; ?>">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
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
    </div>

    <!-- Genehmigen Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Reservierung genehmigen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Sind Sie sicher, dass Sie diese Reservierung genehmigen möchten?</p>
                        <input type="hidden" name="reservation_id" id="approve_reservation_id">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-success">Genehmigen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Ablehnen Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Reservierung ablehnen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Grund für die Ablehnung *</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        </div>
                        <input type="hidden" name="reservation_id" id="reject_reservation_id">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Ablehnen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modal Event Listeners
        document.getElementById('approveModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const reservationId = button.getAttribute('data-reservation-id');
            document.getElementById('approve_reservation_id').value = reservationId;
        });

        document.getElementById('rejectModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const reservationId = button.getAttribute('data-reservation-id');
            document.getElementById('reject_reservation_id').value = reservationId;
        });
    </script>
</body>
</html>
