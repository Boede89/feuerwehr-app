<?php
// Debug-Version der Übung planen Seite
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!-- Debug: PHP gestartet -->\n";

session_start();
echo "<!-- Debug: Session gestartet -->\n";

require_once '../config/database.php';
echo "<!-- Debug: Database geladen -->\n";

require_once '../includes/functions.php';
echo "<!-- Debug: Functions geladen -->\n";

// Berechtigung prüfen
if (!isset($_SESSION['user_id'])) {
    echo "<!-- Debug: Keine Session -->\n";
    header('Location: ../login.php?error=access_denied');
    exit;
}

echo "<!-- Debug: Session OK, User ID: " . $_SESSION['user_id'] . " -->\n";

$hasPermission = has_permission('atemschutz') || hasAdminPermission();
echo "<!-- Debug: Berechtigung: " . ($hasPermission ? 'JA' : 'NEIN') . " -->\n";

if (!$hasPermission) {
    echo "<!-- Debug: Keine Berechtigung -->\n";
    header('Location: ../login.php?error=access_denied');
    exit;
}

echo "<!-- Debug: Berechtigung OK -->\n";

// Heutiges Datum für Vorauswahl
$today = date('Y-m-d');
echo "<!-- Debug: Heute: $today -->\n";
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
