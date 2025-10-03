<?php
/**
 * Debug-Skript für Admin-Login Problem
 */

// Direkte Datenbankverbindung
$host = 'mysql';
$dbname = 'feuerwehr_app';
$username = 'feuerwehr_user';
$password = 'feuerwehr_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🔍 Admin-Login Debug\n";
    echo "==================\n\n";
    
    // Admin-Benutzer abfragen
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "✅ Admin-Benutzer gefunden:\n";
        echo "   ID: " . $admin['id'] . "\n";
        echo "   Username: " . $admin['username'] . "\n";
        echo "   Email: " . $admin['email'] . "\n";
        echo "   First Name: " . $admin['first_name'] . "\n";
        echo "   Last Name: " . $admin['last_name'] . "\n";
        echo "   Is Admin: " . ($admin['is_admin'] ? 'Ja' : 'Nein') . "\n";
        echo "   Is Active: " . ($admin['is_active'] ? 'Ja' : 'Nein') . "\n";
        echo "   Password Hash: " . substr($admin['password_hash'], 0, 20) . "...\n\n";
        
        // Passwort testen
        $test_password = 'admin123';
        $password_verify = password_verify($test_password, $admin['password_hash']);
        echo "🔑 Passwort-Test:\n";
        echo "   Test-Passwort: $test_password\n";
        echo "   Passwort korrekt: " . ($password_verify ? 'JA' : 'NEIN') . "\n\n";
        
        if (!$password_verify) {
            echo "❌ Passwort-Hash ist falsch! Erstelle neuen Hash...\n";
            $new_hash = password_hash($test_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
            $stmt->execute([$new_hash]);
            echo "✅ Neuer Passwort-Hash erstellt\n";
            
            // Nochmal testen
            $password_verify = password_verify($test_password, $new_hash);
            echo "   Neuer Hash korrekt: " . ($password_verify ? 'JA' : 'NEIN') . "\n";
        }
        
    } else {
        echo "❌ Admin-Benutzer nicht gefunden!\n";
        
        // Admin-Benutzer erstellen
        $admin_password = 'admin123';
        $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@feuerwehr-app.local', $password_hash, 'Admin', 'User', 1, 1]);
        
        echo "✅ Admin-Benutzer erstellt\n";
        echo "   Username: admin\n";
        echo "   Password: $admin_password\n";
    }
    
    echo "\n🎉 Admin-Login sollte jetzt funktionieren!\n";
    echo "👤 Benutzername: admin\n";
    echo "🔑 Passwort: admin123\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
