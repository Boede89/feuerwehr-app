<?php
// HTML Debug Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);

    session_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>Debug Dashboard</h1>
        
        <div class="alert alert-info">
            <h4>Session Information:</h4>
            <p><strong>Session ID:</strong> <?php echo session_id(); ?></p>
            <p><strong>Session Status:</strong> <?php echo session_status() === PHP_SESSION_ACTIVE ? "Aktiv" : "Nicht aktiv"; ?></p>
            <p><strong>Session Data:</strong></p>
            <pre><?php print_r($_SESSION); ?></pre>
        </div>

        <?php
        // Datenbankverbindung testen
        echo '<div class="alert alert-warning">';
        echo '<h4>Datenbank Test:</h4>';
        try {
            require_once 'config/database.php';
            echo '<p class="text-success">✓ Database config geladen</p>';
            
            if (isset($db) && $db) {
                echo '<p class="text-success">✓ Datenbankverbindung erfolgreich</p>';
                
                // Test Query
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
    $stmt->execute();
                $result = $stmt->fetch();
                echo '<p class="text-success">✓ Anzahl Benutzer: ' . $result['count'] . '</p>';
            } else {
                echo '<p class="text-danger">✗ FEHLER: Keine Datenbankverbindung</p>';
            }
} catch (Exception $e) {
            echo '<p class="text-danger">✗ FEHLER: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '</div>';

        // Functions testen
        echo '<div class="alert alert-warning">';
        echo '<h4>Functions Test:</h4>';
        try {
            require_once 'includes/functions.php';
            echo '<p class="text-success">✓ Functions geladen</p>';
            
            if (function_exists('is_logged_in')) {
                echo '<p class="text-success">✓ is_logged_in() Funktion verfügbar</p>';
                $logged_in = is_logged_in();
                echo '<p class="text-' . ($logged_in ? 'success' : 'warning') . '">Eingeloggt: ' . ($logged_in ? "Ja" : "Nein") . '</p>';
            } else {
                echo '<p class="text-danger">✗ FEHLER: is_logged_in() Funktion nicht verfügbar</p>';
            }
        } catch (Exception $e) {
            echo '<p class="text-danger">✗ FEHLER: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '</div>';

        // Login-Status prüfen
        if (isset($_SESSION['user_id'])) {
            echo '<div class="alert alert-success">';
            echo '<h4>Benutzer Information:</h4>';
            echo '<p><strong>User ID:</strong> ' . $_SESSION['user_id'] . '</p>';
            echo '<p><strong>Username:</strong> ' . ($_SESSION['username'] ?? 'Nicht gesetzt') . '</p>';
            echo '<p><strong>Name:</strong> ' . ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '') . '</p>';
            echo '<p><strong>Rolle:</strong> ' . ($_SESSION['role'] ?? 'Nicht gesetzt') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="alert alert-danger">';
            echo '<h4>Nicht eingeloggt!</h4>';
            echo '<p>Keine Session-Daten gefunden. <a href="login.php">Zur Anmeldung</a></p>';
            echo '</div>';
        }
        ?>
        
        <div class="mt-4">
            <a href="admin/dashboard.php" class="btn btn-primary">Zum echten Dashboard</a>
            <a href="login.php" class="btn btn-secondary">Zur Anmeldung</a>
        </div>
    </div>
</body>
</html>