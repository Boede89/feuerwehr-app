<?php
/**
 * Check Database Structure - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/check-database-structure.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔍 Check Database Structure</h1>";
echo "<p>Diese Seite überprüft die Datenbankstruktur.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe users Tabelle
    echo "<h2>2. Prüfe users Tabelle:</h2>";
    
    $stmt = $db->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ users Tabelle existiert nicht</p>";
    } else {
        echo "<p style='color: green;'>✅ users Tabelle existiert</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Spalte</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Prüfe reservations Tabelle
    echo "<h2>3. Prüfe reservations Tabelle:</h2>";
    
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ reservations Tabelle existiert nicht</p>";
    } else {
        echo "<p style='color: green;'>✅ reservations Tabelle existiert</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Spalte</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 4. Prüfe vehicles Tabelle
    echo "<h2>4. Prüfe vehicles Tabelle:</h2>";
    
    $stmt = $db->query("DESCRIBE vehicles");
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ vehicles Tabelle existiert nicht</p>";
    } else {
        echo "<p style='color: green;'>✅ vehicles Tabelle existiert</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Spalte</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 5. Prüfe alle Tabellen
    echo "<h2>5. Prüfe alle Tabellen:</h2>";
    
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll();
    
    if (empty($tables)) {
        echo "<p style='color: red;'>❌ Keine Tabellen gefunden</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($tables) . " Tabellen gefunden</p>";
        echo "<ul>";
        foreach ($tables as $table) {
            $table_name = array_values($table)[0];
            echo "<li>" . htmlspecialchars($table_name) . "</li>";
        }
        echo "</ul>";
    }
    
    // 6. Prüfe vorhandene Benutzer (ohne role Spalte)
    echo "<h2>6. Prüfe vorhandene Benutzer (ohne role Spalte):</h2>";
    
    try {
        $stmt = $db->query("SELECT * FROM users LIMIT 5");
        $users = $stmt->fetchAll();
        
        if (empty($users)) {
            echo "<p style='color: red;'>❌ Keine Benutzer gefunden</p>";
        } else {
            echo "<p style='color: green;'>✅ " . count($users) . " Benutzer gefunden</p>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Benutzername</th><th>E-Mail</th><th>Erstellt</th></tr>";
            foreach ($users as $user) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($user['id']) . "</td>";
                echo "<td>" . htmlspecialchars($user['username'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($user['email'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($user['created_at'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Benutzer-Abfrage fehlgeschlagen: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 7. Prüfe vorhandene Reservierungen
    echo "<h2>7. Prüfe vorhandene Reservierungen:</h2>";
    
    try {
        $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.id DESC LIMIT 5");
        $reservations = $stmt->fetchAll();
        
        if (empty($reservations)) {
            echo "<p style='color: red;'>❌ Keine Reservierungen gefunden</p>";
        } else {
            echo "<p style='color: green;'>✅ " . count($reservations) . " Reservierungen gefunden</p>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Status</th><th>Genehmigt von</th></tr>";
            foreach ($reservations as $reservation) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($reservation['id']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['status']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['approved_by'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Reservierungs-Abfrage fehlgeschlagen: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 8. Nächste Schritte
    echo "<h2>8. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Überprüfen Sie die Tabellenstruktur oben</li>";
    echo "<li>Falls die users Tabelle keine role Spalte hat, muss sie hinzugefügt werden</li>";
    echo "<li>Falls die users Tabelle nicht existiert, muss sie erstellt werden</li>";
    echo "<li>Falls die Reservierungen keine approved_by Spalte haben, muss sie hinzugefügt werden</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Check Database Structure abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
