<?php
/**
 * TCPDF Installation Script
 * Lädt TCPDF herunter und installiert es
 */

echo "<h1>TCPDF Installation</h1>";

// Prüfen ob TCPDF bereits installiert ist
if (class_exists('TCPDF')) {
    echo "<p style='color: green;'>✓ TCPDF ist bereits installiert!</p>";
    exit;
}

// TCPDF herunterladen
$tcpdfUrl = 'https://github.com/tecnickcom/TCPDF/archive/refs/heads/main.zip';
$zipFile = 'tcpdf.zip';
$extractDir = 'vendor/tecnickcom/';

echo "<p>Lade TCPDF herunter...</p>";

// Verzeichnis erstellen
if (!file_exists($extractDir)) {
    mkdir($extractDir, 0755, true);
}

// ZIP herunterladen
$zipContent = file_get_contents($tcpdfUrl);
if ($zipContent === false) {
    echo "<p style='color: red;'>✗ Fehler beim Herunterladen von TCPDF</p>";
    exit;
}

file_put_contents($zipFile, $zipContent);

// ZIP entpacken
$zip = new ZipArchive();
if ($zip->open($zipFile) === TRUE) {
    $zip->extractTo($extractDir);
    $zip->close();
    
    // Umbenennen
    if (file_exists($extractDir . 'TCPDF-main')) {
        rename($extractDir . 'TCPDF-main', $extractDir . 'tcpdf');
    }
    
    // ZIP-Datei löschen
    unlink($zipFile);
    
    echo "<p style='color: green;'>✓ TCPDF erfolgreich installiert!</p>";
    echo "<p>Installationspfad: " . $extractDir . "tcpdf/</p>";
    
    // Test
    require_once $extractDir . 'tcpdf/tcpdf.php';
    if (class_exists('TCPDF')) {
        echo "<p style='color: green;'>✓ TCPDF funktioniert korrekt!</p>";
    } else {
        echo "<p style='color: red;'>✗ TCPDF konnte nicht geladen werden</p>";
    }
    
} else {
    echo "<p style='color: red;'>✗ Fehler beim Entpacken der ZIP-Datei</p>";
}
?>
