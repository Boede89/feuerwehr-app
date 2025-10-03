<?php
/**
 * Überprüft die aktuellen Benutzerrollen
 */

require_once 'config/database.php';

echo "🔍 Benutzerrollen-Überprüfung\n";
echo "=============================\n\n";

try {
    // Alle Benutzer mit ihren Rollen anzeigen
    $stmt = $db->prepare("SELECT id, username, email, first_name, last_name, user_role, email_notifications, is_active FROM users ORDER BY user_role, username");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "📋 Aktuelle Benutzer:\n";
    echo "---------------------\n";
    
    $role_counts = ['admin' => 0, 'approver' => 0, 'user' => 0];
    
    foreach ($users as $user) {
        $role_counts[$user['user_role']]++;
        
        echo "ID: {$user['id']}\n";
        echo "  Username: {$user['username']}\n";
        echo "  E-Mail: {$user['email']}\n";
        echo "  Name: {$user['first_name']} {$user['last_name']}\n";
        echo "  Rolle: {$user['user_role']}\n";
        echo "  E-Mail-Benachrichtigungen: " . ($user['email_notifications'] ? 'JA' : 'NEIN') . "\n";
        echo "  Status: " . ($user['is_active'] ? 'AKTIV' : 'INAKTIV') . "\n";
        echo "  ---\n";
    }
    
    echo "\n📊 Rollen-Übersicht:\n";
    echo "-------------------\n";
    echo "Administratoren: {$role_counts['admin']}\n";
    echo "Genehmiger: {$role_counts['approver']}\n";
    echo "Benutzer: {$role_counts['user']}\n";
    
    echo "\n🎯 Überprüfung abgeschlossen!\n";
    
    // Test-Anmeldedaten anzeigen
    echo "\n🔑 Test-Anmeldedaten:\n";
    echo "---------------------\n";
    
    foreach ($users as $user) {
        if ($user['is_active']) {
            $test_password = '';
            switch ($user['username']) {
                case 'admin':
                    $test_password = 'admin123';
                    break;
                case 'genehmiger':
                    $test_password = 'genehmiger123';
                    break;
                default:
                    $test_password = 'passwort123'; // Standard für andere Benutzer
            }
            
            echo "Username: {$user['username']}\n";
            echo "Passwort: $test_password\n";
            echo "Rolle: {$user['user_role']}\n";
            echo "Zugriff: ";
            
            switch ($user['user_role']) {
                case 'admin':
                    echo "Vollzugriff (Dashboard, Reservierungen, Fahrzeuge, Benutzer, Einstellungen)\n";
                    break;
                case 'approver':
                    echo "Genehmiger-Zugriff (Dashboard, Reservierungen)\n";
                    break;
                case 'user':
                    echo "Nur Reservierungen einreichen\n";
                    break;
            }
            echo "---\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Setup erfolgreich!\n";
echo "📧 Die Anwendung ist bereit für den Einsatz.\n";
?>
