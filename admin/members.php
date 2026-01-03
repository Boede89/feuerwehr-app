<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Mitgliederverwaltungs-Berechtigung hat
if (!has_permission('members')) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Erfolgsmeldung anzeigen
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'edited':
            $message = "Mitglied wurde erfolgreich bearbeitet.";
            break;
        case 'deleted':
            $message = "Mitglied wurde erfolgreich gelöscht.";
            break;
        case 'toggle':
            $message = "PA-Träger Status wurde aktualisiert.";
            break;
        case 'added':
            $message = "Mitglied wurde erfolgreich hinzugefügt.";
            break;
    }
}

// Mitglieder laden
$members = [];
try {
    // Tabelle sicherstellen mit user_id Verknüpfung
    $db->exec(
        "CREATE TABLE IF NOT EXISTS members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NULL,
            birthdate DATE NULL,
            phone VARCHAR(50) NULL,
            is_pa_traeger TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );
    
    // is_pa_traeger Spalte hinzufügen falls nicht vorhanden
    try {
        $db->exec("ALTER TABLE members ADD COLUMN is_pa_traeger TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    
    // user_id Spalte hinzufügen falls nicht vorhanden
    try {
        $db->exec("ALTER TABLE members ADD COLUMN user_id INT NULL");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    
    // Unique Key hinzufügen falls nicht vorhanden
    try {
        $db->exec("ALTER TABLE members ADD UNIQUE KEY unique_user_id (user_id)");
    } catch (Exception $e) {
        // Key existiert bereits, ignoriere Fehler
    }
    
    // Foreign Key hinzufügen falls nicht vorhanden
    // WICHTIG: ON DELETE SET NULL statt CASCADE, damit Mitglieder nicht gelöscht werden wenn Benutzer gelöscht wird
    try {
        // Prüfe ob Foreign Key bereits existiert
        $stmt = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'");
        $existing_fk = $stmt->fetch();
        
        if ($existing_fk) {
            // Foreign Key existiert bereits, versuche ihn zu löschen und mit SET NULL neu zu erstellen
            try {
                $constraint_name = $existing_fk['CONSTRAINT_NAME'];
                $db->exec("ALTER TABLE members DROP FOREIGN KEY " . $constraint_name);
            } catch (Exception $e) {
                // Fehler beim Löschen, ignoriere
            }
        }
        
        // Erstelle Foreign Key mit SET NULL
        $db->exec("ALTER TABLE members ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // Foreign Key existiert bereits oder Fehler, ignoriere
    }
    
    // Bestehende Benutzer synchronisieren (erstelle Mitglieder für Benutzer die noch kein Mitglied haben)
    try {
        $stmt = $db->query("
            INSERT INTO members (user_id, first_name, last_name, email)
            SELECT u.id, u.first_name, u.last_name, u.email
            FROM users u
            WHERE u.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM members m WHERE m.user_id = u.id)
        ");
    } catch (Exception $e) {
        // Fehler ignorieren
    }
    
    // Sicherstellen, dass is_pa_traeger Spalte existiert
    try {
        $db->exec("ALTER TABLE members ADD COLUMN is_pa_traeger TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits
    }
    
    // Stelle sicher, dass member_id Spalte in atemschutz_traeger existiert
    try {
        $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
    } catch (Exception $e) {
        // Spalte existiert bereits
    }
    
    // Synchronisiere: Alle Geräteträger sollten is_pa_traeger = 1 haben
    // ABER: Nur wenn is_pa_traeger NULL ist (nicht wenn es explizit auf 0 gesetzt wurde)
    try {
        // Setze is_pa_traeger auf 1 für alle Mitglieder, die einen Geräteträger haben UND is_pa_traeger ist NULL
        // NICHT wenn is_pa_traeger explizit auf 0 gesetzt wurde
        $db->exec("
            UPDATE members m
            INNER JOIN atemschutz_traeger at ON m.id = at.member_id
            SET m.is_pa_traeger = 1
            WHERE m.is_pa_traeger IS NULL
        ");
        
        // Für Geräteträger ohne member_id: Versuche über Name zu verknüpfen und is_pa_traeger zu setzen
        $stmt = $db->query("
            SELECT at.id as traeger_id, at.first_name, at.last_name, at.email, at.birthdate
            FROM atemschutz_traeger at
            WHERE at.member_id IS NULL
        ");
        $traeger_ohne_member = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($traeger_ohne_member as $traeger) {
            // Suche nach passendem Mitglied
            $stmt = $db->prepare("
                SELECT id FROM members 
                WHERE first_name = ? AND last_name = ?
                LIMIT 1
            ");
            $stmt->execute([$traeger['first_name'], $traeger['last_name']]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($member) {
                // Verknüpfe Geräteträger mit Mitglied
                $stmt = $db->prepare("UPDATE atemschutz_traeger SET member_id = ? WHERE id = ?");
                $stmt->execute([$member['id'], $traeger['traeger_id']]);
                
                // Setze is_pa_traeger auf 1
                $stmt = $db->prepare("UPDATE members SET is_pa_traeger = 1 WHERE id = ?");
                $stmt->execute([$member['id']]);
            }
        }
    } catch (Exception $e) {
        // Fehler ignorieren
        error_log("Fehler bei PA-Träger Synchronisation: " . $e->getMessage());
    }
    
    // Stelle sicher, dass alle Benutzer ein Mitglied haben
    try {
        $stmt = $db->query("
            INSERT INTO members (user_id, first_name, last_name, email, is_pa_traeger)
            SELECT u.id, u.first_name, u.last_name, u.email, 0
            FROM users u
            WHERE u.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM members m WHERE m.user_id = u.id)
        ");
    } catch (Exception $e) {
        // Fehler ignorieren
    }
    
    // Alle Mitglieder laden: Benutzer aus users + zusätzliche Mitglieder aus members
    // Zuerst alle Benutzer als Mitglieder (mit is_pa_traeger aus members, prüfe auch ob Geräteträger existiert)
    $stmt = $db->query("
        SELECT 
            u.id as user_id,
            u.first_name,
            u.last_name,
            u.email,
            m.birthdate,
            m.phone,
            CASE 
                WHEN EXISTS (SELECT 1 FROM atemschutz_traeger at2 WHERE at2.member_id = m.id) THEN 1
                ELSE COALESCE(m.is_pa_traeger, 0)
            END as is_pa_traeger,
            m.id as member_id,
            u.created_at,
            at.strecke_am,
            at.g263_am,
            at.uebung_am,
            'user' as source
        FROM users u
        INNER JOIN members m ON m.user_id = u.id
        LEFT JOIN atemschutz_traeger at ON at.member_id = m.id
        WHERE u.is_active = 1
        ORDER BY u.last_name, u.first_name
    ");
    $user_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Dann zusätzliche Mitglieder (ohne user_id Verknüpfung, prüfe auch ob Geräteträger existiert)
    $stmt = $db->query("
        SELECT 
            NULL as user_id,
            m.first_name,
            m.last_name,
            m.email,
            m.birthdate,
            m.phone,
            CASE 
                WHEN EXISTS (SELECT 1 FROM atemschutz_traeger at2 WHERE at2.member_id = m.id) THEN 1
                ELSE COALESCE(m.is_pa_traeger, 0)
            END as is_pa_traeger,
            m.id as member_id,
            m.created_at,
            at.strecke_am,
            at.g263_am,
            at.uebung_am,
            'member' as source
        FROM members m
        LEFT JOIN atemschutz_traeger at ON at.member_id = m.id
        WHERE m.user_id IS NULL
        ORDER BY m.last_name, m.first_name
    ");
    $additional_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Kombiniere beide Listen
    $members = array_merge($user_members, $additional_members);
    
    // Sortiere nach Nachname, dann Vorname
    usort($members, function($a, $b) {
        $cmp = strcmp($a['last_name'], $b['last_name']);
        if ($cmp === 0) {
            return strcmp($a['first_name'], $b['first_name']);
        }
        return $cmp;
    });
    
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Mitglieder: ' . $e->getMessage();
}

// Prüfe ob aktueller Benutzer Admin ist
$is_admin = hasAdminPermission();

// Prüfe RIC-Berechtigung
$can_ric = has_permission('ric');

// Mitglied hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_member') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $create_user = isset($_POST['create_user']) && $_POST['create_user'] == '1';
        $is_pa_traeger = isset($_POST['is_pa_traeger']) ? 1 : 0;
        $strecke_am = trim($_POST['strecke_am'] ?? '');
        $g263_am = trim($_POST['g263_am'] ?? '');
        $uebung_am = trim($_POST['uebung_am'] ?? '');
        
        if (empty($first_name) || empty($last_name)) {
            $error = 'Bitte geben Sie Vorname und Nachname ein.';
        } elseif ($is_pa_traeger == 1 && (empty($birthdate) || empty($strecke_am) || empty($g263_am) || empty($uebung_am))) {
            $error = 'Bitte füllen Sie alle Pflichtfelder für PA-Träger aus (Geburtsdatum, Strecke Am, G26.3 Am, Übung/Einsatz Am).';
        } else {
            // Sicherstellen, dass is_pa_traeger Spalte existiert (vor Transaktion)
            try {
                $db->exec("ALTER TABLE members ADD COLUMN is_pa_traeger TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {
                // Spalte existiert bereits, ignoriere Fehler
            }
            
            try {
                $db->beginTransaction();
                
                $user_id = null;
                
                // Wenn "Login erlaubt" aktiviert ist und Admin, dann Benutzer erstellen
                if ($create_user && $is_admin) {
                    if (empty($email)) {
                        $error = 'Für die Erstellung eines Benutzerkontos ist eine E-Mail-Adresse erforderlich.';
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                    } else {
                        // Prüfe ob E-Mail bereits verwendet wird
                        $stmt_check = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt_check->execute([$email]);
                        if ($stmt_check->fetch()) {
                            $error = 'Diese E-Mail-Adresse wird bereits von einem anderen Benutzer verwendet.';
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                        } else {
                            // Generiere Benutzername
                            // 1. Versuch: Nur Vorname
                            $username = strtolower($first_name);
                            $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ?");
                            $stmt_check->execute([$username]);
                            
                            // 2. Versuch: Vorname + "." + erster Buchstabe des Nachnamens
                            if ($stmt_check->fetch()) {
                                $first_letter_lastname = !empty($last_name) ? strtolower(substr($last_name, 0, 1)) : '';
                                $username = strtolower($first_name) . '.' . $first_letter_lastname;
                                $stmt_check->execute([$username]);
                                
                                // 3. Versuch: Mit Nummern falls auch dieser existiert
                                if ($stmt_check->fetch()) {
                                    $base_username = $username;
                                    $counter = 1;
                                    while (true) {
                                        $username = $base_username . $counter;
                                        $stmt_check->execute([$username]);
                                        if (!$stmt_check->fetch()) {
                                            break; // Benutzername ist verfügbar
                                        }
                                        $counter++;
                                    }
                                }
                            }
                            
                            // Generiere Standard-Passwort (4 zufällige Zahlen)
                            $default_password = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                            $password_hash = hash_password($default_password);
                            
                            // Benutzer erstellen
                            $stmt_user = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles, email_notifications) VALUES (?, ?, ?, ?, ?, 'user', 1, 0, 0, 0, 0, 0, 0, 0)");
                            $stmt_user->execute([$username, $email, $password_hash, $first_name, $last_name]);
                            $user_id = $db->lastInsertId();
                            
                            // Passwort in Session speichern für Anzeige (nur einmal)
                            $_SESSION['new_user_password_' . $user_id] = $default_password;
                            
                            // E-Mail mit Benutzername und Passwort senden
                            if (!empty($email)) {
                                $email_subject = 'Ihr Benutzerkonto wurde erstellt';
                                $email_body = '
                                <html>
                                <head>
                                    <meta charset="UTF-8">
                                    <style>
                                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                        .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                                        .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                                        .credentials { background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #dc3545; }
                                        .credentials strong { color: #dc3545; }
                                        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                                    </style>
                                </head>
                                <body>
                                    <div class="container">
                                        <div class="header">
                                            <h1>Willkommen bei der Feuerwehr-App</h1>
                                        </div>
                                        <div class="content">
                                            <p>Hallo ' . htmlspecialchars($first_name) . ',</p>
                                            <p>Ihr Benutzerkonto wurde erfolgreich erstellt. Sie können sich nun mit folgenden Zugangsdaten anmelden:</p>
                                            <div class="credentials">
                                                <p><strong>Benutzername:</strong> ' . htmlspecialchars($username) . '</p>
                                                <p><strong>Passwort:</strong> ' . htmlspecialchars($default_password) . '</p>
                                            </div>
                                            <p style="text-align: center; margin: 30px 0;">
                                                <a href="https://feuerwehr.boede89.selfhost.co/" style="display: inline-block; background-color: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Zur Startseite</a>
                                            </p>
                                            <p>Bitte ändern Sie Ihr Passwort nach dem ersten Login für mehr Sicherheit.</p>
                                            <p>Bei Fragen wenden Sie sich bitte an den Administrator.</p>
                                        </div>
                                        <div class="footer">
                                            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
                                        </div>
                                    </div>
                                </body>
                                </html>';
                                
                                if (send_email($email, $email_subject, $email_body, '', true)) {
                                    error_log("Willkommens-E-Mail erfolgreich gesendet an: $email");
                                } else {
                                    error_log("Fehler beim Senden der Willkommens-E-Mail an: $email");
                                }
                            }
                        }
                    }
                }
                
                if (empty($error)) {
                    // Mitglied erstellen
                    $is_pa_traeger = isset($_POST['is_pa_traeger']) ? 1 : 0;
                    
                    $stmt = $db->prepare("INSERT INTO members (user_id, first_name, last_name, email, birthdate, phone, is_pa_traeger) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $user_id,
                        $first_name,
                        $last_name,
                        !empty($email) ? $email : null,
                        !empty($birthdate) ? $birthdate : null,
                        !empty($phone) ? $phone : null,
                        $is_pa_traeger
                    ]);
                    
                    $new_member_id = $db->lastInsertId();
                    
                    // Wenn PA-Träger aktiviert, erstelle automatisch Geräteträger
                    if ($is_pa_traeger == 1) {
                        $birthdate_value = !empty($birthdate) ? $birthdate : date('Y-m-d');
                        $stmt = $db->prepare("
                            INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status, member_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv', ?)
                        ");
                        $stmt->execute([
                            $first_name,
                            $last_name,
                            !empty($email) ? $email : null,
                            $birthdate_value,
                            $strecke_am,
                            $g263_am,
                            $uebung_am,
                            $new_member_id
                        ]);
                    }
                    
                    // Nur committen wenn Transaktion noch aktiv ist
                    if ($db->inTransaction()) {
                        $db->commit();
                    }
                    
                    if ($create_user && $is_admin && $user_id) {
                        $message = 'Mitglied wurde erfolgreich hinzugefügt und Benutzerkonto wurde erstellt. Benutzername: ' . htmlspecialchars($username) . ', Passwort: ' . htmlspecialchars($default_password);
                    } else {
                        $message = 'Mitglied wurde erfolgreich hinzugefügt.';
                    }
                    
                    // Weiterleitung um POST-Problem zu vermeiden
                    header("Location: members.php?show_list=1&success=added");
                    exit();
                } else {
                    // Wenn Fehler aufgetreten ist, Transaktion beenden
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Fehler beim Speichern: ' . $e->getMessage();
                error_log("Fehler beim Hinzufügen von Mitglied: " . $e->getMessage());
            }
        }
    }
}

// Mitglied bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_member') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $is_pa_traeger = isset($_POST['is_pa_traeger']) ? 1 : 0;
        $create_user = isset($_POST['create_user']) && $_POST['create_user'] == '1';
        $strecke_am = trim($_POST['strecke_am'] ?? '');
        $g263_am = trim($_POST['g263_am'] ?? '');
        $uebung_am = trim($_POST['uebung_am'] ?? '');
        
        if (empty($first_name) || empty($last_name)) {
            $error = 'Bitte geben Sie Vorname und Nachname ein.';
        } elseif ($member_id <= 0) {
            $error = 'Ungültige Mitglieds-ID.';
        } elseif ($is_pa_traeger == 1 && (empty($birthdate) || empty($strecke_am) || empty($g263_am) || empty($uebung_am))) {
            $error = 'Bitte füllen Sie alle Pflichtfelder für PA-Träger aus (Geburtsdatum, Strecke Am, G26.3 Am, Übung/Einsatz Am).';
        } else {
            try {
                $db->beginTransaction();
                
                // Lade Mitglied
                $stmt = $db->prepare("SELECT user_id FROM members WHERE id = ?");
                $stmt->execute([$member_id]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$member) {
                    $error = 'Mitglied nicht gefunden.';
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } else {
                    $old_user_id = $member['user_id'];
                    $user_deleted = false;
                    $user_created = false;
                    $new_user_id = null;
                    $default_password = null;
                    $username = null;
                    
                    // Prüfe ob Benutzerkonto gelöscht werden soll
                    if (!empty($old_user_id) && !$create_user && $is_admin) {
                        // WICHTIG: Zuerst user_id im Mitglied auf NULL setzen, DANN den Benutzer löschen
                        // Dies verhindert, dass der Foreign Key CASCADE das Mitglied löscht
                        $stmt = $db->prepare("UPDATE members SET user_id = NULL WHERE id = ?");
                        $stmt->execute([$member_id]);
                        
                        // Jetzt kann der Benutzer sicher gelöscht werden
                        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$old_user_id]);
                        
                        $user_deleted = true;
                        $old_user_id = null; // Für weitere Prüfungen
                    }
                    
                    // Prüfe ob Benutzerkonto erstellt werden soll
                    if (empty($old_user_id) && $create_user && $is_admin) {
                        if (empty($email)) {
                            $error = 'Für die Erstellung eines Benutzerkontos ist eine E-Mail-Adresse erforderlich.';
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                        } else {
                            // Prüfe ob E-Mail bereits verwendet wird
                            $stmt_check = $db->prepare("SELECT id FROM users WHERE email = ?");
                            $stmt_check->execute([$email]);
                            if ($stmt_check->fetch()) {
                                $error = 'Diese E-Mail-Adresse wird bereits von einem anderen Benutzer verwendet.';
                                if ($db->inTransaction()) {
                                    $db->rollBack();
                                }
                            } else {
                                // Generiere Benutzername
                                // 1. Versuch: Nur Vorname
                                $username = strtolower($first_name);
                                $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ?");
                                $stmt_check->execute([$username]);
                                
                                // 2. Versuch: Vorname + "." + erster Buchstabe des Nachnamens
                                if ($stmt_check->fetch()) {
                                    $first_letter_lastname = !empty($last_name) ? strtolower(substr($last_name, 0, 1)) : '';
                                    $username = strtolower($first_name) . '.' . $first_letter_lastname;
                                    $stmt_check->execute([$username]);
                                    
                                    // 3. Versuch: Mit Nummern falls auch dieser existiert
                                    if ($stmt_check->fetch()) {
                                        $base_username = $username;
                                        $counter = 1;
                                        while (true) {
                                            $username = $base_username . $counter;
                                            $stmt_check->execute([$username]);
                                            if (!$stmt_check->fetch()) {
                                                break; // Benutzername ist verfügbar
                                            }
                                            $counter++;
                                        }
                                    }
                                }
                                
                                // Generiere Standard-Passwort (4 zufällige Zahlen)
                                $default_password = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                                $password_hash = hash_password($default_password);
                                
                                // Benutzer erstellen
                                $stmt_user = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles, email_notifications) VALUES (?, ?, ?, ?, ?, 'user', 1, 0, 0, 0, 0, 0, 0, 0)");
                                $stmt_user->execute([$username, $email, $password_hash, $first_name, $last_name]);
                                $new_user_id = $db->lastInsertId();
                                
                                // user_id im Mitglied setzen
                                $stmt = $db->prepare("UPDATE members SET user_id = ? WHERE id = ?");
                                $stmt->execute([$new_user_id, $member_id]);
                                
                                // Passwort in Session speichern für Anzeige
                                $_SESSION['new_user_password_' . $new_user_id] = $default_password;
                                
                                // E-Mail mit Benutzername und Passwort senden
                                if (!empty($email)) {
                                    $email_subject = 'Ihr Benutzerkonto wurde erstellt';
                                    $email_body = '
                                    <html>
                                    <head>
                                        <meta charset="UTF-8">
                                        <style>
                                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                            .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                                            .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                                            .credentials { background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #dc3545; }
                                            .credentials strong { color: #dc3545; }
                                            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                                        </style>
                                    </head>
                                    <body>
                                        <div class="container">
                                            <div class="header">
                                                <h1>Willkommen bei der Feuerwehr-App</h1>
                                            </div>
                                            <div class="content">
                                                <p>Hallo ' . htmlspecialchars($first_name) . ',</p>
                                                <p>Ihr Benutzerkonto wurde erfolgreich erstellt. Sie können sich nun mit folgenden Zugangsdaten anmelden:</p>
                                                <div class="credentials">
                                                    <p><strong>Benutzername:</strong> ' . htmlspecialchars($username) . '</p>
                                                    <p><strong>Passwort:</strong> ' . htmlspecialchars($default_password) . '</p>
                                                </div>
                                                <p style="text-align: center; margin: 30px 0;">
                                                    <a href="https://feuerwehr.boede89.selfhost.co/" style="display: inline-block; background-color: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Zur Startseite</a>
                                                </p>
                                                <p>Bitte ändern Sie Ihr Passwort nach dem ersten Login für mehr Sicherheit.</p>
                                                <p>Bei Fragen wenden Sie sich bitte an den Administrator.</p>
                                            </div>
                                            <div class="footer">
                                                <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
                                            </div>
                                        </div>
                                    </body>
                                    </html>';
                                    
                                    if (send_email($email, $email_subject, $email_body, '', true)) {
                                        error_log("Willkommens-E-Mail erfolgreich gesendet an: $email");
                                    } else {
                                        error_log("Fehler beim Senden der Willkommens-E-Mail an: $email");
                                    }
                                }
                                
                                $user_created = true;
                                $old_user_id = $new_user_id; // Für weitere Prüfungen
                            }
                        }
                    }
                    
                    if (empty($error)) {
                        // Aktualisiere Mitglied
                        $stmt = $db->prepare("UPDATE members SET first_name = ?, last_name = ?, email = ?, birthdate = ?, phone = ?, is_pa_traeger = ? WHERE id = ?");
                        $stmt->execute([
                            $first_name,
                            $last_name,
                            !empty($email) ? $email : null,
                            !empty($birthdate) ? $birthdate : null,
                            !empty($phone) ? $phone : null,
                            $is_pa_traeger,
                            $member_id
                        ]);
                        
                        // Wenn PA-Träger deaktiviert, lösche den zugehörigen Geräteträger
                        if ($is_pa_traeger == 0) {
                            $stmt = $db->prepare("DELETE FROM atemschutz_traeger WHERE member_id = ?");
                            $stmt->execute([$member_id]);
                        } elseif ($is_pa_traeger == 1) {
                            // Wenn PA-Träger aktiviert, stelle sicher dass ein Geräteträger existiert
                            $stmt = $db->prepare("SELECT id FROM atemschutz_traeger WHERE member_id = ? LIMIT 1");
                            $stmt->execute([$member_id]);
                            $existing_traeger = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            $birthdate_value = !empty($birthdate) ? $birthdate : date('Y-m-d');
                            
                            if (!$existing_traeger) {
                                // Erstelle neuen Geräteträger
                                $stmt = $db->prepare("
                                    INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status, member_id) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv', ?)
                                ");
                                $stmt->execute([
                                    $first_name,
                                    $last_name,
                                    !empty($email) ? $email : null,
                                    $birthdate_value,
                                    $strecke_am,
                                    $g263_am,
                                    $uebung_am,
                                    $member_id
                                ]);
                            } else {
                                // Aktualisiere bestehenden Geräteträger
                                $stmt = $db->prepare("
                                    UPDATE atemschutz_traeger 
                                    SET first_name = ?, last_name = ?, email = ?, birthdate = ?, strecke_am = ?, g263_am = ?, uebung_am = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $first_name,
                                    $last_name,
                                    !empty($email) ? $email : null,
                                    $birthdate_value,
                                    $strecke_am,
                                    $g263_am,
                                    $uebung_am,
                                    $existing_traeger['id']
                                ]);
                            }
                        }
                        
                        // Wenn Mitglied mit Benutzer verknüpft ist, aktualisiere auch Benutzer
                        if (!empty($old_user_id)) {
                            $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
                            $stmt->execute([$first_name, $last_name, !empty($email) ? $email : null, $old_user_id]);
                        }
                    
                    // Wenn Mitglied mit Geräteträger verknüpft ist, aktualisiere auch Geräteträger
                    $stmt = $db->prepare("SELECT id FROM atemschutz_traeger WHERE member_id = ? LIMIT 1");
                    $stmt->execute([$member_id]);
                    $traeger = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($traeger) {
                        $stmt = $db->prepare("UPDATE atemschutz_traeger SET first_name = ?, last_name = ?, email = ?, birthdate = ? WHERE id = ?");
                        $stmt->execute([
                            $first_name,
                            $last_name,
                            !empty($email) ? $email : null,
                            !empty($birthdate) ? $birthdate : null,
                            $traeger['id']
                        ]);
                    }
                    
                        if ($db->inTransaction()) {
                            $db->commit();
                        }
                        
                        // Erfolgsmeldung mit Passwort falls Benutzer erstellt wurde
                        if ($user_created && !empty($new_user_id) && !empty($default_password) && !empty($username)) {
                            $message = 'Mitglied wurde erfolgreich aktualisiert und Benutzerkonto wurde erstellt. Benutzername: ' . htmlspecialchars($username) . ', Passwort: ' . htmlspecialchars($default_password);
                        } elseif ($user_deleted) {
                            $message = 'Mitglied wurde erfolgreich aktualisiert und Benutzerkonto wurde gelöscht.';
                        } else {
                            $message = 'Mitglied wurde erfolgreich aktualisiert.';
                        }
                        
                        header("Location: members.php?show_list=1&success=edited");
                        exit();
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Fehler beim Aktualisieren: ' . $e->getMessage();
                error_log("Fehler beim Bearbeiten von Mitglied: " . $e->getMessage());
            }
        }
    }
}

// Mitglied löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_member') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $member_id = (int)($_POST['member_id'] ?? 0);
        
        if ($member_id <= 0) {
            $error = 'Ungültige Mitglieds-ID.';
        } else {
            try {
                $db->beginTransaction();
                
                // Lade Mitglied
                $stmt = $db->prepare("SELECT user_id FROM members WHERE id = ?");
                $stmt->execute([$member_id]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$member) {
                    $error = 'Mitglied nicht gefunden.';
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } else {
                    // Prüfe ob Mitglied mit Benutzer verknüpft ist
                    if (!empty($member['user_id'])) {
                        $error = 'Mitglied kann nicht gelöscht werden, da es mit einem Benutzerkonto verknüpft ist. Bitte löschen Sie zuerst das Benutzerkonto.';
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                    } else {
                        // Lösche zugehörigen Geräteträger (falls vorhanden)
                        $stmt = $db->prepare("DELETE FROM atemschutz_traeger WHERE member_id = ?");
                        $stmt->execute([$member_id]);
                        
                        // Lösche Mitglied
                        $stmt = $db->prepare("DELETE FROM members WHERE id = ?");
                        $stmt->execute([$member_id]);
                        
                        if ($db->inTransaction()) {
                            $db->commit();
                        }
                        $message = 'Mitglied wurde erfolgreich gelöscht.';
                        header("Location: members.php?show_list=1&success=deleted");
                        exit();
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Fehler beim Löschen: ' . $e->getMessage();
                error_log("Fehler beim Löschen von Mitglied: " . $e->getMessage());
            }
        }
    }
}

// PA-Träger Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_pa_traeger') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $is_pa_traeger = isset($_POST['is_pa_traeger']) ? 1 : 0;
        
        if ($member_id > 0) {
            try {
                // Sicherstellen, dass is_pa_traeger Spalte existiert
                try {
                    $db->exec("ALTER TABLE members ADD COLUMN is_pa_traeger TINYINT(1) DEFAULT 0");
                } catch (Exception $e) {
                    // Spalte existiert bereits
                }
                
                // Aktualisiere is_pa_traeger
                $stmt = $db->prepare("UPDATE members SET is_pa_traeger = ? WHERE id = ?");
                $stmt->execute([$is_pa_traeger, $member_id]);
                
                // Wenn aktiviert, stelle sicher dass ein Geräteträger existiert
                if ($is_pa_traeger == 1) {
                    // Prüfe ob bereits ein Geräteträger mit dieser member_id existiert
                    $stmt = $db->prepare("SELECT id FROM atemschutz_traeger WHERE member_id = ? LIMIT 1");
                    $stmt->execute([$member_id]);
                    if (!$stmt->fetch()) {
                        // Lade Mitgliedsdaten
                        $stmt = $db->prepare("SELECT first_name, last_name, email, birthdate FROM members WHERE id = ?");
                        $stmt->execute([$member_id]);
                        $member = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($member) {
                            // Erstelle Geräteträger - verwende heute als Standard, da keine Datumsfelder vorhanden sind
                            $today = date('Y-m-d');
                            $birthdate = $member['birthdate'] ?? $today;
                            
                            $stmt = $db->prepare("
                                INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status, member_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv', ?)
                            ");
                            $stmt->execute([
                                $member['first_name'],
                                $member['last_name'],
                                $member['email'],
                                $birthdate,
                                $today,
                                $today,
                                $today,
                                $member_id
                            ]);
                        }
                    }
                }
                
                $message = 'PA-Träger Status wurde aktualisiert.';
                header("Location: members.php?show_list=1&success=toggle");
                exit();
            } catch (Exception $e) {
                $error = 'Fehler beim Aktualisieren: ' . $e->getMessage();
            }
        }
    }
}

// Aktuelle Liste anzeigen (Toggle)
$show_list = isset($_GET['show_list']) && $_GET['show_list'] == '1';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitgliederverwaltung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-users"></i> Mitgliederverwaltung
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Aktions-Buttons -->
        <div class="row mb-4">
            <div class="col-12 col-md-4 mb-2">
                <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    <i class="fas fa-user-plus"></i> Mitglied hinzufügen
                </button>
            </div>
            <div class="col-12 col-md-4 mb-2">
                <a href="?show_list=<?php echo $show_list ? '0' : '1'; ?>" class="btn btn-outline-primary w-100">
                    <i class="fas fa-list"></i> <?php echo $show_list ? 'Liste ausblenden' : 'Aktuelle Liste anzeigen'; ?>
                </a>
            </div>
            <?php if (has_permission('members') && has_permission('ric')): ?>
            <div class="col-12 col-md-4 mb-2">
                <a href="ric-verwaltung.php" class="btn btn-warning w-100">
                    <i class="fas fa-broadcast-tower"></i> RIC Verwaltung (Divera)
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Mitglieder-Liste -->
        <?php if ($show_list): ?>
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-users"></i> Aktuelle Mitgliederliste
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($members)): ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-info-circle"></i> Noch keine Mitglieder vorhanden.
                            </p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Vorname</th>
                                            <th>Nachname</th>
                                            <th>E-Mail</th>
                                            <th>Geburtsdatum</th>
                                            <th>Telefon</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($members as $member): ?>
                                        <tr style="cursor: pointer;" 
                                            data-member-id="<?php echo htmlspecialchars($member['member_id'] ?? ''); ?>"
                                            data-member-data="<?php echo htmlspecialchars(json_encode($member)); ?>"
                                            class="member-row"
                                            onmouseover="this.style.backgroundColor='#f8f9fa'" 
                                            onmouseout="this.style.backgroundColor=''">
                                            <td>
                                                <?php echo htmlspecialchars($member['first_name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                            <td><?php echo $member['birthdate'] ? date('d.m.Y', strtotime($member['birthdate'])) : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($member['phone'] ?? '-'); ?></td>
                                            <td class="no-click">
                                                <?php if (!empty($member['member_id'])): ?>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            onclick="editMember(<?php echo htmlspecialchars(json_encode($member)); ?>); return false;"
                                                            title="Bearbeiten">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if (empty($member['user_id'])): ?>
                                                    <button type="button" class="btn btn-outline-danger" 
                                                            onclick="deleteMember(<?php echo (int)$member['member_id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>'); return false;"
                                                            title="Löschen">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Mitglied Details Modal -->
    <div class="modal fade" id="memberDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i> Mitglied Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <h5 class="mb-3" id="memberDetailsName"></h5>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Vorname</label>
                            <p class="form-control-plaintext" id="memberDetailsFirstName"></p>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Nachname</label>
                            <p class="form-control-plaintext" id="memberDetailsLastName"></p>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">E-Mail</label>
                            <p class="form-control-plaintext" id="memberDetailsEmail"></p>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Geburtsdatum</label>
                            <p class="form-control-plaintext" id="memberDetailsBirthdate"></p>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">Telefon</label>
                            <p class="form-control-plaintext" id="memberDetailsPhone"></p>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold">PA-Träger</label>
                            <p class="form-control-plaintext" id="memberDetailsPaTraegerStatus"></p>
                        </div>
                        <div class="col-12" id="memberDetailsPaTraeger" style="display: none;">
                            <hr>
                            <h6 class="mb-3"><i class="fas fa-user-shield me-2"></i>PA-Träger Informationen</h6>
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-bold">Strecke Am</label>
                                    <p class="form-control-plaintext" id="memberDetailsStreckeAm"></p>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-bold">G26.3 Am</label>
                                    <p class="form-control-plaintext" id="memberDetailsG263Am"></p>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-bold">Übung/Einsatz Am</label>
                                    <p class="form-control-plaintext" id="memberDetailsUebungAm"></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <hr>
                            <h6 class="mb-3"><i class="fas fa-broadcast-tower me-2"></i>Zugewiesene RIC-Codes</h6>
                            <div id="memberDetailsRics">
                                <p class="text-muted">Lade RIC-Codes...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Schließen
                    </button>
                    <?php if ($can_ric): ?>
                    <button type="button" class="btn btn-warning" id="memberDetailsAssignRicBtn">
                        <i class="fas fa-broadcast-tower me-1"></i>RIC's Zuweisen
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" id="memberDetailsEditBtn">
                        <i class="fas fa-edit me-1"></i>Bearbeiten
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mitglied hinzufügen/bearbeiten Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white" id="memberModalHeader">
                    <h5 class="modal-title" id="memberModalTitle">
                        <i class="fas fa-user-plus me-2"></i> Mitglied hinzufügen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" id="memberForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="add_member" id="memberAction">
                    <input type="hidden" name="member_id" value="" id="memberId">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-user me-1"></i>Vorname <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="first_name" id="memberFirstName" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-user me-1"></i>Nachname <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="last_name" id="memberLastName" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" id="emailLabel">
                                    <i class="fas fa-envelope me-1"></i>E-Mail (optional)
                                </label>
                                <input type="email" class="form-control" name="email" id="memberEmail">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" id="birthdateLabel">
                                    <i class="fas fa-calendar me-1"></i>Geburtsdatum (optional)
                                </label>
                                <input type="date" class="form-control" name="birthdate" id="memberBirthdate">
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    <i class="fas fa-phone me-1"></i>Telefon (optional)
                                </label>
                                <input type="tel" class="form-control" name="phone" id="memberPhone">
                            </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_pa_traeger" id="memberIsPaTraeger" value="1">
                                        <label class="form-check-label" for="memberIsPaTraeger">
                                            <i class="fas fa-user-shield me-1"></i>PA-Träger
                                        </label>
                                        <small class="form-text text-muted d-block mt-1">
                                            Wenn aktiviert, erscheint dieses Mitglied in der Liste der Geräteträger.
                                        </small>
                                    </div>
                                </div>
                                
                                <!-- PA-Träger Pflichtfelder (nur sichtbar wenn PA-Träger aktiviert) -->
                                <div class="col-12" id="paTraegerFields" style="display: none;">
                                    <div class="border rounded p-3 bg-light">
                                        <h6 class="mb-3"><i class="fas fa-user-shield me-2"></i>PA-Träger Pflichtfelder</h6>
                                        
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="border rounded p-3 mb-3">
                                                    <h6 class="mb-3"><i class="fas fa-road me-2"></i> Strecke</h6>
                                                    <div class="row g-3">
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label">Strecke Am <span class="text-danger">*</span></label>
                                                            <input type="date" class="form-control" name="strecke_am" id="memberStreckeAm">
                                                            <small class="form-text text-muted">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-12">
                                                <div class="border rounded p-3 mb-3">
                                                    <h6 class="mb-3"><i class="fas fa-stethoscope me-2"></i> G26.3</h6>
                                                    <div class="row g-3">
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label">G26.3 Am <span class="text-danger">*</span></label>
                                                            <input type="date" class="form-control" name="g263_am" id="memberG263Am">
                                                            <small class="form-text text-muted">Bis-Datum: unter 50 Jahre +3 Jahre, ab 50 +1 Jahr.</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-12">
                                                <div class="border rounded p-3">
                                                    <h6 class="mb-3"><i class="fas fa-dumbbell me-2"></i> Übung/Einsatz</h6>
                                                    <div class="row g-3">
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label">Übung/Einsatz Am <span class="text-danger">*</span></label>
                                                            <input type="date" class="form-control" name="uebung_am" id="memberUebungAm">
                                                            <small class="form-text text-muted">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <div class="col-12" id="createUserSection">
                                <?php if ($is_admin): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="create_user" id="create_user" value="1">
                                    <label class="form-check-label" for="create_user">
                                        <i class="fas fa-user-shield me-1"></i>Login erlaubt (Benutzerkonto erstellen)
                                    </label>
                                    <small class="form-text text-muted d-block mt-1">
                                        Wenn aktiviert, wird automatisch ein Benutzerkonto mit zufälligem Passwort erstellt. Eine E-Mail-Adresse ist dafür erforderlich.
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Abbrechen
                        </button>
                        <button type="submit" class="btn btn-success" id="memberSubmitBtn">
                            <i class="fas fa-save me-1"></i>Speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Event-Listener für klickbare Zeilen
        document.addEventListener('DOMContentLoaded', function() {
            const memberRows = document.querySelectorAll('.member-row');
            console.log('Gefundene Mitgliederzeilen:', memberRows.length);
            memberRows.forEach(function(row) {
                row.addEventListener('click', function(e) {
                    console.log('Klick erkannt auf:', e.target);
                    console.log('Klick auf Button?', e.target.closest('button'));
                    console.log('Klick auf letzte Spalte?', e.target.closest('td:last-child'));
                    
                    // Ignoriere Klicks auf Aktionen-Spalte (letzte Spalte mit class="no-click") oder Buttons
                    const clickedCell = e.target.closest('td');
                    if (clickedCell && clickedCell.classList.contains('no-click')) {
                        console.log('Klick auf Aktionen-Spalte ignoriert');
                        return;
                    }
                    
                    if (e.target.closest('button') || e.target.closest('.btn-group')) {
                        console.log('Klick auf Button ignoriert');
                        return;
                    }
                    
                    const memberData = row.getAttribute('data-member-data');
                    console.log('Klick auf Mitgliederzeile, Daten:', memberData);
                    if (memberData) {
                        try {
                            const member = JSON.parse(memberData);
                            console.log('Parsed member:', member);
                            showMemberDetails(member);
                        } catch (error) {
                            console.error('Fehler beim Parsen der Mitgliederdaten:', error);
                            alert('Fehler beim Laden der Mitgliederdaten: ' + error.message);
                        }
                    } else {
                        console.error('Keine Mitgliederdaten gefunden!');
                    }
                });
            });
        });
        
        // Funktion zum Anzeigen der Mitgliederdetails
        function showMemberDetails(member) {
            try {
                console.log('showMemberDetails aufgerufen mit:', member);
                
                const modalElement = document.getElementById('memberDetailsModal');
                if (!modalElement) {
                    console.error('Modal-Element nicht gefunden!');
                    alert('Fehler: Modal-Element nicht gefunden.');
                    return;
                }
                
                const modal = new bootstrap.Modal(modalElement);
                
                // Daten in das Modal einfügen
                const nameEl = document.getElementById('memberDetailsName');
                const firstNameEl = document.getElementById('memberDetailsFirstName');
                const lastNameEl = document.getElementById('memberDetailsLastName');
                const emailEl = document.getElementById('memberDetailsEmail');
                const birthdateEl = document.getElementById('memberDetailsBirthdate');
                const phoneEl = document.getElementById('memberDetailsPhone');
                
                if (nameEl) nameEl.textContent = (member.first_name || '') + ' ' + (member.last_name || '');
                if (firstNameEl) firstNameEl.textContent = member.first_name || '-';
                if (lastNameEl) lastNameEl.textContent = member.last_name || '-';
                if (emailEl) emailEl.textContent = member.email || '-';
                if (birthdateEl) {
                    if (member.birthdate) {
                        try {
                            const birthdate = new Date(member.birthdate);
                            birthdateEl.textContent = birthdate.toLocaleDateString('de-DE');
                        } catch (e) {
                            birthdateEl.textContent = member.birthdate;
                        }
                    } else {
                        birthdateEl.textContent = '-';
                    }
                }
                
                // PA-Träger Status anzeigen
                const paTraegerStatusEl = document.getElementById('memberDetailsPaTraegerStatus');
                if (paTraegerStatusEl) {
                    if (member.is_pa_traeger == 1 || member.is_pa_traeger === '1' || member.is_pa_traeger === 1) {
                        paTraegerStatusEl.textContent = 'Ja';
                    } else {
                        paTraegerStatusEl.textContent = 'Nein';
                    }
                }
                
                // PA-Träger Informationen anzeigen
                const paTraegerSection = document.getElementById('memberDetailsPaTraeger');
                if (paTraegerSection) {
                    if (member.is_pa_traeger == 1 || member.is_pa_traeger === '1' || member.is_pa_traeger === 1 || member.strecke_am || member.g263_am || member.uebung_am) {
                        paTraegerSection.style.display = 'block';
                        
                        const streckeEl = document.getElementById('memberDetailsStreckeAm');
                        const g263El = document.getElementById('memberDetailsG263Am');
                        const uebungEl = document.getElementById('memberDetailsUebungAm');
                        
                        if (streckeEl) {
                            if (member.strecke_am) {
                                try {
                                    streckeEl.textContent = new Date(member.strecke_am).toLocaleDateString('de-DE');
                                } catch (e) {
                                    streckeEl.textContent = member.strecke_am;
                                }
                            } else {
                                streckeEl.textContent = '-';
                            }
                        }
                        
                        if (g263El) {
                            if (member.g263_am) {
                                try {
                                    g263El.textContent = new Date(member.g263_am).toLocaleDateString('de-DE');
                                } catch (e) {
                                    g263El.textContent = member.g263_am;
                                }
                            } else {
                                g263El.textContent = '-';
                            }
                        }
                        
                        if (uebungEl) {
                            if (member.uebung_am) {
                                try {
                                    uebungEl.textContent = new Date(member.uebung_am).toLocaleDateString('de-DE');
                                } catch (e) {
                                    uebungEl.textContent = member.uebung_am;
                                }
                            } else {
                                uebungEl.textContent = '-';
                            }
                        }
                    } else {
                        paTraegerSection.style.display = 'none';
                    }
                }
                
                // RIC-Codes laden
                if (member.member_id || member.id) {
                    loadMemberRicsForDetails(member.member_id || member.id);
                }
                
                // Bearbeiten-Button konfigurieren
                const editBtn = document.getElementById('memberDetailsEditBtn');
                if (editBtn) {
                    if (member.member_id) {
                        editBtn.style.display = 'inline-block';
                        editBtn.onclick = function() {
                            modal.hide();
                            setTimeout(function() {
                                editMember(member);
                            }, 300);
                        };
                    } else {
                        editBtn.style.display = 'none';
                    }
                }
                
                // RIC-Zuweisen-Button konfigurieren
                const assignRicBtn = document.getElementById('memberDetailsAssignRicBtn');
                if (assignRicBtn) {
                    if (member.member_id || member.id) {
                        assignRicBtn.style.display = 'inline-block';
                        assignRicBtn.onclick = function() {
                            modal.hide();
                            setTimeout(function() {
                                // Öffne RIC-Verwaltung mit diesem Mitglied
                                window.location.href = 'ric-verwaltung.php?member_id=' + (member.member_id || member.id);
                            }, 300);
                        };
                    } else {
                        assignRicBtn.style.display = 'none';
                    }
                }
                
                modal.show();
            } catch (error) {
                console.error('Fehler in showMemberDetails:', error);
                alert('Fehler beim Öffnen der Mitgliederdetails: ' + error.message);
            }
        }
        
        // Funktion zum Laden der RIC-Codes für ein Mitglied (für Details-Modal)
        function loadMemberRicsForDetails(memberId) {
            const ricsContainer = document.getElementById('memberDetailsRics');
            if (!ricsContainer) return;
            
            // AJAX-Anfrage zum Laden der RIC-Zuweisungen
            fetch('get-member-rics.php?member_id=' + memberId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.rics && data.rics.length > 0) {
                        let html = '';
                        data.rics.forEach(function(ric) {
                            const badgeClass = ric.status === 'pending' ? 'bg-warning' : 'bg-primary';
                            const badgeStyle = ric.status === 'pending' ? 'background-color: #ffc107 !important;' : '';
                            const isRemoved = (ric.status === 'pending' && ric.action === 'remove');
                            html += '<span class="badge ' + badgeClass + ' me-1 mb-1" style="' + badgeStyle + '">';
                            if (isRemoved) {
                                html += '<span style="text-decoration: line-through;">' + ric.kurztext + '</span>';
                            } else {
                                html += ric.kurztext;
                            }
                            if (ric.beschreibung) {
                                html += ' <small>(' + ric.beschreibung + ')</small>';
                            }
                            html += '</span>';
                        });
                        ricsContainer.innerHTML = html;
                    } else {
                        ricsContainer.innerHTML = '<p class="text-muted">Keine RIC-Codes zugewiesen</p>';
                    }
                })
                .catch(error => {
                    console.error('Fehler beim Laden der RIC-Codes:', error);
                    ricsContainer.innerHTML = '<p class="text-danger">Fehler beim Laden der RIC-Codes</p>';
                });
        }
        
        // Funktion zum Bearbeiten eines Mitglieds
        function editMember(member) {
            const modal = new bootstrap.Modal(document.getElementById('addMemberModal'));
            const form = document.getElementById('memberForm');
            const actionInput = document.getElementById('memberAction');
            const memberIdInput = document.getElementById('memberId');
            const title = document.getElementById('memberModalTitle');
            const header = document.getElementById('memberModalHeader');
            const submitBtn = document.getElementById('memberSubmitBtn');
            const createUserSection = document.getElementById('createUserSection');
            
            // Modal für Bearbeitung konfigurieren
            actionInput.value = 'edit_member';
            memberIdInput.value = member.member_id || '';
            title.innerHTML = '<i class="fas fa-user-edit me-2"></i> Mitglied bearbeiten';
            header.className = 'modal-header bg-primary text-white';
            submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Änderungen speichern';
            
            // Checkbox "Login erlaubt" immer anzeigen
            if (createUserSection) {
                createUserSection.style.display = 'block';
            }
            
            // Formularfelder füllen
            document.getElementById('memberFirstName').value = member.first_name || '';
            document.getElementById('memberLastName').value = member.last_name || '';
            document.getElementById('memberEmail').value = member.email || '';
            document.getElementById('memberBirthdate').value = member.birthdate || '';
            document.getElementById('memberPhone').value = member.phone || '';
            const isPaTraeger = member.is_pa_traeger == 1;
            document.getElementById('memberIsPaTraeger').checked = isPaTraeger;
            
            // PA-Träger Felder anzeigen/ausblenden basierend auf Status
            const paTraegerFields = document.getElementById('paTraegerFields');
            const birthdateInput = document.getElementById('memberBirthdate');
            const birthdateLabel = document.getElementById('birthdateLabel');
            
            if (paTraegerFields) {
                if (isPaTraeger) {
                    paTraegerFields.style.display = 'block';
                    const requiredFields = paTraegerFields.querySelectorAll('input[type="date"]');
                    requiredFields.forEach(field => {
                        field.setAttribute('required', 'required');
                    });
                    
                    // Geburtsdatum als Pflichtfeld markieren
                    if (birthdateInput) {
                        birthdateInput.setAttribute('required', 'required');
                    }
                    if (birthdateLabel) {
                        birthdateLabel.innerHTML = '<i class="fas fa-calendar me-1"></i>Geburtsdatum <span class="text-danger">*</span>';
                    }
                } else {
                    paTraegerFields.style.display = 'none';
                    const requiredFields = paTraegerFields.querySelectorAll('input[type="date"]');
                    requiredFields.forEach(field => {
                        field.removeAttribute('required');
                    });
                    
                    // Geburtsdatum wieder optional machen
                    if (birthdateInput) {
                        birthdateInput.removeAttribute('required');
                    }
                    if (birthdateLabel) {
                        birthdateLabel.innerHTML = '<i class="fas fa-calendar me-1"></i>Geburtsdatum (optional)';
                    }
                }
            }
            
            // PA-Träger Datumsfelder füllen (falls vorhanden)
            if (isPaTraeger && member.strecke_am) {
                document.getElementById('memberStreckeAm').value = member.strecke_am || '';
            }
            if (isPaTraeger && member.g263_am) {
                document.getElementById('memberG263Am').value = member.g263_am || '';
            }
            if (isPaTraeger && member.uebung_am) {
                document.getElementById('memberUebungAm').value = member.uebung_am || '';
            }
            
            // Checkbox "Login erlaubt" setzen basierend auf user_id
            const createUserCheckbox = document.getElementById('create_user');
            const emailInput = document.getElementById('memberEmail');
            const emailLabel = document.getElementById('emailLabel');
            const hasUserAccount = (member.user_id && member.user_id !== null && member.user_id !== '');
            
            if (createUserCheckbox) {
                createUserCheckbox.checked = hasUserAccount;
                
                // E-Mail-Validierung basierend auf Checkbox-Status setzen
                if (hasUserAccount) {
                    if (emailInput) {
                        emailInput.required = true;
                    }
                    if (emailLabel) {
                        emailLabel.innerHTML = '<i class="fas fa-envelope me-1"></i>E-Mail <span class="text-danger">*</span>';
                    }
                } else {
                    if (emailInput) {
                        emailInput.required = false;
                    }
                    if (emailLabel) {
                        emailLabel.innerHTML = '<i class="fas fa-envelope me-1"></i>E-Mail (optional)';
                    }
                }
            }
            
            modal.show();
        }
        
        // Funktion zum Löschen eines Mitglieds
        function deleteMember(memberId, memberName) {
            if (confirm('Möchten Sie das Mitglied "' + memberName + '" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken;
                form.appendChild(csrfInput);
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_member';
                form.appendChild(actionInput);
                
                const memberIdInput = document.createElement('input');
                memberIdInput.type = 'hidden';
                memberIdInput.name = 'member_id';
                memberIdInput.value = memberId;
                form.appendChild(memberIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Modal zurücksetzen beim Schließen
        document.getElementById('addMemberModal').addEventListener('hidden.bs.modal', function() {
            const form = document.getElementById('memberForm');
            const actionInput = document.getElementById('memberAction');
            const memberIdInput = document.getElementById('memberId');
            const title = document.getElementById('memberModalTitle');
            const header = document.getElementById('memberModalHeader');
            const submitBtn = document.getElementById('memberSubmitBtn');
            const createUserSection = document.getElementById('createUserSection');
            
            // Zurück auf "Hinzufügen" zurücksetzen
            form.reset();
            actionInput.value = 'add_member';
            memberIdInput.value = '';
            title.innerHTML = '<i class="fas fa-user-plus me-2"></i> Mitglied hinzufügen';
            header.className = 'modal-header bg-success text-white';
            submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Speichern';
            createUserSection.style.display = 'block';
            
            // E-Mail wieder optional machen
            const emailInput = document.getElementById('memberEmail');
            if (emailInput) {
                emailInput.required = false;
            }
            
            // PA-Träger Toggle zurücksetzen
            const paTraegerToggle = document.getElementById('memberIsPaTraeger');
            if (paTraegerToggle) {
                paTraegerToggle.checked = false;
            }
            
            // PA-Träger Felder ausblenden und zurücksetzen
            const paTraegerFields = document.getElementById('paTraegerFields');
            const birthdateInput = document.getElementById('memberBirthdate');
            const birthdateLabel = document.getElementById('birthdateLabel');
            
            if (paTraegerFields) {
                paTraegerFields.style.display = 'none';
                const requiredFields = paTraegerFields.querySelectorAll('input[type="date"]');
                requiredFields.forEach(field => {
                    field.removeAttribute('required');
                    field.value = '';
                });
            }
            
            // Geburtsdatum wieder optional machen
            if (birthdateInput) {
                birthdateInput.removeAttribute('required');
            }
            if (birthdateLabel) {
                birthdateLabel.innerHTML = '<i class="fas fa-calendar me-1"></i>Geburtsdatum (optional)';
            }
        });
        
        // PA-Träger Toggle Event Listener - MUSS NACH DEM MODAL EVENT LISTENER SEIN
        const paTraegerToggle = document.getElementById('memberIsPaTraeger');
        const paTraegerFields = document.getElementById('paTraegerFields');
        const birthdateInput = document.getElementById('memberBirthdate');
        const birthdateLabel = document.getElementById('birthdateLabel');
        
        if (paTraegerToggle && paTraegerFields) {
            paTraegerToggle.addEventListener('change', function() {
                if (this.checked) {
                    // PA-Träger Felder anzeigen
                    paTraegerFields.style.display = 'block';
                    
                    // Alle Datumsfelder als required markieren
                    const requiredFields = paTraegerFields.querySelectorAll('input[type="date"]');
                    requiredFields.forEach(field => {
                        field.setAttribute('required', 'required');
                    });
                    
                    // Geburtsdatum als Pflichtfeld markieren
                    if (birthdateInput) {
                        birthdateInput.setAttribute('required', 'required');
                    }
                    if (birthdateLabel) {
                        birthdateLabel.innerHTML = '<i class="fas fa-calendar me-1"></i>Geburtsdatum <span class="text-danger">*</span>';
                    }
                } else {
                    // PA-Träger Felder ausblenden
                    paTraegerFields.style.display = 'none';
                    
                    // Required entfernen von PA-Träger Feldern
                    const requiredFields = paTraegerFields.querySelectorAll('input[type="date"]');
                    requiredFields.forEach(field => {
                        field.removeAttribute('required');
                        field.value = ''; // Felder leeren
                    });
                    
                    // Geburtsdatum wieder optional machen
                    if (birthdateInput) {
                        birthdateInput.removeAttribute('required');
                    }
                    if (birthdateLabel) {
                        birthdateLabel.innerHTML = '<i class="fas fa-calendar me-1"></i>Geburtsdatum (optional)';
                    }
                }
            });
        }
        
        <?php if ($is_admin): ?>
        // E-Mail als Pflichtfeld setzen wenn "Login erlaubt" aktiviert ist
        const createUserCheckbox = document.getElementById('create_user');
        const emailInput = document.getElementById('memberEmail');
        
        if (createUserCheckbox && emailInput) {
            createUserCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    emailInput.required = true;
                    emailInput.setAttribute('placeholder', 'E-Mail ist für Benutzerkonto erforderlich');
                } else {
                    emailInput.required = false;
                    emailInput.removeAttribute('placeholder');
                }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>

