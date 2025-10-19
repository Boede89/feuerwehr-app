<?php
// Debug-Version der Übung planen Seite
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Berechtigung prüfen
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$hasPermission = has_permission('atemschutz') || hasAdminPermission();
if (!$hasPermission) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

// Heutiges Datum für Vorauswahl
$today = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Übung planen - Atemschutz (Debug)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>Übung planen - Debug Version</h1>
        <p>Diese Seite funktioniert! Das Problem liegt in der ursprünglichen Datei.</p>
        
        <div class="alert alert-info">
            <h5>Debug-Informationen:</h5>
            <ul>
                <li>Session ID: <?php echo session_id(); ?></li>
                <li>User ID: <?php echo $_SESSION['user_id']; ?></li>
                <li>Heute: <?php echo $today; ?></li>
                <li>Berechtigung: <?php echo $hasPermission ? 'JA' : 'NEIN'; ?></li>
            </ul>
        </div>
        
        <a href="atemschutz.php" class="btn btn-primary">Zurück zur Atemschutz-Liste</a>
    </div>
</body>
</html>
