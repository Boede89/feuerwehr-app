<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

header('Content-Type: application/json');

try {
    // Prüfe ob Benutzer eingeloggt ist (optional für Atemschutzeinträge)
    $user_id = null;
    $user_name = 'Unbekannt';
    
    if (is_logged_in()) {
        $user_id = $_SESSION['user_id'];
        $user_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
    }
    
    // Stelle sicher, dass die Tabellen existieren
    $db->exec("
        CREATE TABLE IF NOT EXISTS atemschutz_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_type ENUM('einsatz', 'uebung', 'atemschutzstrecke', 'g263') NOT NULL,
            entry_date DATE NOT NULL,
            requester_id INT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            rejection_reason TEXT NULL,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Stelle sicher, dass die ENUM-Werte korrekt sind
    $db->exec("ALTER TABLE atemschutz_entries MODIFY COLUMN entry_type ENUM('einsatz', 'uebung', 'atemschutzstrecke', 'g263') NOT NULL");
    
    // Stelle sicher, dass requester_id NULL sein kann
    try {
        $db->exec("ALTER TABLE atemschutz_entries MODIFY COLUMN requester_id INT NULL");
        error_log("Create Atemschutz Entry - requester_id auf NULL geändert");
    } catch (Exception $e) {
        error_log("Create Atemschutz Entry - requester_id Änderung: " . $e->getMessage());
    }
    
    // Entferne die reason Spalte falls sie existiert
    try {
        $db->exec("ALTER TABLE atemschutz_entries DROP COLUMN reason");
        error_log("Create Atemschutz Entry - reason Spalte entfernt");
    } catch (Exception $e) {
        // Spalte existiert nicht, das ist OK
        error_log("Create Atemschutz Entry - reason Spalte existiert nicht: " . $e->getMessage());
    }
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS atemschutz_entry_traeger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT NOT NULL,
            traeger_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (entry_id) REFERENCES atemschutz_entries(id) ON DELETE CASCADE,
            FOREIGN KEY (traeger_id) REFERENCES atemschutz_traeger(id) ON DELETE CASCADE,
            UNIQUE KEY unique_entry_traeger (entry_id, traeger_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Nur POST-Requests erlaubt');
    }
    
    if (is_logged_in() && !has_permission_write('atemschutz') && !hasAdminPermission()) {
        echo json_encode(['success' => false, 'message' => 'Sie haben keine Schreibrechte für Atemschutz.']);
        exit;
    }
    
    // Formulardaten validieren
    $entry_type = $_POST['entry_type'] ?? '';
    $entry_date = $_POST['entry_date'] ?? '';
    $traeger_ids = $_POST['traeger'] ?? [];
    
    // Debug-Logs
    error_log("Create Atemschutz Entry - Entry Type: '" . $entry_type . "' (Length: " . strlen($entry_type) . ")");
    error_log("Create Atemschutz Entry - Entry Date: " . $entry_date);
    error_log("Create Atemschutz Entry - Traeger IDs: " . json_encode($traeger_ids));
    error_log("Create Atemschutz Entry - User ID: " . ($_SESSION['user_id'] ?? 'nicht gesetzt'));
    error_log("Create Atemschutz Entry - POST Data: " . json_encode($_POST));
    
    if (empty($entry_type) || empty($entry_date) || empty($traeger_ids)) {
        throw new Exception('Alle Felder müssen ausgefüllt werden');
    }
    
    if (!in_array($entry_type, ['einsatz', 'uebung', 'atemschutzstrecke', 'g263'])) {
        throw new Exception('Ungültiger Eintragstyp');
    }
    
    // Datum validieren
    $date_obj = DateTime::createFromFormat('Y-m-d', $entry_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $entry_date) {
        throw new Exception('Ungültiges Datum');
    }
    
    // Geräteträger-IDs validieren
    $traeger_ids = array_map('intval', $traeger_ids);
    $traeger_ids = array_filter($traeger_ids, function($id) { return $id > 0; });
    
    if (empty($traeger_ids)) {
        throw new Exception('Mindestens ein Geräteträger muss ausgewählt werden');
    }
    
    $einheit_id = isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0;
    if ($einheit_id <= 0) $einheit_id = function_exists('get_current_einheit_id') ? get_current_einheit_id() : null;
    if (!$einheit_id) $einheit_id = function_exists('get_current_unit_id') ? get_current_unit_id() : null;
    $einheit_id = $einheit_id ?: 1;

    // Prüfe ob alle Geräteträger existieren und zur Einheit gehören (über members.einheit_id)
    $placeholders = str_repeat('?,', count($traeger_ids) - 1) . '?';
    $stmt = $db->prepare("
        SELECT at.id FROM atemschutz_traeger at
        LEFT JOIN members m ON at.member_id = m.id
        WHERE at.id IN ($placeholders) AND at.status = 'Aktiv'
        AND (
            (at.member_id IS NOT NULL AND (m.einheit_id = ? OR m.einheit_id IS NULL))
            OR (at.member_id IS NULL AND EXISTS (
                SELECT 1 FROM members m2 WHERE m2.first_name = at.first_name AND m2.last_name = at.last_name
                AND (m2.einheit_id = ? OR m2.einheit_id IS NULL)
            ))
        )
    ");
    $stmt->execute(array_merge($traeger_ids, [$einheit_id, $einheit_id]));
    $existing_traeger = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($existing_traeger) !== count($traeger_ids)) {
        throw new Exception('Ein oder mehrere ausgewählte Geräteträger existieren nicht');
    }
    
    $db->beginTransaction();
    
    try {
        try {
            $db->exec("ALTER TABLE atemschutz_entries ADD COLUMN einheit_id INT NULL");
        } catch (Exception $e) {}
        try {
            $stmt = $db->prepare("
                INSERT INTO atemschutz_entries 
                (entry_type, entry_date, requester_id, status, einheit_id, created_at) 
                VALUES (?, ?, ?, 'pending', ?, NOW())
            ");
            $stmt->execute([$entry_type, $entry_date, $user_id, $einheit_id]);
        } catch (Exception $e) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO atemschutz_entries 
                    (entry_type, entry_date, requester_id, status, unit_id, created_at) 
                    VALUES (?, ?, ?, 'pending', ?, NOW())
                ");
                $stmt->execute([$entry_type, $entry_date, $user_id, $einheit_id]);
            } catch (Exception $e2) {
                $stmt = $db->prepare("
                    INSERT INTO atemschutz_entries 
                    (entry_type, entry_date, requester_id, status, created_at) 
                    VALUES (?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([$entry_type, $entry_date, $user_id]);
            }
        }
        
        $entry_id = $db->lastInsertId();
        
        // Verknüpfe Geräteträger mit dem Eintrag
        $stmt = $db->prepare("
            INSERT INTO atemschutz_entry_traeger 
            (entry_id, traeger_id) 
            VALUES (?, ?)
        ");
        
        foreach ($traeger_ids as $traeger_id) {
            $stmt->execute([$entry_id, $traeger_id]);
        }
        
        // Sende E-Mail-Benachrichtigung an Atemschutz-Admins
        $stmt = $db->prepare("SELECT email, first_name, last_name FROM users WHERE atemschutz_notifications = 1 AND is_active = 1");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($admins)) {
            $entry_type_names = [
                'einsatz' => 'Einsatz',
                'uebung' => 'Übung',
                'atemschutzstrecke' => 'Atemschutzstrecke',
                'g263' => 'G26.3'
            ];
            
            $subject = "🔔 Neuer Atemschutzeintrag-Antrag - " . $entry_type_names[$entry_type];
            $message = createAtemschutzEntryEmailHTML($entry_type, $entry_date, $user_name, $traeger_ids);
            
            foreach ($admins as $admin) {
                send_email($admin['email'], $subject, $message, '', true);
            }
        }
        
        // Logge Aktivität (nur wenn eingeloggt)
        if ($user_id) {
            log_activity($user_id, 'atemschutz_entry_created', "Atemschutzeintrag-Antrag #$entry_id erstellt ($entry_type)");
        }
        
        $db->commit();
        
        error_log("Create Atemschutz Entry - Erfolgreich erstellt: Entry ID " . $entry_id);
        
        echo json_encode([
            'success' => true,
            'message' => 'Atemschutzeintrag erfolgreich erstellt',
            'entry_id' => $entry_id
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Create Atemschutz Entry Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// HTML-E-Mail für Atemschutzeintrag-Antrag erstellen
function createAtemschutzEntryEmailHTML($entry_type, $entry_date, $requester_name, $traeger_ids) {
    global $db;
    
    $entry_type_names = [
        'einsatz' => 'Einsatz',
        'uebung' => 'Übung',
        'atemschutzstrecke' => 'Atemschutzstrecke',
        'g263' => 'G26.3'
    ];
    
    $type_name = $entry_type_names[$entry_type];
    $formatted_date = date('d.m.Y', strtotime($entry_date));
    
    // Lade Geräteträger-Namen
    $placeholders = str_repeat('?,', count($traeger_ids) - 1) . '?';
    $stmt = $db->prepare("
        SELECT first_name, last_name 
        FROM atemschutz_traeger 
        WHERE id IN ($placeholders)
        ORDER BY last_name, first_name
    ");
    $stmt->execute($traeger_ids);
    $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $traeger_names = array_map(function($t) {
        return $t['first_name'] . ' ' . $t['last_name'];
    }, $traeger);
    
    // Basis-URL für Links in E-Mails: bevorzugt aus Einstellungen 'app_url'
    try {
        $stmtApp = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_url'");
        $stmtApp->execute();
        $appUrl = $stmtApp->fetchColumn();
        if (!$appUrl) {
            $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        }
    } catch (Exception $e) {
        $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    
    $dashboardUrl = $appUrl . '/admin/dashboard.php';
    
    return '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Neuer Atemschutzeintrag-Antrag</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #17a2b8, #138496); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .header .icon { font-size: 48px; margin-bottom: 10px; }
            .content { padding: 30px; }
            .info-badge { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: bold; }
            .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 12px; align-items: center; }
            .detail-label { font-weight: bold; color: #495057; width: 120px; flex-shrink: 0; }
            .detail-value { color: #212529; }
            .traeger-list { background: #e9ecef; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .traeger-list ul { margin: 0; padding-left: 20px; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; }
            .highlight { color: #17a2b8; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="icon">🎭</div>
                <h1>Neuer Atemschutzeintrag-Antrag</h1>
            </div>
            <div class="content">
                <div class="info-badge">
                    📋 Ein neuer Atemschutzeintrag wartet auf Ihre Genehmigung
                </div>
                
                <p>Hallo,</p>
                
                <p>es wurde ein neuer Atemschutzeintrag-Antrag eingereicht, der Ihre Genehmigung benötigt.</p>
                
                <div class="details">
                    <h3 style="margin-top: 0; color: #495057;">📋 Antragsdetails</h3>
                    <div class="detail-row">
                        <div class="detail-label">🎭 Typ:</div>
                        <div class="detail-value highlight">' . $type_name . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📅 Datum:</div>
                        <div class="detail-value">' . $formatted_date . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">👤 Antragsteller:</div>
                        <div class="detail-value">' . htmlspecialchars($requester_name) . '</div>
                    </div>
                </div>
                
                <div class="traeger-list">
                    <h4 style="margin-top: 0; color: #495057;">👥 Beteiligte Geräteträger</h4>
                    <ul>
                        ' . implode('', array_map(function($name) {
                            return '<li><strong>' . htmlspecialchars($name) . '</strong></li>';
                        }, $traeger_names)) . '
                    </ul>
                </div>
                
                <div style="text-align: center; margin: 25px 0;">
                    <a href="' . $dashboardUrl . '" 
                       style="background-color: #17a2b8; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">
                        🎭 Antrag bearbeiten
                    </a>
                </div>
                
                <p>Bitte loggen Sie sich in das Dashboard ein, um den Antrag zu genehmigen oder abzulehnen.</p>
            </div>
            <div class="footer">
                <p><strong>Mit freundlichen Grüßen</strong><br>Ihre Feuerwehr App</p>
            </div>
        </div>
    </body>
    </html>';
}
?>
