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
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Offene Anträge -->
            <div class="col-lg-8 mb-4">
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
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fahrzeug</th>
                                            <th>Antragsteller</th>
                                            <th>E-Mail</th>
                                            <th>Datum/Zeit</th>
                                            <th>Grund</th>
                                            <th>Aktion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_reservations as $reservation): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($reservation['vehicle_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($reservation['requester_name']); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['requester_email']); ?></td>
                                                <td>
                                                    <strong><?php echo format_datetime($reservation['start_datetime'], 'd.m.Y'); ?></strong><br>
                                                    <small class="text-muted">
                                                        <?php echo format_datetime($reservation['start_datetime'], 'H:i'); ?> - 
                                                        <?php echo format_datetime($reservation['end_datetime'], 'H:i'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($reservation['reason']); ?></small>
                                                </td>
                                                <td>
                                                    <a href="reservations.php?id=<?php echo $reservation['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-edit"></i> Bearbeiten
                                                    </a>
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

            <!-- Schnellzugriff -->
            <div class="col-lg-4 mb-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-bolt"></i> Schnellzugriff
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="reservations.php" class="btn btn-outline-primary">
                                <i class="fas fa-calendar-check"></i> Alle Reservierungen
                            </a>
                            <a href="reservations.php?status=pending" class="btn btn-outline-warning">
                                <i class="fas fa-clock"></i> Nur Offene Anträge
                            </a>
                            <a href="reservations.php?status=approved" class="btn btn-outline-success">
                                <i class="fas fa-check-circle"></i> Genehmigte Anträge
                            </a>
                            <a href="reservations.php?status=rejected" class="btn btn-outline-danger">
                                <i class="fas fa-times-circle"></i> Abgelehnte Anträge
                            </a>
                        </div>
                        
                        <hr>
                        
                        <div class="text-center">
                            <h6 class="text-muted mb-3">Statistik</h6>
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="h4 text-warning"><?php echo count($pending_reservations); ?></div>
                                    <small class="text-muted">Offen</small>
                                </div>
                                <div class="col-4">
                                    <div class="h4 text-success">-</div>
                                    <small class="text-muted">Genehmigt</small>
                                </div>
                                <div class="col-4">
                                    <div class="h4 text-danger">-</div>
                                    <small class="text-muted">Abgelehnt</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
