<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Einfache Test-Seite
echo "Test-Seite funktioniert!<br>";
echo "Session ID: " . session_id() . "<br>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";

// Berechtigung prüfen
$hasPermission = false;
if (isset($_SESSION['user_id'])) {
    $hasPermission = has_permission('atemschutz') || hasAdminPermission();
    echo "Berechtigung: " . ($hasPermission ? 'JA' : 'NEIN') . "<br>";
} else {
    echo "Nicht eingeloggt<br>";
}

if (!$hasPermission) {
    echo "Zugriff verweigert - Weiterleitung zu Login...";
    // header('Location: ../login.php?error=access_denied');
    // exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Test - Übung planen</title>
</head>
<body>
    <h1>Test-Seite für Übung planen</h1>
    <p>Wenn Sie diese Seite sehen, funktioniert der Link!</p>
    <a href="atemschutz.php">Zurück zur Atemschutz-Liste</a>
</body>
</html>
