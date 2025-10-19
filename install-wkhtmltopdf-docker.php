<?php
/**
 * wkhtmltopdf Docker-basierte Installation
 * Verwendet Docker für wkhtmltopdf ohne Abhängigkeitsprobleme
 */

echo "<h1>wkhtmltopdf Docker-Installation</h1>";

// Prüfen ob Docker verfügbar ist
$dockerAvailable = shell_exec('which docker 2>/dev/null');
if (!$dockerAvailable) {
    echo "<p style='color: red;'>✗ Docker ist nicht installiert!</p>";
    echo "<p>Bitte installieren Sie Docker zuerst:</p>";
    echo "<pre>";
    echo "sudo apt-get update\n";
    echo "sudo apt-get install -y docker.io\n";
    echo "sudo systemctl start docker\n";
    echo "sudo systemctl enable docker\n";
    echo "sudo usermod -aG docker \$USER\n";
    echo "</pre>";
    echo "<p>Nach der Installation müssen Sie sich neu einloggen!</p>";
    exit;
}

echo "<p style='color: green;'>✓ Docker ist verfügbar</p>";

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

echo "<p>Installiere wkhtmltopdf mit Docker...</p>";

// Docker-Image herunterladen
echo "<h3>Docker-Image herunterladen:</h3>";
$pullCommand = 'docker pull yanwk/wkhtmltopdf:latest';
echo "<pre>" . htmlspecialchars($pullCommand) . "</pre>";

$pullOutput = shell_exec($pullCommand . ' 2>&1');
echo "<pre>" . htmlspecialchars($pullOutput) . "</pre>";

// Wrapper-Skript erstellen
$wrapperScript = '#!/bin/bash
# wkhtmltopdf Docker Wrapper
docker run --rm -v "$(pwd)":/app -w /app yanwk/wkhtmltopdf:latest wkhtmltopdf "$@"';

$wrapperPath = '/usr/local/bin/wkhtmltopdf';
file_put_contents('wkhtmltopdf-wrapper.sh', $wrapperScript);

echo "<h3>Wrapper-Skript erstellen:</h3>";
echo "<pre>";
echo "sudo cp wkhtmltopdf-wrapper.sh " . $wrapperPath;
echo "sudo chmod +x " . $wrapperPath;
echo "sudo ln -sf " . $wrapperPath . " /usr/bin/wkhtmltopdf";
echo "</pre>";

echo "<h3>Befehle ausführen:</h3>";
echo "<pre>";
echo "sudo cp wkhtmltopdf-wrapper.sh " . $wrapperPath . "\n";
echo "sudo chmod +x " . $wrapperPath . "\n";
echo "sudo ln -sf " . $wrapperPath . " /usr/bin/wkhtmltopdf\n";
echo "</pre>";

echo "<h3>Alles in einem Befehl:</h3>";
echo "<pre>";
echo "sudo cp wkhtmltopdf-wrapper.sh " . $wrapperPath . " && sudo chmod +x " . $wrapperPath . " && sudo ln -sf " . $wrapperPath . " /usr/bin/wkhtmltopdf";
echo "</pre>";

echo "<h3>Nach der Installation testen:</h3>";
echo "<p><a href='test-wkhtmltopdf.php'>test-wkhtmltopdf.php</a></p>";

echo "<h3>Vorteile der Docker-Lösung:</h3>";
echo "<ul>";
echo "<li>Keine Abhängigkeitsprobleme</li>";
echo "<li>Funktioniert auf allen Linux-Distributionen</li>";
echo "<li>Isolierte Umgebung</li>";
echo "<li>Einfache Updates</li>";
echo "</ul>";

echo "<h3>Hinweise:</h3>";
echo "<ul>";
echo "<li>Docker muss installiert und laufen</li>";
echo "<li>Der Benutzer muss in der docker-Gruppe sein</li>";
echo "<li>Erste Ausführung kann etwas dauern (Image-Download)</li>";
echo "</ul>";
?>
