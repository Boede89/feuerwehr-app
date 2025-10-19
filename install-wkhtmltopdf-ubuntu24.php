<?php
/**
 * wkhtmltopdf Installation für Ubuntu 24.04+ (Noble)
 * Installiert wkhtmltopdf mit korrekten Abhängigkeiten
 */

echo "<h1>wkhtmltopdf Installation für Ubuntu 24.04+</h1>";

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

echo "<p>Installiere wkhtmltopdf für Ubuntu 24.04+...</p>";

// Ubuntu Version prüfen
$osInfo = shell_exec('cat /etc/os-release 2>/dev/null');
$ubuntuVersion = 'unknown';
if (preg_match('/VERSION_ID="([^"]+)"/', $osInfo, $matches)) {
    $ubuntuVersion = $matches[1];
}

echo "<p>Ubuntu Version: " . $ubuntuVersion . "</p>";

// Für Ubuntu 24.04+ verwenden wir die statische Binary
echo "<h3>Installation mit statischer Binary (empfohlen für Ubuntu 24.04+):</h3>";

$installCommands = [
    'apt-get update',
    'apt-get install -y wget fontconfig libfreetype6 libjpeg-turbo8 libssl3 libxrender1 xfonts-75dpi xfonts-base',
    'wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb',
    'ar x wkhtmltox_0.12.6-1.focal_amd64.deb',
    'tar -xf data.tar.xz',
    'cp usr/local/bin/wkhtmltopdf /usr/local/bin/',
    'chmod +x /usr/local/bin/wkhtmltopdf',
    'ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf',
    'rm -rf usr/ data.tar.xz control.tar.xz debian-binary wkhtmltox_0.12.6-1.focal_amd64.deb'
];

echo "<h3>Befehle ausführen:</h3>";
echo "<pre>";
foreach ($installCommands as $cmd) {
    echo "sudo " . $cmd . "\n";
}
echo "</pre>";

echo "<h3>Alles in einem Befehl:</h3>";
echo "<pre>";
echo "sudo " . implode(" && sudo ", $installCommands);
echo "</pre>";

echo "<h3>Alternative: Snap-Installation</h3>";
echo "<p>Falls die statische Binary nicht funktioniert, können Sie wkhtmltopdf über Snap installieren:</p>";
echo "<pre>";
echo "sudo snap install wkhtmltopdf";
echo "sudo ln -sf /snap/bin/wkhtmltopdf /usr/bin/wkhtmltopdf";
echo "</pre>";

echo "<h3>Nach der Installation testen:</h3>";
echo "<p><a href='test-wkhtmltopdf.php'>test-wkhtmltopdf.php</a></p>";

echo "<h3>Hinweise:</h3>";
echo "<ul>";
echo "<li>Ubuntu 24.04+ verwendet libssl3 statt libssl1.1</li>";
echo "<li>Die statische Binary umgeht Abhängigkeitsprobleme</li>";
echo "<li>Snap ist eine moderne Alternative</li>";
echo "</ul>";
?>
