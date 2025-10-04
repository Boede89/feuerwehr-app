<?php
/**
 * Einfaches Debug-Script ohne komplexe Funktionen
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug</title></head><body>";
echo "<h1>🔍 Einfaches Debug-Script</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Datenbank-Verbindung testen</h2>";
    require_once 'config/database.php';
    echo "✅ Datenbank-Verbindung erfolgreich<br>";
    
    echo "<h2>1.5. Lade functions.php explizit</h2>";
    require_once 'includes/functions.php';
    echo "✅ includes/functions.php geladen<br>";
    
    echo "<h2>2. Reservierungen-Tabelle prüfen</h2>";
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    $has_calendar_conflicts = false;
    echo "<table border='1'><tr><th>Feld</th><th>Typ</th><th>Null</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "</tr>";
        
        if ($column['Field'] === 'calendar_conflicts') {
            $has_calendar_conflicts = true;
        }
    }
    echo "</table>";
    
    if ($has_calendar_conflicts) {
        echo "✅ calendar_conflicts Feld existiert<br>";
    } else {
        echo "❌ calendar_conflicts Feld fehlt!<br>";
        echo "<a href='update-database-web.php'>Datenbank-Update ausführen</a><br>";
    }
    
    echo "<h2>3. Funktionen prüfen</h2>";
    if (function_exists('check_calendar_conflicts')) {
        echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
    } else {
        echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar<br>";
    }
    
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
    } else {
        echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
    }
    
    echo "<h2>4. Google Calendar Einstellungen</h2>";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll();
    
    foreach ($settings as $setting) {
        $key = $setting['setting_key'];
        $value = $setting['setting_value'];
        
        if ($key === 'google_calendar_service_account_json') {
            echo "$key: " . (empty($value) ? 'Nicht konfiguriert' : 'Konfiguriert (' . strlen($value) . ' Zeichen)') . "<br>";
        } else {
            echo "$key: " . (empty($value) ? 'Nicht konfiguriert' : $value) . "<br>";
        }
    }
    
    echo "<h2>5. Ausstehende Reservierungen</h2>";
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "ℹ️ Keine ausstehenden Reservierungen gefunden<br>";
    } else {
        echo "Gefundene ausstehende Reservierungen:<br>";
        echo "<table border='1'><tr><th>ID</th><th>Fahrzeug</th><th>Grund</th><th>Status</th></tr>";
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>{$reservation['id']}</td>";
            echo "<td>{$reservation['vehicle_name']}</td>";
            echo "<td>{$reservation['reason']}</td>";
            echo "<td>{$reservation['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
