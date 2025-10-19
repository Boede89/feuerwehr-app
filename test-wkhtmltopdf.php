<?php
/**
 * wkhtmltopdf Test Script
 * Testet ob wkhtmltopdf funktioniert
 */

echo "<h1>wkhtmltopdf Test</h1>";

// Prüfen ob wkhtmltopdf verfügbar ist
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

if (!$wkhtmltopdfPath) {
    echo "<p style='color: red;'>✗ wkhtmltopdf nicht gefunden!</p>";
    echo "<p>Bitte installieren Sie wkhtmltopdf zuerst: <a href='install-wkhtmltopdf.php'>install-wkhtmltopdf.php</a></p>";
    exit;
}

echo "<p style='color: green;'>✓ wkhtmltopdf gefunden: " . $wkhtmltopdfPath . "</p>";

// Version prüfen
$version = shell_exec($wkhtmltopdfPath . ' --version 2>&1');
echo "<p>Version: " . htmlspecialchars($version) . "</p>";

// Test-PDF generieren
$testHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test PDF</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #dc3545; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #dc3545; color: white; }
    </style>
</head>
<body>
    <h1>Test PDF - wkhtmltopdf</h1>
    <p>Dies ist ein Test-PDF, generiert mit wkhtmltopdf.</p>
    <p>Erstellt am: ' . date('d.m.Y H:i:s') . '</p>
    
    <table>
        <tr>
            <th>Name</th>
            <th>E-Mail</th>
            <th>Status</th>
        </tr>
        <tr>
            <td>Max Mustermann</td>
            <td>max@example.com</td>
            <td>Verfügbar</td>
        </tr>
        <tr>
            <td>Anna Schmidt</td>
            <td>anna@example.com</td>
            <td>Übung geplant</td>
        </tr>
    </table>
</body>
</html>';

$htmlFile = tempnam(sys_get_temp_dir(), 'test_') . '.html';
$pdfFile = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';

file_put_contents($htmlFile, $testHtml);

// PDF generieren
$command = escapeshellarg($wkhtmltopdfPath) . ' --page-size A4 --margin-top 10mm --margin-right 10mm --margin-bottom 10mm --margin-left 10mm --encoding UTF-8 ' . escapeshellarg($htmlFile) . ' ' . escapeshellarg($pdfFile);

echo "<h3>Test-Befehl:</h3>";
echo "<pre>" . htmlspecialchars($command) . "</pre>";

$output = shell_exec($command . ' 2>&1');
echo "<h3>Ausgabe:</h3>";
echo "<pre>" . htmlspecialchars($output) . "</pre>";

if (file_exists($pdfFile) && filesize($pdfFile) > 0) {
    echo "<p style='color: green;'>✓ PDF erfolgreich generiert!</p>";
    echo "<p>Dateigröße: " . filesize($pdfFile) . " Bytes</p>";
    
    // PDF anzeigen
    echo "<h3>Test-PDF:</h3>";
    echo "<iframe src='data:application/pdf;base64," . base64_encode(file_get_contents($pdfFile)) . "' width='100%' height='600px'></iframe>";
    
    // Download-Link
    echo "<p><a href='data:application/pdf;base64," . base64_encode(file_get_contents($pdfFile)) . "' download='test-wkhtmltopdf.pdf'>Test-PDF herunterladen</a></p>";
    
    // Aufräumen
    unlink($htmlFile);
    unlink($pdfFile);
    
} else {
    echo "<p style='color: red;'>✗ PDF-Generierung fehlgeschlagen!</p>";
    echo "<p>Mögliche Ursachen:</p>";
    echo "<ul>";
    echo "<li>wkhtmltopdf ist nicht korrekt installiert</li>";
    echo "<li>Fehlende Abhängigkeiten (z.B. X11, Fonts)</li>";
    echo "<li>Berechtigungsprobleme</li>";
    echo "</ul>";
    
    // Aufräumen
    if (file_exists($htmlFile)) unlink($htmlFile);
    if (file_exists($pdfFile)) unlink($pdfFile);
}

echo "<h3>Nächste Schritte:</h3>";
echo "<p>Wenn der Test erfolgreich war, können Sie <a href='admin/atemschutz-suchergebnisse.php'>zurück zur PA-Träger-Liste</a> gehen und die PDF-Download-Funktion testen.</p>";
?>
