<?php
/**
 * Debug-Datei für Atemschutz-Liste
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    die("Keine Berechtigung");
}

echo "<h1>🔍 Atemschutz-Liste Debug</h1>";

// 1. Warnschwelle laden
$warnDays = 90;
try {
    $stmtWarn = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
    $stmtWarn->execute();
    $v = $stmtWarn->fetchColumn();
    if ($v !== false && is_numeric($v)) { 
        $warnDays = (int)$v; 
    }
} catch (Exception $e) { 
    echo "<p style='color: red;'>Fehler beim Laden der Warnschwelle: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>1. Einstellungen:</h2>";
echo "<p><strong>Warnschwelle:</strong> $warnDays Tage</p>";

// 2. Arno Adrians spezifisch laden
echo "<h2>2. Arno Adrians (spezifisch):</h2>";

try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am
        FROM atemschutz_traeger 
        WHERE first_name = 'Arno' AND last_name = 'Adrians'
        LIMIT 1
    ");
    $stmt->execute();
    $traeger = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($traeger) {
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 15px; background: #f9f9f9;'>";
        echo "<h3>" . htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']) . "</h3>";
        
        $now = new DateTime('today');
        
        // Strecke prüfen
        if ($traeger['strecke_am']) {
            $streckeAm = new DateTime($traeger['strecke_am']);
            $streckeBis = clone $streckeAm;
            $streckeBis->add(new DateInterval('P1Y'));
            $streckeDiff = (int)$now->diff($streckeBis)->format('%r%a');
            
            $streckeStatus = '';
            $streckeClass = '';
            if ($streckeDiff < 0) {
                $streckeStatus = 'ABGELAUFEN';
                $streckeClass = 'bis-expired';
            } elseif ($streckeDiff <= $warnDays && $streckeDiff >= 0) {
                $streckeStatus = 'WARNUNG';
                $streckeClass = 'bis-warn';
            } else {
                $streckeStatus = 'TAUGLICH';
                $streckeClass = '';
            }
            
            echo "<p><strong>Strecke:</strong> ";
            echo "<span style='background: " . ($streckeClass === 'bis-expired' ? '#dc3545' : ($streckeClass === 'bis-warn' ? '#fff3cd' : 'transparent')) . "; color: " . ($streckeClass === 'bis-expired' ? 'white' : ($streckeClass === 'bis-warn' ? '#664d03' : 'black')) . "; padding: 2px 8px; border-radius: 3px;'>";
            echo $streckeStatus . " (Diff: $streckeDiff Tage)";
            echo "</span>";
            echo " - Ablaufdatum: " . $streckeBis->format('d.m.Y');
            echo "</p>";
            
            echo "<p><strong>Debug-Info:</strong></p>";
            echo "<ul>";
            echo "<li>Am-Datum: " . $streckeAm->format('d.m.Y') . "</li>";
            echo "<li>Bis-Datum: " . $streckeBis->format('d.m.Y') . "</li>";
            echo "<li>Differenz: $streckeDiff Tage</li>";
            echo "<li>Warnschwelle: $warnDays Tage</li>";
            echo "<li>Bedingung 1 (abgelaufen): " . ($streckeDiff < 0 ? 'TRUE' : 'FALSE') . "</li>";
            echo "<li>Bedingung 2 (Warnung): " . (($streckeDiff <= $warnDays && $streckeDiff >= 0) ? 'TRUE' : 'FALSE') . "</li>";
            echo "<li>CSS-Klasse: '$streckeClass'</li>";
            echo "</ul>";
        }
        
        echo "</div>";
    } else {
        echo "<p style='color: red;'>Arno Adrians nicht gefunden!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Laden der Geräteträger: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. HTML-Ausgabe simulieren
echo "<h2>3. HTML-Ausgabe Simulation:</h2>";
echo "<p>So würde das HTML aussehen:</p>";

if ($traeger && $traeger['strecke_am']) {
    $streckeAm = new DateTime($traeger['strecke_am']);
    $streckeBis = clone $streckeAm;
    $streckeBis->add(new DateInterval('P1Y'));
    $diff = (int)$now->diff($streckeBis)->format('%r%a');
    $cls = '';
    if ($diff < 0) { 
        $cls = 'bis-expired'; 
    } elseif ($diff <= $warnDays && $diff >= 0) { 
        $cls = 'bis-warn'; 
    }
    
    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px;'>";
    echo "<p><strong>Strecke:</strong></p>";
    echo "<div><span class='bis-badge $cls'>" . $streckeBis->format('d.m.Y') . "</span></div>";
    echo "<p><strong>Debug:</strong> diff=$diff, warnDays=$warnDays, cls='$cls'</p>";
    echo "</div>";
    
    // CSS einbetten
    echo "<style>";
    echo ".bis-badge { padding: .25rem .5rem; border-radius: .375rem; display: inline-block; }";
    echo ".bis-warn { background-color: #fff3cd; color: #664d03; }";
    echo ".bis-expired { background-color: #dc3545; color: #fff; }";
    echo "</style>";
}
?>
