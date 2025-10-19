<?php
/**
 * Debug-Datei für Atemschutz-Benachrichtigungen
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    die("Keine Berechtigung");
}

echo "<h1>🔍 Atemschutz-Benachrichtigungen Debug</h1>";

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

// 2. Auffällige Geräteträger finden
echo "<h2>2. Auffällige Geräteträger:</h2>";

try {
    $stmt = $db->prepare("
        SELECT 
            id, first_name, last_name, email,
            strecke_am, g263_am, uebung_am,
            CASE 
                WHEN strecke_am IS NULL THEN NULL
                ELSE DATEDIFF(strecke_am, CURDATE())
            END as strecke_diff,
            CASE 
                WHEN g263_am IS NULL THEN NULL
                ELSE DATEDIFF(g263_am, CURDATE())
            END as g263_diff,
            CASE 
                WHEN uebung_am IS NULL THEN NULL
                ELSE DATEDIFF(uebung_am, CURDATE())
            END as uebung_diff
        FROM atemschutz_traeger 
        WHERE status = 'Aktiv'
        ORDER BY last_name, first_name
    ");
    $stmt->execute();
    $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $auffaellige = [];
    
    foreach ($traeger as $t) {
        $certificates = [];
        
        // Strecke prüfen
        if ($t['strecke_diff'] !== null) {
            if ($t['strecke_diff'] < 0) {
                $certificates[] = [
                    'type' => 'strecke',
                    'name' => 'Strecke-Zertifikat',
                    'expiry_date' => $t['strecke_am'],
                    'urgency' => 'abgelaufen',
                    'days' => abs($t['strecke_diff'])
                ];
            } elseif ($t['strecke_diff'] <= $warnDays) {
                $certificates[] = [
                    'type' => 'strecke',
                    'name' => 'Strecke-Zertifikat',
                    'expiry_date' => $t['strecke_am'],
                    'urgency' => 'warnung',
                    'days' => $t['strecke_diff']
                ];
            }
        }
        
        // G26.3 prüfen
        if ($t['g263_diff'] !== null) {
            if ($t['g263_diff'] < 0) {
                $certificates[] = [
                    'type' => 'g263',
                    'name' => 'G26.3-Zertifikat',
                    'expiry_date' => $t['g263_am'],
                    'urgency' => 'abgelaufen',
                    'days' => abs($t['g263_diff'])
                ];
            } elseif ($t['g263_diff'] <= $warnDays) {
                $certificates[] = [
                    'type' => 'g263',
                    'name' => 'G26.3-Zertifikat',
                    'expiry_date' => $t['g263_am'],
                    'urgency' => 'warnung',
                    'days' => $t['g263_diff']
                ];
            }
        }
        
        // Übung prüfen
        if ($t['uebung_diff'] !== null) {
            if ($t['uebung_diff'] < 0) {
                $certificates[] = [
                    'type' => 'uebung',
                    'name' => 'Übung/Einsatz',
                    'expiry_date' => $t['uebung_am'],
                    'urgency' => 'abgelaufen',
                    'days' => abs($t['uebung_diff'])
                ];
            } elseif ($t['uebung_diff'] <= $warnDays) {
                $certificates[] = [
                    'type' => 'uebung',
                    'name' => 'Übung/Einsatz',
                    'expiry_date' => $t['uebung_am'],
                    'urgency' => 'warnung',
                    'days' => $t['uebung_diff']
                ];
            }
        }
        
        if (!empty($certificates)) {
            $auffaellige[] = [
                'traeger' => $t,
                'certificates' => $certificates
            ];
        }
    }
    
    echo "<p><strong>Anzahl auffällige Geräteträger:</strong> " . count($auffaellige) . "</p>";
    
    foreach ($auffaellige as $item) {
        $t = $item['traeger'];
        $certs = $item['certificates'];
        
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 15px; background: #f9f9f9;'>";
        echo "<h3>" . htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) . " (" . htmlspecialchars($t['email']) . ")</h3>";
        
        if (count($certs) === 1) {
            echo "<p><strong>Ein Problem:</strong></p>";
        } else {
            echo "<p><strong>Mehrere Probleme (" . count($certs) . " Zertifikate):</strong></p>";
        }
        
        echo "<ul>";
        foreach ($certs as $cert) {
            $status = $cert['urgency'] === 'abgelaufen' ? 'ABGELAUFEN' : 'Läuft bald ab';
            $color = $cert['urgency'] === 'abgelaufen' ? '#dc3545' : '#ffc107';
            
            echo "<li>";
            echo "<strong>" . htmlspecialchars($cert['name']) . "</strong> - ";
            echo "<span style='color: $color; font-weight: bold;'>$status</span>";
            echo " (Ablaufdatum: " . date('d.m.Y', strtotime($cert['expiry_date'])) . ")";
            
            if ($cert['urgency'] === 'abgelaufen') {
                echo " - <strong>Seit " . $cert['days'] . " Tag" . ($cert['days'] !== 1 ? 'en' : '') . " abgelaufen!</strong>";
            } else {
                echo " - <strong>Noch " . $cert['days'] . " Tag" . ($cert['days'] !== 1 ? 'e' : '') . " gültig</strong>";
            }
            
            echo "</li>";
        }
        echo "</ul>";
        
        // E-Mail-Vorlagen für diesen Geräteträger
        echo "<h4>Verfügbare E-Mail-Vorlagen:</h4>";
        $templates = [];
        foreach ($certs as $cert) {
            $templateKey = $cert['type'] . '_' . $cert['urgency'];
            $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_key = ?");
            $stmt->execute([$templateKey]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                $templates[] = $template;
                echo "<p><strong>" . htmlspecialchars($template['template_name']) . ":</strong> " . htmlspecialchars($template['subject']) . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Keine Vorlage gefunden für: $templateKey</p>";
            }
        }
        
        // Kombinierte E-Mail simulieren
        if (count($templates) > 1) {
            echo "<h4>Kombinierte E-Mail würde lauten:</h4>";
            $hasExpired = in_array('abgelaufen', array_column($certs, 'urgency'));
            $subjectPrefix = $hasExpired ? 'ACHTUNG: Mehrere Zertifikate sind abgelaufen' : 'Erinnerung: Mehrere Zertifikate laufen bald ab';
            
            echo "<p><strong>Betreff:</strong> $subjectPrefix</p>";
            echo "<div style='background: white; padding: 10px; border: 1px solid #ddd; margin: 10px 0;'>";
            echo createCombinedAtemschutzEmail($t, $certs, $hasExpired);
            echo "</div>";
        }
        
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Laden der Geräteträger: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. E-Mail-Vorlagen prüfen
echo "<h2>3. Verfügbare E-Mail-Vorlagen:</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM email_templates ORDER BY template_key");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Key</th><th>Name</th><th>Betreff</th><th>Status</th></tr>";
    
    foreach ($templates as $template) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($template['template_key']) . "</td>";
        echo "<td>" . htmlspecialchars($template['template_name']) . "</td>";
        echo "<td>" . htmlspecialchars($template['subject']) . "</td>";
        echo "<td>✅ Verfügbar</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Laden der Vorlagen: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 4. Test-Benachrichtigung senden
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_notification'])) {
    echo "<h2>4. Test-Benachrichtigung senden:</h2>";
    
    $testEmail = $_POST['test_email'] ?? '';
    if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        // Simuliere eine kombinierte E-Mail
        $testTraeger = [
            'first_name' => 'Test',
            'last_name' => 'Benutzer',
            'email' => $testEmail
        ];
        
        $testCertificates = [
            [
                'type' => 'strecke',
                'name' => 'Strecke-Zertifikat',
                'expiry_date' => date('Y-m-d', strtotime('-5 days')),
                'urgency' => 'abgelaufen',
                'days' => 5
            ],
            [
                'type' => 'g263',
                'name' => 'G26.3-Zertifikat',
                'expiry_date' => date('Y-m-d', strtotime('+10 days')),
                'urgency' => 'warnung',
                'days' => 10
            ]
        ];
        
        $subject = 'ACHTUNG: Mehrere Zertifikate sind abgelaufen';
        $body = createCombinedAtemschutzEmail($testTraeger, $testCertificates, true);
        
        try {
            require_once 'includes/functions.php';
            send_email($testEmail, $subject, $body);
            echo "<p style='color: green;'>✅ Test-E-Mail erfolgreich gesendet an $testEmail</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Senden: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Ungültige E-Mail-Adresse</p>";
    }
}

// Test-Formular
echo "<h2>4. Test-Benachrichtigung senden:</h2>";
echo "<form method='post'>";
echo "<p>E-Mail-Adresse: <input type='email' name='test_email' placeholder='test@example.com' required></p>";
echo "<button type='submit' name='test_notification' style='background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>Test-E-Mail senden</button>";
echo "</form>";

/**
 * Erstellt eine kombinierte E-Mail für mehrere abgelaufene/bald ablaufende Zertifikate
 */
