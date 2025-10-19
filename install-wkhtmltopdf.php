<?php
/**
 * wkhtmltopdf Installation Script
 * Installiert wkhtmltopdf für verschiedene Linux-Distributionen
 */

echo "<h1>wkhtmltopdf Installation</h1>";

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

echo "<p>wkhtmltopdf wird installiert...</p>";

// Installation für verschiedene Distributionen
$osInfo = shell_exec('cat /etc/os-release 2>/dev/null || echo "ID=unknown"');
$distro = 'unknown';

if (strpos($osInfo, 'ubuntu') !== false || strpos($osInfo, 'debian') !== false) {
    $distro = 'ubuntu';
} elseif (strpos($osInfo, 'centos') !== false || strpos($osInfo, 'rhel') !== false) {
    $distro = 'centos';
} elseif (strpos($osInfo, 'alpine') !== false) {
    $distro = 'alpine';
}

echo "<p>Erkannte Distribution: " . $distro . "</p>";

$installCommands = [];

switch ($distro) {
    case 'ubuntu':
    case 'debian':
        $installCommands = [
            'apt-get update',
            'apt-get install -y wget',
            'wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb',
            'dpkg -i wkhtmltox_0.12.6-1.focal_amd64.deb || apt-get install -f -y',
            'ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf'
        ];
        break;
        
    case 'centos':
        $installCommands = [
            'yum install -y wget',
            'wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox-0.12.6-1.centos7.x86_64.rpm',
            'rpm -i wkhtmltox-0.12.6-1.centos7.x86_64.rpm || yum install -y wkhtmltox-0.12.6-1.centos7.x86_64.rpm',
            'ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf'
        ];
        break;
        
    case 'alpine':
        $installCommands = [
            'apk add --no-cache wkhtmltopdf'
        ];
        break;
        
    default:
        echo "<p style='color: orange;'>⚠ Unbekannte Distribution. Versuche manuelle Installation...</p>";
        $installCommands = [
            'wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb',
            'dpkg -i wkhtmltox_0.12.6-1.focal_amd64.deb || apt-get install -f -y',
            'ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf'
        ];
}

echo "<h3>Installationsbefehle:</h3>";
echo "<pre>";
foreach ($installCommands as $cmd) {
    echo $cmd . "\n";
}
echo "</pre>";

echo "<h3>Installation ausführen:</h3>";
echo "<p style='color: red;'>⚠ ACHTUNG: Diese Befehle benötigen Root-Rechte!</p>";
echo "<p>Führen Sie die folgenden Befehle als Root aus:</p>";
echo "<pre>";
echo "sudo " . implode(" && sudo ", $installCommands);
echo "</pre>";

echo "<h3>Alternative: Manuelle Installation</h3>";
echo "<p>1. Laden Sie wkhtmltopdf von <a href='https://wkhtmltopdf.org/downloads.html' target='_blank'>https://wkhtmltopdf.org/downloads.html</a> herunter</p>";
echo "<p>2. Installieren Sie es in <code>/usr/local/bin/wkhtmltopdf</code></p>";
echo "<p>3. Erstellen Sie einen Symlink: <code>ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf</code></p>";

echo "<h3>Test nach Installation</h3>";
echo "<p>Nach der Installation können Sie <a href='test-wkhtmltopdf.php'>test-wkhtmltopdf.php</a> aufrufen, um zu testen, ob wkhtmltopdf funktioniert.</p>";
?>
