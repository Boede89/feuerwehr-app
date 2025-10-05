<?php
/**
 * Debug-Log-System: Schreibt Logs direkt in die Datenbank
 * So können wir die Google Calendar Lösch-Logs definitiv sehen
 */

// Datenbankverbindung herstellen
require_once 'config/database.php';

// Erstelle Log-Tabelle falls sie nicht existiert
function create_debug_logs_table() {
    global $db;
    
    $sql = "CREATE TABLE IF NOT EXISTS debug_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        level ENUM('INFO', 'WARNING', 'ERROR', 'DEBUG') DEFAULT 'INFO',
        message TEXT,
        context TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    try {
        $db->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Fehler beim Erstellen der debug_logs Tabelle: " . $e->getMessage());
        return false;
    }
}

// Log-Funktion für die Datenbank
function debug_log($message, $level = 'INFO', $context = '') {
    global $db;
    
    try {
        $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
        $stmt->execute([$level, $message, $context]);
        return true;
    } catch (PDOException $e) {
        error_log("Fehler beim Schreiben in debug_logs: " . $e->getMessage());
        return false;
    }
}

// Erstelle Tabelle
create_debug_logs_table();

// Test-Log schreiben
debug_log("Debug-System gestartet", "INFO", "debug-logs.php");

// Zeige vorhandene Logs
$stmt = $db->prepare("SELECT * FROM debug_logs ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Logs - Datenbank</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .log-entry { 
            margin: 10px 0; 
            padding: 10px; 
            border-left: 4px solid #ccc; 
            background: #f9f9f9;
        }
        .log-ERROR { border-left-color: #d32f2f; background: #ffebee; }
        .log-WARNING { border-left-color: #f57c00; background: #fff3e0; }
        .log-INFO { border-left-color: #1976d2; background: #e3f2fd; }
        .log-DEBUG { border-left-color: #388e3c; background: #e8f5e8; }
        .timestamp { color: #666; font-size: 0.9em; }
        .level { font-weight: bold; }
        .context { color: #888; font-size: 0.8em; margin-top: 5px; }
        .refresh-btn { 
            background: #1976d2; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            cursor: pointer; 
            margin: 10px 0;
        }
        .clear-btn { 
            background: #d32f2f; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            cursor: pointer; 
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <h1>🔍 Debug Logs (Datenbank)</h1>
    
    <div>
        <button class="refresh-btn" onclick="location.reload()">🔄 Aktualisieren</button>
        <button class="clear-btn" onclick="clearLogs()">🗑️ Logs löschen</button>
        <button class="refresh-btn" onclick="testLog()">🧪 Test-Log schreiben</button>
    </div>
    
    <h2>Letzte 50 Log-Einträge:</h2>
    
    <?php if (empty($logs)): ?>
        <p>Keine Logs gefunden. Klicke auf "Test-Log schreiben" um das System zu testen.</p>
    <?php else: ?>
        <?php foreach ($logs as $log): ?>
            <div class="log-entry log-<?php echo $log['level']; ?>">
                <div class="timestamp"><?php echo $log['created_at']; ?></div>
                <div class="level">[<?php echo $log['level']; ?>]</div>
                <div><?php echo htmlspecialchars($log['message']); ?></div>
                <?php if (!empty($log['context'])): ?>
                    <div class="context">Kontext: <?php echo htmlspecialchars($log['context']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
        function clearLogs() {
            if (confirm('Alle Logs löschen?')) {
                fetch('debug-logs.php?action=clear', {method: 'POST'})
                    .then(() => location.reload());
            }
        }
        
        function testLog() {
            fetch('debug-logs.php?action=test', {method: 'POST'})
                .then(() => location.reload());
        }
        
        // Auto-refresh alle 3 Sekunden
        setInterval(() => location.reload(), 3000);
    </script>
</body>
</html>

<?php
// AJAX-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'clear':
                $db->exec("DELETE FROM debug_logs");
                debug_log("Alle Logs gelöscht", "INFO", "debug-logs.php");
                echo "Logs gelöscht";
                break;
                
            case 'test':
                debug_log("Test-Log von " . date('Y-m-d H:i:s'), "DEBUG", "Test-Funktion");
                echo "Test-Log geschrieben";
                break;
        }
    }
    exit;
}
?>