function createCombinedAtemschutzEmail($traeger, $certificates, $hasExpired) {
    $name = $traeger['first_name'] . ' ' . $traeger['last_name'];
    
    $html = '<h2>Atemschutz-Hinweis</h2>';
    $html .= '<p>Hallo ' . htmlspecialchars($name) . ',</p>';
    
    if ($hasExpired) {
        $html .= '<p><strong style="color: #dc3545;">ACHTUNG: Mehrere Ihrer Atemschutz-Zertifikate sind abgelaufen!</strong></p>';
    } else {
        $html .= '<p><strong style="color: #ffc107;">Erinnerung: Mehrere Ihrer Atemschutz-Zertifikate laufen bald ab!</strong></p>';
    }
    
    $html .= '<p>Folgende Zertifikate benötigen Ihre Aufmerksamkeit:</p>';
    $html .= '<ul>';
    
    foreach ($certificates as $cert) {
        $status = $cert['urgency'] === 'abgelaufen' ? 'ABGELAUFEN' : 'Läuft bald ab';
        $color = $cert['urgency'] === 'abgelaufen' ? '#dc3545' : '#ffc107';
        $days = $cert['days'];
        
        $html .= '<li>';
        $html .= '<strong>' . htmlspecialchars($cert['name']) . '</strong> - ';
        $html .= '<span style="color: ' . $color . '; font-weight: bold;">' . $status . '</span>';
        $html .= ' (Ablaufdatum: ' . date('d.m.Y', strtotime($cert['expiry_date'])) . ')';
        
        if ($cert['urgency'] === 'abgelaufen') {
            $html .= ' - <strong>Seit ' . $days . ' Tag' . ($days !== 1 ? 'en' : '') . ' abgelaufen!</strong>';
        } else {
            $html .= ' - <strong>Noch ' . $days . ' Tag' . ($days !== 1 ? 'e' : '') . ' gültig</strong>';
        }
        
        $html .= '</li>';
    }
    
    $html .= '</ul>';
    
    if ($hasExpired) {
        $html .= '<p><strong style="color: #dc3545;">WICHTIG: Sie dürfen bis zur Verlängerung nicht am Atemschutz teilnehmen!</strong></p>';
        $html .= '<p>Bitte vereinbaren Sie <strong>SOFORT</strong> einen Termin für die Verlängerung aller abgelaufenen Zertifikate.</p>';
    } else {
        $html .= '<p>Bitte vereinbaren Sie rechtzeitig einen Termin für die Verlängerung der bald ablaufenden Zertifikate.</p>';
    }
    
    $html .= '<p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>';
    
    return $html;
}
?>
