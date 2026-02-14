<?php
/**
 * Dompdf Installation für echte PDF-Generierung.
 * Führt "composer require dompdf/dompdf" aus.
 * Voraussetzung: Composer muss installiert sein (https://getcomposer.org)
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dompdf Installation</title>
</head>
<body>
<h1>Dompdf Installation für PDF-Generierung</h1>

<?php
if (class_exists('Dompdf\Dompdf')) {
    echo '<p style="color: green;">✓ Dompdf ist bereits installiert!</p>';
    exit;
}

if (!file_exists(__DIR__ . '/composer.json')) {
    file_put_contents(__DIR__ . '/composer.json', json_encode([
        'name' => 'feuerwehr/app',
        'require' => ['dompdf/dompdf' => '^2.0']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '<p>composer.json wurde erstellt.</p>';
}

echo '<h2>Installation</h2>';
echo '<p>Führen Sie im Projektverzeichnis aus:</p>';
echo '<pre>composer install</pre>';
echo '<p>Falls Composer nicht installiert ist: <a href="https://getcomposer.org/download/" target="_blank">Composer herunterladen</a></p>';
echo '<p>Alternativ: <a href="install-tcpdf.php">TCPDF installieren</a> (kein Composer nötig)</p>';
?>
</body>
</html>
