<?php
/**
 * Anwesenheitsliste: Dienste/Einsatz/Manuell auswählen, Vorschlag aus Dienstplan für den Tag.
 * Nur für eingeloggte Benutzer.
 */
session_start();
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('HTTP/1.0 500 Internal Server Error');
        }
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Fehler – Anwesenheitsliste</title></head><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;padding:1rem;">';
        echo '<h1>Fehler beim Laden der Anwesenheitsliste</h1>';
        echo '<p>Die Seite konnte nicht geladen werden. Bitte prüfen Sie die Konfiguration (Datenbank, PHP-Version) oder wenden Sie sich an den Administrator.</p>';
        echo '<p><strong>Technische Details:</strong><br><code>' . htmlspecialchars($err['message']) . '</code></p>';
        echo '<p><small>Datei: ' . htmlspecialchars($err['file']) . ' · Zeile: ' . (int)$err['line'] . '</small></p>';
        echo '<p><a href="index.php">Zur Startseite</a></p>';
        echo '</body></html>';
    } else {
        ob_end_flush();
    }
});

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
// Einheit VOR Divera setzen, damit einheitsspezifischer Divera-Key geladen wird
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
}
if ($einheit_id > 0) {
    if (function_exists('user_has_einheit_access') && user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
        $_SESSION['current_einheit_id'] = $einheit_id;
    } elseif ($einheit_id > 0 && isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_eid = $row ? (int)($row['einheit_id'] ?? 0) : 0;
        if ($user_eid === $einheit_id) $_SESSION['current_einheit_id'] = $einheit_id;
        else $einheit_id = $user_eid > 0 ? $user_eid : 0;
    }
}
require_once __DIR__ . '/config/divera.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';
require_once __DIR__ . '/includes/einheiten-setup.php';
// Einheitsspezifische Divera-Konfiguration explizit anwenden (falls Session noch nicht gesetzt war)
if ($einheit_id > 0 && function_exists('apply_divera_config_for_einheit')) {
    apply_divera_config_for_einheit($db, $einheit_id);
}

