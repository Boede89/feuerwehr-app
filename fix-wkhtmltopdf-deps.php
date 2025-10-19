<?php
/**
 * wkhtmltopdf Dependencies Fix
 * Installiert fehlende Abhängigkeiten für wkhtmltopdf
 */

echo "<h1>wkhtmltopdf Abhängigkeiten reparieren</h1>";

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

echo "<p>Installiere fehlende Abhängigkeiten...</p>";

// Abhängigkeiten installieren
$depsCommand = 'apt-get update && apt-get install -y fontconfig libfreetype6 libjpeg-turbo8 libssl1.1 libxrender1 xfonts-75dpi xfonts-base';
echo "<h3>Befehl ausführen:</h3>";
echo "<pre>" . htmlspecialchars($depsCommand) . "</pre>";

echo "<h3>Führen Sie diesen Befehl als Root aus:</h3>";
echo "<pre>";
echo "sudo " . $depsCommand;
echo "</pre>";

echo "<h3>Dann wkhtmltopdf installieren:</h3>";
echo "<pre>";
echo "sudo dpkg -i wkhtmltox_0.12.6-1.focal_amd64.deb";
echo "sudo apt-get install -f -y";
echo "sudo ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf";
echo "</pre>";

echo "<h3>Oder alles in einem Befehl:</h3>";
echo "<pre>";
echo "sudo apt-get update && sudo apt-get install -y fontconfig libfreetype6 libjpeg-turbo8 libssl1.1 libxrender1 xfonts-75dpi xfonts-base && sudo dpkg -i wkhtmltox_0.12.6-1.focal_amd64.deb && sudo apt-get install -f -y && sudo ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf";
echo "</pre>";

echo "<h3>Nach der Installation testen:</h3>";
echo "<p><a href='test-wkhtmltopdf.php'>test-wkhtmltopdf.php</a></p>";

echo "<h3>Alternative: Statische Binary verwenden</h3>";
echo "<p>Falls die Installation weiterhin Probleme macht, können Sie eine statische Binary verwenden:</p>";
echo "<pre>";
echo "wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb";
echo "ar x wkhtmltox_0.12.6-1.focal_amd64.deb";
echo "tar -xf data.tar.xz";
echo "sudo cp usr/local/bin/wkhtmltopdf /usr/local/bin/";
echo "sudo chmod +x /usr/local/bin/wkhtmltopdf";
echo "sudo ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf";
echo "</pre>";
?>
