<?php
/**
 * Web-Version: Datenbank-Update für Kalender-Konflikte
 */

require_once 'config/database.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_database'])) {
    try {
        echo "<div class='container mt-4'>";
        echo "<h2>🔧 Datenbank-Update für Kalender-Konflikte</h2>";
        echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";
        
        // Prüfe ob calendar_conflicts Spalte bereits existiert
        $stmt = $db->query("SHOW COLUMNS FROM reservations LIKE 'calendar_conflicts'");
        $column_exists = $stmt->fetch();
        
        if (!$column_exists) {
            // Füge calendar_conflicts Spalte hinzu
            $db->exec("ALTER TABLE reservations ADD COLUMN calendar_conflicts TEXT NULL AFTER location");
            echo "<div class='alert alert-success'>✅ Kalender-Konflikt-Feld erfolgreich zur Reservierungen-Tabelle hinzugefügt.</div>";
            
            // Füge Kommentar hinzu
            $db->exec("ALTER TABLE reservations MODIFY COLUMN calendar_conflicts TEXT NULL COMMENT 'JSON-Array der gefundenen Kalender-Konflikte'");
            echo "<div class='alert alert-success'>✅ Kommentar für Kalender-Konflikt-Feld hinzugefügt.</div>";
        } else {
            echo "<div class='alert alert-info'>ℹ️ Kalender-Konflikt-Feld existiert bereits in der Reservierungen-Tabelle.</div>";
        }
        
        // Zeige aktuelle Tabellenstruktur
        echo "<h3>📋 Aktuelle Reservierungen-Tabellenstruktur:</h3>";
        $stmt = $db->query("DESCRIBE reservations");
        $columns = $stmt->fetchAll();
        
        echo "<table class='table table-striped'>";
        echo "<thead><tr><th>Feld</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>";
        echo "<tbody>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td><strong>{$column['Field']}</strong></td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "<td>{$column['Extra']}</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        
        echo "<div class='alert alert-success'>🎉 Datenbank-Update abgeschlossen!</div>";
        echo "<p><a href='admin/dashboard.php' class='btn btn-primary'>Zum Dashboard</a></p>";
        
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>❌ Fehler beim Hinzufügen des Kalender-Konflikt-Feldes: " . $e->getMessage() . "</div>";
        echo "<div class='alert alert-danger'>Stack Trace: " . $e->getTraceAsString() . "</div>";
    }
    
    echo "</div>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datenbank-Update - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-database"></i> Datenbank-Update</h2>
                    </div>
                    <div class="card-body">
                        <p>Dieses Update fügt das <code>calendar_conflicts</code> Feld zur Reservierungen-Tabelle hinzu.</p>
                        
                        <h4>Was wird gemacht:</h4>
                        <ul>
                            <li>Fügt <code>calendar_conflicts</code> Spalte hinzu</li>
                            <li>Speichert JSON-Array der gefundenen Kalender-Konflikte</li>
                            <li>Ermöglicht automatische Konfliktprüfung</li>
                        </ul>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warnung:</strong> Führe dieses Update nur einmal aus!
                        </div>
                        
                        <form method="POST">
                            <button type="submit" name="update_database" class="btn btn-primary btn-lg">
                                <i class="fas fa-play"></i> Datenbank-Update ausführen
                            </button>
                        </form>
                        
                        <hr>
                        
                        <h4>Nach dem Update:</h4>
                        <ol>
                            <li>Teste die Reservierungsgenehmigung</li>
                            <li>Prüfe ob Kalender-Konflikte angezeigt werden</li>
                            <li>Teste die Google Calendar Integration</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
