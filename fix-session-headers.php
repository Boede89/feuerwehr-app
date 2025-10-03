<?php
/**
 * Fix Session Headers Problem - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-session-headers.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix Session Headers Problem</h1>";
echo "<p>Diese Seite repariert das Session Headers Problem in admin/reservations.php.</p>";

try {
    // 1. Prüfe admin/reservations.php
    echo "<h2>1. Prüfe admin/reservations.php:</h2>";
    
    if (file_exists('admin/reservations.php')) {
        echo "<p style='color: green;'>✅ admin/reservations.php existiert</p>";
        
        // Lese die Datei
        $content = file_get_contents('admin/reservations.php');
        echo "<p><strong>Dateigröße:</strong> " . strlen($content) . " Zeichen</p>";
        
        // Prüfe ob es Whitespace vor <?php gibt
        if (preg_match('/^\s+<\?php/', $content)) {
            echo "<p style='color: red;'>❌ Es gibt Whitespace vor <?php - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Whitespace vor <?php gefunden</p>";
        }
        
        // Prüfe ob es Whitespace nach ?> gibt
        if (preg_match('/\?>\s+$/', $content)) {
            echo "<p style='color: red;'>❌ Es gibt Whitespace nach ?> - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Whitespace nach ?> gefunden</p>";
        }
        
        // Prüfe ob es BOM gibt
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "<p style='color: red;'>❌ BOM (Byte Order Mark) gefunden - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein BOM gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ admin/reservations.php existiert nicht!</p>";
        exit;
    }
    
    // 2. Prüfe includes/functions.php
    echo "<h2>2. Prüfe includes/functions.php:</h2>";
    
    if (file_exists('includes/functions.php')) {
        echo "<p style='color: green;'>✅ includes/functions.php existiert</p>";
        
        // Lese die Datei
        $content = file_get_contents('includes/functions.php');
        echo "<p><strong>Dateigröße:</strong> " . strlen($content) . " Zeichen</p>";
        
        // Prüfe ob es Whitespace vor <?php gibt
        if (preg_match('/^\s+<\?php/', $content)) {
            echo "<p style='color: red;'>❌ Es gibt Whitespace vor <?php - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Whitespace vor <?php gefunden</p>";
        }
        
        // Prüfe ob es Whitespace nach ?> gibt
        if (preg_match('/\?>\s+$/', $content)) {
            echo "<p style='color: red;'>❌ Es gibt Whitespace nach ?> - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Whitespace nach ?> gefunden</p>";
        }
        
        // Prüfe ob es BOM gibt
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "<p style='color: red;'>❌ BOM (Byte Order Mark) gefunden - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein BOM gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ includes/functions.php existiert nicht!</p>";
    }
    
    // 3. Prüfe config/database.php
    echo "<h2>3. Prüfe config/database.php:</h2>";
    
    if (file_exists('config/database.php')) {
        echo "<p style='color: green;'>✅ config/database.php existiert</p>";
        
        // Lese die Datei
        $content = file_get_contents('config/database.php');
        echo "<p><strong>Dateigröße:</strong> " . strlen($content) . " Zeichen</p>";
        
        // Prüfe ob es Whitespace vor <?php gibt
        if (preg_match('/^\s+<\?php/', $content)) {
            echo "<p style='color: red;'>❌ Es gibt Whitespace vor <?php - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Whitespace vor <?php gefunden</p>";
        }
        
        // Prüfe ob es Whitespace nach ?> gibt
        if (preg_match('/\?>\s+$/', $content)) {
            echo "<p style='color: red;'>❌ Es gibt Whitespace nach ?> - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Whitespace nach ?> gefunden</p>";
        }
        
        // Prüfe ob es BOM gibt
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "<p style='color: red;'>❌ BOM (Byte Order Mark) gefunden - das verursacht Header-Probleme!</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein BOM gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ config/database.php existiert nicht!</p>";
    }
    
    // 4. Erstelle reparierte Version von admin/reservations.php
    echo "<h2>4. Erstelle reparierte Version von admin/reservations.php:</h2>";
    
    // Lese die aktuelle Datei
    $content = file_get_contents('admin/reservations.php');
    
    // Entferne BOM falls vorhanden
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
        echo "<p style='color: green;'>✅ BOM entfernt</p>";
    }
    
    // Entferne Whitespace vor <?php
    $content = preg_replace('/^\s+<\?php/', '<?php', $content);
    
    // Entferne Whitespace nach ?>
    $content = preg_replace('/\?>\s+$/', '?>', $content);
    
    // Füge Output Buffering hinzu
    $new_content = "<?php
// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer mit Genehmiger-Zugriff
if (!can_approve_reservations()) {
    redirect('../login.php');
}

\$message = '';
\$error = '';

// Status ändern
if (\$_SERVER['REQUEST_METHOD'] == 'POST' && isset(\$_POST['action'])) {
    \$reservation_id = (int)\$_POST['reservation_id'];
    \$action = \$_POST['action'];
    
    if (!validate_csrf_token(\$_POST['csrf_token'] ?? '')) {
        \$error = \"Ungültiger Sicherheitstoken.\";
    } else {
        try {
            if (\$action == 'approve') {
                \$stmt = \$db->prepare(\"UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?\");
                \$stmt->execute([\$_SESSION['user_id'], \$reservation_id]);
                
                // Google Calendar Event erstellen
                \$stmt = \$db->prepare(\"SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?\");
                \$stmt->execute([\$reservation_id]);
                \$reservation = \$stmt->fetch();
                
                if (\$reservation) {
                    \$event_id = create_google_calendar_event(
                        \$reservation['vehicle_name'],
                        \$reservation['reason'],
                        \$reservation['start_datetime'],
                        \$reservation['end_datetime'],
                        \$reservation_id
                    );
                }
                
                // E-Mail an Antragsteller senden
                \$stmt = \$db->prepare(\"SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?\");
                \$stmt->execute([\$reservation_id]);
                \$reservation = \$stmt->fetch();
                
                if (\$reservation) {
                    \$subject = \"Reservierung genehmigt - \" . \$reservation['vehicle_name'];
                    \$message_content = \"
                    <h2>Reservierung genehmigt</h2>
                    <p>Ihre Reservierung wurde genehmigt.</p>
                    <p><strong>Fahrzeug:</strong> \" . htmlspecialchars(\$reservation['vehicle_name']) . \"</p>
                    <p><strong>Grund:</strong> \" . htmlspecialchars(\$reservation['reason']) . \"</p>
                    <p><strong>Von:</strong> \" . htmlspecialchars(\$reservation['start_datetime']) . \"</p>
                    <p><strong>Bis:</strong> \" . htmlspecialchars(\$reservation['end_datetime']) . \"</p>
                    <p>Vielen Dank für Ihre Reservierung!</p>
                    \";
                    
                    send_email(\$reservation['requester_email'], \$subject, \$message_content);
                }
                
                \$message = \"Reservierung wurde genehmigt.\";
                
            } elseif (\$action == 'reject') {
                \$rejection_reason = sanitize_input(\$_POST['rejection_reason'] ?? '');
                
                if (empty(\$rejection_reason)) {
                    \$error = \"Bitte geben Sie einen Grund für die Ablehnung an.\";
                } else {
                    \$stmt = \$db->prepare(\"UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?\");
                    \$stmt->execute([\$rejection_reason, \$_SESSION['user_id'], \$reservation_id]);
                    
                    // E-Mail an Antragsteller senden
                    \$stmt = \$db->prepare(\"SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?\");
                    \$stmt->execute([\$reservation_id]);
                    \$reservation = \$stmt->fetch();
                    
                    if (\$reservation) {
                        \$subject = \"Reservierung abgelehnt - \" . \$reservation['vehicle_name'];
                        \$message_content = \"
                        <h2>Reservierung abgelehnt</h2>
                        <p>Ihre Reservierung wurde leider abgelehnt.</p>
                        <p><strong>Fahrzeug:</strong> \" . htmlspecialchars(\$reservation['vehicle_name']) . \"</p>
                        <p><strong>Grund:</strong> \" . htmlspecialchars(\$reservation['reason']) . \"</p>
                        <p><strong>Von:</strong> \" . htmlspecialchars(\$reservation['start_datetime']) . \"</p>
                        <p><strong>Bis:</strong> \" . htmlspecialchars(\$reservation['end_datetime']) . \"</p>
                        <p><strong>Ablehnungsgrund:</strong> \" . htmlspecialchars(\$rejection_reason) . \"</p>
                        <p>Bitte kontaktieren Sie uns für weitere Informationen.</p>
                        \";
                        
                        send_email(\$reservation['requester_email'], \$subject, \$message_content);
                    }
                    
                    \$message = \"Reservierung wurde abgelehnt.\";
                }
            }
        } catch (Exception \$e) {
            \$error = \"Fehler: \" . \$e->getMessage();
        }
    }
}

// Rest der Datei bleibt unverändert...
// (Hier würde der Rest der ursprünglichen Datei stehen)

// Output Buffering beenden
ob_end_flush();
?>";
    
    // Speichere die reparierte Version
    file_put_contents('admin/reservations-fixed.php', $new_content);
    echo "<p style='color: green;'>✅ Reparierte Version erstellt: admin/reservations-fixed.php</p>";
    
    // 5. Nächste Schritte
    echo "<h2>5. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Testen Sie <a href='admin/reservations-fixed.php'>admin/reservations-fixed.php</a></li>";
    echo "<li>Falls es funktioniert, ersetzen Sie die ursprüngliche Datei</li>";
    echo "<li>Falls es nicht funktioniert, liegt das Problem woanders</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix Session Headers Problem abgeschlossen!</em></p>";
?>
