<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug-Skript für E-Mail-Datenstruktur
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 E-Mail-Datenstruktur Debug</h1>";

try {
    // 1. Teste die API-Datenstruktur
    echo "<h2>1. API-Datenstruktur testen:</h2>";
    
    // Simuliere die Suche wie in der echten Anwendung
    $searchData = [
        'uebungsDatum' => '2024-02-15',
        'anzahlPaTraeger' => 'alle',
        'statusFilter' => ['Tauglich', 'Warnung']
    ];
    
    // Führe die Suche durch
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/search-pa-traeger.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($searchData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Cookie: ' . session_name() . '=' . session_id()
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        echo "<p style='color: green;'>✅ API-Aufruf erfolgreich</p>";
        
        if (isset($data['traeger']) && !empty($data['traeger'])) {
            echo "<p>Anzahl gefundener PA-Träger: " . count($data['traeger']) . "</p>";
            
            echo "<h3>Erste 3 PA-Träger:</h3>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>Feld</th><th>Wert</th></tr>";
            
            $firstTraeger = $data['traeger'][0];
            foreach ($firstTraeger as $key => $value) {
                echo "<tr><td><strong>$key</strong></td><td>" . htmlspecialchars($value) . "</td></tr>";
            }
            echo "</table>";
            
            // 2. Teste E-Mail-Generierung
            echo "<h2>2. E-Mail-Generierung testen:</h2>";
            
            $testMessage = "Test-Nachricht für Debug";
            $testParams = [
                'uebungsDatum' => '2024-02-15',
                'anzahlPaTraeger' => 'alle',
                'statusFilter' => ['Tauglich', 'Warnung']
            ];
            
            // Teste HTML-Generierung
            require_once 'api/email-pa-traeger.php';
            
            $htmlContent = [
                'results' => $data['traeger'],
                'params' => $testParams,
                'uebungsDatum' => $testParams['uebungsDatum'],
                'anzahlPaTraeger' => $testParams['anzahlPaTraeger'],
                'statusFilter' => $testParams['statusFilter']
            ];
            
            $htmlEmail = generateBeautifulEmailHTML($data['traeger'], $htmlContent, $testMessage);
            
            echo "<p style='color: green;'>✅ HTML-E-Mail generiert (" . strlen($htmlEmail) . " Zeichen)</p>";
            
            // Prüfe ob Namen enthalten sind
            if (strpos($htmlEmail, 'Max Mustermann') !== false || strpos($htmlEmail, 'Anna Musterfrau') !== false) {
                echo "<p style='color: green;'>✅ Namen sind in der E-Mail enthalten</p>";
            } else {
                echo "<p style='color: red;'>❌ Namen fehlen in der E-Mail</p>";
                
                // Zeige einen Teil der HTML-E-Mail
                echo "<h3>HTML-E-Mail Vorschau (erste 2000 Zeichen):</h3>";
                echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f9f9f9; max-height: 300px; overflow-y: auto;'>";
                echo htmlspecialchars(substr($htmlEmail, 0, 2000)) . "...";
                echo "</div>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Keine PA-Träger gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ API-Aufruf fehlgeschlagen (HTTP $httpCode)</p>";
        echo "<p>Response: " . htmlspecialchars($response) . "</p>";
    }
    
    // 3. Direkte Datenbankabfrage
    echo "<h2>3. Direkte Datenbankabfrage:</h2>";
    
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, status FROM atemschutz_traeger LIMIT 3");
    $stmt->execute();
    $dbResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($dbResults)) {
        echo "<p>Datenbank-Ergebnisse:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>First Name</th><th>Last Name</th><th>Email</th><th>Status</th></tr>";
        
        foreach ($dbResults as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['first_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ Keine Daten in der Datenbank gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>4. Nächste Schritte:</h2>";
echo "<ol>";
echo "<li>Überprüfen Sie die Datenbank auf PA-Träger</li>";
echo "<li>Testen Sie die E-Mail-Export-Funktion</li>";
echo "<li>Prüfen Sie die SMTP-Einstellungen</li>";
echo "</ol>";

echo "<p><a href='admin/dashboard.php'>Zurück zum Dashboard</a></p>";
?>




