<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Test-Skript für E-Mail-Export der PA-Träger-Liste
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h1>🧪 E-Mail-Export Test</h1>";

try {
    // 1. SMTP-Einstellungen prüfen
    echo "<h2>1. SMTP-Einstellungen:</h2>";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "<p><strong>$key:</strong> " . (empty($value) ? 'LEER' : 'GESETZT (' . strlen($value) . ' Zeichen)') . "</p>";
        } else {
            echo "<p><strong>$key:</strong> " . ($value ?: 'LEER') . "</p>";
        }
    }
    
    // 2. Test-Daten erstellen (wie von der API geliefert)
    echo "<h2>2. Test-Daten erstellen:</h2>";
    $testResults = [
        [
            'id' => 1,
            'name' => 'Max Mustermann',
            'email' => 'max.mustermann@feuerwehr.de',
            'status' => 'Tauglich',
            'strecke_am' => '2024-01-15',
            'g263_am' => '2024-01-10',
            'uebung_am' => '2024-01-20',
            'uebung_bis' => '2025-01-20'
        ],
        [
            'id' => 2,
            'name' => 'Anna Musterfrau',
            'email' => 'anna.musterfrau@feuerwehr.de',
            'status' => 'Warnung',
            'strecke_am' => '2023-12-01',
            'g263_am' => '2023-11-15',
            'uebung_am' => '2023-12-10',
            'uebung_bis' => '2024-12-10'
        ],
        [
            'id' => 3,
            'name' => 'Peter Feuerwehrmann',
            'email' => 'peter.feuerwehrmann@feuerwehr.de',
            'status' => 'Abgelaufen',
            'strecke_am' => '2023-06-01',
            'g263_am' => '2023-05-15',
            'uebung_am' => '2023-06-10',
            'uebung_bis' => '2024-06-10'
        ]
    ];
    
    $testParams = [
        'uebungsDatum' => '2024-02-15',
        'anzahlPaTraeger' => 'alle',
        'statusFilter' => ['Tauglich', 'Warnung']
    ];
    
    echo "<p>✅ Test-Daten erstellt (" . count($testResults) . " PA-Träger)</p>";
    
    // 3. E-Mail-Export testen
    echo "<h2>3. E-Mail-Export testen:</h2>";
    
    // Simuliere die API-Anfrage
    $emailData = [
        'recipients' => ['dleuchtenberg89@gmail.com'],
        'subject' => 'Test PA-Träger Liste - ' . date('H:i:s'),
        'message' => 'Dies ist ein Test der E-Mail-Export-Funktion.',
        'results' => $testResults,
        'params' => $testParams
    ];
    
    // Teste die sendEmailWithAttachment Funktion direkt
    require_once 'api/email-pa-traeger.php';
    
    $testEmail = 'dleuchtenberg89@gmail.com';
    $testSubject = 'Test PA-Träger Export - ' . date('H:i:s');
    $testMessage = 'Test der E-Mail-Export-Funktion für PA-Träger-Liste.';
    
    $emailContent = [
        'results' => $testResults,
        'params' => $testParams,
        'uebungsDatum' => $testParams['uebungsDatum'],
        'anzahlPaTraeger' => $testParams['anzahlPaTraeger'],
        'statusFilter' => $testParams['statusFilter']
    ];
    
    echo "<p>Teste E-Mail-Versand an: $testEmail</p>";
    
    $result = sendEmailWithAttachment($testEmail, 'loeschzug.amern@gmail.com', 'Löschzug Amern', $testSubject, $testMessage, $emailContent);
    
    if ($result) {
        echo "<p style='color: green;'>✅ E-Mail-Export erfolgreich!</p>";
    } else {
        echo "<p style='color: red;'>❌ E-Mail-Export fehlgeschlagen!</p>";
        
        // 4. Detaillierte Fehleranalyse
        echo "<h2>4. Fehleranalyse:</h2>";
        
        $smtp_host = $settings['smtp_host'] ?? '';
        $smtp_username = $settings['smtp_username'] ?? '';
        $smtp_password = $settings['smtp_password'] ?? '';
        
        if (empty($smtp_password)) {
            echo "<p style='color: red;'>❌ SMTP-Passwort ist leer!</p>";
            echo "<p>Gehen Sie zu: <a href='admin/settings.php'>Einstellungen</a> und setzen Sie das Gmail App-Passwort.</p>";
        }
        
        if (empty($smtp_host)) {
            echo "<p style='color: red;'>❌ SMTP-Host ist leer!</p>";
        }
        
        if (empty($smtp_username)) {
            echo "<p style='color: red;'>❌ SMTP-Benutzername ist leer!</p>";
        }
        
        // Teste send_email Funktion direkt
        echo "<h3>Teste send_email Funktion:</h3>";
        $testResult = send_email($testEmail, 'Direkter Test - ' . date('H:i:s'), 'Test der send_email Funktion');
        
        if ($testResult) {
            echo "<p style='color: green;'>✅ send_email Funktion funktioniert!</p>";
        } else {
            echo "<p style='color: red;'>❌ send_email Funktion funktioniert nicht!</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>5. Nächste Schritte:</h2>";
echo "<ol>";
echo "<li>Überprüfen Sie die SMTP-Einstellungen in den <a href='admin/settings.php'>Einstellungen</a></li>";
echo "<li>Stellen Sie sicher, dass das Gmail App-Passwort korrekt gesetzt ist</li>";
echo "<li>Testen Sie den E-Mail-Export in der Übungsplanung</li>";
echo "</ol>";

echo "<p><a href='admin/dashboard.php'>Zurück zum Dashboard</a></p>";
?>
