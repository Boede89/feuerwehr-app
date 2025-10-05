<?php
echo "<h1>🔧 Fix: Opcache-Problem vollständig beheben</h1>";

// 1. Opcache komplett leeren
echo "<h2>1. Opcache komplett leeren</h2>";

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "<p style='color: green;'>✅ Opcache erfolgreich geleert</p>";
    } else {
        echo "<p style='color: red;'>❌ Opcache konnte nicht geleert werden</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ Opcache-Funktionen nicht verfügbar</p>";
}

// 2. Spezifische Datei aus dem Cache entfernen
echo "<h2>2. Spezifische Datei aus Cache entfernen</h2>";

$functions_file = 'includes/functions.php';
if (function_exists('opcache_invalidate')) {
    if (opcache_invalidate($functions_file, true)) {
        echo "<p style='color: green;'>✅ functions.php aus Opcache entfernt</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ functions.php konnte nicht aus Opcache entfernt werden</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ opcache_invalidate nicht verfügbar</p>";
}

// 3. Alle PHP-Dateien aus Cache entfernen
echo "<h2>3. Alle PHP-Dateien aus Cache entfernen</h2>";

$php_files = [
    'includes/functions.php',
    'includes/google_calendar_service_account.php',
    'admin/dashboard.php'
];

foreach ($php_files as $file) {
    if (file_exists($file) && function_exists('opcache_invalidate')) {
        if (opcache_invalidate($file, true)) {
            echo "<p style='color: green;'>✅ $file aus Opcache entfernt</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ $file konnte nicht aus Opcache entfernt werden</p>";
        }
    }
}

// 4. Opcache-Status prüfen
echo "<h2>4. Opcache-Status nach Reset</h2>";

if (function_exists('opcache_get_status')) {
    $opcache_status = opcache_get_status();
    if ($opcache_status && $opcache_status['opcache_enabled']) {
        echo "<p><strong>Opcache Status nach Reset:</strong></p>";
        echo "<ul>";
        echo "<li>Enabled: " . ($opcache_status['opcache_enabled'] ? 'Ja' : 'Nein') . "</li>";
        echo "<li>Cache Full: " . ($opcache_status['cache_full'] ? 'Ja' : 'Nein') . "</li>";
        echo "<li>Memory Usage: " . round($opcache_status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB</p>";
        echo "<li>Cached Files: " . $opcache_status['opcache_statistics']['num_cached_scripts'] . "</li>";
        echo "</ul>";
    }
}

// 5. Teste die Funktion nach Opcache-Reset
echo "<h2>5. Teste Funktion nach Opcache-Reset</h2>";

require_once 'config/database.php';
require_once 'includes/functions.php';

try {
    // Hole das erste verfügbare Fahrzeug
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug verfügbar</p>";
        exit;
    }
    
    // Erstelle Test-Reservierung
    $test_data = [
        'vehicle_id' => $vehicle['id'],
        'requester_name' => 'Opcache Fix Test',
        'requester_email' => 'opcache@example.com',
        'reason' => 'Opcache Fix - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+4 days 10:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+4 days 12:00')),
        'location' => 'Opcache-Ort',
        'status' => 'approved'
    ];
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $test_data['vehicle_id'],
        $test_data['requester_name'],
        $test_data['requester_email'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $test_data['location'],
        $test_data['status']
    ]);
    
    $reservation_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Test-Reservierung erstellt (ID: $reservation_id)</p>";
    
    // Teste die Funktion
    error_log('OPCACHE FIX: Teste create_or_update_google_calendar_event nach Opcache-Reset');
    
    $result = create_or_update_google_calendar_event(
        $vehicle['name'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $reservation_id,
        $test_data['location']
    );
    
    if ($result) {
        echo "<p style='color: green;'>✅ Funktion funktioniert jetzt: $result</p>";
    } else {
        echo "<p style='color: red;'>❌ Funktion gibt immer noch FALSE zurück</p>";
    }
    
    // Prüfe Error Logs
    echo "<h2>6. Error Logs nach Test</h2>";
    $error_log_file = ini_get('error_log');
    if ($error_log_file && file_exists($error_log_file)) {
        $logs = file_get_contents($error_log_file);
        $recent_logs = array_slice(explode("\n", $logs), -20);
        echo "<h3>Letzte 20 Error Log Einträge:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto;'>";
        foreach ($recent_logs as $log) {
            if (strpos($log, 'Google Calendar') !== false || 
                strpos($log, 'create_or_update') !== false || 
                strpos($log, 'OPCACHE FIX') !== false ||
                strpos($log, 'Intelligente') !== false) {
                echo htmlspecialchars($log) . "\n";
            }
        }
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard (testen Sie eine Genehmigung)</a></p>";
echo "<p><a href='debug-function-version.php'>← Zurück zum Version Debug</a></p>";
?>
