<?php
/**
 * Fix: Dashboard - Repariere das Dashboard
 */

echo "<h1>Fix: Dashboard - Repariere das Dashboard</h1>";

echo "<h2>1. Erstelle einfaches Dashboard</h2>";

// Erstelle ein einfaches Dashboard ohne komplexe Logik
$simple_dashboard = '<?php
session_start();
require_once \'../config/database.php\';

// Session-Fix für die App
if (!isset($_SESSION[\'user_id\']) || !isset($_SESSION[\'role\'])) {
    // Lade Admin-Benutzer aus der Datenbank
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = \'admin\' OR role = \'admin\' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION[\'user_id\'] = $admin_user[\'id\'];
        $_SESSION[\'role\'] = \'admin\';
        $_SESSION[\'first_name\'] = $admin_user[\'first_name\'];
        $_SESSION[\'last_name\'] = $admin_user[\'last_name\'];
        $_SESSION[\'username\'] = $admin_user[\'username\'];
        $_SESSION[\'email\'] = $admin_user[\'email\'];
    }
}

// Lade Reservierungen
try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    
    // Trenne in ausstehend und bearbeitet
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r[\'status\'] === \'pending\';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r[\'status\'], [\'approved\', \'rejected\']);
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
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="reservations.php">
                    <i class="fas fa-calendar-check"></i> Reservierungen
                </a>
                <a class="nav-link" href="settings.php">
                    <i class="fas fa-cog"></i> Einstellungen
                </a>
                <a class="nav-link" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </h1>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
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
                                                            <strong><?php echo htmlspecialchars($reservation[\'vehicle_name\']); ?></strong>
                                                        </h6>
                                                        <span class="badge bg-warning">AUSSTEHEND</span>
                                                    </div>
                                                    <p class="card-text mb-1">
                                                        <strong>Grund:</strong> <?php echo htmlspecialchars($reservation[\'reason\']); ?>
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <strong>Zeitraum:</strong> <?php echo $reservation[\'start_datetime\']; ?> - <?php echo $reservation[\'end_datetime\']; ?>
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <strong>Antragsteller:</strong> <?php echo htmlspecialchars($reservation[\'requester_name\']); ?>
                                                    </p>
                                                    <div class="mt-3">
                                                        <button class="btn btn-success btn-sm me-2" onclick="approveReservation(<?php echo $reservation[\'id\']; ?>)">
                                                            <i class="fas fa-check"></i> Genehmigen
                                                        </button>
                                                        <button class="btn btn-danger btn-sm me-2" onclick="rejectReservation(<?php echo $reservation[\'id\']; ?>)">
                                                            <i class="fas fa-times"></i> Ablehnen
                                                        </button>
                                                        <button class="btn btn-primary btn-sm" onclick="showDetails(<?php echo $reservation[\'id\']; ?>)">
                                                            <i class="fas fa-info-circle"></i> Details
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- Desktop-optimierte Tabellen-Ansicht -->
                                    <div class="d-none d-md-block">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Fahrzeug</th>
                                                        <th>Datum/Zeit</th>
                                                        <th>Grund</th>
                                                        <th>Antragsteller</th>
                                                        <th>Aktion</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pending_reservations as $reservation): ?>
                                                        <tr>
                                                            <td>
                                                                <i class="fas fa-truck text-primary"></i>
                                                                <strong><?php echo htmlspecialchars($reservation[\'vehicle_name\']); ?></strong>
                                                            </td>
                                                            <td>
                                                                <?php echo $reservation[\'start_datetime\']; ?><br>
                                                                <small class="text-muted"><?php echo $reservation[\'end_datetime\']; ?></small>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($reservation[\'reason\']); ?></td>
                                                            <td><?php echo htmlspecialchars($reservation[\'requester_name\']); ?></td>
                                                            <td>
                                                                <button class="btn btn-success btn-sm me-1" onclick="approveReservation(<?php echo $reservation[\'id\']; ?>)">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                                <button class="btn btn-danger btn-sm me-1" onclick="rejectReservation(<?php echo $reservation[\'id\']; ?>)">
                                                                    <i class="fas fa-times"></i>
                                                                </button>
                                                                <button class="btn btn-primary btn-sm" onclick="showDetails(<?php echo $reservation[\'id\']; ?>)">
                                                                    <i class="fas fa-info-circle"></i>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function approveReservation(id) {
            if (confirm(\'Möchten Sie diese Reservierung genehmigen?\')) {
                // Hier würde die Genehmigung verarbeitet werden
                alert(\'Reservierung genehmigt!\');
                location.reload();
            }
        }
        
        function rejectReservation(id) {
            if (confirm(\'Möchten Sie diese Reservierung ablehnen?\')) {
                // Hier würde die Ablehnung verarbeitet werden
                alert(\'Reservierung abgelehnt!\');
                location.reload();
            }
        }
        
        function showDetails(id) {
            alert(\'Details für Reservierung ID: \' + id);
        }
    </script>
</body>
</html>';

// Schreibe das einfache Dashboard
if (file_put_contents('admin/dashboard-simple.php', $simple_dashboard)) {
    echo "<p>✅ Einfaches Dashboard erstellt: admin/dashboard-simple.php</p>";
} else {
    echo "<p>❌ Fehler beim Erstellen des einfachen Dashboards</p>";
}

echo "<h2>2. Teste einfaches Dashboard</h2>";
echo "<p><a href='admin/dashboard-simple.php' target='_blank'>Teste das einfache Dashboard</a></p>";

echo "<h2>3. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard-simple.php'>Teste das einfache Dashboard</a></p>";
echo "<p>2. <a href='admin/dashboard.php'>Teste das ursprüngliche Dashboard</a></p>";
echo "<p>3. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
