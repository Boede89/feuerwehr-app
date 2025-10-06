<?php
/**
 * Cron-Job Setup für automatisches Cleanup abgelaufener Reservierungen
 * 
 * Dieses Script hilft beim Einrichten eines Cron-Jobs für das automatische Cleanup.
 */

echo "🔧 Cron-Job Setup für Cleanup abgelaufener Reservierungen\n";
echo "========================================================\n\n";

// Aktuelles Verzeichnis ermitteln
$current_dir = __DIR__;
$cleanup_script = $current_dir . '/cleanup-expired-reservations.php';

echo "📁 Aktuelles Verzeichnis: {$current_dir}\n";
echo "📄 Cleanup-Script: {$cleanup_script}\n\n";

// Prüfen ob Cleanup-Script existiert
if (!file_exists($cleanup_script)) {
    echo "❌ Fehler: cleanup-expired-reservations.php nicht gefunden!\n";
    echo "   Bitte stellen Sie sicher, dass das Script im gleichen Verzeichnis liegt.\n";
    exit(1);
}

echo "✅ Cleanup-Script gefunden\n\n";

// PHP-Pfad ermitteln
$php_path = PHP_BINARY;
echo "🐘 PHP-Pfad: {$php_path}\n\n";

// Cron-Job Vorschläge
echo "📋 Cron-Job Vorschläge:\n";
echo "========================\n\n";

echo "1. Täglich um 2:00 Uhr (empfohlen):\n";
echo "   0 2 * * * {$php_path} {$cleanup_script} >> /var/log/cleanup-reservations.log 2>&1\n\n";

echo "2. Alle 6 Stunden:\n";
echo "   0 */6 * * * {$php_path} {$cleanup_script} >> /var/log/cleanup-reservations.log 2>&1\n\n";

echo "3. Wöchentlich (Sonntag um 3:00 Uhr):\n";
echo "   0 3 * * 0 {$php_path} {$cleanup_script} >> /var/log/cleanup-reservations.log 2>&1\n\n";

echo "4. Ohne Logging (nur bei Fehlern):\n";
echo "   0 2 * * * {$php_path} {$cleanup_script} > /dev/null 2>&1\n\n";

// Cron-Job einrichten
echo "⚙️ Cron-Job einrichten:\n";
echo "======================\n\n";

echo "1. Öffnen Sie die Crontab:\n";
echo "   crontab -e\n\n";

echo "2. Fügen Sie eine der obigen Zeilen hinzu\n\n";

echo "3. Speichern und schließen Sie den Editor\n\n";

echo "4. Prüfen Sie den Cron-Job:\n";
echo "   crontab -l\n\n";

// Test-Ausführung
echo "🧪 Test-Ausführung:\n";
echo "==================\n\n";

echo "Führen Sie das Cleanup-Script manuell aus, um zu testen:\n";
echo "{$php_path} {$cleanup_script}\n\n";

echo "Oder mit Logging:\n";
echo "{$php_path} {$cleanup_script} >> /var/log/cleanup-reservations.log 2>&1\n\n";

// Log-Verzeichnis erstellen
$log_dir = '/var/log';
$log_file = $log_dir . '/cleanup-reservations.log';

echo "📝 Log-Datei:\n";
echo "=============\n\n";

if (is_writable($log_dir)) {
    echo "✅ Log-Verzeichnis ist beschreibbar: {$log_dir}\n";
    echo "📄 Log-Datei wird erstellt: {$log_file}\n";
} else {
    echo "⚠️ Log-Verzeichnis ist nicht beschreibbar: {$log_dir}\n";
    echo "💡 Alternative: Verwenden Sie ein lokales Verzeichnis:\n";
    echo "   {$current_dir}/logs/cleanup-reservations.log\n\n";
    
    // Lokales Log-Verzeichnis erstellen
    $local_log_dir = $current_dir . '/logs';
    if (!is_dir($local_log_dir)) {
        if (mkdir($local_log_dir, 0755, true)) {
            echo "✅ Lokales Log-Verzeichnis erstellt: {$local_log_dir}\n";
        } else {
            echo "❌ Konnte lokales Log-Verzeichnis nicht erstellen\n";
        }
    }
}

// Docker-spezifische Anweisungen
echo "\n🐳 Docker-spezifische Anweisungen:\n";
echo "==================================\n\n";

echo "Falls Sie Docker verwenden, können Sie einen Cron-Job im Container einrichten:\n\n";

echo "1. Container mit Cron starten:\n";
echo "   docker run -d --name feuerwehr-cron \\\n";
echo "     -v {$current_dir}:/app \\\n";
echo "     -v /var/log:/var/log \\\n";
echo "     your-image /bin/bash -c 'cron && tail -f /dev/null'\n\n";

echo "2. Cron-Job im Container einrichten:\n";
echo "   docker exec -it feuerwehr-cron crontab -e\n\n";

echo "3. Oder mit docker-compose:\n";
echo "   Fügen Sie einen cron-Service zu Ihrer docker-compose.yml hinzu:\n\n";

echo "   services:\n";
echo "     cron:\n";
echo "       image: your-image\n";
echo "       volumes:\n";
echo "         - .:/app\n";
echo "         - /var/log:/var/log\n";
echo "       command: /bin/bash -c 'cron && tail -f /dev/null'\n\n";

echo "✅ Setup-Anweisungen abgeschlossen!\n";
echo "📚 Weitere Informationen finden Sie in der README.md\n";
?>
