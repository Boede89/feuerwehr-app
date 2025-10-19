<?php
/**
 * mPDF Installation
 * Installiert mPDF für PDF-Generierung ohne externe Abhängigkeiten
 */

echo "<h1>mPDF Installation</h1>";

// Prüfen ob mPDF bereits installiert ist
if (class_exists('Mpdf\\Mpdf')) {
    echo "<p style='color: green;'>✓ mPDF ist bereits installiert!</p>";
    exit;
}

echo "<p>Installiere mPDF...</p>";

// mPDF herunterladen
$mpdfUrl = 'https://github.com/mpdf/mpdf/releases/download/v8.2.0/mpdf-8.2.0.zip';
$zipFile = 'mpdf.zip';
$extractDir = 'vendor/mpdf/';

echo "<h3>mPDF herunterladen:</h3>";
echo "<pre>";
echo "wget " . $mpdfUrl;
echo "</pre>";

// Verzeichnis erstellen
if (!file_exists($extractDir)) {
    mkdir($extractDir, 0755, true);
}

// ZIP herunterladen
$zipContent = file_get_contents($mpdfUrl);
if ($zipContent === false) {
    echo "<p style='color: red;'>✗ Fehler beim Herunterladen von mPDF</p>";
    exit;
}

file_put_contents($zipFile, $zipContent);

// ZIP entpacken
$zip = new ZipArchive();
if ($zip->open($zipFile) === TRUE) {
    $zip->extractTo($extractDir);
    $zip->close();
    
    // Umbenennen
    if (file_exists($extractDir . 'mpdf-8.2.0')) {
        rename($extractDir . 'mpdf-8.2.0', $extractDir . 'mpdf');
    }
    
    // ZIP-Datei löschen
    unlink($zipFile);
    
    echo "<p style='color: green;'>✓ mPDF erfolgreich installiert!</p>";
    echo "<p>Installationspfad: " . $extractDir . "mpdf/</p>";
    
    // Test
    require_once $extractDir . 'mpdf/vendor/autoload.php';
    if (class_exists('Mpdf\\Mpdf')) {
        echo "<p style='color: green;'>✓ mPDF funktioniert korrekt!</p>";
    } else {
        echo "<p style='color: red;'>✗ mPDF konnte nicht geladen werden</p>";
    }
    
} else {
    echo "<p style='color: red;'>✗ Fehler beim Entpacken der ZIP-Datei</p>";
}

echo "<h3>Nach der Installation:</h3>";
echo "<p>mPDF wird automatisch in der PDF-Generierung verwendet, falls wkhtmltopdf nicht verfügbar ist.</p>";
?>
