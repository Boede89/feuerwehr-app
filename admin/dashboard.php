<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer
if (!is_logged_in()) {
    redirect('../login.php');
}

// Statistiken laden
$stats = [];

try {
    // Anzahl ausstehender Anträge
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $stmt->execute();
    $stats['pending'] = $stmt->fetch()['count'];
    
    // Anzahl genehmigter Anträge (heute)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE status = 'approved' AND DATE(created_at) = CURDATE()");
    $stmt->execute();
    $stats['approved_today'] = $stmt->fetch()['count'];
    
    // Anzahl abgelehnter Anträge (heute)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE status = 'rejected' AND DATE(created_at) = CURDATE()");
    $stmt->execute();
    $stats['rejected_today'] = $stmt->fetch()['count'];
    
    // Anzahl aktiver Fahrzeuge
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM vehicles WHERE is_active = 1");
    $stmt->execute();
    $stats['vehicles'] = $stmt->fetch()['count'];
    
    // Letzte Aktivitäten
    $stmt = $db->prepare("
        SELECT al.action, al.details, al.created_at, u.username 
        FROM activity_log al 
        LEFT JOIN users u ON al.user_id = u.id 
        ORDER BY al.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();
    
    // Heutige Reservierungen
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, v.type as vehicle_type
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE DATE(r.start_datetime) = CURDATE()
        ORDER BY r.start_datetime ASC
    ");
    $stmt->execute();
    $today_reservations = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Daten: " . $e->getMessage();
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
                    <small class="text-muted">Willkommen zurück, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</small>
                </h1>
            </div>
        </div>

        <!-- Statistiken -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Ausstehende Anträge
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $stats['pending']; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Heute genehmigt
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $stats['approved_today']; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-danger shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                    Heute abgelehnt
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $stats['rejected_today']; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-times-circle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Aktive Fahrzeuge
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $stats['vehicles']; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-truck fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
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
                                                    <strong><?php echo htmlspecialchars($reservation['vehicle_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($reservation['vehicle_type']); ?></small>
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
