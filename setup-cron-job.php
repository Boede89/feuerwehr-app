<?php
/**
 * Cron Job Setup für automatisches Löschen von überschrittenen Reservierungen
 */

echo "<h1>⏰ Cron Job Setup für automatisches Cleanup</h1>";

echo "<h2>1. Cron Job Befehl</h2>";
echo "<p>Fügen Sie folgenden Befehl zu Ihrem Cron Job hinzu:</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "# Automatisches Löschen von überschrittenen Reservierungen (täglich um 2:00 Uhr)";
echo "0 2 * * * /usr/bin/php " . realpath('cleanup-expired-reservations.php') . " > /dev/null 2>&1";
echo "</pre>";

echo "<h2>2. Alternative: Web-basierter Cron Job</h2>";
echo "<p>Falls Sie keinen direkten Cron Job Zugriff haben, können Sie einen Web-basierten Cron Job verwenden:</p>";
echo "<ul>";
echo "<li><strong>URL:</strong> <code>http://192.168.10.150/cleanup-expired-reservations.php</code></li>";
echo "<li><strong>Häufigkeit:</strong> Täglich um 2:00 Uhr</li>";
echo "<li><strong>Service:</strong> z.B. cron-job.org, setcronjob.com, oder ähnlich</li>";
echo "</ul>";

echo "<h2>3. Manueller Test</h2>";
echo "<p>Testen Sie das Cleanup-Skript manuell:</p>";
echo "<p><a href='cleanup-expired-reservations.php' class='btn btn-primary'>Cleanup jetzt ausführen</a></p>";

echo "<h2>4. Cron Job Konfiguration</h2>";
echo "<h3>4.1 cPanel (falls verfügbar)</h3>";
echo "<ol>";
echo "<li>Loggen Sie sich in cPanel ein</li>";
echo "<li>Gehen Sie zu 'Cron Jobs'</li>";
echo "<li>Fügen Sie einen neuen Cron Job hinzu:</li>";
echo "<li><strong>Minute:</strong> 0</li>";
echo "<li><strong>Stunde:</strong> 2</li>";
echo "<li><strong>Tag:</strong> *</li>";
echo "<li><strong>Monat:</strong> *</li>";
echo "<li><strong>Wochentag:</strong> *</li>";
echo "<li><strong>Befehl:</strong> <code>/usr/bin/php " . realpath('cleanup-expired-reservations.php') . "</code></li>";
echo "</ol>";

echo "<h3>4.2 Linux Server (SSH)</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "# Crontab bearbeiten";
echo "crontab -e";
echo "";
echo "# Folgende Zeile hinzufügen:";
echo "0 2 * * * /usr/bin/php " . realpath('cleanup-expired-reservations.php') . " > /dev/null 2>&1";
echo "";
echo "# Crontab anzeigen";
echo "crontab -l";
echo "</pre>";

echo "<h3>4.3 Windows Server (Task Scheduler)</h3>";
echo "<ol>";
echo "<li>Öffnen Sie den Task Scheduler</li>";
echo "<li>Erstellen Sie eine neue Aufgabe</li>";
echo "<li><strong>Name:</strong> Feuerwehr App Cleanup</li>";
echo "<li><strong>Trigger:</strong> Täglich um 2:00 Uhr</li>";
echo "<li><strong>Aktion:</strong> Programm starten</li>";
echo "<li><strong>Programm:</strong> php.exe</li>";
echo "<li><strong>Argumente:</strong> " . realpath('cleanup-expired-reservations.php') . "</li>";
echo "<li><strong>Arbeitsverzeichnis:</strong> " . dirname(realpath('cleanup-expired-reservations.php')) . "</li>";
echo "</ol>";

echo "<h2>5. Logging und Monitoring</h2>";
echo "<p>Das Cleanup-Skript erstellt Logs in der PHP error_log. Überwachen Sie diese regelmäßig:</p>";
echo "<ul>";
echo "<li><strong>Log-Datei:</strong> " . ini_get('error_log') . "</li>";
echo "<li><strong>Suchbegriff:</strong> 'Cleanup' oder 'Google Calendar Event gelöscht'</li>";
echo "</ul>";

echo "<h2>6. Test der Konfiguration</h2>";
echo "<p>Nach der Einrichtung des Cron Jobs:</p>";
echo "<ol>";
echo "<li>Warten Sie bis zur nächsten Ausführung (2:00 Uhr)</li>";
echo "<li>Prüfen Sie die Logs auf Fehler</li>";
echo "<li>Führen Sie einen manuellen Test durch</li>";
echo "<li>Überprüfen Sie, ob alte Reservierungen gelöscht wurden</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><small>Setup abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
