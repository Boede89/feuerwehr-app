<?php
/**
 * wkhtmltopdf Statische Binary Installation
 * Lädt eine statische Binary herunter (keine Abhängigkeiten)
 */

echo "<h1>wkhtmltopdf Statische Binary Installation</h1>";

// Prüfen ob wkhtmltopdf bereits installiert ist
$wkhtmltopdfPath = '';
$possiblePaths = [
    '/usr/bin/wkhtmltopdf',
    '/usr/local/bin/wkhtmltopdf',
    'wkhtmltopdf'
];

foreach ($possiblePaths as $path) {
    if (is_executable($path) || (strpos($path, '/') === false && shell_exec('which ' . $path))) {
        $wkhtmltopdfPath = $path;
        break;
    }
}

if ($wkhtmltopdfPath) {
    echo "<p style='color: green;'>✓ wkhtmltopdf ist bereits installiert!</p>";
    echo "<p>Pfad: " . $wkhtmltopdfPath . "</p>";
    
    // Version prüfen
    $version = shell_exec($wkhtmltopdfPath . ' --version 2>&1');
    echo "<p>Version: " . htmlspecialchars($version) . "</p>";
    exit;
}

echo "<p>Installiere wkhtmltopdf statische Binary...</p>";

// Statische Binary herunterladen
$downloadUrl = 'https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb';
$debFile = 'wkhtmltox_0.12.6-1.focal_amd64.deb';

echo "<h3>Statische Binary herunterladen:</h3>";
echo "<pre>";
echo "wget " . $downloadUrl;
echo "</pre>";

// Prüfen ob wget verfügbar ist
if (!shell_exec('which wget 2>/dev/null')) {
    echo "<p style='color: red;'>✗ wget ist nicht verfügbar!</p>";
    echo "<p>Installieren Sie wget zuerst:</p>";
    echo "<pre>sudo apt-get install -y wget</pre>";
    exit;
}

// Binary extrahieren und installieren
$installCommands = [
    'wget ' . $downloadUrl,
    'ar x ' . $debFile,
    'tar -xf data.tar.xz',
    'cp usr/local/bin/wkhtmltopdf /usr/local/bin/',
    'chmod +x /usr/local/bin/wkhtmltopdf',
    'ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf',
    'rm -rf usr/ data.tar.xz control.tar.xz debian-binary ' . $debFile
];

echo "<h3>Installationsbefehle:</h3>";
echo "<pre>";
foreach ($installCommands as $cmd) {
    echo "sudo " . $cmd . "\n";
}
echo "</pre>";

echo "<h3>Alles in einem Befehl:</h3>";
echo "<pre>";
echo "sudo " . implode(" && sudo ", $installCommands);
echo "</pre>";

echo "<h3>Nach der Installation testen:</h3>";
echo "<p><a href='test-wkhtmltopdf.php'>test-wkhtmltopdf.php</a></p>";

echo "<h3>Vorteile der statischen Binary:</h3>";
echo "<ul>";
echo "<li>Keine Abhängigkeitsprobleme</li>";
echo "<li>Funktioniert auf allen Linux-Distributionen</li>";
echo "<li>Keine Docker erforderlich</li>";
echo "<li>Einfache Installation</li>";
echo "</ul>";

echo "<h3>Hinweise:</h3>";
echo "<ul>";
echo "<li>Die statische Binary ist größer als die normale Installation</li>";
echo "<li>Funktioniert auch ohne X11 (headless)</li>";
echo "<li>Alle Abhängigkeiten sind bereits enthalten</li>";
echo "</ul>";
?>
