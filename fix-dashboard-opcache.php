<?php
echo "<h1>🔧 Fix: Dashboard Opcache Problem</h1>";

echo "<h2>1. Opcache Status prüfen</h2>";

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status) {
        echo "<p style='color: green;'>✅ Opcache ist aktiv</p>";
        echo "<p><strong>Opcache Memory Usage:</strong> " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB / " . round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) . " MB</p>";
        echo "<p><strong>Cached Scripts:</strong> " . $status['opcache_statistics']['num_cached_scripts'] . "</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Opcache Status nicht verfügbar</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Opcache nicht verfügbar</p>";
}

echo "<h2>2. Opcache zurücksetzen</h2>";

if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    if ($result) {
        echo "<p style='color: green;'>✅ Opcache erfolgreich zurückgesetzt</p>";
    } else {
        echo "<p style='color: red;'>❌ Opcache Reset fehlgeschlagen</p>";
    }
} else {
    echo "<p style='color: red;'>❌ opcache_reset() nicht verfügbar</p>";
}

echo "<h2>3. Spezifische Dateien invalidieren</h2>";

$files_to_invalidate = [
    'includes/functions.php',
    'includes/google_calendar_service_account.php',
    'admin/dashboard.php'
];

foreach ($files_to_invalidate as $file) {
    if (file_exists($file)) {
        if (function_exists('opcache_invalidate')) {
            $result = opcache_invalidate($file, true);
            if ($result) {
                echo "<p style='color: green;'>✅ $file invalidiert</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ $file konnte nicht invalidiert werden</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠️ opcache_invalidate() nicht verfügbar für $file</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ $file nicht gefunden</p>";
    }
}

echo "<h2>4. Funktionen neu laden testen</h2>";

// Lade functions.php neu
require_once 'includes/functions.php';

if (function_exists('create_or_update_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_or_update_google_calendar_event verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_or_update_google_calendar_event nicht verfügbar</p>";
}

if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event nicht verfügbar</p>";
}

echo "<h2>5. Teste Google Calendar Service Account</h2>";

if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
    
    try {
        require_once 'config/database.php';
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $json = $stmt->fetchColumn();
        
        if ($json) {
            $service_account = new GoogleCalendarServiceAccount($json, 'test-calendar-id');
            echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Instanz erstellt</p>";
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Service Account JSON nicht gefunden</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Erstellen der GoogleCalendarServiceAccount Instanz: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
}

echo "<h2>6. Teste create_or_update_google_calendar_event nach Opcache Reset</h2>";

try {
    require_once 'config/database.php';
    
    // Erstelle Test-Reservierung
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $vehicle['id'],
        'Opcache Test',
        'opcache@example.com',
        'Opcache Test - ' . date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+5 days 09:00')),
        date('Y-m-d H:i:s', strtotime('+5 days 11:00')),
        'Opcache-Ort',
        'approved'
    ]);
    
    $reservation_id = $db->lastInsertId();
    
    // Lade die Reservierung
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();
    
    echo "<p><strong>Test-Reservierung:</strong> " . $reservation['vehicle_name'] . " - " . $reservation['reason'] . "</p>";
    
    // Teste die Funktion
    $result = create_or_update_google_calendar_event(
        $reservation['vehicle_name'],
        $reservation['reason'],
        $reservation['start_datetime'],
        $reservation['end_datetime'],
        $reservation['id'],
        $reservation['location'] ?? null
    );
    
    if ($result) {
        echo "<p style='color: green;'>✅ create_or_update_google_calendar_event nach Opcache Reset erfolgreich: $result</p>";
    } else {
        echo "<p style='color: red;'>❌ create_or_update_google_calendar_event nach Opcache Reset fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception beim Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='debug-dashboard-approval.php'>← Zurück zum Dashboard Debug</a></p>";
?>
