<?php
// Dashboard mit berechtigungsbasierten Bereichen
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session starten bevor alles andere
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datenbankverbindung (wie restliche App)
require_once __DIR__ . '/../config/database.php';
if (!$db) {
    die('Datenbankverbindung fehlgeschlagen.');
}

// Functions laden für Berechtigungsprüfungen
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/rooms-setup.php';

// Dashboard-Einstellungen Tabelle erstellen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS dashboard_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            section_name VARCHAR(50) NOT NULL,
            is_collapsed BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_section (user_id, section_name),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Tabelle existiert bereits oder Fehler - ignorieren
}

// Login-Prüfung
if (!isset($_SESSION["user_id"])) {
    echo '<script>window.location.href = "../login.php";</script>';
    exit();
}

// Einheit aus URL setzen (z.B. dashboard.php?einheit_id=5), falls Berechtigung
if (isset($_GET['einheit_id']) && function_exists('user_has_einheit_access')) {
    $eid = (int)$_GET['einheit_id'];
    if ($eid > 0 && user_has_einheit_access($_SESSION['user_id'], $eid)) {
        $_SESSION['current_einheit_id'] = $eid;
        $_SESSION['current_unit_id'] = $eid;
    }
}

// Einheit muss gewählt sein (unit_id ODER einheit_id aus Einheiten-System)
$current_unit_id = function_exists('get_current_unit_id') ? get_current_unit_id() : null;
$current_einheit_id = function_exists('get_current_einheit_id') ? get_current_einheit_id() : null;
if (!$current_unit_id && !$current_einheit_id) {
    header('Location: ../index.php');
    exit();
}
// Einheit für Abfragen: einheit_id hat Vorrang (Einheiten-System), sonst unit_id
$effective_unit_id = $current_einheit_id ?: $current_unit_id ?: 1;
// Sync: Wenn nur einheit_id gesetzt (z.B. von index.php), unit_id für Kompatibilität setzen
if (!$current_unit_id && $current_einheit_id) {
    $_SESSION['current_unit_id'] = $current_einheit_id;
}

// Dashboard-Einstellungen laden
$dashboard_preferences = [];
try {
    $stmt = $db->prepare("SELECT section_name, is_collapsed FROM dashboard_preferences WHERE user_id = ? AND COALESCE(unit_id, 1) = ?");
    $stmt->execute([$_SESSION['user_id'], $effective_unit_id]);
} catch (Exception $e) {
    try {
        $stmt = $db->prepare("SELECT section_name, is_collapsed FROM dashboard_preferences WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Exception $e2) { $stmt = null; }
}
if ($stmt) {
    $preferences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($preferences as $pref) {
        $dashboard_preferences[$pref['section_name']] = $pref['is_collapsed'];
    }
}

// Benutzer laden
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    echo '<script>window.location.href = "../login.php";</script>';
    exit();
}

// Systembenutzer haben keinen Dashboard-Zugriff
if (!empty($user['is_system_user'])) {
    $_SESSION['is_system_user'] = 1;
    echo '<script>window.location.href = "../formulare.php";</script>';
    exit();
}

// Sicherstellen, dass Berechtigungsspalten existieren (vor Berechtigungsprüfungen)
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_reservations TINYINT(1) DEFAULT 1");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_users TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_settings TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_vehicles TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_atemschutz TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_members TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}

// Berechtigungen prüfen
// Für Administratoren: Alle Berechtigungen automatisch aktivieren
$is_admin = !empty($user['is_admin']);
if ($is_admin) {
    // Admin hat alle Berechtigungen
    $can_reservations = true;
    $can_atemschutz = true;
    $can_settings = true;
    $can_members = true;
    $can_forms = true;
} else {
    // Für Nicht-Admins: Spezifische Berechtigungen prüfen
    $can_reservations = has_permission('reservations');
    $can_atemschutz = has_permission('atemschutz');
    $can_settings = has_permission('settings');
    $can_members = has_permission('members');
    $can_forms = has_permission('forms');
    $can_users = has_permission('users');
}

// Sicherstellen, dass Berechtigungsspalten existieren
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_reservations TINYINT(1) DEFAULT 1");
    $db->exec("ALTER TABLE users ADD COLUMN can_users TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_settings TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_vehicles TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_atemschutz TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_members TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalten existieren bereits, ignoriere Fehler
}

// Debug: Berechtigungen anzeigen
echo '<script>console.log("🔍 Dashboard Debug - Berechtigungen:");</script>';
echo '<script>console.log("can_reservations:", ' . json_encode($can_reservations) . ');</script>';
echo '<script>console.log("can_atemschutz:", ' . json_encode($can_atemschutz) . ');</script>';
echo '<script>console.log("can_settings:", ' . json_encode($can_settings) . ');</script>';
echo '<script>console.log("user_id:", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');</script>';

// Reservierungen laden (nur wenn berechtigt)
$pending_reservations = [];
$pending_room_reservations = [];
if ($can_reservations) {
    // Fahrzeug-Reservierungen (eigener try, damit Fehler Raum-Reservierungen nicht blockieren)
    // Filter: Reservierung ODER Fahrzeug muss zur Einheit passen (wie bei Raumreservierungen)
    try {
        try { $db->exec("ALTER TABLE reservations ADD COLUMN unit_id INT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE vehicles ADD COLUMN unit_id INT NULL"); } catch (Exception $e) {}
        $stmt = $db->prepare("
            SELECT r.*, v.name as vehicle_name
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            WHERE r.status = 'pending' 
            AND (COALESCE(r.einheit_id, r.unit_id, 1) = ? OR COALESCE(v.einheit_id, v.unit_id, 1) = ?)
            ORDER BY r.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$effective_unit_id, $effective_unit_id]);
        $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo '<script>console.log("❌ Fahrzeug-Reservierungen:", ' . json_encode($e->getMessage()) . ');</script>';
    }
    // Raum-Reservierungen (gefiltert nach Einheit: Reservation ODER Raum muss passen; NULL = Einheit 1)
    try {
        $stmt_room = $db->prepare("
            SELECT rr.*, ro.name as room_name
            FROM room_reservations rr
            JOIN rooms ro ON rr.room_id = ro.id
            WHERE rr.status = 'pending'
            AND (COALESCE(rr.einheit_id, 1) = ? OR COALESCE(ro.einheit_id, 1) = ?)
            ORDER BY rr.created_at DESC
            LIMIT 10
        ");
        $stmt_room->execute([$effective_unit_id, $effective_unit_id]);
        $pending_room_reservations = $stmt_room->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Dashboard room_reservations: " . $e->getMessage());
        echo '<script>console.log("❌ Raum-Reservierungen:", ' . json_encode($e->getMessage()) . ');</script>';
    }
    echo '<script>console.log("🔍 Reservierungen geladen:", ' . json_encode(count($pending_reservations) . ' Fahrzeuge, ' . count($pending_room_reservations) . ' Räume') . ');</script>';
    echo '<script>console.log("Raum-Reservierungen:", ' . json_encode($pending_room_reservations) . ');</script>';
}

// Divera-Empfänger-Gruppen und Standard für Genehmigung (nur Einheit der aktuellen Ansicht, kein globaler Fallback)
$divera_reservation_groups = [];
$divera_reservation_default_group_id = '';
$divera_reservation_enabled = false;
if ($can_reservations && $effective_unit_id > 0) {
    try {
        require_once __DIR__ . '/../includes/einheit-settings-helper.php';
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key IN ('divera_reservation_groups', 'divera_reservation_default_group_id', 'divera_reservation_enabled')");
        $stmt->execute([$effective_unit_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_reservation_groups' && $row['setting_value'] !== '') {
                $dec = json_decode($row['setting_value'], true);
                $divera_reservation_groups = is_array($dec) ? $dec : [];
            } elseif ($row['setting_key'] === 'divera_reservation_default_group_id') {
                $divera_reservation_default_group_id = trim((string)$row['setting_value']);
            } elseif ($row['setting_key'] === 'divera_reservation_enabled') {
                $divera_reservation_enabled = ($row['setting_value'] ?? '0') === '1';
            }
        }
    } catch (Exception $e) {}
}

