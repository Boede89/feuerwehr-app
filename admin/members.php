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

// Doppelte Mitglieder zusammenführen (manuell oder automatisch beim ersten Laden)
if (isset($_GET['merge_duplicates'])) {
    try {
        $merge_result = merge_duplicate_members();
        if ($merge_result['merged'] > 0) {
            $message = "Doppelte Mitglieder wurden zusammengeführt: " . $merge_result['merged'] . " Gruppen, " . $merge_result['deleted'] . " Duplikate entfernt.";
        } else {
            $message = "Keine doppelten Mitglieder gefunden.";
        }
        // Weiterleitung um POST-Problem zu vermeiden
        header("Location: members.php?success=merged");
        exit();
    } catch (Exception $e) {
        $error = "Fehler beim Zusammenführen: " . $e->getMessage();
        error_log("Fehler beim Zusammenführen: " . $e->getMessage());
    }
}

// Erfolgsmeldung anzeigen
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'merged':
            $message = "Doppelte Mitglieder wurden erfolgreich zusammengeführt.";
            break;
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
    try {
        // Prüfe ob Foreign Key bereits existiert
        $stmt = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'");
        if (!$stmt->fetch()) {
            $db->exec("ALTER TABLE members ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        }
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
    try {
        // Setze is_pa_traeger auf 1 für alle Mitglieder, die einen Geräteträger haben
        $db->exec("
            UPDATE members m
            INNER JOIN atemschutz_traeger at ON m.id = at.member_id
            SET m.is_pa_traeger = 1
            WHERE m.is_pa_traeger = 0 OR m.is_pa_traeger IS NULL
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
                WHEN EXISTS (SELECT 1 FROM atemschutz_traeger at WHERE at.member_id = m.id) THEN 1
                ELSE COALESCE(m.is_pa_traeger, 0)
            END as is_pa_traeger,
            m.id as member_id,
            u.created_at,
            'user' as source
        FROM users u
        INNER JOIN members m ON m.user_id = u.id
        WHERE u.is_active = 1
        ORDER BY u.last_name, u.first_name
    ");
    $user_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Dann zusätzliche Mitglieder (ohne user_id Verknüpfung, prüfe auch ob Geräteträger existiert)
    $stmt = $db->query("
        SELECT 
            NULL as user_id,
            first_name,
            last_name,
            email,
            birthdate,
            phone,
            CASE 
                WHEN EXISTS (SELECT 1 FROM atemschutz_traeger at WHERE at.member_id = members.id) THEN 1
                ELSE COALESCE(is_pa_traeger, 0)
            END as is_pa_traeger,
            id as member_id,
            created_at,
            'member' as source
        FROM members
        WHERE user_id IS NULL
        ORDER BY last_name, first_name
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
        
        if (empty($first_name) || empty($last_name)) {
            $error = 'Bitte geben Sie Vorname und Nachname ein.';
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
                            // Generiere Benutzername (Vorname.Nachname oder mit Nummer falls vorhanden)
                            $base_username = strtolower($first_name . '.' . $last_name);
                            $username = $base_username;
                            $counter = 1;
                            
                            // Prüfe ob Benutzername bereits existiert
                            while (true) {
                                $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ?");
                                $stmt_check->execute([$username]);
                                if (!$stmt_check->fetch()) {
                                    break; // Benutzername ist verfügbar
                                }
                                $username = $base_username . $counter;
                                $counter++;
                            }
                            
                            // Generiere Standard-Passwort (kann später geändert werden)
                            $default_password = bin2hex(random_bytes(8)); // 16 Zeichen zufälliges Passwort
                            $password_hash = hash_password($default_password);
                            
                            // Benutzer erstellen
                            $stmt_user = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles, email_notifications) VALUES (?, ?, ?, ?, ?, 'user', 1, 0, 0, 0, 0, 0, 0, 0)");
                            $stmt_user->execute([$username, $email, $password_hash, $first_name, $last_name]);
                            $user_id = $db->lastInsertId();
                            
                            // Passwort in Session speichern für Anzeige (nur einmal)
                            $_SESSION['new_user_password_' . $user_id] = $default_password;
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
                        $today = date('Y-m-d');
                        $stmt = $db->prepare("
                            INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status, member_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv', ?)
                        ");
                        $stmt->execute([
                            $first_name,
                            $last_name,
                            !empty($email) ? $email : null,
                            !empty($birthdate) ? $birthdate : $today,
                            $today,
                            $today,
                            $today,
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
        
        if (empty($first_name) || empty($last_name)) {
            $error = 'Bitte geben Sie Vorname und Nachname ein.';
        } elseif ($member_id <= 0) {
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
                    
                    // Wenn PA-Träger aktiviert, stelle sicher dass ein Geräteträger existiert
                    if ($is_pa_traeger == 1) {
                        $stmt = $db->prepare("SELECT id FROM atemschutz_traeger WHERE member_id = ? LIMIT 1");
                        $stmt->execute([$member_id]);
                        if (!$stmt->fetch()) {
                            // Erstelle Geräteträger mit Standard-Daten (heute)
                            $today = date('Y-m-d');
                            $stmt = $db->prepare("
                                INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status, member_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv', ?)
                            ");
                            $stmt->execute([
                                $first_name,
                                $last_name,
                                !empty($email) ? $email : null,
                                !empty($birthdate) ? $birthdate : $today,
                                $today,
                                $today,
                                $today,
                                $member_id
                            ]);
                        }
                    }
                    
                    // Wenn Mitglied mit Benutzer verknüpft ist, aktualisiere auch Benutzer
                    if (!empty($member['user_id'])) {
                        $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
                        $stmt->execute([$first_name, $last_name, !empty($email) ? $email : null, $member['user_id']]);
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
                    $message = 'Mitglied wurde erfolgreich aktualisiert.';
                    header("Location: members.php?show_list=1&success=edited");
                    exit();
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
                            // Erstelle Geräteträger mit Standard-Daten (heute)
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
            <div class="col-12 col-md-6 mb-2">
                <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    <i class="fas fa-user-plus"></i> Mitglied hinzufügen
                </button>
            </div>
            <div class="col-12 col-md-6 mb-2">
                <a href="?show_list=<?php echo $show_list ? '0' : '1'; ?>" class="btn btn-outline-primary w-100">
                    <i class="fas fa-list"></i> <?php echo $show_list ? 'Liste ausblenden' : 'Aktuelle Liste anzeigen'; ?>
                </a>
            </div>
            <div class="col-12 col-md-6 mb-2">
                <a href="?merge_duplicates=1" class="btn btn-outline-warning w-100" onclick="return confirm('Möchten Sie wirklich doppelte Mitglieder zusammenführen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                    <i class="fas fa-compress-alt"></i> Doppelte Mitglieder zusammenführen
                </a>
            </div>
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
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($member['first_name']); ?>
                                                <?php if ($member['source'] ?? '' === 'user'): ?>
                                                    <span class="badge bg-primary ms-2" title="Benutzer des Systems">Benutzer</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                            <td><?php echo $member['birthdate'] ? date('d.m.Y', strtotime($member['birthdate'])) : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($member['phone'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($member['member_id']) && empty($member['user_id'])): ?>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            onclick="editMember(<?php echo htmlspecialchars(json_encode($member)); ?>)"
                                                            title="Bearbeiten">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger" 
                                                            onclick="deleteMember(<?php echo (int)$member['member_id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>')"
                                                            title="Löschen">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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
                                <label class="form-label">
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
            createUserSection.style.display = 'none';
            
            // Formularfelder füllen
            document.getElementById('memberFirstName').value = member.first_name || '';
            document.getElementById('memberLastName').value = member.last_name || '';
            document.getElementById('memberEmail').value = member.email || '';
            document.getElementById('memberBirthdate').value = member.birthdate || '';
            document.getElementById('memberPhone').value = member.phone || '';
            document.getElementById('memberIsPaTraeger').checked = member.is_pa_traeger == 1;
            
            // E-Mail wieder optional machen
            const emailInput = document.getElementById('memberEmail');
            emailInput.required = false;
            
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
        });
        
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

