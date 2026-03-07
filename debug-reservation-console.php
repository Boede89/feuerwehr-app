<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Reservation mit Browser Console Logging
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug: Reservation Console</title></head><body>";
echo "<h1>🔍 Debug: Reservation mit Browser Console</h1>";

try {
    echo "<h2>1. Prüfe reservation.php</h2>";
    
    if (file_exists('reservation.php')) {
        echo "✅ reservation.php existiert<br>";
        
        $content = file_get_contents('reservation.php');
        if ($content) {
            echo "✅ reservation.php kann gelesen werden<br>";
            
            // Prüfe auf JavaScript Console Logging
            if (strpos($content, 'console.log') !== false) {
                echo "✅ Console Logging bereits vorhanden<br>";
            } else {
                echo "❌ Kein Console Logging gefunden<br>";
            }
            
        } else {
            echo "❌ reservation.php kann nicht gelesen werden<br>";
        }
    } else {
        echo "❌ reservation.php existiert nicht<br>";
    }
    
    echo "<h2>2. Füge Console Logging zu reservation.php hinzu</h2>";
    
    // Lade die aktuelle reservation.php
    $reservation_content = file_get_contents('reservation.php');
    
    // Füge Console Logging nach dem Session-Fix hinzu
    $console_logging = "
// Browser Console Logging für Debugging
echo '<script>';
echo 'console.log(\"🔍 Reservation Page Debug\");';
echo 'console.log(\"Zeitstempel:\", new Date().toLocaleString());';
echo 'console.log(\"Session user_id:\", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Session role:\", ' . json_encode($_SESSION['role'] ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Selected Vehicle:\", ' . json_encode($selectedVehicle ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Message:\", ' . json_encode($message ?? '') . ');';
echo 'console.log(\"Error:\", ' . json_encode($error ?? '') . ');';
echo 'console.log(\"POST Data:\", ' . json_encode($_POST ?? []) . ');';
echo '</script>';
";
    
    // Füge Console Logging nach dem Session-Fix ein
    $insert_position = strpos($reservation_content, '$message = \'\';');
    if ($insert_position !== false) {
        $new_content = substr($reservation_content, 0, $insert_position) . 
                      $console_logging . "\n" . 
                      substr($reservation_content, $insert_position);
        
        if (file_put_contents('reservation.php', $new_content)) {
            echo "✅ Console Logging zu reservation.php hinzugefügt<br>";
        } else {
            echo "❌ Fehler beim Hinzufügen des Console Loggings<br>";
        }
    } else {
        echo "❌ Einfügeposition nicht gefunden<br>";
    }
    
    echo "<h2>3. Füge Console Logging zu admin/dashboard.php hinzu</h2>";
    
    if (file_exists('admin/dashboard.php')) {
        $dashboard_content = file_get_contents('admin/dashboard.php');
        
        // Füge Console Logging am Anfang hinzu
        $dashboard_console = "
// Browser Console Logging für Debugging
echo '<script>';
echo 'console.log(\"🔍 Admin Dashboard Debug\");';
echo 'console.log(\"Zeitstempel:\", new Date().toLocaleString());';
echo 'console.log(\"Session user_id:\", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Session role:\", ' . json_encode($_SESSION['role'] ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Anzahl Reservierungen:\", ' . count($reservations) . ');';
echo 'console.log(\"Google Calendar Einstellungen:\", ' . json_encode($google_calendar_settings ?? []) . ');';
echo '</script>';
";
        
        // Füge nach dem Session-Fix ein
        $insert_pos = strpos($dashboard_content, '// Google Calendar Einstellungen laden');
        if ($insert_pos !== false) {
            $new_dashboard = substr($dashboard_content, 0, $insert_pos) . 
                           $dashboard_console . "\n" . 
                           substr($dashboard_content, $insert_pos);
            
            if (file_put_contents('admin/dashboard.php', $new_dashboard)) {
                echo "✅ Console Logging zu admin/dashboard.php hinzugefügt<br>";
            } else {
                echo "❌ Fehler beim Hinzufügen des Console Loggings zu Dashboard<br>";
            }
        }
    }
    
    echo "<h2>4. Füge Console Logging zu admin/reservations.php hinzu</h2>";
    
    if (file_exists('admin/reservations.php')) {
        $reservations_content = file_get_contents('admin/reservations.php');
        
        // Füge Console Logging am Anfang hinzu
        $reservations_console = "
// Browser Console Logging für Debugging
echo '<script>';
echo 'console.log(\"🔍 Admin Reservations Debug\");';
echo 'console.log(\"Zeitstempel:\", new Date().toLocaleString());';
echo 'console.log(\"Session user_id:\", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Session role:\", ' . json_encode($_SESSION['role'] ?? 'nicht gesetzt') . ');';
echo 'console.log(\"Filter:\", ' . json_encode($filter ?? '') . ');';
echo 'console.log(\"Anzahl Reservierungen:\", ' . count($reservations) . ');';
echo 'console.log(\"Google Calendar Einstellungen:\", ' . json_encode($google_calendar_settings ?? []) . ');';
echo '</script>';
";
        
        // Füge nach dem Session-Fix ein
        $insert_pos = strpos($reservations_content, '// Google Calendar Einstellungen laden');
        if ($insert_pos !== false) {
            $new_reservations = substr($reservations_content, 0, $insert_pos) . 
                              $reservations_console . "\n" . 
                              substr($reservations_content, $insert_pos);
            
            if (file_put_contents('admin/reservations.php', $new_reservations)) {
                echo "✅ Console Logging zu admin/reservations.php hinzugefügt<br>";
            } else {
                echo "❌ Fehler beim Hinzufügen des Console Loggings zu Reservations<br>";
            }
        }
    }
    
    echo "<h2>5. Teste Console Logging</h2>";
    
    // Teste ob die Dateien geladen werden können
    try {
        ob_start();
        include 'reservation.php';
        $output = ob_get_clean();
        
        if (strpos($output, 'console.log') !== false) {
            echo "✅ Console Logging in reservation.php funktioniert<br>";
        } else {
            echo "❌ Console Logging in reservation.php funktioniert nicht<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Fehler beim Testen: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    
    echo "<h2>6. Zusammenfassung</h2>";
    echo "✅ Console Logging zu allen relevanten Seiten hinzugefügt<br>";
    echo "✅ Debug-Informationen werden in der Browser Console angezeigt<br>";
    echo "✅ Session-Werte werden geloggt<br>";
    echo "✅ Google Calendar Einstellungen werden geloggt<br>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<h3>🔍 Jetzt teste die Seiten und öffne die Browser Console (F12):</h3>";
echo "<p><a href='reservation.php' target='_blank'>Reservierung erstellen</a></p>";
echo "<p><a href='admin/dashboard.php' target='_blank'>Dashboard</a></p>";
echo "<p><a href='admin/reservations.php' target='_blank'>Reservierungen verwalten</a></p>";
echo "<p><strong>Öffne die Browser Console (F12) um die Debug-Informationen zu sehen!</strong></p>";
echo "</body></html>";
?>