if (!$db) {
    header('Content-Type: text/html; charset=utf-8');
    die('Datenbankverbindung fehlgeschlagen. Bitte Konfiguration prüfen.');
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';

// Tabellen anlegen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS dienstplan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL,
            typ VARCHAR(50) DEFAULT 'dienst',
            uhrzeit_dienstbeginn TIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("ALTER TABLE dienstplan ADD COLUMN uhrzeit_dienstbeginn TIME NULL AFTER typ");
    } catch (Exception $e2) {
        /* Spalte existiert bereits */
    }
    try {
        $db->exec("ALTER TABLE dienstplan ADD COLUMN einheit_id INT NOT NULL DEFAULT 1");
    } catch (Exception $e2) {}
    // Migration: einheit_id NULL erlauben für "globale" Dienste (zeigen für alle Einheiten)
    try {
        $db->exec("ALTER TABLE dienstplan MODIFY COLUMN einheit_id INT NULL DEFAULT NULL");
    } catch (Exception $e2) {}
    try {
        $db->exec("ALTER TABLE dienstplan ADD COLUMN uhrzeit_dienstende TIME NULL AFTER uhrzeit_dienstbeginn");
    } catch (Exception $e2) {
        /* Spalte existiert bereits */
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS dienstplan_ausbilder (
                dienstplan_id INT NOT NULL,
                member_id INT NOT NULL,
                PRIMARY KEY (dienstplan_id, member_id),
                FOREIGN KEY (dienstplan_id) REFERENCES dienstplan(id) ON DELETE CASCADE,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e2) {
        /* Tabelle existiert evtl. bereits */
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS anwesenheitslisten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            dienstplan_id INT NULL,
            typ ENUM('dienst','einsatz','manuell') NOT NULL DEFAULT 'dienst',
            bezeichnung VARCHAR(255) NULL,
            bemerkung TEXT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (dienstplan_id) REFERENCES dienstplan(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            KEY idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS anwesenheitsliste_mitglieder (
                id INT AUTO_INCREMENT PRIMARY KEY,
                anwesenheitsliste_id INT NOT NULL,
                member_id INT NOT NULL,
                vehicle_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (anwesenheitsliste_id) REFERENCES anwesenheitslisten(id) ON DELETE CASCADE,
                UNIQUE KEY unique_list_member (anwesenheitsliste_id, member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e2) {
        // members-Tabelle kann fehlen oder andere DB-Struktur
        error_log('Anwesenheitsliste_mitglieder Tabelle: ' . $e2->getMessage());
    }
    try {
        $db->exec("ALTER TABLE anwesenheitsliste_mitglieder ADD COLUMN vehicle_id INT NULL");
    } catch (Exception $e2) {
        // Spalte existiert bereits
    }
    try {
        $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN divera_id INT NULL");
    } catch (Exception $e2) {
        // Spalte existiert bereits
    }
    try {
        $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN einheit_id INT NOT NULL DEFAULT 1");
    } catch (Exception $e2) {}
    $anwesenheit_extra_columns = [
        "einsatzstichwort VARCHAR(100) NULL",
        "einsatzbericht_nummer VARCHAR(50) NULL",
        "uhrzeit_von TIME NULL",
        "uhrzeit_bis TIME NULL",
        "alarmierung_durch VARCHAR(100) NULL",
        "einsatzstelle VARCHAR(255) NULL",
        "objekt TEXT NULL",
        "eigentuemer VARCHAR(255) NULL",
        "geschaedigter VARCHAR(255) NULL",
        "klassifizierung VARCHAR(100) NULL",
        "kostenpflichtiger_einsatz VARCHAR(10) NULL",
        "personenschaeden VARCHAR(50) NULL",
        "brandwache VARCHAR(10) NULL",
        "einsatzleiter_member_id INT NULL",
        "einsatzleiter_freitext VARCHAR(255) NULL",
    ];
    foreach ($anwesenheit_extra_columns as $colDef) {
        $colName = preg_replace('/\s.*/', '', $colDef);
        try {
            $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN {$colDef}");
        } catch (Exception $e2) {
            // Spalte existiert bereits
        }
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS vehicle_equipment (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
                KEY idx_vehicle (vehicle_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e2) {
        error_log('vehicle_equipment Tabelle: ' . $e2->getMessage());
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS anwesenheitsliste_fahrzeuge (
                id INT AUTO_INCREMENT PRIMARY KEY,
                anwesenheitsliste_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                maschinist_member_id INT NULL,
                einheitsfuehrer_member_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (anwesenheitsliste_id) REFERENCES anwesenheitslisten(id) ON DELETE CASCADE,
                UNIQUE KEY unique_list_vehicle (anwesenheitsliste_id, vehicle_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e2) {
        error_log('Anwesenheitsliste_fahrzeuge Tabelle: ' . $e2->getMessage());
    }
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS anwesenheitsliste_drafts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                datum DATE NOT NULL,
                auswahl VARCHAR(50) NOT NULL,
                dienstplan_id INT NULL,
                typ VARCHAR(50) NOT NULL DEFAULT 'dienst',
                bezeichnung VARCHAR(255) NULL,
                einheit_id INT NOT NULL DEFAULT 1,
                draft_data JSON NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_datum_auswahl (datum, auswahl)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e2) {
        error_log('anwesenheitsliste_drafts Tabelle: ' . $e2->getMessage());
    }
    try {
        $db->exec("ALTER TABLE anwesenheitsliste_drafts ADD COLUMN einheit_id INT NOT NULL DEFAULT 1");
    } catch (Exception $e2) {}
    // Migration: unique_datum_auswahl auf (datum, auswahl, einheit_id) für einheitsspezifische Entwürfe
    try {
        $db->exec("ALTER TABLE anwesenheitsliste_drafts DROP INDEX unique_datum_auswahl");
    } catch (Exception $e) { /* Index evtl. nicht vorhanden */ }
    try {
        $db->exec("ALTER TABLE anwesenheitsliste_drafts ADD UNIQUE KEY unique_datum_auswahl_einheit (datum, auswahl, einheit_id)");
    } catch (Exception $e) { /* Evtl. schon vorhanden */ }
    // Migration: alte unique_user_draft entfernen
    try {
        $db->exec("ALTER TABLE anwesenheitsliste_drafts MODIFY COLUMN user_id INT NULL");
    } catch (Exception $e) { /* Spalte evtl. schon NULL */ }
    try {
        $db->exec("ALTER TABLE anwesenheitsliste_drafts DROP INDEX unique_user_draft");
    } catch (Exception $e) { /* Index existiert evtl. nicht */ }
    try {
        $db->exec("DELETE d1 FROM anwesenheitsliste_drafts d1 INNER JOIN anwesenheitsliste_drafts d2 ON d1.datum = d2.datum AND d1.auswahl = d2.auswahl AND COALESCE(d1.einheit_id,1) = COALESCE(d2.einheit_id,1) AND d1.updated_at < d2.updated_at");
    } catch (Exception $e) { /* ignore */ }
} catch (Exception $e) {
    error_log('Anwesenheitsliste Tabellen: ' . $e->getMessage());
}

// Entwurf löschen (wenn angefragt, per datum+auswahl)
if (isset($_GET['action']) && $_GET['action'] === 'delete_draft' && isset($_SESSION['user_id'])) {
    $del_datum = isset($_GET['datum']) ? trim($_GET['datum']) : '';
    $del_auswahl = isset($_GET['auswahl']) ? trim($_GET['auswahl']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $del_datum) && $del_auswahl !== '') {
        try {
            require_once __DIR__ . '/includes/bericht-anhaenge-helper.php';
            $del_eid = $einheit_id > 0 ? $einheit_id : 1;
            $stmtLoad = $db->prepare("SELECT draft_data FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ? AND einheit_id = ?");
            $stmtLoad->execute([$del_datum, $del_auswahl, $del_eid]);
            $rowDel = $stmtLoad->fetch(PDO::FETCH_ASSOC);
            if ($rowDel && !empty($rowDel['draft_data'])) {
                $draftDel = json_decode($rowDel['draft_data'], true);
                if (is_array($draftDel)) {
                    bericht_anhaenge_draft_cleanup_files($draftDel);
                }
            }
            if ($einheit_id > 0) {
                $stmt = $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ? AND einheit_id = ?");
                $stmt->execute([$del_datum, $del_auswahl, $einheit_id]);
            } else {
                $stmt = $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ?");
                $stmt->execute([$del_datum, $del_auswahl]);
            }
            if ($del_datum === ($_SESSION['anwesenheit_draft']['datum'] ?? '') && $del_auswahl === ($_SESSION['anwesenheit_draft']['auswahl'] ?? '')) {
                if (!empty($_SESSION['anwesenheit_draft']) && is_array($_SESSION['anwesenheit_draft'])) {
                    bericht_anhaenge_draft_cleanup_files($_SESSION['anwesenheit_draft']);
                }
                unset($_SESSION['anwesenheit_draft']);
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    header('Location: anwesenheitsliste.php' . $einheit_param);
    exit;
}

$message = '';
$error = '';
if (isset($_GET['message']) && $_GET['message'] === 'erfolg') {
    $message = 'Anwesenheitsliste wurde angelegt.';
}
// Datum: GET (z. B. nach Schritt 2) oder heute
$datum = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['datum'])) {
    $datum = trim($_POST['datum']);
} elseif (!empty($_GET['datum'])) {
    $d = trim($_GET['datum']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $datum = $d;
    }
} else {
    $datum = date('Y-m-d');
}

// Divera: Aktiven Einsatz abfragen (für Vorschlag)
// Bei Einheitsauswahl (einheit_id > 0) NUR den Einheits-Key nutzen – kein Fallback auf Benutzer-Key
$divera_alarm = null;
$divera_key = trim((string) ($divera_config['access_key'] ?? ''));
if ($divera_key === '' && $einheit_id <= 0 && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $divera_key = trim((string) ($row['divera_access_key'] ?? ''));
    } catch (Exception $e) { /* ignore */ }
}
if ($divera_key !== '') {
    $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
    $divera_err = '';
    $divera_alarms = fetch_divera_alarms($divera_key, $api_base, $divera_err);
    $divera_alarm = null;
    if (!empty($divera_alarms)) {
        try {
            if ($einheit_id > 0) {
                $stmt = $db->prepare("SELECT divera_id FROM anwesenheitslisten WHERE divera_id IS NOT NULL AND (einheit_id = ? OR einheit_id IS NULL)");
                $stmt->execute([$einheit_id]);
            } else {
                $stmt = $db->prepare("SELECT divera_id FROM anwesenheitslisten WHERE divera_id IS NOT NULL");
                $stmt->execute();
            }
            $used_divera_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $used_divera_ids = [];
        }
        foreach ($divera_alarms as $alarm) {
            $aid = (int)($alarm['id'] ?? 0);
            if ($aid > 0 && !in_array($aid, $used_divera_ids)) {
                $divera_alarm = $alarm;
                break;
            }
        }
    }
}

// Dienste für gewähltes Datum laden (nur solche, für die noch KEINE Anwesenheitsliste existiert)
// Bei Einheitsauswahl: nur Anwesenheitslisten dieser Einheit prüfen; Dienste: eigene Einheit, NULL (global), 1 (Legacy)
$dienste_fuer_tag = [];
$vorschlag = null;
try {
    $dienstplan_where = "d.datum = ? AND a.id IS NULL";
    $dienstplan_params = [$datum];
    $join_einheit = "";
    if ($einheit_id > 0) {
        $dienstplan_where .= " AND (d.einheit_id = ? OR d.einheit_id IS NULL OR d.einheit_id = 1)";
        $dienstplan_params[] = $einheit_id;
        $join_einheit = " AND (a.einheit_id = ? OR a.einheit_id IS NULL)";
        $dienstplan_params[] = $einheit_id; // für JOIN-Bedingung
    }
    $stmt = $db->prepare("
        SELECT d.id, d.bezeichnung, d.typ, d.datum
        FROM dienstplan d
        LEFT JOIN anwesenheitslisten a ON a.dienstplan_id = d.id $join_einheit
        WHERE $dienstplan_where
        ORDER BY d.bezeichnung
    ");
    $stmt->execute($dienstplan_params);
    $dienste_fuer_tag = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $vorschlag = $dienste_fuer_tag[0] ?? null;
} catch (Exception $e) {
    // Tabelle kann fehlen oder Spalte einheit_id fehlt – Fallback ohne Einheitsfilter
    try {
        $stmt = $db->prepare("SELECT d.id, d.bezeichnung, d.typ, d.datum FROM dienstplan d LEFT JOIN anwesenheitslisten a ON a.dienstplan_id = d.id WHERE d.datum = ? AND a.id IS NULL ORDER BY d.bezeichnung");
        $stmt->execute([$datum]);
        $dienste_fuer_tag = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $vorschlag = $dienste_fuer_tag[0] ?? null;
    } catch (Exception $e2) {}
}

// Alle Dienste ohne Anwesenheitsliste (für "Dienst auswählen") - nur bis heute, keine Zukunft
$andere_dienste = [];
try {
    $andere_where = "a.id IS NULL AND d.datum <= ?";
    $andere_params = [$datum];
    $andere_join = "";
    if ($einheit_id > 0) {
        $andere_where .= " AND (d.einheit_id = ? OR d.einheit_id IS NULL OR d.einheit_id = 1)";
        $andere_params[] = $einheit_id;
        $andere_join = " AND (a.einheit_id = ? OR a.einheit_id IS NULL)";
        $andere_params[] = $einheit_id;
    }
    $stmt = $db->prepare("
        SELECT d.id, d.datum, d.bezeichnung
        FROM dienstplan d
        LEFT JOIN anwesenheitslisten a ON a.dienstplan_id = d.id" . $andere_join . "
        WHERE $andere_where
        ORDER BY d.datum DESC, d.bezeichnung
    ");
    $stmt->execute($andere_params);
    $andere_dienste = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $db->prepare("SELECT d.id, d.datum, d.bezeichnung FROM dienstplan d LEFT JOIN anwesenheitslisten a ON a.dienstplan_id = d.id WHERE a.id IS NULL AND d.datum <= ? ORDER BY d.datum DESC, d.bezeichnung");
        $stmt->execute([$datum]);
        $andere_dienste = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

// Speichern erfolgt auf der nächsten Seite (anwesenheitsliste-eingaben.php)

// Letzte abgeschlossene Anwesenheitsliste (nur 1, einheitsspezifisch)
$letzte_abgeschlossen = null;
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("
            SELECT a.*, d.bezeichnung AS dienst_bezeichnung
            FROM anwesenheitslisten a
            LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
            WHERE a.einheit_id = ? OR a.einheit_id IS NULL
            ORDER BY a.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("
            SELECT a.*, d.bezeichnung AS dienst_bezeichnung
            FROM anwesenheitslisten a
            LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
            ORDER BY a.created_at DESC
            LIMIT 1
        ");
    }
    $letzte_abgeschlossen = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// Alle Entwürfe laden (einheitsspezifisch)
$alle_entwuerfe = [];
if (isset($_SESSION['user_id'])) {
    try {
        if ($einheit_id > 0) {
            $stmt = $db->prepare("SELECT * FROM anwesenheitsliste_drafts WHERE einheit_id = ? ORDER BY updated_at DESC");
            $stmt->execute([$einheit_id]);
        } else {
            $stmt = $db->query("SELECT * FROM anwesenheitsliste_drafts ORDER BY updated_at DESC");
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['draft_data'])) continue;
            $d = json_decode($row['draft_data'], true);
            if (!is_array($d)) continue;
            $has_content = !empty($d['members']) || !empty($d['vehicles']) || !empty($d['einsatzleiter_member_id']);
            $tf = ['alarmierung_durch', 'einsatzstelle', 'objekt', 'eigentuemer', 'geschaedigter', 'klassifizierung', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache', 'bemerkung', 'einsatzleiter_freitext'];
            foreach ($tf as $f) {
                if (!empty(trim((string)($d[$f] ?? '')))) { $has_content = true; break; }
            }
            $bez = trim((string)($d['bezeichnung_sonstige'] ?? ''));
            if ($bez !== '' && !in_array($bez, array_values(get_dienstplan_typen_auswahl()), true)) {
                $has_content = true;
            }
            if (!$has_content && !empty($d['custom_data'])) {
                foreach ($d['custom_data'] as $v) {
                    if (!empty(trim((string)$v))) { $has_content = true; break; }
                }
            }
            if ($has_content) $alle_entwuerfe[] = $row;
        }
    } catch (Exception $e) {
        // ignore
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .anwesenheits-btn {
            min-height: 160px;
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .anwesenheits-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        .anwesenheits-btn .feature-icon {
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .anwesenheits-btn .feature-icon i {
            font-size: 2.25rem;
        }
        .anwesenheits-btn .card-title {
            font-size: 1.1rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php else: ?>
                <?php include __DIR__ . '/includes/system-user-nav.inc.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste</h3>
                        <p class="text-muted mb-0 mt-1">Wählen Sie den Dienst oder die Art der Anwesenheitsliste für das gewünschte Datum.</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <label class="form-label">Anwesenheitsliste für heute</label>
                            <div class="row g-3">
                                <?php if ($divera_alarm): 
                                    $alarm_datum = date('Y-m-d', $divera_alarm['date']);
                                    $alarm_uhrzeit = date('H:i', $divera_alarm['date']);
                                    $alarm_stichwort = $divera_alarm['title'] ?: $divera_alarm['text'];
                                    $alarm_geschlossen = !empty($divera_alarm['closed']);
                                    $has_draft = !empty($alle_entwuerfe);
                                ?>
                                    <div class="col-12 col-md-4">
                                        <?php if ($has_draft): ?>
                                        <button type="button" class="btn btn-danger w-100 h-100 anwesenheits-btn text-decoration-none" data-bs-toggle="modal" data-bs-target="#draftHinweisModal">
                                        <?php else: ?>
                                        <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($alarm_datum); ?>&auswahl=einsatz&divera_id=<?php echo (int)$divera_alarm['id']; ?><?php echo $einheit_param ? '&einheit_id=' . (int)$einheit_id : ''; ?>" class="btn btn-danger w-100 h-100 anwesenheits-btn text-decoration-none">
                                        <?php endif; ?>
                                            <div class="feature-icon mb-2"><i class="fas fa-exclamation-triangle"></i></div>
                                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($alarm_stichwort ?: 'Aktueller Einsatz'); ?></h5>
                                            <p class="mb-0 small opacity-90"><?php echo date('d.m.Y H:i', $divera_alarm['date']); ?><?php if ($alarm_geschlossen): ?> <span class="badge bg-secondary">geschlossen</span><?php endif; ?></p>
                                            <small class="d-block mt-1 opacity-75">(Vorschlag aus Divera)</small>
                                        <?php echo $has_draft ? '</button>' : '</a>'; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($vorschlag): ?>
                                    <div class="col-12 col-md-4">
                                        <?php if (!empty($alle_entwuerfe)): ?>
                                        <button type="button" class="btn btn-primary w-100 h-100 anwesenheits-btn text-decoration-none" data-bs-toggle="modal" data-bs-target="#draftHinweisModal">
                                        <?php else: ?>
                                        <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($datum); ?>&auswahl=<?php echo (int)$vorschlag['id']; ?><?php echo $einheit_param ? '&einheit_id=' . (int)$einheit_id : ''; ?>" class="btn btn-primary w-100 h-100 anwesenheits-btn text-decoration-none">
                                        <?php endif; ?>
                                            <div class="feature-icon mb-2"><i class="fas fa-check"></i></div>
                                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($vorschlag['bezeichnung']); ?></h5>
                                            <p class="mb-0 small opacity-90"><?php echo htmlspecialchars(get_dienstplan_typ_label($vorschlag['typ'] ?? 'uebungsdienst')); ?> · <?php echo date('d.m.Y', strtotime($vorschlag['datum'])); ?></p>
                                            <small class="d-block mt-1 opacity-75">(Vorschlag für heute)</small>
                                        <?php echo !empty($alle_entwuerfe) ? '</button>' : '</a>'; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12 col-md-4">
                                    <?php if (!empty($alle_entwuerfe)): ?>
                                    <button type="button" class="btn btn-outline-danger w-100 h-100 anwesenheits-btn text-decoration-none" data-bs-toggle="modal" data-bs-target="#draftHinweisModal">
                                    <?php else: ?>
                                    <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($datum); ?>&auswahl=einsatz&neu=1<?php echo $einheit_param ? '&einheit_id=' . (int)$einheit_id : ''; ?>" class="btn btn-outline-danger w-100 h-100 anwesenheits-btn text-decoration-none">
                                    <?php endif; ?>
                                        <div class="feature-icon mb-2"><i class="fas fa-exclamation-triangle"></i></div>
                                        <h5 class="card-title mb-0">Manuelle Anwesenheit</h5>
                                    <?php echo !empty($alle_entwuerfe) ? '</button>' : '</a>'; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <button type="button" class="btn btn-outline-primary w-100 h-100 anwesenheits-btn" data-bs-toggle="modal" data-bs-target="<?php echo !empty($alle_entwuerfe) ? '#draftHinweisModal' : '#andereDiensteModal'; ?>">
                                        <div class="feature-icon mb-2"><i class="fas fa-list"></i></div>
                                        <h5 class="card-title mb-0">Dienst auswählen</h5>
                                    </button>
                                </div>
                                <?php if (function_exists('is_admin') && is_admin()): ?>
                                <div class="col-12 col-md-4">
                                    <?php if (!empty($alle_entwuerfe)): ?>
                                    <button type="button" class="btn btn-outline-success w-100 h-100 anwesenheits-btn text-decoration-none" data-bs-toggle="modal" data-bs-target="#draftHinweisModal">
                                    <?php else: ?>
                                    <a href="anwesenheitsliste-umfrage.php?datum=<?php echo urlencode($datum); ?>&auswahl=einsatz&neu=1<?php echo $einheit_param ? '&einheit_id=' . (int)$einheit_id : ''; ?>" class="btn btn-outline-success w-100 h-100 anwesenheits-btn text-decoration-none">
                                    <?php endif; ?>
                                        <div class="feature-icon mb-2"><i class="fas fa-poll"></i></div>
                                        <h5 class="card-title mb-0">Umfrage</h5>
                                        <small class="d-block mt-1 opacity-75">Schrittweise Erfassung</small>
                                    <?php echo !empty($alle_entwuerfe) ? '</button>' : '</a>'; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <p class="text-muted small mt-2 mb-0">Wählen Sie eine Option – Sie werden zur Eingabe weitergeleitet.</p>
                        </div>

                        <!-- Modal: Bericht in Bearbeitung -->
                        <div class="modal fade" id="draftHinweisModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Bericht in Bearbeitung</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Es gibt bereits einen Bericht in Bearbeitung. Bitte schließen Sie diesen zuerst ab oder löschen Sie den Entwurf, bevor Sie eine neue Anwesenheitsliste anlegen.</p>
                                        <p class="mb-0">Unten finden Sie die Entwürfe zum Fortsetzen oder Löschen.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Verstanden</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <a href="formulare.php<?php echo $einheit_param; ?>" class="btn btn-link">Zurück zu Formulare</a>

                        <!-- Modal: Dienst auswählen -->
                        <div class="modal fade" id="andereDiensteModal" tabindex="-1">
                            <div class="modal-dialog modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Dienst auswählen</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="text-muted small">Nur Dienste, für die noch keine Anwesenheitsliste existiert.</p>
                                        <?php if (empty($andere_dienste)): ?>
                                            <p class="text-muted">Keine weiteren Dienste zur Auswahl.</p>
                                        <?php else: ?>
                                            <div class="list-group">
                                                <?php foreach ($andere_dienste as $d): ?>
                                                    <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($d['datum']); ?>&auswahl=<?php echo (int)$d['id']; ?><?php echo $einheit_param ? '&einheit_id=' . (int)$einheit_id : ''; ?>" class="list-group-item list-group-item-action">
                                                        <strong><?php echo date('d.m.Y', strtotime($d['datum'])); ?></strong> — <?php echo htmlspecialchars($d['bezeichnung']); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($letzte_abgeschlossen || !empty($alle_entwuerfe)): ?>
                        <hr class="my-4">
                        <h5 class="h6 text-muted">Zuletzt angelegte Anwesenheitslisten</h5>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($alle_entwuerfe as $e): 
                                $label = '';
                                if (($e['typ'] ?? '') === 'einsatz') $label = htmlspecialchars($e['bezeichnung'] ?? 'Einsatz');
                                elseif (($e['typ'] ?? '') === 'manuell') $label = htmlspecialchars($e['bezeichnung'] ?? 'Manuelle Anwesenheit');
                                else {
                                    $dp_id = $e['dienstplan_id'] ?? null;
                                    if ($dp_id) {
                                        $stmt = $db->prepare("SELECT bezeichnung FROM dienstplan WHERE id = ?");
                                        $stmt->execute([$dp_id]);
                                        $d = $stmt->fetch(PDO::FETCH_ASSOC);
                                        $label = htmlspecialchars($d['bezeichnung'] ?? 'Dienst');
                                    } else $label = 'Dienst';
                                }
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 border-warning">
                                <span>
                                    <span class="badge bg-warning text-dark me-2">Entwurf</span>
                                    <?php echo date('d.m.Y', strtotime($e['datum'])); ?>
                                    —
                                    <?php echo $label; ?>
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($e['datum']); ?>&auswahl=<?php echo urlencode($e['auswahl']); ?><?php echo $einheit_param ? '&einheit_id=' . (int)$einheit_id : ''; ?>" class="btn btn-sm btn-outline-primary">Fortsetzen</a>
                                    <a href="anwesenheitsliste.php?action=delete_draft&amp;datum=<?php echo urlencode($e['datum']); ?>&amp;auswahl=<?php echo urlencode($e['auswahl']); ?><?php $eid = (int)($e['einheit_id'] ?? $einheit_id); echo $eid > 0 ? '&amp;einheit_id=' . $eid : ''; ?>" class="btn btn-sm btn-outline-danger" title="Entwurf löschen" onclick="return confirm('Entwurf wirklich löschen?');"><i class="fas fa-trash"></i></a>
                                </span>
                            </li>
                            <?php endforeach; ?>
                            <?php if ($letzte_abgeschlossen): 
                                $l = $letzte_abgeschlossen;
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>
                                    <?php echo date('d.m.Y', strtotime($l['datum'])); ?>
                                    —
                                    <?php
                                    if ($l['typ'] === 'einsatz') echo 'Einsatz';
                                    elseif ($l['typ'] === 'manuell') echo htmlspecialchars($l['bezeichnung'] ?: 'Manuelle Anwesenheit');
                                    else echo htmlspecialchars($l['dienst_bezeichnung'] ?? 'Dienst');
                                    ?>
                                </span>
                                <small class="text-muted"><?php echo format_datetime_berlin($l['created_at'], 'd.m. H:i'); ?></small>
                            </li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/admin/includes/print-toast.inc.php'; ?>
    <script>
    var ANWESENHEITSLISTE_EINHEIT_ID = <?php echo $einheit_id > 0 ? (int)$einheit_id : 0; ?>;
    (function() {
        var search = window.location.search;
        var m = /[?&]print=(\d+)/.exec(search);
        var mMb = /[?&]print_maengelbericht=([^&]+)/.exec(search);
        var mGwm = /[?&]print_geraetewartmitteilung=(\d+)/.exec(search);
        var hasAny = (m && m[1]) || (mMb && mMb[1]) || (mGwm && mGwm[1]);
        if (!hasAny) return;
        var einheitParam = (typeof ANWESENHEITSLISTE_EINHEIT_ID !== 'undefined' && ANWESENHEITSLISTE_EINHEIT_ID > 0) ? '&einheit_id=' + ANWESENHEITSLISTE_EINHEIT_ID : '';
        var url = 'api/print-anwesenheitsliste-kombi.php?';
        if (m && m[1]) url += 'print=' + m[1] + '&';
        if (mMb && mMb[1]) url += 'print_maengelbericht=' + encodeURIComponent(mMb[1]) + '&';
        if (mGwm && mGwm[1]) url += 'print_geraetewartmitteilung=' + mGwm[1] + '&';
        if (einheitParam) url += einheitParam.replace(/^&/, '');
        url = url.replace(/&$/, '');
        function showResult(success, msg) {
            if (typeof showPrintToast === 'function') showPrintToast(msg || (success ? 'Druckauftrag gesendet.' : 'Druck fehlgeschlagen.'), success);
            else alert(success ? 'Druckauftrag wurde gesendet.' : 'Fehler: ' + (msg || 'Druck fehlgeschlagen.'));
        }
        function handlePrintResult(data) {
            if (data.success && data.open_pdf && data.pdf_base64) {
                try {
                    var binary = atob(data.pdf_base64);
                    var bytes = new Uint8Array(binary.length);
                    for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                    var blob = new Blob([bytes], { type: 'application/pdf' });
                    var blobUrl = URL.createObjectURL(blob);
                    var w = window.open(blobUrl, '_blank', 'noopener,width=900,height=700');
                    if (w) {
                        w.onload = function() { try { w.print(); } catch (e) {} setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 10000); };
                        setTimeout(function() { try { w.print(); } catch (e) {} }, 1500);
                    } else {
                        var iframe = document.createElement('iframe');
                        iframe.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;border:none;z-index:99999;background:#fff';
                        iframe.src = blobUrl;
                        document.body.appendChild(iframe);
                        var btnBar = document.createElement('div');
                        btnBar.style.cssText = 'position:fixed;top:10px;right:10px;z-index:100000;display:flex;gap:8px';
                        var newWinBtn = document.createElement('button');
                        newWinBtn.textContent = 'In neuem Fenster öffnen';
                        newWinBtn.className = 'btn btn-outline-primary';
                        newWinBtn.onclick = function() { var w2 = window.open(blobUrl, '_blank', 'noopener,width=900,height=700'); if (w2) w2.onload = function() { try { w2.print(); } catch (e) {} }; };
                        var closeBtn = document.createElement('button');
                        closeBtn.textContent = 'Schließen';
                        closeBtn.className = 'btn btn-primary';
                        closeBtn.onclick = function() { iframe.remove(); btnBar.remove(); URL.revokeObjectURL(blobUrl); };
                        btnBar.appendChild(newWinBtn);
                        btnBar.appendChild(closeBtn);
                        document.body.appendChild(btnBar);
                        iframe.onload = function() { setTimeout(function() { try { iframe.contentWindow.print(); } catch (e) {} }, 500); };
                    }
                    showResult(true, 'PDF wurde geöffnet. Sie können lokal drucken (Strg+P).');
                } catch (e) { showResult(false, 'PDF konnte nicht geöffnet werden.'); }
            } else {
                showResult(data.success, data.message);
            }
            var q = search.replace(/[?&]print=\d+/g, '').replace(/[?&]print_maengelbericht=[^&]+/g, '').replace(/[?&]print_geraetewartmitteilung=\d+/g, '').replace(/^&/, '?').replace(/&$/, '');
            if (q === '?') q = '';
            history.replaceState(null, '', window.location.pathname + (q || '?message=erfolg'));
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) { handlePrintResult(data); })
            .catch(function() { showResult(false, 'Verbindungsfehler'); });
    })();
    </script>
</body>
</html>
