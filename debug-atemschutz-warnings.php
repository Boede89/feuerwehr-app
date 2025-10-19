<?php
/**
 * Debug-Datei für Atemschutz-Warnungen
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    die("Keine Berechtigung");
}

echo "<h1>🔍 Atemschutz-Warnungen Debug</h1>";

// 1. Warnschwelle aus Einstellungen laden
$warnDays = 90;
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false && is_numeric($val)) { 
        $warnDays = (int)$val; 
    }
} catch (Exception $e) { /* ignore */ }

echo "<h2>1. Einstellungen:</h2>";
echo "<p><strong>Warnschwelle:</strong> $warnDays Tage</p>";

// 2. Alle Geräteträger laden
echo "<h2>2. Alle Geräteträger:</h2>";

try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am
        FROM atemschutz_traeger 
        WHERE status = 'Aktiv'
        ORDER BY last_name, first_name
    ");
    $stmt->execute();
    $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Anzahl Geräteträger:</strong> " . count($traeger) . "</p>";
    
    foreach ($traeger as $t) {
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 15px; background: #f9f9f9;'>";
        echo "<h3>" . htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) . "</h3>";
        
        $now = new DateTime('today');
        
        // Strecke prüfen
        if ($t['strecke_am']) {
            $streckeAm = new DateTime($t['strecke_am']);
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
                $streckeClass = 'bis-ok';
            }
            
            echo "<p><strong>Strecke:</strong> ";
            echo "<span style='background: " . ($streckeClass === 'bis-expired' ? '#dc3545' : ($streckeClass === 'bis-warn' ? '#ffc107' : '#28a745')) . "; color: white; padding: 2px 8px; border-radius: 3px;'>";
            echo $streckeStatus . " (Diff: $streckeDiff Tage)";
            echo "</span>";
            echo " - Ablaufdatum: " . $streckeBis->format('d.m.Y');
            echo "</p>";
        }
        
        // G26.3 prüfen
        if ($t['g263_am']) {
            $g263Am = new DateTime($t['g263_am']);
            $birthdate = new DateTime($t['birthdate']);
            $age = $birthdate->diff(new DateTime())->y;
            
            $g263Bis = clone $g263Am;
            if ($age < 50) {
                $g263Bis->add(new DateInterval('P3Y'));
            } else {
                $g263Bis->add(new DateInterval('P1Y'));
            }
            
            $g263Diff = (int)$now->diff($g263Bis)->format('%r%a');
            
            $g263Status = '';
            $g263Class = '';
            if ($g263Diff < 0) {
                $g263Status = 'ABGELAUFEN';
                $g263Class = 'bis-expired';
            } elseif ($g263Diff <= $warnDays && $g263Diff >= 0) {
                $g263Status = 'WARNUNG';
                $g263Class = 'bis-warn';
            } else {
                $g263Status = 'TAUGLICH';
                $g263Class = 'bis-ok';
            }
            
            echo "<p><strong>G26.3:</strong> ";
            echo "<span style='background: " . ($g263Class === 'bis-expired' ? '#dc3545' : ($g263Class === 'bis-warn' ? '#ffc107' : '#28a745')) . "; color: white; padding: 2px 8px; border-radius: 3px;'>";
            echo $g263Status . " (Diff: $g263Diff Tage, Alter: $age)";
            echo "</span>";
            echo " - Ablaufdatum: " . $g263Bis->format('d.m.Y');
            echo "</p>";
        }
        
        // Übung prüfen
        if ($t['uebung_am']) {
            $uebungAm = new DateTime($t['uebung_am']);
            $uebungBis = clone $uebungAm;
            $uebungBis->add(new DateInterval('P1Y'));
            $uebungDiff = (int)$now->diff($uebungBis)->format('%r%a');
            
            $uebungStatus = '';
            $uebungClass = '';
            if ($uebungDiff < 0) {
                $uebungStatus = 'ABGELAUFEN';
                $uebungClass = 'bis-expired';
            } elseif ($uebungDiff <= $warnDays && $uebungDiff >= 0) {
                $uebungStatus = 'WARNUNG';
                $uebungClass = 'bis-warn';
            } else {
                $uebungStatus = 'TAUGLICH';
                $uebungClass = 'bis-ok';
            }
            
            echo "<p><strong>Übung:</strong> ";
            echo "<span style='background: " . ($uebungClass === 'bis-expired' ? '#dc3545' : ($uebungClass === 'bis-warn' ? '#ffc107' : '#28a745')) . "; color: white; padding: 2px 8px; border-radius: 3px;'>";
            echo $uebungStatus . " (Diff: $uebungDiff Tage)";
            echo "</span>";
            echo " - Ablaufdatum: " . $uebungBis->format('d.m.Y');
            echo "</p>";
        }
        
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Laden der Geräteträger: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. Test mit verschiedenen Warnschwellen
echo "<h2>3. Test mit verschiedenen Warnschwellen:</h2>";

$testWarnDays = [30, 90, 180, 365];
$testTraeger = $traeger[0] ?? null;

if ($testTraeger) {
    echo "<p><strong>Test mit Geräteträger:</strong> " . htmlspecialchars($testTraeger['first_name'] . ' ' . $testTraeger['last_name']) . "</p>";
    
    foreach ($testWarnDays as $testWarn) {
        echo "<h4>Warnschwelle: $testWarn Tage</h4>";
        
        if ($testTraeger['strecke_am']) {
            $streckeAm = new DateTime($testTraeger['strecke_am']);
            $streckeBis = clone $streckeAm;
            $streckeBis->add(new DateInterval('P1Y'));
            $streckeDiff = (int)$now->diff($streckeBis)->format('%r%a');
            
            $isWarning = ($streckeDiff <= $testWarn && $streckeDiff >= 0);
            $isExpired = ($streckeDiff < 0);
            
            echo "<p>Strecke: Diff=$streckeDiff, Warnung=$isWarning, Abgelaufen=$isExpired</p>";
        }
    }
}
?>
