<?php
/**
 * wkhtmltopdf Echte Statische Binary Installation
 * Lädt eine echte statische Binary herunter (keine Abhängigkeiten)
 */

echo "<h1>wkhtmltopdf Echte Statische Binary Installation</h1>";

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

echo "<p>Installiere echte statische Binary...</p>";

// Echte statische Binary herunterladen
$downloadUrl = 'https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb';
$debFile = 'wkhtmltox_0.12.6-1.focal_amd64.deb';

echo "<h3>Echte statische Binary herunterladen:</h3>";
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

echo "<h3>Alternative: Direkte statische Binary</h3>";
echo "<p>Falls die DEB-Extraktion nicht funktioniert, können Sie eine direkte statische Binary verwenden:</p>";
echo "<pre>";
echo "wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb\n";
echo "ar x wkhtmltox_0.12.6-1.focal_amd64.deb\n";
echo "tar -xf data.tar.xz\n";
echo "sudo cp usr/local/bin/wkhtmltopdf /usr/local/bin/\n";
echo "sudo chmod +x /usr/local/bin/wkhtmltopdf\n";
echo "sudo ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf\n";
echo "rm -rf usr/ data.tar.xz control.tar.xz debian-binary wkhtmltox_0.12.6-1.focal_amd64.deb\n";
echo "</pre>";

echo "<h3>Nach der Installation testen:</h3>";
echo "<p><a href='test-wkhtmltopdf.php'>test-wkhtmltopdf.php</a></p>";

echo "<h3>Falls immer noch Abhängigkeitsprobleme:</h3>";
echo "<p>Versuchen Sie diese Lösung:</p>";
echo "<pre>";
echo "sudo apt-get install -y libjpeg-turbo8 libssl3 libxrender1 fontconfig\n";
echo "sudo ldconfig\n";
echo "</pre>";

echo "<h3>Oder verwenden Sie eine andere PDF-Lösung:</p>";
echo "<p>Falls wkhtmltopdf weiterhin Probleme macht, können wir eine andere PDF-Generierung implementieren:</p>";
echo "<ul>";
echo "<li>TCPDF (reine PHP-Lösung)</li>";
echo "<li>mPDF (reine PHP-Lösung)</li>";
echo "<li>Dompdf (reine PHP-Lösung)</li>";
echo "</ul>";
?>
