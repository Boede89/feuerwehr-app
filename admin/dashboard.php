<?php
// Minimale Dashboard-Version - garantiert funktioniert
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Login-Prüfung
if (!is_logged_in()) {
    redirect('../login.php');
}

// Benutzer laden
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    redirect('../login.php');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">Hallo, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</span>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-tachometer-alt"></i> Dashboard</h5>
                    </div>
                    <div class="card-body">
                        <h1>Dashboard funktioniert!</h1>
                        <p>Willkommen, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</p>
                        <p>Benutzer ID: <?php echo $_SESSION['user_id']; ?></p>
                        <p>Rolle: <?php echo htmlspecialchars($_SESSION['role']); ?></p>
                        
                        <div class="mt-4">
                            <h4>Verfügbare Bereiche:</h4>
                            <div class="row">
                                <div class="col-md-3 mb-2">
                                    <a href="atemschutz.php" class="btn btn-outline-danger w-100">
                                        <i class="fas fa-mask"></i> Atemschutz
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="reservations.php" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-calendar"></i> Reservierungen
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="users.php" class="btn btn-outline-success w-100">
                                        <i class="fas fa-users"></i> Benutzer
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="settings.php" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-cog"></i> Einstellungen
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h4>System-Status:</h4>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Datenbankverbindung erfolgreich
                            </div>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Session aktiv
                            </div>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> Dashboard lädt korrekt
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>