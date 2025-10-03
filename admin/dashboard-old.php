<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer mit Genehmiger-Zugriff
if (!can_approve_reservations()) {
    redirect('../login.php');
}

$error = '';

try {
    // Offene Anträge (ausstehend)
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    // Genehmigte Anträge (letzte 10)
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'approved'
        ORDER BY r.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $approved_reservations = $stmt->fetchAll();
    
    // Abgelehnte Anträge (letzte 10)
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'rejected'
        ORDER BY r.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $rejected_reservations = $stmt->fetchAll();
    
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
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Heutige Reservierungen -->
            <div class="col-lg-8 mb-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-calendar-day"></i> Heutige Reservierungen
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($today_reservations)): ?>
                            <p class="text-muted text-center">Keine Reservierungen für heute.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Fahrzeug</th>
                                            <th>Antragsteller</th>
                                            <th>Zeit</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($today_reservations as $reservation): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($reservation['vehicle_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($reservation['requester_name']); ?></td>
                                                <td>
                                                    <?php echo format_datetime($reservation['start_datetime'], 'H:i'); ?> - 
                                                    <?php echo format_datetime($reservation['end_datetime'], 'H:i'); ?>
                                                </td>
                                                <td><?php echo get_status_badge($reservation['status']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Letzte Aktivitäten -->
            <div class="col-lg-4 mb-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-history"></i> Letzte Aktivitäten
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activities)): ?>
                            <p class="text-muted text-center">Keine Aktivitäten.</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker bg-primary"></div>
                                        <div class="timeline-content">
                                            <h6 class="timeline-title"><?php echo htmlspecialchars($activity['action']); ?></h6>
                                            <p class="timeline-text">
                                                <?php if ($activity['username']): ?>
                                                    <strong><?php echo htmlspecialchars($activity['username']); ?></strong>
                                                <?php endif; ?>
                                                <?php if ($activity['details']): ?>
                                                    - <?php echo htmlspecialchars($activity['details']); ?>
                                                <?php endif; ?>
                                            </p>
                                            <p class="timeline-time">
                                                <small class="text-muted">
                                                    <?php echo format_datetime($activity['created_at']); ?>
                                                </small>
                                            </p>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .border-left-primary {
            border-left: 0.25rem solid #4e73df !important;
        }
        .border-left-success {
            border-left: 0.25rem solid #1cc88a !important;
        }
        .border-left-danger {
            border-left: 0.25rem solid #e74a3b !important;
        }
        .border-left-info {
            border-left: 0.25rem solid #36b9cc !important;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-marker {
            position: absolute;
            left: -35px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .timeline-content {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
        }
    </style>
</body>
</html>