// Atemschutzeintrag-Anträge laden (nur wenn berechtigt)
$atemschutz_entries = [];
$atemschutz_load_error = null;
if ($can_atemschutz) {
    try {
        // Tabellen erstellen (einzeln, Fehler blockieren nicht den Rest)
        try { $db->exec("CREATE TABLE IF NOT EXISTS atemschutz_traeger (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(255) NULL, birthdate DATE NOT NULL, strecke_am DATE NOT NULL, g263_am DATE NOT NULL, uebung_am DATE NOT NULL, status VARCHAR(50) NOT NULL DEFAULT 'Aktiv', member_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
        try { $db->exec("CREATE TABLE IF NOT EXISTS atemschutz_entries (id INT AUTO_INCREMENT PRIMARY KEY, entry_type ENUM('einsatz', 'uebung', 'atemschutzstrecke', 'g263') NOT NULL, entry_date DATE NOT NULL, requester_id INT NULL, status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending', rejection_reason TEXT NULL, approved_by INT NULL, approved_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
        try { $db->exec("CREATE TABLE IF NOT EXISTS atemschutz_entry_traeger (id INT AUTO_INCREMENT PRIMARY KEY, entry_id INT NOT NULL, traeger_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_entry_traeger (entry_id, traeger_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
        
                    // Spalten einheit_id/unit_id sicherstellen (für Abfrage)
                    try { $db->exec("ALTER TABLE atemschutz_entries ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE atemschutz_entries ADD COLUMN unit_id INT NULL"); } catch (Exception $e) {}
                    // Einfache Abfrage (wie Debug-API) – ohne JOINs, die Fehler verursachen könnten
                    $stmt = $db->prepare("
                        SELECT id, entry_type, entry_date, status, requester_id, created_at
                        FROM atemschutz_entries
                        WHERE status = 'pending'
                        AND (COALESCE(einheit_id, unit_id, 1) = ?)
                        ORDER BY created_at DESC
                    ");
        $stmt->execute([$effective_unit_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $atemschutz_entries = [];
        foreach ($rows as $row) {
            $eid = (int)$row['id'];
            $traeger_names = '';
            $traeger_count = 0;
            try {
                $st = $db->prepare("
                    SELECT at.first_name, at.last_name FROM atemschutz_entry_traeger aet
                    LEFT JOIN atemschutz_traeger at ON aet.traeger_id = at.id
                    WHERE aet.entry_id = ?
                ");
                $st->execute([$eid]);
                $tr = $st->fetchAll(PDO::FETCH_ASSOC);
                $traeger_count = count($tr);
                $traeger_names = implode(', ', array_map(function($t) { return trim(($t['first_name']??'').' '.($t['last_name']??'')); }, $tr));
            } catch (Exception $e2) {}
            $first_name = 'Unbekannt';
            $last_name = '';
            if (!empty($row['requester_id'])) {
                try {
                    $st = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $st->execute([$row['requester_id']]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if ($u) { $first_name = $u['first_name'] ?? 'Unbekannt'; $last_name = $u['last_name'] ?? ''; }
                } catch (Exception $e2) {}
            }
            $atemschutz_entries[] = array_merge($row, ['first_name' => $first_name, 'last_name' => $last_name, 'traeger_names' => $traeger_names, 'traeger_count' => $traeger_count]);
        }
        
        error_log("Atemschutzeintrag-Anträge geladen: " . count($atemschutz_entries) . " (effective_unit_id=" . $effective_unit_id . ")");
    } catch (Exception $e) {
        error_log("Fehler beim Laden der Atemschutzeintrag-Anträge: " . $e->getMessage());
        $atemschutz_load_error = $e->getMessage();
    }
}

// RIC-Genehmigungen laden (nur für Divera Admin)
$pending_ric_approvals = [];
$is_divera_admin = false;
try {
    // Prüfe ob aktueller Benutzer Divera Admin ist
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ric_divera_admin_user_id' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    if ($result) {
        $divera_admin_user_id = (int)$result;
        $is_divera_admin = ($divera_admin_user_id && $_SESSION['user_id'] == $divera_admin_user_id);
    }
    
    if ($is_divera_admin) {
        // Lade offene RIC-Genehmigungen (sowohl add als auch remove)
        $stmt = $db->prepare("
            SELECT mr.id as assignment_id,
                   mr.member_id,
                   mr.ric_id,
                   mr.status,
                   mr.action,
                   mr.created_at,
                   mr.created_by,
                   m.first_name as member_first_name,
                   m.last_name as member_last_name,
                   rc.kurztext as ric_kurztext,
                   rc.beschreibung as ric_beschreibung,
                   u.first_name as created_by_first_name,
                   u.last_name as created_by_last_name,
                   u.email as created_by_email
            FROM member_ric mr
            JOIN members m ON mr.member_id = m.id
            JOIN ric_codes rc ON mr.ric_id = rc.id
            LEFT JOIN users u ON mr.created_by = u.id
            WHERE mr.status = 'pending'
            ORDER BY mr.created_at DESC
        ");
        $stmt->execute();
        $pending_ric_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden der RIC-Genehmigungen: " . $e->getMessage());
}

// Atemschutz-Warnungen laden (nur wenn berechtigt)
$atemschutz_warnings = [];
if ($can_atemschutz) {
    try {
        // Sicherstellen, dass Tabelle existiert
        $db->exec("
            CREATE TABLE IF NOT EXISTS atemschutz_traeger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NULL,
                birthdate DATE NOT NULL,
                strecke_am DATE NOT NULL,
                g263_am DATE NOT NULL,
                uebung_am DATE NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'Aktiv',
                member_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // member_id Spalte hinzufügen falls nicht vorhanden
        try {
            $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
        } catch (Exception $e) {
            // Spalte existiert bereits, ignoriere Fehler
        }
        // unit_id / einheit_id für Einheiten-Filter (falls nicht vorhanden)
        try { $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN unit_id INT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
        
        // Warnschwelle laden (einheitsspezifisch aus Einstellungen)
        require_once __DIR__ . '/../includes/einheit-settings-helper.php';
        $warn_days = get_atemschutz_warn_days($db, $effective_unit_id);
        
        $warn_date = date('Y-m-d', strtotime("+{$warn_days} days"));
        
        // Lade alle aktiven Geräteträger der Einheit (Fallback: alle wenn unit_id/einheit_id fehlen)
        $all_traeger = [];
        try {
            $stmt = $db->prepare("
                SELECT t.*, 
                       el.sent_at as last_email_sent
                FROM atemschutz_traeger t
                LEFT JOIN (
                    SELECT traeger_id, sent_at,
                           ROW_NUMBER() OVER (PARTITION BY traeger_id ORDER BY sent_at DESC) as rn
                    FROM email_log
                ) el ON t.id = el.traeger_id AND el.rn = 1
                WHERE t.status = 'Aktiv' AND (COALESCE(t.unit_id, t.einheit_id, 1) = ?)
                ORDER BY t.last_name ASC, t.first_name ASC
            ");
            $stmt->execute([$effective_unit_id]);
            $all_traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            try {
                $stmt = $db->query("
                    SELECT t.*, NULL as last_email_sent
                    FROM atemschutz_traeger t
                    WHERE t.status = 'Aktiv'
                    ORDER BY t.last_name ASC, t.first_name ASC
                ");
                $all_traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {}
        }
        
        // Filtere nur die wirklich auffälligen Geräteträger
        $atemschutz_warnings = [];
        foreach ($all_traeger as $traeger) {
            $now = new DateTime('today');
            $streckeExpired = false; $g263Expired = false; $uebungExpired = false;
            $streckeWarn = false; $g263Warn = false; $uebungWarn = false;
            
            // Prüfe Strecke (1 Jahr Gültigkeit)
            $streckeAm = new DateTime($traeger['strecke_am']);
            $streckeBis = clone $streckeAm;
            $streckeBis->add(new DateInterval('P1Y'));
            $diff = (int)$now->diff($streckeBis)->format('%r%a');
            if ($diff < 0) {
                $streckeExpired = true;
            } elseif ($diff <= $warn_days && $diff >= 0) {
                $streckeWarn = true;
            }
            
            // Prüfe G26.3 (3 Jahre unter 50, 1 Jahr über 50)
            $g263Am = new DateTime($traeger['g263_am']);
            $birthdate = new DateTime($traeger['birthdate']);
            $age = $birthdate->diff(new DateTime())->y;
            
            $g263Bis = clone $g263Am;
            if ($age < 50) {
                $g263Bis->add(new DateInterval('P3Y'));
            } else {
                $g263Bis->add(new DateInterval('P1Y'));
            }
            
            $diff = (int)$now->diff($g263Bis)->format('%r%a');
            if ($diff < 0) {
                $g263Expired = true;
            } elseif ($diff <= $warn_days && $diff >= 0) {
                $g263Warn = true;
            }
            
            // Prüfe Übung (1 Jahr Gültigkeit)
            $uebungAm = new DateTime($traeger['uebung_am']);
            $uebungBis = clone $uebungAm;
            $uebungBis->add(new DateInterval('P1Y'));
            
            $diff = (int)$now->diff($uebungBis)->format('%r%a');
            if ($diff < 0) {
                $uebungExpired = true;
            } elseif ($diff <= $warn_days && $diff >= 0) {
                $uebungWarn = true;
            }
            
            // Status berechnen
            $status = 'Tauglich';
            if ($streckeExpired || $g263Expired || $uebungExpired) {
                if ($uebungExpired && !$streckeExpired && !$g263Expired) {
                    $status = 'Übung abgelaufen';
                } else {
                    $status = 'Abgelaufen';
                }
            } elseif ($streckeWarn || $g263Warn || $uebungWarn) {
                $status = 'Warnung';
            }
            
            // Nur auffällige Geräteträger hinzufügen (alle außer Tauglich)
            if ($status !== 'Tauglich') {
                $traeger['calculated_status'] = $status;
                $atemschutz_warnings[] = $traeger;
            }
        }
    } catch (Exception $e) {
        // Fehler ignorieren
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .bis-badge { 
            padding: .25rem .5rem; 
            border-radius: .375rem; 
            display: inline-block; 
        }
        .bis-warn { 
            background-color: #fff3cd; 
            color: #664d03; 
        }
        .bis-expired { 
            background-color: #dc3545; 
            color: #fff; 
        }
        .warning-item {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .warning-header {
            display: flex;
            justify-content-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        .warning-name {
            font-weight: 600;
            color: #495057;
            margin: 0;
        }
        .warning-reasons {
            margin-top: 0.5rem;
        }
        .warning-reason {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 0.375rem;
        }
        .reason-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }
        .reason-details {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .reason-date {
            font-size: 0.875rem;
            color: #495057;
        }
    </style>
</head>
<body>
    <!-- Details Modal für Reservierungen -->
    <div class="modal fade" id="reservationDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Reservierungsdetails
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3" id="modalResourceLabel">
                                <i class="fas fa-truck me-2"></i>Fahrzeug
                            </h6>
                            <p id="modalResourceName" class="mb-3"></p>
                            
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-user me-2"></i>Antragsteller
                            </h6>
                            <p id="modalRequesterName" class="mb-3"></p>
                            <p id="modalRequesterEmail" class="text-muted mb-3"></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-calendar me-2"></i>Zeitraum
                            </h6>
                            <p id="modalDateTime" class="mb-3"></p>
                            
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-map-marker-alt me-2"></i>Standort
                            </h6>
                            <p id="modalLocation" class="mb-3"></p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-clipboard-list me-2"></i>Grund
                            </h6>
                            <p id="modalReason" class="mb-3"></p>
                            
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-info-circle me-2"></i>Status
                            </h6>
                            <p id="modalStatus" class="mb-3"></p>
                        </div>
                    </div>
                    <?php if ($divera_reservation_enabled && !empty($divera_reservation_groups)): ?>
                    <hr>
                    <div class="mb-3">
                        <label for="diveraGroupSelect" class="form-label">
                            <i class="fas fa-users me-1"></i>Empfänger-Gruppe (Divera 24/7)
                        </label>
                        <select class="form-select" id="diveraGroupSelect">
                            <option value="">– Keine Vorauswahl –</option>
                            <?php foreach ($divera_reservation_groups as $g): 
                                $gid = (int)($g['id'] ?? 0);
                                $gval = $gid > 0 ? (string)$gid : '0';
                                $gname = htmlspecialchars($g['name'] ?? ($gid > 0 ? 'Gruppe ' . $gid : 'Alle des Standortes'));
                            ?>
                            <option value="<?php echo $gval; ?>" <?php echo $divera_reservation_default_group_id === $gval ? 'selected' : ''; ?>>
                                <?php echo $gname; ?><?php echo $gid > 0 ? ' (ID: ' . $gid . ')' : ' (keine Gruppen-ID)'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">An welche Divera-Gruppe(n) der Termin gesendet werden soll.</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Schließen
                    </button>
                    <button type="button" class="btn btn-danger" id="rejectBtn" onclick="showRejectModal()">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                    <button type="button" class="btn btn-success" id="approveBtn" onclick="approveReservation()">
                        <i class="fas fa-check me-1"></i>Genehmigen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Ablehnungsgrund Modal -->
    <div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times-circle me-2"></i>Reservierung ablehnen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Hinweis:</strong> Bitte geben Sie einen Grund für die Ablehnung an. Dieser wird dem Antragsteller per E-Mail mitgeteilt.
                    </div>
                    
                    <div class="mb-3">
                        <label for="rejectReason" class="form-label">
                            <i class="fas fa-comment me-1"></i>Ablehnungsgrund
                        </label>
                        <textarea class="form-control" id="rejectReason" rows="4" placeholder="Grund für die Ablehnung eingeben..."></textarea>
                        <div class="form-text">Der Grund wird dem Antragsteller per E-Mail übermittelt.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn" onclick="confirmReject()">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Erfolgs-Modal (ersetzt Browser-Alert) -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Erfolgreich
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body pt-4">
                    <div id="successModalBody">
                        <!-- Inhalt wird per JS eingefügt -->
                    </div>
                    <p class="text-muted small mb-0 mt-3">
                        <i class="fas fa-sync-alt fa-spin me-1"></i>Seite wird in Kürze neu geladen…
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Konflikt-Warnung Modal -->
    <div class="modal fade" id="conflictWarningModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Konflikte gefunden
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warnung:</strong> Die Genehmigung dieser Reservierung würde zu Konflikten mit bestehenden Reservierungen führen.
                    </div>
                    
                    <p>Folgende Reservierungen würden <strong>storniert</strong> werden:</p>
                    
                    <div id="conflictList" class="mb-3">
                        <!-- Konflikte werden hier dynamisch eingefügt -->
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Hinweis:</strong> Die stornierten Antragsteller erhalten eine E-Mail-Benachrichtigung über die Stornierung.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-warning" id="confirmConflictResolutionBtn" onclick="confirmConflictResolution()">
                        <i class="fas fa-check me-1"></i>Bestätigen und Genehmigen
                    </button>
                </div>
            </div>
        </div>
    </div>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>


    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">
                <i class="fas fa-tachometer-alt"></i> Dashboard
                <small class="text-muted">Willkommen, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</small>
            </h1>
        </div>

        <!-- Feedback-Statistiken (nur für Admins) -->
        <?php if ($can_settings): ?>
        <?php
        // Feedback-Statistiken laden
        $feedback_stats = [];
        try {
            $stmt = $db->query("SELECT status, COUNT(*) as count FROM feedback GROUP BY status");
            $feedback_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            // Tabelle existiert möglicherweise noch nicht
        }
        ?>
        
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header dashboard-section-header" data-section="feedback" style="cursor: pointer;">
                        <h6 class="m-0 font-weight-bold text-info">
                            <i class="fas fa-chevron-down collapse-icon" data-section="feedback"></i>
                            <i class="fas fa-comment-dots"></i> Feedback-Übersicht
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="feedback" <?php echo (isset($dashboard_preferences['feedback']) && $dashboard_preferences['feedback']) ? 'style="display: none;"' : ''; ?>>
                        <div class="row text-center">
                            <div class="col-md-3">
                                <a href="feedback.php?status=new" class="text-decoration-none">
                                    <div class="border rounded p-3 hover-card" style="cursor: pointer; transition: all 0.2s;">
                                        <h4 class="text-primary mb-1"><?php echo $feedback_stats['new'] ?? 0; ?></h4>
                                        <small class="text-muted">Neue</small>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="feedback.php?status=in_progress" class="text-decoration-none">
                                    <div class="border rounded p-3 hover-card" style="cursor: pointer; transition: all 0.2s;">
                                        <h4 class="text-warning mb-1"><?php echo $feedback_stats['in_progress'] ?? 0; ?></h4>
                                        <small class="text-muted">In Bearbeitung</small>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="feedback.php?status=resolved" class="text-decoration-none">
                                    <div class="border rounded p-3 hover-card" style="cursor: pointer; transition: all 0.2s;">
                                        <h4 class="text-success mb-1"><?php echo $feedback_stats['resolved'] ?? 0; ?></h4>
                                        <small class="text-muted">Gelöst</small>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="feedback.php?status=closed" class="text-decoration-none">
                                    <div class="border rounded p-3 hover-card" style="cursor: pointer; transition: all 0.2s;">
                                        <h4 class="text-secondary mb-1"><?php echo $feedback_stats['closed'] ?? 0; ?></h4>
                                        <small class="text-muted">Geschlossen</small>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reservierungen Bereich -->
        <?php if ($can_reservations): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="reservations" style="cursor: pointer;">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-chevron-down collapse-icon" data-section="reservations"></i>
                            <i class="fas fa-calendar"></i> Offene Reservierungen (<?php echo count($pending_reservations) + count($pending_room_reservations); ?>)
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="reservations" <?php echo (isset($dashboard_preferences['reservations']) && $dashboard_preferences['reservations']) ? 'style="display: none;"' : ''; ?>>
                        <?php if (empty($pending_reservations) && empty($pending_room_reservations)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine offenen Reservierungen</h5>
                                <p class="text-muted">Alle Reservierungen wurden bearbeitet.</p>
                            </div>
                        <?php else: ?>
                            <!-- Mobile-optimierte Karten-Ansicht -->
                            <div class="d-md-none">
                                <?php foreach ($pending_reservations as $reservation): $reservation['_type'] = 'vehicle'; ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="fas fa-truck text-primary"></i>
                                                    <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                                </h6>
                                                <span class="badge bg-warning text-dark">
                                                    Ausstehend
                                                </span>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-calendar-alt text-success"></i>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-user text-info"></i>
                                                <span><?php echo htmlspecialchars($reservation['requester_name']); ?></span>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <i class="fas fa-clipboard-list text-warning"></i>
                                                <span><?php echo htmlspecialchars(substr($reservation['reason'], 0, 80)); ?><?php echo strlen($reservation['reason']) > 80 ? '...' : ''; ?></span>
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button class="btn btn-outline-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                                    <i class="fas fa-eye"></i> Details anzeigen
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($pending_room_reservations as $reservation): $reservation['_type'] = 'room'; ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="fas fa-door-open text-info"></i>
                                                    <?php echo htmlspecialchars($reservation['room_name']); ?>
                                                </h6>
                                                <span class="badge bg-warning text-dark">Ausstehend</span>
                                            </div>
                                            <div class="mb-2">
                                                <i class="fas fa-calendar-alt text-success"></i>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> -
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </div>
                                            <div class="mb-2">
                                                <i class="fas fa-user text-info"></i>
                                                <span><?php echo htmlspecialchars($reservation['requester_name']); ?></span>
                                            </div>
                                            <div class="mb-3">
                                                <i class="fas fa-clipboard-list text-warning"></i>
                                                <span><?php echo htmlspecialchars(substr($reservation['reason'], 0, 80)); ?><?php echo strlen($reservation['reason']) > 80 ? '...' : ''; ?></span>
                                            </div>
                                            <div class="d-grid">
                                                <button class="btn btn-outline-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                                    <i class="fas fa-eye"></i> Details anzeigen
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Desktop-Tabellen-Ansicht -->
                            <div class="d-none d-md-block">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                        <tr>
                                            <th>Fahrzeug</th>
                                            <th>Antragsteller</th>
                                            <th>Datum/Zeit</th>
                                            <th>Grund</th>
                                            <th>Status</th>
                                            <th>Aktionen</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($pending_reservations as $reservation): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-truck text-primary"></i>
                                                <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($reservation['requester_name']); ?></td>
                                            <td>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong><br>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($reservation['reason'], 0, 50)); ?><?php echo strlen($reservation['reason']) > 50 ? '...' : ''; ?></td>
                                            <td><span class="badge bg-warning text-dark">Ausstehend</span></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                                    <i class="fas fa-eye"></i> Details
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php foreach ($pending_room_reservations as $reservation): $reservation['_type'] = 'room'; ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-door-open text-info"></i>
                                                <?php echo htmlspecialchars($reservation['room_name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($reservation['requester_name']); ?></td>
                                            <td>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong><br>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> -
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($reservation['reason'], 0, 50)); ?><?php echo strlen($reservation['reason']) > 50 ? '...' : ''; ?></td>
                                            <td><span class="badge bg-warning text-dark">Ausstehend</span></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                                    <i class="fas fa-eye"></i> Details
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
                        </div>
        <?php endif; ?>

        <!-- RIC-Genehmigungen Bereich (nur für Divera Admin) -->
        <?php if ($is_divera_admin): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="ric_approvals" style="cursor: pointer;">
                        <h6 class="m-0 font-weight-bold text-warning">
                            <i class="fas fa-chevron-down collapse-icon" data-section="ric_approvals"></i>
                            <i class="fas fa-broadcast-tower"></i> Offene RIC-Genehmigungen (<?php echo count($pending_ric_approvals); ?>)
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="ric_approvals" <?php echo (isset($dashboard_preferences['ric_approvals']) && $dashboard_preferences['ric_approvals']) ? 'style="display: none;"' : ''; ?>>
                        <?php if (empty($pending_ric_approvals)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine offenen Genehmigungen</h5>
                                <p class="text-muted">Alle RIC-Zuweisungen wurden bestätigt.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php 
                                // Gruppiere nach Mitglied
                                $grouped_approvals = [];
                                foreach ($pending_ric_approvals as $approval) {
                                    $member_key = $approval['member_id'];
                                    if (!isset($grouped_approvals[$member_key])) {
                                        $grouped_approvals[$member_key] = [
                                            'member_id' => $approval['member_id'],
                                            'member_name' => $approval['member_first_name'] . ' ' . $approval['member_last_name'],
                                            'created_by' => $approval['created_by_first_name'] . ' ' . $approval['created_by_last_name'],
                                            'created_by_email' => $approval['created_by_email'],
                                            'created_at' => $approval['created_at'],
                                            'rics' => []
                                        ];
                                    }
                                    $action = $approval['action'] ?? 'add';
                                    // Debug: Prüfe ob action korrekt gelesen wird
                                    if (empty($approval['action']) || $approval['action'] === null) {
                                        error_log("Dashboard: action ist leer für assignment_id=" . $approval['assignment_id'] . ", verwende 'add' als Fallback");
                                    } else {
                                        error_log("Dashboard: action=" . $action . " für assignment_id=" . $approval['assignment_id']);
                                    }
                                    $grouped_approvals[$member_key]['rics'][] = [
                                        'assignment_id' => $approval['assignment_id'],
                                        'ric_id' => $approval['ric_id'],
                                        'ric_kurztext' => $approval['ric_kurztext'],
                                        'ric_beschreibung' => $approval['ric_beschreibung'],
                                        'action' => $action
                                    ];
                                }
                                ?>
                                <?php foreach ($grouped_approvals as $group): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card border-warning">
                                        <div class="card-body">
                                            <h6 class="card-title">
                                                <i class="fas fa-user text-primary"></i>
                                                <?php echo htmlspecialchars($group['member_name']); ?>
                                            </h6>
                                            <p class="card-text mb-2">
                                                <small class="text-muted">
                                                    <strong>Geändert von:</strong> <?php echo htmlspecialchars($group['created_by']); ?><br>
                                                    <strong>Am:</strong> <?php echo date('d.m.Y H:i', strtotime($group['created_at'])); ?>
                                                </small>
                                            </p>
                                            <p class="card-text mb-2">
                                                <strong>RIC-Codes:</strong><br>
                                                <?php foreach ($group['rics'] as $ric): ?>
                                                    <?php $is_removed = ($ric['action'] === 'remove'); ?>
                                                    <div class="d-inline-flex align-items-center me-2 mb-1">
                                                        <span class="badge bg-warning text-dark">
                                                            <?php if ($is_removed): ?>
                                                                <span style="text-decoration: line-through;"><?php echo htmlspecialchars($ric['ric_kurztext']); ?></span>
                                                                <small>(Entfernung)</small>
                                                            <?php else: ?>
                                                                <?php echo htmlspecialchars($ric['ric_kurztext']); ?>
                                                                <small>(Hinzufügung)</small>
                                                            <?php endif; ?>
                                                        </span>
                                                        <form method="POST" action="ric-verwaltung.php" class="ms-1" onsubmit="return confirm('Möchten Sie diese Änderung bestätigen?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                            <input type="hidden" name="assignment_id" value="<?php echo $ric['assignment_id']; ?>">
                                                            <button type="submit" name="confirm_assignment" class="btn btn-sm btn-success" style="padding: 0 6px; font-size: 0.75rem;" title="Bestätigen">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endforeach; ?>
                                            </p>
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                <form method="POST" action="ric-verwaltung.php" onsubmit="return confirm('Möchten Sie wirklich alle RIC-Änderungen für dieses Mitglied bestätigen?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                    <input type="hidden" name="confirm_member_assignments" value="1">
                                                    <input type="hidden" name="member_id" value="<?php echo (int)$group['member_id']; ?>">
                                                    <input type="hidden" name="redirect_to_dashboard" value="1">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check-double me-1"></i>Alle RICs für dieses Mitglied bestätigen
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Atemschutz Bereich -->
        <?php if ($can_atemschutz): ?>
        
        <!-- Atemschutzeintrag-Anträge -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="atemschutz" style="cursor: pointer;">
                        <h6 class="m-0 font-weight-bold text-info">
                            <i class="fas fa-chevron-down collapse-icon" data-section="atemschutz"></i>
                            <i class="fas fa-clipboard-list"></i> Offene Atemschutzeinträge (<?php echo count($atemschutz_entries); ?>)<?php if ($can_atemschutz && empty($atemschutz_entries)): ?><small class="text-muted ms-1">[Einheit <?php echo (int)$effective_unit_id; ?>]</small><?php endif; ?>
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="atemschutz" <?php echo (isset($dashboard_preferences['atemschutz']) && $dashboard_preferences['atemschutz']) ? 'style="display: none;"' : ''; ?>>
                        <?php if (empty($atemschutz_entries)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine offenen Anträge</h5>
                                <p class="text-muted">Alle Atemschutzeintrag-Anträge wurden bearbeitet.</p>
                                <?php if (!empty($atemschutz_load_error) && hasAdminPermission()): ?>
                                <p class="small text-danger mt-2">Fehler: <?php echo htmlspecialchars($atemschutz_load_error); ?></p>
                                <?php endif; ?>
                                <p class="small mt-2"><a href="../api/debug-atemschutz-entries.php?einheit_id=<?php echo (int)$effective_unit_id; ?>" target="_blank" class="text-muted">Diagnose: Pending-Einträge prüfen</a></p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($atemschutz_entries as $entry): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card border-info">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title text-dark mb-0">
                                                    <?php
                                                    $type_names = [
                                                        'einsatz' => 'Einsatz',
                                                        'uebung' => 'Übung',
                                                        'atemschutzstrecke' => 'Atemschutzstrecke',
                                                        'g263' => 'G26.3'
                                                    ];
                                                    echo $type_names[$entry['entry_type']] ?? $entry['entry_type'];
                                                    ?>
                                                </h6>
                                                <span class="badge bg-info">Antrag</span>
                                            </div>
                                            
                                            <p class="card-text mb-2">
                                                <strong>Datum:</strong><br>
                                                <?php echo date('d.m.Y', strtotime($entry['entry_date'])); ?>
                                            </p>
                                            
                                            <?php if ($entry['traeger_count'] == 1): ?>
                                            <p class="card-text mb-3">
                                                <strong>Geräteträger:</strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($entry['traeger_names'] ?? 'Keine'); ?></small>
                                            </p>
                                            <?php else: ?>
                                            <p class="card-text mb-3">
                                                <strong>Geräteträger:</strong><br>
                                                <small class="text-muted"><?php echo $entry['traeger_count']; ?> Geräteträger - siehe Details</small>
                                            </p>
                                            <?php endif; ?>
                                            
                                            <div class="d-grid gap-2">
                                                <?php if ($entry['traeger_count'] == 1): ?>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-success btn-sm" onclick="approveAtemschutzEntry(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-check me-1"></i>Genehmigen
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="showAtemschutzRejectModal(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-times me-1"></i>Ablehnen
                                                    </button>
                                                </div>
                                                <?php else: ?>
                                                <button class="btn btn-outline-info btn-sm" onclick="showAtemschutzEntryDetails(<?php echo $entry['id']; ?>)">
                                                    <i class="fas fa-eye me-1"></i>Details anzeigen
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Auffällige Geräteträger -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="atemschutz_warnings" style="cursor: pointer;">
                        <h6 class="m-0 font-weight-bold text-white">
                            <i class="fas fa-chevron-down collapse-icon" data-section="atemschutz_warnings"></i>
                            <i class="fas fa-user-shield"></i> Auffällige Geräteträger (<?php echo count($atemschutz_warnings); ?>)
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="atemschutz_warnings" <?php echo (isset($dashboard_preferences['atemschutz_warnings']) && $dashboard_preferences['atemschutz_warnings']) ? 'style="display: none;"' : ''; ?>>
                        <?php if (empty($atemschutz_warnings)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Alle Geräteträger sind aktuell</h5>
                                <p class="text-muted">Keine Warnungen oder abgelaufenen Zertifikate.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($atemschutz_warnings as $traeger): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="warning-item">
                                        <div class="warning-header">
                                            <h6 class="warning-name"><?php echo htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']); ?></h6>
                                            <?php if (!empty($traeger['last_email_sent'])): ?>
                                                <div class="email-status">
                                                    <small class="text-muted">
                                                        <i class="fas fa-envelope-open"></i> 
                                                        Letzte E-Mail: <?php echo date('d.m.Y', strtotime($traeger['last_email_sent'])); ?>
                                                    </small>
                                                </div>
                                            <?php else: ?>
                                                <div class="email-status">
                                                    <small class="text-muted">
                                                        <i class="fas fa-envelope"></i> 
                                                        Noch keine E-Mail gesendet
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                            <div class="email-buttons">
                                                <?php
                                                // Status für jeden Zertifikatstyp bestimmen
                                                $now = new DateTime('today');
                                                
                                                // Strecke Status
                                                $streckeAm = new DateTime($traeger['strecke_am']);
                                                $streckeBis = clone $streckeAm;
                                                $streckeBis->add(new DateInterval('P1Y'));
                                                $streckeDiff = (int)$now->diff($streckeBis)->format('%r%a');
                                                $streckeUrgency = ($streckeDiff < 0) ? 'abgelaufen' : 'warnung';
                                                $streckeClass = ($streckeDiff < 0) ? 'bis-expired' : (($streckeDiff <= $warn_days && $streckeDiff >= 0) ? 'bis-warn' : '');
                                                
                                                // G26.3 Status
                                                $g263Am = new DateTime($traeger['g263_am']);
                                                $birthdate = new DateTime($traeger['birthdate']);
                                                $age = $birthdate->diff(new DateTime())->y;
                                                $g263Bis = clone $g263Am;
                                                if ($age < 50) {
                                                    $g263Bis->add(new DateInterval('P3Y'));
                                                } else {
                                                    $g263Bis->add(new DateInterval('P1Y'));
                                                }
                                                $g263Diff = (int)$now->diff($g263Bis)->format('%r%a');
                                                $g263Urgency = ($g263Diff < 0) ? 'abgelaufen' : 'warnung';
                                                $g263Class = ($g263Diff < 0) ? 'bis-expired' : (($g263Diff <= $warn_days && $g263Diff >= 0) ? 'bis-warn' : '');
                                                
                                                // Übung Status
                                                $uebungAm = new DateTime($traeger['uebung_am']);
                                                $uebungBis = clone $uebungAm;
                                                $uebungBis->add(new DateInterval('P1Y'));
                                                $uebungDiff = (int)$now->diff($uebungBis)->format('%r%a');
                                                $uebungUrgency = ($uebungDiff < 0) ? 'abgelaufen' : 'warnung';
                                                $uebungClass = ($uebungDiff < 0) ? 'bis-expired' : (($uebungDiff <= $warn_days && $uebungDiff >= 0) ? 'bis-warn' : '');
                                                
                                                // Bestimme den höchsten Prioritätsstatus (abgelaufen > warnung)
                                                $hasExpired = ($streckeDiff < 0 || $g263Diff < 0 || $uebungDiff < 0);
                                                $hasWarning = ($streckeDiff <= $warn_days || $g263Diff <= $warn_days || $uebungDiff <= $warn_days);
                                                
                                                $buttonClass = $hasExpired ? 'btn-danger' : 'btn-warning';
                                                $buttonText = $hasExpired ? 'E-Mail senden (Aufforderung)' : 'E-Mail senden (Erinnerung)';
                                                
                                                // Sammle alle problematischen Zertifikate
                                                $problematicCertificates = [];
                                                if ($streckeDiff < 0 || ($streckeDiff <= $warn_days && $streckeDiff >= 0)) {
                                                    $problematicCertificates[] = [
                                                        'type' => 'strecke',
                                                        'urgency' => $streckeUrgency,
                                                        'expiry_date' => $streckeBis->format('d.m.Y')
                                                    ];
                                                }
                                                if ($g263Diff < 0 || ($g263Diff <= $warn_days && $g263Diff >= 0)) {
                                                    $problematicCertificates[] = [
                                                        'type' => 'g263',
                                                        'urgency' => $g263Urgency,
                                                        'expiry_date' => $g263Bis->format('d.m.Y')
                                                    ];
                                                }
                                                if ($uebungDiff < 0 || ($uebungDiff <= $warn_days && $uebungDiff >= 0)) {
                                                    $problematicCertificates[] = [
                                                        'type' => 'uebung',
                                                        'urgency' => $uebungUrgency,
                                                        'expiry_date' => $uebungBis->format('d.m.Y')
                                                    ];
                                                }
                                                ?>
                                                
                                                <button class="btn btn-sm <?php echo $buttonClass; ?> email-btn" 
                                                        data-traeger-id="<?php echo $traeger['id']; ?>"
                                                        data-traeger-name="<?php echo htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']); ?>"
                                                        data-email="<?php echo htmlspecialchars($traeger['email'] ?? ''); ?>"
                                                        data-certificates="<?php echo htmlspecialchars(json_encode($problematicCertificates)); ?>"
                                                        title="<?php echo $buttonText; ?>">
                                                    <i class="fas fa-envelope"></i> <?php echo $buttonText; ?>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="warning-reasons">
                                            <?php
                                            $now = new DateTime('today');
                                            
                                            // Strecke anzeigen
                                            $streckeAm = new DateTime($traeger['strecke_am']);
                                            $streckeBis = clone $streckeAm;
                                            $streckeBis->add(new DateInterval('P1Y'));
                                            $diff = (int)$now->diff($streckeBis)->format('%r%a');
                                            $cls = '';
                                            if ($diff < 0) {
                                                $cls = 'bis-expired';
                                            } elseif ($diff <= $warn_days && $diff >= 0) {
                                                $cls = 'bis-warn';
                                            }
                                            ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">Strecke</span>
                                                <div class="reason-details">
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo $streckeBis->format('d.m.Y'); ?></span>
                    </div>
                                            </div>
                                            
                                            <?php
                                            // G26.3 anzeigen
                                            $g263Am = new DateTime($traeger['g263_am']);
                                            $birthdate = new DateTime($traeger['birthdate']);
                                            $age = $birthdate->diff(new DateTime())->y;
                                            
                                            $g263Bis = clone $g263Am;
                                            if ($age < 50) {
                                                $g263Bis->add(new DateInterval('P3Y'));
                                            } else {
                                                $g263Bis->add(new DateInterval('P1Y'));
                                            }
                                            
                                            $diff = (int)$now->diff($g263Bis)->format('%r%a');
                                            $cls = '';
                                            if ($diff < 0) {
                                                $cls = 'bis-expired';
                                            } elseif ($diff <= $warn_days && $diff >= 0) {
                                                $cls = 'bis-warn';
                                            }
                                            ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">G26.3</span>
                                                <div class="reason-details">
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo $g263Bis->format('d.m.Y'); ?></span>
                                                </div>
                                            </div>
                                            
                                            <?php
                                            // Übung anzeigen
                                            $uebungAm = new DateTime($traeger['uebung_am']);
                                            $uebungBis = clone $uebungAm;
                                            $uebungBis->add(new DateInterval('P1Y'));
                                            
                                            $diff = (int)$now->diff($uebungBis)->format('%r%a');
                                            $cls = '';
                                            if ($diff < 0) {
                                                $cls = 'bis-expired';
                                            } elseif ($diff <= $warn_days && $diff >= 0) {
                                                $cls = 'bis-warn';
                                            }
                                            ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">Übung/Einsatz</span>
                                                <div class="reason-details">
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo $uebungBis->format('d.m.Y'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal: E-Mail-Adresse eingeben -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope me-2"></i>E-Mail senden
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Geräteträger</label>
                        <input type="text" class="form-control" id="modalTraegerName" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="modalEmail" class="form-label">E-Mail-Adresse <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="modalEmail" placeholder="max.mustermann@feuerwehr.de" required>
                        <div class="form-text">Die E-Mail-Adresse wird für diesen Geräteträger gespeichert.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-Mail-Inhalt</label>
                        <div class="border rounded p-3 bg-light">
                            <div class="mb-2">
                                <label class="form-label small">Betreff <span class="text-muted">(editierbar)</span></label>
                                <input type="text" class="form-control form-control-sm" id="emailSubject" placeholder="E-Mail-Betreff wird hier geladen...">
                            </div>
                            <div>
                                <label class="form-label small">Nachricht <span class="text-muted">(editierbar)</span></label>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span></span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="resetEmailBtn">
                                        <i class="fas fa-undo"></i> Zurücksetzen
                                    </button>
                                </div>
                                <textarea class="form-control" id="emailBody" rows="8" placeholder="E-Mail-Inhalt wird hier geladen..."></textarea>
                                <div class="form-text">
                                    <strong>Verfügbare Platzhalter:</strong>
                                    <code>{first_name}</code> <code>{last_name}</code> <code>{expiry_date}</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-primary" id="sendEmailBtn">
                        <i class="fas fa-paper-plane"></i> E-Mail senden
                    </button>
                            </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentTraegerId = null;
        let currentCertificates = [];
        let originalSubject = '';
        let originalBody = '';
        
        document.addEventListener('DOMContentLoaded', function() {
            const emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
            const modalTraegerName = document.getElementById('modalTraegerName');
            const modalEmail = document.getElementById('modalEmail');
            const emailSubject = document.getElementById('emailSubject');
            const emailBody = document.getElementById('emailBody');
            const sendEmailBtn = document.getElementById('sendEmailBtn');
            const resetEmailBtn = document.getElementById('resetEmailBtn');
            
            // E-Mail-Buttons Event Listener
            document.querySelectorAll('.email-btn').forEach(button => {
                button.addEventListener('click', function() {
                    currentTraegerId = this.dataset.traegerId;
                    const traegerName = this.dataset.traegerName;
                    const email = this.dataset.email;
                    currentCertificates = JSON.parse(this.dataset.certificates);
                    
                    // Modal füllen
                    modalTraegerName.value = traegerName;
                    modalEmail.value = email;
                    
                    // E-Mail-Vorschau laden
                    loadEmailPreview();
                    
                    // Modal öffnen
                    emailModal.show();
                });
            });
            
            // E-Mail-Vorschau laden
            function loadEmailPreview() {
                if (currentCertificates.length === 0) {
                    emailSubject.value = '';
                    emailBody.value = 'Keine problematischen Zertifikate gefunden.';
                    return;
                }
                
                // Bestimme den höchsten Prioritätsstatus
                const hasExpired = currentCertificates.some(cert => cert.urgency === 'abgelaufen');
                const urgency = hasExpired ? 'abgelaufen' : 'warnung';
                
                // Lade E-Mail-Vorlagen
                fetch('get-email-preview.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `traeger_id=${currentTraegerId}&certificates=${encodeURIComponent(JSON.stringify(currentCertificates))}&urgency=${urgency}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        emailSubject.value = data.subject;
                        emailBody.value = data.body;
                        originalSubject = data.subject;
                        originalBody = data.body;
                    } else {
                        emailSubject.value = 'Fehler';
                        emailBody.value = 'Fehler beim Laden der E-Mail-Vorschau.';
                        originalSubject = '';
                        originalBody = '';
                    }
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    emailSubject.value = 'Fehler';
                    emailBody.value = 'Fehler beim Laden der E-Mail-Vorschau.';
                });
            }
            
            // Zurücksetzen-Button
            resetEmailBtn.addEventListener('click', function() {
                emailSubject.value = originalSubject;
                emailBody.value = originalBody;
            });
            
            // E-Mail senden
            sendEmailBtn.addEventListener('click', function() {
                const email = modalEmail.value.trim();
                const subject = emailSubject.value.trim();
                const body = emailBody.value.trim();
                
                if (!email) {
                    alert('Bitte geben Sie eine E-Mail-Adresse ein.');
                    return;
                }
                
                if (!subject.trim() || !body.trim()) {
                    alert('Bitte geben Sie einen Betreff und eine Nachricht ein.');
                    return;
                }
                
                // Button während des Versands deaktivieren
                const originalText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sende...';
                
                // AJAX-Anfrage
                fetch('send-atemschutz-email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `traeger_id=${currentTraegerId}&email=${encodeURIComponent(email)}&subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}&certificates=${encodeURIComponent(JSON.stringify(currentCertificates))}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Erfolg anzeigen
                        this.innerHTML = '<i class="fas fa-check"></i> Gesendet';
                        this.classList.remove('btn-primary');
                        this.classList.add('btn-success');
                        
                        // Modal nach 2 Sekunden schließen
                        setTimeout(() => {
                            emailModal.hide();
                            location.reload(); // Seite neu laden um E-Mail-Adresse zu aktualisieren
                        }, 2000);
                    } else {
                        // Fehler anzeigen
                        this.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Fehler';
                        this.classList.remove('btn-primary');
                        this.classList.add('btn-danger');
                        
                        // Nach 3 Sekunden zurücksetzen
                        setTimeout(() => {
                            this.disabled = false;
                            this.classList.remove('btn-danger');
                            this.classList.add('btn-primary');
                            this.innerHTML = originalText;
                        }, 3000);
                        
                        alert('Fehler: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    this.disabled = false;
                    this.innerHTML = originalText;
                    alert('Fehler beim Senden der E-Mail');
                });
            });
            
            // Modal zurücksetzen beim Schließen
            emailModal._element.addEventListener('hidden.bs.modal', function() {
                modalEmail.value = '';
                emailSubject.value = '';
                emailBody.value = '';
                originalSubject = '';
                originalBody = '';
                sendEmailBtn.disabled = false;
                sendEmailBtn.innerHTML = '<i class="fas fa-paper-plane"></i> E-Mail senden';
                sendEmailBtn.classList.remove('btn-success', 'btn-danger');
                sendEmailBtn.classList.add('btn-primary');
            });
        });
        
        // Reservierungsdetails anzeigen (Fahrzeug oder Raum)
        function showReservationDetails(reservation) {
            const isRoom = reservation._type === 'room';
            window.currentReservationType = isRoom ? 'room' : 'vehicle';
            window.currentReservationId = reservation.id;
            window.forceAvailabilityOverride = false;
            const resourceLabel = document.getElementById('modalResourceLabel');
            const resourceName = document.getElementById('modalResourceName');
            if (isRoom) {
                resourceLabel.innerHTML = '<i class="fas fa-door-open me-2"></i>Raum';
                resourceName.textContent = reservation.room_name || '';
            } else {
                resourceLabel.innerHTML = '<i class="fas fa-truck me-2"></i>Fahrzeug';
                resourceName.textContent = reservation.vehicle_name || '';
            }
            document.getElementById('modalRequesterName').textContent = reservation.requester_name;
            document.getElementById('modalRequesterEmail').textContent = reservation.requester_email;
            document.getElementById('modalDateTime').innerHTML = 
                '<strong>' + new Date(reservation.start_datetime).toLocaleDateString('de-DE') + '</strong><br>' +
                '<small class="text-muted">' + 
                new Date(reservation.start_datetime).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}) + 
                ' - ' + 
                new Date(reservation.end_datetime).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}) + 
                '</small>';
            document.getElementById('modalLocation').textContent = reservation.location || 'Nicht angegeben';
            document.getElementById('modalReason').textContent = reservation.reason;
            document.getElementById('modalStatus').innerHTML = '<span class="badge bg-warning text-dark">Ausstehend</span>';
            
            const conflictDetails = document.getElementById('conflictDetails');
            if (conflictDetails) conflictDetails.remove();
            
            const diveraSelectEl = document.getElementById('diveraGroupSelect');
            const diveraBlock = diveraSelectEl ? diveraSelectEl.closest('.mb-3') : null;
            if (diveraBlock) diveraBlock.style.display = isRoom ? 'none' : '';
            
            if (isRoom) checkRoomConflicts(reservation.id); else checkReservationConflicts(reservation.id);
            
            // Modal anzeigen
            const modal = new bootstrap.Modal(document.getElementById('reservationDetailsModal'));
            modal.show();
        }
        
        // Konfliktprüfung für Raumreservierung
        function checkRoomConflicts(reservationId) {
            fetch('check-room-conflicts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    reservation_id: reservationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayConflictInfo(data, true);
                } else {
                    console.error('Raum-Konfliktprüfung fehlgeschlagen:', data.message);
                }
            })
            .catch(error => {
                console.error('Fehler bei Raum-Konfliktprüfung:', error);
            });
        }

        // Konfliktprüfung für Fahrzeugreservierung
        function checkReservationConflicts(reservationId) {
            fetch('check-reservation-conflicts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    reservation_id: reservationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayConflictInfo(data, false);
                } else {
                    console.error('Konfliktprüfung fehlgeschlagen:', data.message);
                }
            })
            .catch(error => {
                console.error('Fehler bei Konfliktprüfung:', error);
            });
        }
        
        // Konfliktinformationen anzeigen (isRoom: true = Raumreservierung, false = Fahrzeugreservierung)
        function displayConflictInfo(data, isRoom) {
            const statusElement = document.getElementById('modalStatus');
            const nameKey = isRoom ? 'room_name' : 'vehicle_name';
            const availabilityWarn = (!isRoom && data.availability_warning && data.availability_warning.warning) ? data.availability_warning : null;

            if (data.has_conflicts) {
                // Konflikte gefunden - zeige Warnung
                statusElement.innerHTML = `
                    <span class="badge bg-warning text-dark me-2">Ausstehend</span>
                    <span class="badge bg-danger">${data.conflict_count} Konflikt${data.conflict_count > 1 ? 'e' : ''}</span>
                `;

                // Konflikte-Details hinzufügen
                let conflictsHtml = '<div class="mt-3"><h6 class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Zeitüberschneidungen gefunden:</h6>';
                conflictsHtml += '<div class="alert alert-warning">';

                data.conflicts.forEach(conflict => {
                    const displayName = conflict[nameKey] || conflict.vehicle_name || conflict.room_name || 'Unbekannt';
                    conflictsHtml += `
                        <div class="mb-2 p-2 border-start border-3 border-warning">
                            <strong>${displayName}</strong><br>
                            <small class="text-muted">
                                ${conflict.start_date} von ${conflict.start_time} bis ${conflict.end_time}<br>
                                Antragsteller: ${conflict.requester_name}<br>
                                Grund: ${conflict.reason}
                            </small>
                        </div>
                    `;
                });
                
                conflictsHtml += '</div></div>';
                if (availabilityWarn) {
                    const overlap = Array.isArray(availabilityWarn.overlapping_reservations) ? availabilityWarn.overlapping_reservations : [];
                    let rows = '';
                    overlap.forEach(function(r) {
                        rows += `<li><strong>${r.vehicle_name || 'Fahrzeug'}</strong> (${r.status === 'approved' ? 'genehmigt' : 'beantragt'}) – ${r.requester_name || 'Unbekannt'}: ${r.reason || '-'} </li>`;
                    });
                    conflictsHtml += `<div class="mt-3"><h6 class="text-danger"><i class="fas fa-shield-alt me-2"></i>Mindestverfügbarkeit:</h6><div class="alert alert-danger mb-0">Nach Genehmigung bleiben nur <strong>${availabilityWarn.remaining_after}</strong> ${availabilityWarn.group_label || 'Löschfahrzeug(e)'} verfügbar (Mindestwert: ${availabilityWarn.min_available}). ${rows ? '<ul class="mb-0 mt-2">' + rows + '</ul>' : ''}</div></div>`;
                }
                
                // Konflikte nach dem Status-Element einfügen
                const existingConflicts = document.getElementById('conflictDetails');
                if (existingConflicts) {
                    existingConflicts.remove();
                }
                
                const conflictDiv = document.createElement('div');
                conflictDiv.id = 'conflictDetails';
                conflictDiv.innerHTML = conflictsHtml;
                statusElement.parentNode.insertAdjacentElement('afterend', conflictDiv);
                
            } else {
                // Keine Konflikte - zeige grünen Status
                statusElement.innerHTML = `
                    <span class="badge bg-warning text-dark me-2">Ausstehend</span>
                    <span class="badge ${availabilityWarn ? 'bg-danger' : 'bg-success'}">${availabilityWarn ? 'Verfügbarkeitswarnung' : 'Kein Konflikt'}</span>
                `;
                
                // Entferne eventuell vorhandene Konflikt-Details
                const existingConflicts = document.getElementById('conflictDetails');
                if (existingConflicts) {
                    existingConflicts.remove();
                }
                if (availabilityWarn) {
                    const overlap = Array.isArray(availabilityWarn.overlapping_reservations) ? availabilityWarn.overlapping_reservations : [];
                    let rows = '';
                    overlap.forEach(function(r) {
                        rows += `<li><strong>${r.vehicle_name || 'Fahrzeug'}</strong> (${r.status === 'approved' ? 'genehmigt' : 'beantragt'}) – ${r.requester_name || 'Unbekannt'}: ${r.reason || '-'} </li>`;
                    });
                    const conflictDiv = document.createElement('div');
                    conflictDiv.id = 'conflictDetails';
                    conflictDiv.innerHTML = `<div class="mt-3"><h6 class="text-danger"><i class="fas fa-shield-alt me-2"></i>Mindestverfügbarkeit:</h6><div class="alert alert-danger mb-0">Nach Genehmigung bleiben nur <strong>${availabilityWarn.remaining_after}</strong> ${availabilityWarn.group_label || 'Löschfahrzeug(e)'} verfügbar (Mindestwert: ${availabilityWarn.min_available}). ${rows ? '<ul class="mb-0 mt-2">' + rows + '</ul>' : ''}</div></div>`;
                    statusElement.parentNode.insertAdjacentElement('afterend', conflictDiv);
                }
            }
        }
        
        // Erfolgs-Meldung in Modal anzeigen (ersetzt alert)
        // reloadDelay: bei needs_divera_key 5000 ms, damit die Access-Key-Hinweise besser lesbar sind
        function showSuccessMessage(mainMsg, subMsgHtml, reloadDelay) {
            reloadDelay = (reloadDelay !== undefined && reloadDelay > 0) ? reloadDelay : 2000;
            const detailsModal = bootstrap.Modal.getInstance(document.getElementById('reservationDetailsModal'));
            const conflictModal = bootstrap.Modal.getInstance(document.getElementById('conflictWarningModal'));
            if (detailsModal) detailsModal.hide();
            if (conflictModal) conflictModal.hide();
            const body = document.getElementById('successModalBody');
            if (body) {
                body.innerHTML = '<p class="mb-0 fs-5">' + mainMsg + '</p>' + (subMsgHtml || '');
            }
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
            setTimeout(() => location.reload(), reloadDelay);
        }

        function buildAvailabilityWarningText(warn) {
            if (!warn) return 'Warnung zur Fahrzeugverfügbarkeit erkannt.';
            return `Achtung: Nach dieser Genehmigung bleiben in der Kategorie ${warn.group_label || 'Löschfahrzeug'} nur ${warn.remaining_after} verfügbar (Mindestwert: ${warn.min_available}). Insgesamt: ${warn.total_count}, danach belegt: ${warn.reserved_after_count}.`;
        }
        
        // Reservierung genehmigen
        function approveReservation() {
            if (!window.currentReservationId) return;
            
            const approveBtn = document.getElementById('approveBtn');
            const originalText = approveBtn.innerHTML;
            const isRoom = window.currentReservationType === 'room';
            
            approveBtn.disabled = true;
            approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Genehmige...';
            
            const apiUrl = isRoom ? 'process-room-reservation.php' : 'process-reservation.php';
            const body = isRoom 
                ? { action: 'approve', reservation_id: window.currentReservationId }
                : {
                    action: 'approve',
                    reservation_id: window.currentReservationId,
                    force_availability_override: !!window.forceAvailabilityOverride,
                    divera_group_ids: (function() {
                        var dvEl = document.getElementById('diveraGroupSelect');
                        var dvVal = dvEl ? dvEl.value : '';
                        return (dvVal !== '' && dvVal !== '0') ? [parseInt(dvVal, 10)] : [];
                    })()
                };
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(body)
            })
            .then(response => response.text().then(text => ({ status: response.status, ok: response.ok, text })))
            .then(({ status, ok, text }) => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Ungültige JSON-Antwort (HTTP ' + status + '):', text.substring(0, 500));
                    alert('Server-Antwort war kein gültiges JSON (HTTP ' + status + '). Bitte Konsole (F12) prüfen. Erste Zeichen: ' + (text.substring(0, 150) || '(leer)'));
                    approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-danger');
                    approveBtn.disabled = false;
                    setTimeout(() => {
                        approveBtn.innerHTML = originalText;
                        approveBtn.classList.remove('btn-danger');
                        approveBtn.classList.add('btn-success');
                    }, 3000);
                    return;
                }
                if (data.success) {
                    approveBtn.innerHTML = '<i class="fas fa-check me-1"></i>Genehmigt!';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-success');
                    
                    const mainMsg = data.message || 'Reservierung wurde genehmigt.';
                    let subMsg = '';
                    if (data.divera_sent) {
                        subMsg = '<div class="alert alert-success py-2 mt-2 mb-0"><i class="fas fa-calendar-check me-2"></i>Der Termin wurde an Divera 24/7 übermittelt.</div>';
                    } else if (data.needs_divera_key) {
                        subMsg = '<div class="alert alert-warning py-2 mt-2 mb-0"><i class="fas fa-key me-2"></i>Damit der Termin an Divera 24/7 übermittelt wird, bitte in Ihrem Profil oder in den Divera-Einstellungen einen Access Key hinterlegen.</div>';
                    } else if (data.divera_error) {
                        subMsg = '<div class="alert alert-danger py-2 mt-2 mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Divera: ' + (data.divera_error.message || 'HTTP ' + data.divera_error.code) + '</div>';
                    }
                    showSuccessMessage(mainMsg, subMsg, data.needs_divera_key ? 5000 : 2000);
                } else if (data.has_conflicts) {
                    // Konflikte gefunden - zeige Warnung
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    
                    showConflictWarning(data.conflicts);
                } else if (data.has_availability_warning) {
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    const text = buildAvailabilityWarningText(data.availability_warning);
                    if (confirm(text + '\n\nTrotzdem genehmigen?')) {
                        window.forceAvailabilityOverride = true;
                        approveReservation();
                    } else {
                        window.forceAvailabilityOverride = false;
                    }
                } else {
                    approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-danger');
                    alert(data.message || 'Ein Fehler ist aufgetreten.');
                    
                    setTimeout(() => {
                        approveBtn.disabled = false;
                        approveBtn.innerHTML = originalText;
                        approveBtn.classList.remove('btn-danger');
                        approveBtn.classList.add('btn-success');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                approveBtn.classList.remove('btn-success');
                approveBtn.classList.add('btn-danger');
                
                setTimeout(() => {
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    approveBtn.classList.remove('btn-danger');
                    approveBtn.classList.add('btn-success');
                }, 3000);
            });
        }
        
        // Konflikt-Warnung anzeigen
        function showConflictWarning(conflicts) {
            const conflictList = document.getElementById('conflictList');
            let conflictsHtml = '';
            const isRoom = window.currentReservationType === 'room';
            const nameKey = isRoom ? 'room_name' : 'vehicle_name';

            conflicts.forEach(conflict => {
                const displayName = conflict[nameKey] || conflict.vehicle_name || conflict.room_name || 'Unbekannt';
                conflictsHtml += `
                    <div class="card mb-2 border-danger">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="card-title text-danger mb-2">
                                        <i class="fas fa-calendar-times me-2"></i>${displayName}
                                    </h6>
                                    <p class="card-text mb-1">
                                        <strong>Antragsteller:</strong> ${conflict.requester_name}
                                    </p>
                                    <p class="card-text mb-1">
                                        <strong>Zeitraum:</strong> ${conflict.start_date} von ${conflict.start_time} bis ${conflict.end_time}
                                    </p>
                                    <p class="card-text mb-0">
                                        <strong>Grund:</strong> ${conflict.reason}
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <span class="badge bg-danger">Wird storniert</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            conflictList.innerHTML = conflictsHtml;
            
            // Speichere Konflikt-IDs für spätere Verarbeitung
            window.conflictIds = conflicts.map(c => c.id);
            
            // Zeige Modal
            const modal = new bootstrap.Modal(document.getElementById('conflictWarningModal'));
            modal.show();
        }
        
        // Konfliktlösung bestätigen
        function confirmConflictResolution() {
            if (!window.currentReservationId || !window.conflictIds) return;

            const confirmBtn = document.getElementById('confirmConflictResolutionBtn');
            const originalText = confirmBtn.innerHTML;
            const isRoom = window.currentReservationType === 'room';

            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Verarbeite...';

            const apiUrl = isRoom ? 'process-room-reservation.php' : 'process-reservation.php';
            const body = isRoom
                ? { action: 'approve_with_conflict_resolution', reservation_id: window.currentReservationId, conflict_ids: window.conflictIds }
                : {
                    action: 'approve_with_conflict_resolution',
                    reservation_id: window.currentReservationId,
                    conflict_ids: window.conflictIds,
                    force_availability_override: !!window.forceAvailabilityOverride,
                    divera_group_ids: (function() {
                        var dvEl = document.getElementById('diveraGroupSelect');
                        var dvVal = dvEl ? dvEl.value : '';
                        return (dvVal !== '' && dvVal !== '0') ? [parseInt(dvVal, 10)] : [];
                    })()
                };
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(body)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i>Erfolgreich!';
                    confirmBtn.classList.remove('btn-warning');
                    confirmBtn.classList.add('btn-success');
                    
                    const mainMsg = data.message || 'Reservierung wurde genehmigt und Konflikte gelöst.';
                    let subMsg = '';
                    if (data.divera_sent) {
                        subMsg = '<div class="alert alert-success py-2 mt-2 mb-0"><i class="fas fa-calendar-check me-2"></i>Der Termin wurde an Divera 24/7 übermittelt.</div>';
                    } else if (data.needs_divera_key) {
                        subMsg = '<div class="alert alert-warning py-2 mt-2 mb-0"><i class="fas fa-key me-2"></i>Damit der Termin an Divera 24/7 übermittelt wird, bitte in Ihrem Profil oder in den Divera-Einstellungen einen Access Key hinterlegen.</div>';
                    } else if (data.divera_error) {
                        subMsg = '<div class="alert alert-danger py-2 mt-2 mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Divera: ' + (data.divera_error.message || 'HTTP ' + data.divera_error.code) + '</div>';
                    }
                    showSuccessMessage(mainMsg, subMsg, data.needs_divera_key ? 5000 : 2000);
                } else if (data.has_availability_warning) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    const text = buildAvailabilityWarningText(data.availability_warning);
                    if (confirm(text + '\n\nTrotzdem mit Konfliktlösung genehmigen?')) {
                        window.forceAvailabilityOverride = true;
                        confirmConflictResolution();
                    } else {
                        window.forceAvailabilityOverride = false;
                    }
                } else {
                    confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    confirmBtn.classList.remove('btn-warning');
                    confirmBtn.classList.add('btn-danger');
                    
                    setTimeout(() => {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = originalText;
                        confirmBtn.classList.remove('btn-danger');
                        confirmBtn.classList.add('btn-warning');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                confirmBtn.classList.remove('btn-warning');
                confirmBtn.classList.add('btn-danger');
                
                setTimeout(() => {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.classList.remove('btn-danger');
                    confirmBtn.classList.add('btn-warning');
                }, 3000);
            });
        }
        
        // Ablehnungsmodal anzeigen
        function showRejectModal() {
            // Details Modal schließen
            const detailsModal = bootstrap.Modal.getInstance(document.getElementById('reservationDetailsModal'));
            if (detailsModal) {
                detailsModal.hide();
            }
            
            // Platzhalter/Vorauswahl je nach Reservierungstyp (Fahrzeug vs. Raum)
            const rejectReasonEl = document.getElementById('rejectReason');
            const defaultText = window.currentReservationType === 'room'
                ? 'Der gewünschte Raum ist zu diesem Zeitpunkt bereits reserviert.'
                : 'Das gewünschte Fahrzeug ist zu diesem Zeitpunkt bereits reserviert.';
            rejectReasonEl.placeholder = defaultText;
            rejectReasonEl.value = defaultText;
            
            // Ablehnungsmodal anzeigen
            const rejectModal = new bootstrap.Modal(document.getElementById('rejectReasonModal'));
            rejectModal.show();
        }
        
        // Reservierung ablehnen (bestätigen)
        function confirmReject() {
            if (!window.currentReservationId) return;

            const reason = document.getElementById('rejectReason').value.trim();
            if (!reason) {
                alert('Bitte geben Sie einen Grund für die Ablehnung ein.');
                return;
            }

            const confirmBtn = document.getElementById('confirmRejectBtn');
            const originalText = confirmBtn.innerHTML;
            const apiUrl = window.currentReservationType === 'room' ? 'process-room-reservation.php' : 'process-reservation.php';

            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Lehne ab...';

            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'reject',
                    reservation_id: window.currentReservationId,
                    reason: reason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i>Abgelehnt!';
                    confirmBtn.classList.remove('btn-danger');
                    confirmBtn.classList.add('btn-success');
                    
                    // Modal nach 2 Sekunden schließen und Seite neu laden
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    confirmBtn.classList.remove('btn-danger');
                    confirmBtn.classList.add('btn-warning');
                    
                    setTimeout(() => {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = originalText;
                        confirmBtn.classList.remove('btn-warning');
                        confirmBtn.classList.add('btn-danger');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                confirmBtn.classList.remove('btn-danger');
                confirmBtn.classList.add('btn-warning');
                
                setTimeout(() => {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.classList.remove('btn-warning');
                    confirmBtn.classList.add('btn-danger');
                }, 3000);
            });
        }
        
        // Atemschutzeintrag-Details anzeigen
        function showAtemschutzEntryDetails(entryId) {
            window.currentAtemschutzEntryId = entryId;
            
            const detailsDiv = document.getElementById('atemschutzEntryDetails');
            detailsDiv.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Lade Details...</div>';
            
            fetch('../api/get-atemschutz-entry-details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    entry_id: entryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const entry = data.entry;
                    const typeNames = {
                        'einsatz': 'Einsatz',
                        'uebung': 'Übung',
                        'atemschutzstrecke': 'Atemschutzstrecke',
                        'g263': 'G26.3'
                    };
                    
                    detailsDiv.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-info">Eintragstyp</h6>
                                <p>${typeNames[entry.entry_type] || entry.entry_type}</p>
                                
                                <h6 class="text-info">Datum</h6>
                                <p>${new Date(entry.entry_date).toLocaleDateString('de-DE')}</p>
                                
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-info">Geräteträger</h6>
                                <p><small>${entry.traeger_names || 'Keine'}</small></p>
                                
                                <h6 class="text-info">Eingereicht am</h6>
                                <p><small>${new Date(entry.created_at).toLocaleString('de-DE')}</small></p>
                            </div>
                        </div>
                    `;
                } else {
                    detailsDiv.innerHTML = '<div class="alert alert-danger">Fehler beim Laden der Details</div>';
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                detailsDiv.innerHTML = '<div class="alert alert-danger">Fehler beim Laden der Details</div>';
            });
            
            const modal = new bootstrap.Modal(document.getElementById('atemschutzEntryDetailsModal'));
            modal.show();
        }
        
        // Atemschutzeintrag genehmigen
        function approveAtemschutzEntry(entryId) {
            if (!entryId) {
                entryId = window.currentAtemschutzEntryId;
            }
            if (!entryId) return;
            
            const approveBtn = document.getElementById('approveAtemschutzBtn');
            const originalText = approveBtn.innerHTML;
            
            approveBtn.disabled = true;
            approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Genehmige...';
            
            fetch('../api/process-atemschutz-entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'approve',
                    entry_id: entryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    approveBtn.innerHTML = '<i class="fas fa-check me-1"></i>Genehmigt!';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-success');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-danger');
                    
                    setTimeout(() => {
                        approveBtn.disabled = false;
                        approveBtn.innerHTML = originalText;
                        approveBtn.classList.remove('btn-danger');
                        approveBtn.classList.add('btn-success');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                approveBtn.classList.remove('btn-success');
                approveBtn.classList.add('btn-danger');
                
                setTimeout(() => {
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    approveBtn.classList.remove('btn-danger');
                    approveBtn.classList.add('btn-success');
                }, 3000);
            });
        }
        
        // Atemschutzeintrag-Ablehnung Modal anzeigen
        function showAtemschutzRejectModal(entryId) {
            window.currentAtemschutzRejectId = entryId;
            const modal = new bootstrap.Modal(document.getElementById('atemschutzRejectConfirmModal'));
            modal.show();
        }
        
        // Atemschutzeintrag ablehnen (Antrag wird gelöscht)
        function rejectAtemschutzEntry(entryId) {
            fetch('../api/process-atemschutz-entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'delete',
                    entry_id: entryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Zeige Erfolgsmeldung
                    showAtemschutzSuccessMessage('Atemschutzeintrag erfolgreich gelöscht!');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showAtemschutzErrorMessage('Fehler beim Löschen', data.message);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                showAtemschutzErrorMessage('Netzwerkfehler', 'Es ist ein Fehler beim Löschen des Atemschutzeintrags aufgetreten.');
            });
        }
        
        // Atemschutzeintrag ablehnen bestätigen
        function confirmAtemschutzReject() {
            if (!window.currentAtemschutzEntryId) return;
            
            const reason = document.getElementById('atemschutzRejectReason').value.trim();
            if (!reason) {
                alert('Bitte geben Sie einen Ablehnungsgrund an.');
                return;
            }
            
            const rejectBtn = document.querySelector('#atemschutzRejectModal .btn-danger');
            const originalText = rejectBtn.innerHTML;
            
            rejectBtn.disabled = true;
            rejectBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Lehne ab...';
            
            fetch('../api/process-atemschutz-entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'reject',
                    entry_id: window.currentAtemschutzEntryId,
                    reason: reason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    rejectBtn.innerHTML = '<i class="fas fa-check me-1"></i>Abgelehnt!';
                    rejectBtn.classList.remove('btn-danger');
                    rejectBtn.classList.add('btn-success');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    rejectBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    rejectBtn.classList.remove('btn-danger');
                    rejectBtn.classList.add('btn-warning');
                    
                    setTimeout(() => {
                        rejectBtn.disabled = false;
                        rejectBtn.innerHTML = originalText;
                        rejectBtn.classList.remove('btn-warning');
                        rejectBtn.classList.add('btn-danger');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                rejectBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                rejectBtn.classList.remove('btn-danger');
                rejectBtn.classList.add('btn-warning');
                
                setTimeout(() => {
                    rejectBtn.disabled = false;
                    rejectBtn.innerHTML = originalText;
                    rejectBtn.classList.remove('btn-warning');
                    rejectBtn.classList.add('btn-danger');
                }, 3000);
            });
        }
        
        // Hilfsfunktionen für schöne Meldungen
        function showAtemschutzSuccessMessage(message) {
            // Erstelle temporäre Toast-Nachricht
            const toastHtml = `
                <div class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas fa-check-circle me-2"></i>${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.querySelector('.toast:last-child');
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            // Entferne das Element nach 5 Sekunden
            setTimeout(() => {
                if (toastElement && toastElement.parentNode) {
                    toastElement.parentNode.removeChild(toastElement);
                }
            }, 5000);
        }
        
        function showAtemschutzErrorMessage(title, message) {
            // Erstelle temporäre Toast-Nachricht
            const toastHtml = `
                <div class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas fa-exclamation-triangle me-2"></i><strong>${title}:</strong> ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.querySelector('.toast:last-child');
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            // Entferne das Element nach 8 Sekunden
            setTimeout(() => {
                if (toastElement && toastElement.parentNode) {
                    toastElement.parentNode.removeChild(toastElement);
                }
            }, 8000);
        }
        
        // Event-Listener für Atemschutz-Ablehnungs-Bestätigung
        document.addEventListener('DOMContentLoaded', function() {
            const confirmRejectBtn = document.getElementById('confirmAtemschutzReject');
            if (confirmRejectBtn) {
                confirmRejectBtn.addEventListener('click', function() {
                    if (window.currentAtemschutzRejectId) {
                        rejectAtemschutzEntry(window.currentAtemschutzRejectId);
                        // Modal schließen
                        const modal = bootstrap.Modal.getInstance(document.getElementById('atemschutzRejectConfirmModal'));
                        modal.hide();
                    }
                });
            }
        });
    </script>
    
    <!-- Atemschutzeintrag-Details Modal -->
    <div class="modal fade" id="atemschutzEntryDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-clipboard-list me-2"></i>Atemschutzeintrag-Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="atemschutzEntryDetails">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Lade Details...
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Schließen
                    </button>
                    <button type="button" class="btn btn-success" id="approveAtemschutzBtn" onclick="approveAtemschutzEntry(window.currentAtemschutzEntryId)">
                        <i class="fas fa-check me-1"></i>Genehmigen
                    </button>
                    <button type="button" class="btn btn-danger" id="rejectAtemschutzBtn" onclick="showAtemschutzRejectModal(window.currentAtemschutzEntryId)">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Atemschutzeintrag-Ablehnung Modal -->
    <div class="modal fade" id="atemschutzRejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times me-2"></i>Atemschutzeintrag ablehnen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Achtung:</strong> Diese Aktion kann nicht rückgängig gemacht werden!
                    </div>
                    
                    <p>Bitte geben Sie einen Grund für die Ablehnung an:</p>
                    
                    <div class="mb-3">
                        <label for="atemschutzRejectReason" class="form-label">Ablehnungsgrund</label>
                        <textarea class="form-control" id="atemschutzRejectReason" rows="4" placeholder="Grund für die Ablehnung eingeben...">Der Antrag entspricht nicht den Anforderungen.</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmAtemschutzReject()">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .hover-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background-color: #f8f9fa;
        }
        
        /* Dashboard-Bereiche Styling */
        .dashboard-section {
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .dashboard-section-header {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease;
        }
        
        .dashboard-section-header:hover {
            background-color: #f8f9fa;
        }
        
        .dashboard-section-header.collapsed .collapse-icon {
            transform: rotate(-90deg);
        }
        
        .collapse-icon {
            transition: transform 0.3s ease;
            margin-right: 0.5rem;
        }
        
        .dashboard-section-body {
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .dashboard-section-body.collapsed {
            max-height: 0;
            padding: 0;
            margin: 0;
        }
        
        
        
        
        .email-buttons {
            margin-top: 8px;
            display: flex;
            justify-content: center;
        }
        .email-buttons .btn {
            font-size: 0.8rem;
            padding: 4px 12px;
            width: 100%;
        }
        .warning-header {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .warning-name {
            margin-bottom: 0;
        }
    </style>

    <!-- Atemschutz Ablehnungs-Bestätigungs-Modal -->
    <div class="modal fade" id="atemschutzRejectConfirmModal" tabindex="-1" aria-labelledby="atemschutzRejectConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title" id="atemschutzRejectConfirmModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Bestätigung erforderlich
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="fas fa-trash-alt text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <h6 class="text-warning mb-3">Atemschutzeintrag ablehnen</h6>
                    <p class="text-muted mb-0">Möchten Sie diesen Atemschutzeintrag wirklich ablehnen?<br><strong>Diese Aktion kann nicht rückgängig gemacht werden.</strong></p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-secondary px-4 me-2" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-warning px-4" id="confirmAtemschutzReject">
                        <i class="fas fa-trash-alt me-2"></i>Ja, ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Dashboard Einklapp-Funktionalität
    document.addEventListener('DOMContentLoaded', function() {
        // Alle Dashboard-Sektionen initialisieren
        const sections = document.querySelectorAll('.dashboard-section-header');
        
        sections.forEach(header => {
            const sectionName = header.getAttribute('data-section');
            const body = document.querySelector(`.dashboard-section-body[data-section="${sectionName}"]`);
            const icon = header.querySelector('.collapse-icon');
            
            // Klick-Handler hinzufügen
            header.addEventListener('click', function() {
                toggleSection(sectionName, body, icon);
            });
            
            // Initialen Zustand setzen basierend auf gespeicherten Einstellungen
            const isCollapsed = body.style.display === 'none';
            if (isCollapsed) {
                header.classList.add('collapsed');
                icon.style.transform = 'rotate(-90deg)';
            } else {
                header.classList.remove('collapsed');
                icon.style.transform = 'rotate(0deg)';
            }
        });
        
        // Debug: Einstellungen anzeigen
        console.log('Dashboard-Einstellungen geladen:', <?php echo json_encode($dashboard_preferences); ?>);
    });
    
    // Sektion umschalten
    function toggleSection(sectionName, body, icon) {
        const isCollapsed = body.style.display === 'none';
        
        if (isCollapsed) {
            // Ausklappen
            body.style.display = '';
            icon.style.transform = 'rotate(0deg)';
            saveSectionState(sectionName, false);
        } else {
            // Einklappen
            body.style.display = 'none';
            icon.style.transform = 'rotate(-90deg)';
            saveSectionState(sectionName, true);
        }
    }
    
    // Sektion-Zustand speichern
    function saveSectionState(sectionName, isCollapsed) {
        fetch('../api/save-dashboard-preference.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                section_name: sectionName,
                is_collapsed: isCollapsed
            })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Fehler beim Speichern der Dashboard-Einstellung:', data.message);
            }
        })
        .catch(error => {
            console.error('Fehler beim Speichern der Dashboard-Einstellung:', error);
        });
    }
    </script>
</body>
</html>