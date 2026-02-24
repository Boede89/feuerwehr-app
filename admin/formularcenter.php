<?php
/**
 * Formularcenter: Formulare verwalten, ausgefüllte Formulare anzeigen und bearbeiten.
 * Nur für Benutzer mit Berechtigung "Formularcenter" (can_forms).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/divera.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dienstplan-typen.php';
require_once __DIR__ . '/../includes/anwesenheitsliste-helper.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (is_system_user()) {
    header('Location: ../formulare.php');
    exit;
}
if (!has_permission('forms')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Tabellen anlegen falls nicht vorhanden
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS app_forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            schema_json LONGTEXT NOT NULL COMMENT 'JSON: Felder mit name, label, type, required, options',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS app_form_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NOT NULL,
            user_id INT NOT NULL,
            form_data LONGTEXT NOT NULL COMMENT 'JSON: ausgefüllte Werte',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (form_id) REFERENCES app_forms(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Formularcenter Tabellen: ' . $e->getMessage());
}
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS dienstplan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL,
            typ VARCHAR(50) DEFAULT 'uebungsdienst',
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
        $db->exec("ALTER TABLE dienstplan ADD COLUMN uhrzeit_dienstende TIME NULL AFTER uhrzeit_dienstbeginn");
    } catch (Exception $e2) {
        /* Spalte existiert bereits */
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS dienstplan_ausbilder (
            dienstplan_id INT NOT NULL,
            member_id INT NOT NULL,
            PRIMARY KEY (dienstplan_id, member_id),
            FOREIGN KEY (dienstplan_id) REFERENCES dienstplan(id) ON DELETE CASCADE,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Dienstplan Tabelle: ' . $e->getMessage());
}
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS maengelberichte (
            id INT AUTO_INCREMENT PRIMARY KEY,
            standort VARCHAR(100) NOT NULL,
            mangel_an VARCHAR(50) NOT NULL,
            bezeichnung VARCHAR(255) NULL,
            mangel_beschreibung TEXT NULL,
            ursache TEXT NULL,
            verbleib TEXT NULL,
            aufgenommen_durch_text VARCHAR(255) NULL,
            aufgenommen_durch_member_id INT NULL,
            aufgenommen_am DATE NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            email_sent_at DATETIME NULL,
            KEY idx_aufgenommen_am (aufgenommen_am),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('maengelberichte Tabelle: ' . $e->getMessage());
}
try {
    $db->exec("ALTER TABLE maengelberichte ADD COLUMN email_sent_at DATETIME NULL");
} catch (Exception $e) {
    /* Spalte existiert ggf. bereits */
}
try {
    $db->exec("ALTER TABLE maengelberichte ADD COLUMN vehicle_id INT NULL");
} catch (Exception $e) {
    /* Spalte existiert ggf. bereits */
}

$message = isset($_GET['message']) ? trim($_GET['message']) : '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'submissions';
if ($active_tab === 'forms') $active_tab = 'submissions'; // Tab "Formulare verwalten" vorübergehend entfernt
$dienstplan_jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$filter_typ = isset($_GET['filter_typ']) ? trim($_GET['filter_typ']) : '';
$filter_datum_von = isset($_GET['filter_datum_von']) ? trim($_GET['filter_datum_von']) : (date('Y') . '-01-01');
$filter_datum_bis = isset($_GET['filter_datum_bis']) ? trim($_GET['filter_datum_bis']) : date('Y-m-d');
$filter_formular = isset($_GET['filter_formular']) ? trim($_GET['filter_formular']) : '';
$filter_beschreibung = isset($_GET['filter_beschreibung']) ? trim($_GET['filter_beschreibung']) : '';

// Einheit-Filter: strikt nach Einheit – reguläre Benutzer sehen nur ihre Einheit
$einheit_filter = get_admin_einheit_filter();

// CSRF-Token erzeugen
if (empty($_SESSION['form_center_csrf'])) {
    $_SESSION['form_center_csrf'] = bin2hex(random_bytes(32));
}

// POST: Formular anlegen/aktualisieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_center_csrf']) && $_POST['form_center_csrf'] === $_SESSION['form_center_csrf']) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_form') {
        $form_id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $schema_json = $_POST['schema_json'] ?? '[]';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if (empty($title)) {
            $error = 'Bitte einen Formulartitel angeben.';
        } else {
            $decoded = json_decode($schema_json, true);
            if ($decoded === null && $schema_json !== '[]') {
                $error = 'Ungültiges Feld-Schema (JSON).';
            } else {
                try {
                    if ($form_id) {
                        $stmt = $db->prepare("UPDATE app_forms SET title = ?, description = ?, schema_json = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$title, $description, $schema_json, $is_active, $form_id]);
                        $message = 'Formular wurde aktualisiert.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO app_forms (title, description, schema_json, is_active) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$title, $description, $schema_json, $is_active]);
                        $message = 'Formular wurde angelegt.';
                    }
                } catch (Exception $e) {
                    $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
                }
            }
        }
    }
    if ($action === 'save_submission' && !$error) {
        $submission_id = (int)($_POST['submission_id'] ?? 0);
        $form_data_json = $_POST['form_data'] ?? '{}';
        if ($submission_id && json_decode($form_data_json) !== null) {
            try {
                $stmt = $db->prepare("UPDATE app_form_submissions SET form_data = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$form_data_json, $submission_id]);
                $message = 'Eingabe wurde gespeichert.';
                $active_tab = 'submissions';
            } catch (Exception $e) {
                $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'dienstplan_save' && !$error) {
        $id = isset($_POST['dienstplan_id']) ? (int)$_POST['dienstplan_id'] : 0;
        $datum = trim($_POST['dienstplan_datum'] ?? '');
        $thema = trim($_POST['dienstplan_thema'] ?? '');
        $thema_neu = trim($_POST['dienstplan_thema_neu'] ?? '');
        $beschreibung = trim($_POST['dienstplan_beschreibung'] ?? '');
        $typ_raw = trim($_POST['dienstplan_typ'] ?? '');
        $uhrzeit = trim($_POST['dienstplan_uhrzeit'] ?? '');
        $uhrzeit_ende = trim($_POST['dienstplan_uhrzeit_ende'] ?? '');
        $ausbilder_ids = isset($_POST['dienstplan_ausbilder']) && is_array($_POST['dienstplan_ausbilder']) ? array_filter(array_map('intval', $_POST['dienstplan_ausbilder'])) : [];
        $typen = get_dienstplan_typen_auswahl();
        $typ = array_key_exists($typ_raw, $typen) ? $typ_raw : 'uebungsdienst';
        $thema_value = ($typ === 'uebungsdienst') ? ($thema === '__neu__' ? $thema_neu : $thema) : $beschreibung;
        $uhrzeit_val = (preg_match('/^\d{1,2}:\d{2}$/', $uhrzeit) || preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $uhrzeit)) ? $uhrzeit : null;
        $uhrzeit_ende_val = (preg_match('/^\d{1,2}:\d{2}$/', $uhrzeit_ende) || preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $uhrzeit_ende)) ? $uhrzeit_ende : null;
        if (empty($datum)) {
            $error = 'Datum ist erforderlich.';
        } elseif ($typ === 'uebungsdienst' && $thema_value === '') {
            $error = 'Bitte wählen Sie ein Thema oder geben Sie ein neues ein.';
        } elseif (($typ === 'sonstiges' || $typ === 'einsatz') && $beschreibung === '') {
            $error = 'Bitte geben Sie eine Beschreibung ein.';
        } else {
            try {
                $res_einheit = get_admin_einheit_filter() ?? 1;
                try { $db->exec("ALTER TABLE dienstplan ADD COLUMN einheit_id INT NULL"); } catch (Exception $e2) {}
                if ($id) {
                    $stmt = $db->prepare("UPDATE dienstplan SET datum = ?, bezeichnung = ?, typ = ?, uhrzeit_dienstbeginn = ?, uhrzeit_dienstende = ?, einheit_id = ? WHERE id = ?");
                    $stmt->execute([$datum, $thema_value, $typ, $uhrzeit_val, $uhrzeit_ende_val, $res_einheit > 0 ? $res_einheit : null, $id]);
                    $db->prepare("DELETE FROM dienstplan_ausbilder WHERE dienstplan_id = ?")->execute([$id]);
                    foreach ($ausbilder_ids as $mid) {
                        if ($mid > 0) {
                            $db->prepare("INSERT INTO dienstplan_ausbilder (dienstplan_id, member_id) VALUES (?, ?)")->execute([$id, $mid]);
                        }
                    }
                    $message = 'Dienstplan-Eintrag wurde aktualisiert.';
                } else {
                    $stmt = $db->prepare("INSERT INTO dienstplan (datum, bezeichnung, typ, uhrzeit_dienstbeginn, uhrzeit_dienstende, einheit_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$datum, $thema_value, $typ, $uhrzeit_val, $uhrzeit_ende_val, $res_einheit > 0 ? $res_einheit : null]);
                    $new_id = (int)$db->lastInsertId();
                    foreach ($ausbilder_ids as $mid) {
                        if ($mid > 0) {
                            $db->prepare("INSERT INTO dienstplan_ausbilder (dienstplan_id, member_id) VALUES (?, ?)")->execute([$new_id, $mid]);
                        }
                    }
                    $message = 'Dienstplan-Eintrag wurde angelegt.';
                }
                $active_tab = 'dienstplan';
            } catch (Exception $e) {
                $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'dienstplan_delete' && !$error) {
        $id = (int)($_POST['dienstplan_id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM dienstplan WHERE id = ?")->execute([$id]);
                $message = 'Dienstplan-Eintrag wurde gelöscht.';
                $active_tab = 'dienstplan';
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
    if ($action === 'delete_submission' && !$error) {
        $id = (int)($_POST['submission_id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM app_form_submissions WHERE id = ?")->execute([$id]);
                $msg = urlencode('Formulareingabe wurde gelöscht.');
                $redir = 'formularcenter.php?tab=submissions&message=' . $msg;
                if (!empty($_POST['filter_typ'])) $redir .= '&filter_typ=' . urlencode($_POST['filter_typ']);
                if (!empty($_POST['filter_datum_von'])) $redir .= '&filter_datum_von=' . urlencode($_POST['filter_datum_von']);
                if (!empty($_POST['filter_datum_bis'])) $redir .= '&filter_datum_bis=' . urlencode($_POST['filter_datum_bis']);
                if (!empty($_POST['filter_formular'])) $redir .= '&filter_formular=' . urlencode($_POST['filter_formular']);
                if (!empty($_POST['filter_beschreibung'])) $redir .= '&filter_beschreibung=' . urlencode($_POST['filter_beschreibung']);
                header('Location: ' . $redir);
                exit;
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
    if ($action === 'delete_anwesenheitsliste' && !$error) {
        $id = (int)($_POST['anwesenheitsliste_id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM anwesenheitslisten WHERE id = ?")->execute([$id]);
                $msg = urlencode('Anwesenheitsliste wurde gelöscht.');
                $redir = 'formularcenter.php?tab=submissions&message=' . $msg;
                if (!empty($_POST['filter_typ'])) $redir .= '&filter_typ=' . urlencode($_POST['filter_typ']);
                if (!empty($_POST['filter_datum_von'])) $redir .= '&filter_datum_von=' . urlencode($_POST['filter_datum_von']);
                if (!empty($_POST['filter_datum_bis'])) $redir .= '&filter_datum_bis=' . urlencode($_POST['filter_datum_bis']);
                if (!empty($_POST['filter_formular'])) $redir .= '&filter_formular=' . urlencode($_POST['filter_formular']);
                if (!empty($_POST['filter_beschreibung'])) $redir .= '&filter_beschreibung=' . urlencode($_POST['filter_beschreibung']);
                header('Location: ' . $redir);
                exit;
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
    if ($action === 'delete_maengelbericht' && !$error) {
        $id = (int)($_POST['maengelbericht_id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM maengelberichte WHERE id = ?")->execute([$id]);
                $msg = urlencode('Mängelbericht wurde gelöscht.');
                $redir = 'formularcenter.php?tab=submissions&message=' . $msg;
                if (!empty($_POST['filter_datum_von'])) $redir .= '&filter_datum_von=' . urlencode($_POST['filter_datum_von']);
                if (!empty($_POST['filter_datum_bis'])) $redir .= '&filter_datum_bis=' . urlencode($_POST['filter_datum_bis']);
                if (!empty($_POST['filter_formular'])) $redir .= '&filter_formular=' . urlencode($_POST['filter_formular']);
                if (!empty($_POST['filter_beschreibung'])) $redir .= '&filter_beschreibung=' . urlencode($_POST['filter_beschreibung']);
                header('Location: ' . $redir);
                exit;
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
    if ($action === 'delete_geraetewartmitteilung' && !$error) {
        $id = (int)($_POST['geraetewartmitteilung_id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM geraetewartmitteilungen WHERE id = ?")->execute([$id]);
                $msg = urlencode('Gerätewartmitteilung wurde gelöscht.');
                $redir = 'formularcenter.php?tab=submissions&message=' . $msg;
                if (!empty($_POST['filter_datum_von'])) $redir .= '&filter_datum_von=' . urlencode($_POST['filter_datum_von']);
                if (!empty($_POST['filter_datum_bis'])) $redir .= '&filter_datum_bis=' . urlencode($_POST['filter_datum_bis']);
                if (!empty($_POST['filter_formular'])) $redir .= '&filter_formular=' . urlencode($_POST['filter_formular']);
                if (!empty($_POST['filter_beschreibung'])) $redir .= '&filter_beschreibung=' . urlencode($_POST['filter_beschreibung']);
                header('Location: ' . $redir);
                exit;
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
}

// Formulare laden
$forms = [];
try {
    $stmt = $db->query("SELECT * FROM app_forms ORDER BY title");
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ?: 'Formulare konnten nicht geladen werden.';
}

// Eingaben laden (mit Formtitel und Benutzer) – mit Datum-Filter, einheitsspezifisch
$submissions = [];
try {
    $sql = "
        SELECT s.*, f.title AS form_title,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM app_form_submissions s
        JOIN app_forms f ON f.id = s.form_id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE 1=1
    ";
    $params = [];
    if ($einheit_filter) {
        $sql .= " AND u.einheit_id = ?";
        $params[] = $einheit_filter;
    }
    if ($filter_datum_von !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_von)) {
        $sql .= " AND DATE(s.created_at) >= ?";
        $params[] = $filter_datum_von;
    }
    if ($filter_datum_bis !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_bis)) {
        $sql .= " AND DATE(s.created_at) <= ?";
        $params[] = $filter_datum_bis;
    }
    $sql .= " ORDER BY s.updated_at DESC";
    $stmt = $params ? $db->prepare($sql) : $db->query($sql);
    if ($params) $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle kann fehlen
}

// Anwesenheitslisten laden (als "eingegangene Formulare") – mit Typ- und Datum-Filter, einheitsspezifisch
$anwesenheitslisten = [];
try {
    $sql = "
        SELECT a.id, a.datum, a.bezeichnung, a.typ, a.einsatzstichwort, a.custom_data, a.created_at,
               d.bezeichnung AS dienst_bezeichnung, d.typ AS dienst_typ,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM anwesenheitslisten a
        LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE 1=1
    ";
    $params = [];
    if ($einheit_filter) {
        $sql .= " AND (a.einheit_id = ? OR a.einheit_id IS NULL)";
        $params[] = $einheit_filter;
    }
    if ($filter_typ !== '') {
        if ($filter_typ === 'einsatz') {
            $sql .= " AND a.typ = 'einsatz'";
        } elseif ($filter_typ === 'uebungsdienst') {
            $sql .= " AND (a.typ = 'dienst' AND d.typ IN ('uebungsdienst','dienst','uebung'))";
        } elseif ($filter_typ === 'sonstiges') {
            $sql .= " AND ((a.typ = 'dienst' AND d.typ IN ('sonstiges', 'jahreshauptversammlung')) OR (a.typ = 'manuell' AND (a.bezeichnung IN ('Sonstiges', 'Jahreshauptversammlung') OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) IN ('sonstiges', 'jahreshauptversammlung')))))";
            if ($filter_beschreibung !== '') {
                $sql .= " AND (a.bezeichnung = ? OR d.bezeichnung = ? OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung')) = ?))";
                $params[] = $filter_beschreibung;
                $params[] = $filter_beschreibung;
                $params[] = $filter_beschreibung;
            }
        }
    }
    if ($filter_datum_von !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_von)) {
        $sql .= " AND a.datum >= ?";
        $params[] = $filter_datum_von;
    }
    if ($filter_datum_bis !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_bis)) {
        $sql .= " AND a.datum <= ?";
        $params[] = $filter_datum_bis;
    }
    $sql .= " ORDER BY a.datum DESC, a.created_at DESC";
    $stmt = $params ? $db->prepare($sql) : $db->query($sql);
    if ($params) $stmt->execute($params);
    $anwesenheitslisten = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle kann fehlen
}

// Mängelberichte laden – einheitsspezifisch (über user.einheit_id)
$maengelberichte = [];
try {
    $sql = "
        SELECT m.id, m.standort, m.mangel_an, m.bezeichnung, m.aufgenommen_am, m.created_at, m.email_sent_at,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM maengelberichte m
        LEFT JOIN users u ON u.id = m.user_id
        WHERE 1=1
    ";
    $params = [];
    if ($einheit_filter) {
        $sql .= " AND u.einheit_id = ?";
        $params[] = $einheit_filter;
    }
    if ($filter_datum_von !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_von)) {
        $sql .= " AND m.aufgenommen_am >= ?";
        $params[] = $filter_datum_von;
    }
    if ($filter_datum_bis !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_bis)) {
        $sql .= " AND m.aufgenommen_am <= ?";
        $params[] = $filter_datum_bis;
    }
    $sql .= " ORDER BY m.aufgenommen_am DESC, m.created_at DESC";
    $stmt = $params ? $db->prepare($sql) : $db->query($sql);
    if ($params) $stmt->execute($params);
    $maengelberichte = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle kann fehlen
}

// Gerätewartmitteilungen laden
$geraetewartmitteilungen = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geraetewartmitteilungen (id INT AUTO_INCREMENT PRIMARY KEY, typ VARCHAR(20) NOT NULL, einsatz_uebungsart VARCHAR(50) NOT NULL, datum DATE NOT NULL, einsatzbereitschaft VARCHAR(30) NOT NULL, mangel_beschreibung TEXT NULL, einsatzleiter_member_id INT NULL, einsatzleiter_freitext VARCHAR(255) NULL, user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, KEY idx_datum (datum), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS geraetewartmitteilung_fahrzeuge (id INT AUTO_INCREMENT PRIMARY KEY, geraetewartmitteilung_id INT NOT NULL, vehicle_id INT NOT NULL, maschinist_member_id INT NULL, einheitsfuehrer_member_id INT NULL, equipment_used JSON NULL, defective_equipment JSON NULL, defective_freitext TEXT NULL, defective_mangel TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (geraetewartmitteilung_id) REFERENCES geraetewartmitteilungen(id) ON DELETE CASCADE, FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE, UNIQUE KEY unique_gwm_vehicle (geraetewartmitteilung_id, vehicle_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $sql = "
        SELECT g.id, g.typ, g.einsatz_uebungsart, g.datum, g.created_at,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM geraetewartmitteilungen g
        LEFT JOIN users u ON u.id = g.user_id
        WHERE 1=1
    ";
    $params = [];
    if ($einheit_filter) {
        $sql .= " AND u.einheit_id = ?";
        $params[] = $einheit_filter;
    }
    if ($filter_datum_von !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_von)) {
        $sql .= " AND g.datum >= ?";
        $params[] = $filter_datum_von;
    }
    if ($filter_datum_bis !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_datum_bis)) {
        $sql .= " AND g.datum <= ?";
        $params[] = $filter_datum_bis;
    }
    $sql .= " ORDER BY g.datum DESC, g.created_at DESC";
    $stmt = $params ? $db->prepare($sql) : $db->query($sql);
    if ($params) $stmt->execute($params);
    $geraetewartmitteilungen = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle kann fehlen
}

$submissions_total = count($submissions) + count($anwesenheitslisten) + count($maengelberichte) + count($geraetewartmitteilungen);

// Beschreibungen für Sonstiges-Filter-Dropdown laden – aus allen Quellen in vorhandenen Anwesenheitslisten
// Quellen: a.bezeichnung, d.bezeichnung, custom_data->beschreibung
// Typen: dienst (sonstiges, jahreshauptversammlung) + manuell Sonstiges/JHV
$beschreibung_optionen_sonstiges = [];
$typ_cond_sonst = "((a.typ = 'dienst' AND d.typ IN ('sonstiges', 'jahreshauptversammlung')) OR (a.typ = 'manuell' AND (a.bezeichnung IN ('Sonstiges', 'Jahreshauptversammlung') OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) IN ('sonstiges', 'jahreshauptversammlung')))))";
try {
    $seen = [];
    $stmt = $db->prepare("SELECT DISTINCT TRIM(COALESCE(a.bezeichnung, d.bezeichnung)) AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE " . $typ_cond_sonst . " AND TRIM(COALESCE(a.bezeichnung, d.bezeichnung, '')) != '' ORDER BY b");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $b = trim($row['b'] ?? '');
        if ($b !== '' && !in_array($b, $seen)) { $seen[] = $b; $beschreibung_optionen_sonstiges[] = $b; }
    }
    $stmt2 = $db->prepare("SELECT DISTINCT TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE " . $typ_cond_sonst . " AND a.custom_data IS NOT NULL AND JSON_EXTRACT(a.custom_data, '$.beschreibung') IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) != '' ORDER BY b");
    $stmt2->execute();
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $b = trim($row['b'] ?? '');
        if ($b !== '' && !in_array($b, $seen)) { $seen[] = $b; $beschreibung_optionen_sonstiges[] = $b; }
    }
    sort($beschreibung_optionen_sonstiges);
} catch (Exception $e) {
    // Tabellen können fehlen
}

// Zähler für Formular-Buttons (vor Filterung)
$form_counts_for_buttons = [];
foreach ($submissions as $s) {
    $fid = (int)($s['form_id'] ?? 0);
    $ftitle = trim($s['form_title'] ?? 'Formular');
    if ($fid > 0 && $ftitle !== '') {
        $form_counts_for_buttons[$fid] = ($form_counts_for_buttons[$fid] ?? 0) + 1;
    }
}
$anwesenheitslisten_count = count($anwesenheitslisten);
$maengelberichte_count = count($maengelberichte);
$geraetewartmitteilungen_count = count($geraetewartmitteilungen);

// Nach Formulartyp filtern (Buttons: Anwesenheitsliste, Mängelbericht, etc.)
if ($filter_formular === 'anwesenheitsliste') {
    $submissions = [];
    $maengelberichte = [];
    $geraetewartmitteilungen = [];
} elseif ($filter_formular === 'maengelbericht') {
    $submissions = [];
    $anwesenheitslisten = [];
    $geraetewartmitteilungen = [];
} elseif ($filter_formular === 'geraetewartmitteilung') {
    $submissions = [];
    $anwesenheitslisten = [];
    $maengelberichte = [];
} elseif (preg_match('/^form_(\d+)$/', $filter_formular, $fm)) {
    $form_id_filter = (int)$fm[1];
    $submissions = array_filter($submissions, fn($s) => (int)($s['form_id'] ?? 0) === $form_id_filter);
    $anwesenheitslisten = [];
    $maengelberichte = [];
    $geraetewartmitteilungen = [];
}

// Dienstplan-Einträge und Themen für Dropdown – einheitsspezifisch
$dienstplan_eintraege = [];
$dienstplan_themen = [];
try {
    $dp_sql = "
        SELECT d.*, GROUP_CONCAT(da.member_id) AS ausbilder_ids
        FROM dienstplan d
        LEFT JOIN dienstplan_ausbilder da ON da.dienstplan_id = d.id
        WHERE d.datum >= ? AND d.datum <= ?
    ";
    $dp_params = [$dienstplan_jahr . '-01-01', $dienstplan_jahr . '-12-31'];
    if ($einheit_filter) {
        $dp_sql .= " AND (d.einheit_id = ? OR d.einheit_id IS NULL)";
        $dp_params[] = $einheit_filter;
    }
    $dp_sql .= " GROUP BY d.id ORDER BY d.datum, d.bezeichnung";
    $stmt = $db->prepare($dp_sql);
    $stmt->execute($dp_params);
    $dienstplan_eintraege = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($einheit_filter) {
        $stmt = $db->prepare("SELECT DISTINCT bezeichnung FROM dienstplan WHERE (einheit_id = ? OR einheit_id IS NULL) ORDER BY bezeichnung");
        $stmt->execute([$einheit_filter]);
    } else {
        $stmt = $db->query("SELECT DISTINCT bezeichnung FROM dienstplan ORDER BY bezeichnung");
    }
    $dienstplan_themen = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // ignore
}

$edit_submission = null;
$members_for_ausbilder = [];
$members_by_id = [];
try {
    $einheit_where = $einheit_filter ? " WHERE (einheit_id = " . (int)$einheit_filter . " OR einheit_id IS NULL)" : "";
    $stmt = $db->query("SELECT id, first_name, last_name FROM members $einheit_where ORDER BY last_name, first_name");
    $members_for_ausbilder = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($members_for_ausbilder as $m) {
        $members_by_id[(int)$m['id']] = trim($m['last_name'] . ', ' . $m['first_name']);
    }
} catch (Exception $e) {}

$edit_dienstplan = null;
if (isset($_GET['edit_dienstplan'])) {
    $id = (int)$_GET['edit_dienstplan'];
    foreach ($dienstplan_eintraege as $e) {
        if ((int)$e['id'] === $id) { $edit_dienstplan = $e; break; }
    }
    if (!$edit_dienstplan && $id) {
        $stmt = $db->prepare("SELECT * FROM dienstplan WHERE id = ?");
        $stmt->execute([$id]);
        $edit_dienstplan = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($edit_dienstplan) {
        $stmt = $db->prepare("SELECT member_id FROM dienstplan_ausbilder WHERE dienstplan_id = ?");
        $stmt->execute([$edit_dienstplan['id']]);
        $edit_dienstplan['ausbilder_member_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
if (isset($_GET['edit_submission'])) {
    $id = (int)$_GET['edit_submission'];
    foreach ($submissions as $s) {
        if ((int)$s['id'] === $id) {
            header('Location: ../formulare-ausfuellen.php?id=' . (int)$s['form_id'] . '&edit=' . (int)$s['id'] . '&return=formularcenter');
            exit;
        }
    }
}

// Divera-Gruppen und Standard-Gruppe für Export
$divera_groups = [];
$divera_default_group_id = '';
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?)');
    $stmt->execute(['divera_reservation_groups', 'divera_dienstplan_default_group_id']);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['setting_key'] === 'divera_reservation_groups') {
            $dec = json_decode($row['setting_value'], true);
            $divera_groups = is_array($dec) ? $dec : [];
        } else {
            $divera_default_group_id = trim((string)$row['setting_value']);
        }
    }
} catch (Exception $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formularcenter - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h1 class="h3 mb-4"><i class="fas fa-file-alt"></i> Formularcenter</h1>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'submissions' ? 'active' : ''; ?>" href="?tab=submissions">
                    <i class="fas fa-inbox"></i> Eingegangene Formulare (<?php echo $submissions_total; ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'dienstplan' ? 'active' : ''; ?>" href="?tab=dienstplan">
                    <i class="fas fa-calendar-alt"></i> Dienstplan
                </a>
            </li>
        </ul>

        <?php if ($active_tab === 'submissions'): ?>
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="fas fa-inbox"></i> Eingegangene Formulare</span>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if ($filter_formular !== ''): ?>
                    <?php if ($filter_formular === 'anwesenheitsliste' && !empty($anwesenheitslisten)): ?>
                    <?php $anwesenheitsliste_query = array_filter(array_merge(['filter_typ' => $filter_typ, 'filter_datum_von' => $filter_datum_von, 'filter_datum_bis' => $filter_datum_bis, 'filter_beschreibung' => $filter_beschreibung], $einheit_filter ? ['einheit_id' => $einheit_filter] : [])); ?>
                    <a href="../api/anwesenheitsliste-pdf-alle.php?<?php echo http_build_query($anwesenheitsliste_query); ?>" class="btn btn-success btn-sm" download title="Alle Anwesenheitslisten als PDF herunterladen"><i class="fas fa-file-pdf me-1"></i> Alle Berichte als PDF</a>
                    <button type="button" class="btn btn-dark btn-sm" title="Alle Anwesenheitslisten drucken" onclick="druckenAnwesenheitslisteAlle('<?php echo htmlspecialchars(http_build_query($anwesenheitsliste_query)); ?>', this)"><i class="fas fa-print me-1"></i> Alle drucken</button>
                    <?php endif; ?>
                    <?php if ($filter_formular === 'maengelbericht' && !empty($maengelberichte)): ?>
                    <a href="../api/maengelbericht-pdf-alle.php?<?php echo http_build_query(array_filter(['filter_datum_von' => $filter_datum_von, 'filter_datum_bis' => $filter_datum_bis])); ?>" class="btn btn-success btn-sm" download title="Alle Mängelberichte als PDF herunterladen"><i class="fas fa-file-pdf me-1"></i> Alle Mängelberichte als PDF</a>
                    <button type="button" class="btn btn-dark btn-sm" title="Alle Mängelberichte drucken" onclick="druckenMaengelberichtAlle('<?php echo htmlspecialchars(http_build_query(array_filter(array_merge(['filter_datum_von' => $filter_datum_von, 'filter_datum_bis' => $filter_datum_bis], $einheit_filter ? ['einheit_id' => $einheit_filter] : [])))); ?>', this)"><i class="fas fa-print me-1"></i> Alle Mängelberichte drucken</button>
                    <?php endif; ?>
                    <?php if ($filter_formular === 'geraetewartmitteilung' && !empty($geraetewartmitteilungen)): ?>
                    <a href="../api/geraetewartmitteilung-pdf-alle.php?<?php echo http_build_query(array_filter(['filter_datum_von' => $filter_datum_von, 'filter_datum_bis' => $filter_datum_bis])); ?>" class="btn btn-success btn-sm" download title="Alle Gerätewartmitteilungen als PDF herunterladen"><i class="fas fa-file-pdf me-1"></i> Alle Gerätewartmitteilungen als PDF</a>
                    <button type="button" class="btn btn-dark btn-sm" title="Alle Gerätewartmitteilungen drucken" onclick="druckenGeraetewartmitteilungAlle('<?php echo htmlspecialchars(http_build_query(array_filter(array_merge(['filter_datum_von' => $filter_datum_von, 'filter_datum_bis' => $filter_datum_bis], $einheit_filter ? ['einheit_id' => $einheit_filter] : [])))); ?>', this)"><i class="fas fa-print me-1"></i> Alle Gerätewartmitteilungen drucken</button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <form method="get" class="d-flex flex-wrap align-items-center gap-2">
                        <input type="hidden" name="tab" value="submissions">
                        <input type="hidden" name="filter_formular" value="<?php echo htmlspecialchars($filter_formular); ?>">
                        <?php if ($filter_formular === '' || $filter_formular === 'anwesenheitsliste'): ?>
                        <select name="filter_typ" id="filter-typ" class="form-select form-select-sm" style="width: auto; min-width: 140px;" onchange="this.form.submit();">
                            <option value="">Alle Typen</option>
                            <option value="einsatz" <?php echo $filter_typ === 'einsatz' ? 'selected' : ''; ?>>Einsätze</option>
                            <option value="uebungsdienst" <?php echo $filter_typ === 'uebungsdienst' ? 'selected' : ''; ?>>Übungsdienste</option>
                            <option value="sonstiges" <?php echo $filter_typ === 'sonstiges' ? 'selected' : ''; ?>>Sonstiges</option>
                        </select>
                        <div id="filter-beschreibung-wrap" class="<?php echo $filter_typ === 'sonstiges' ? '' : 'd-none'; ?>">
                            <select name="filter_beschreibung" class="form-select form-select-sm" style="width: auto; min-width: 180px;" title="Nach Beschreibung filtern (nur bei Sonstiges)" onchange="this.form.submit();">
                                <option value="">Alle Beschreibungen</option>
                                <?php foreach ($beschreibung_optionen_sonstiges as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $filter_beschreibung === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <input type="date" name="filter_datum_von" class="form-control form-control-sm" style="width: auto;" value="<?php echo htmlspecialchars($filter_datum_von); ?>" placeholder="Von" onchange="this.form.submit()">
                        <input type="date" name="filter_datum_bis" class="form-control form-control-sm" style="width: auto;" value="<?php echo htmlspecialchars($filter_datum_bis); ?>" placeholder="Bis" onchange="this.form.submit()">
                        <button type="submit" class="btn btn-outline-dark btn-sm"><i class="fas fa-filter"></i> Filtern</button>
                        <?php if ($filter_typ !== '' || $filter_datum_von !== '' || $filter_datum_bis !== '' || $filter_formular !== '' || $filter_beschreibung !== ''): ?>
                        <a href="?tab=submissions" class="btn btn-outline-dark btn-sm">Zurücksetzen</a>
                        <?php endif; ?>
                    </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php
                    $base_params = ['tab' => 'submissions'];
                    if ($filter_typ !== '') $base_params['filter_typ'] = $filter_typ;
                    if ($filter_datum_von !== '') $base_params['filter_datum_von'] = $filter_datum_von;
                    if ($filter_datum_bis !== '') $base_params['filter_datum_bis'] = $filter_datum_bis;
                    if ($filter_beschreibung !== '') $base_params['filter_beschreibung'] = $filter_beschreibung;
                    ?>
                    <div class="mb-4">
                        <span class="me-2 text-muted small fw-semibold">Formular:</span>
                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <a href="?<?php echo http_build_query($base_params); ?>" class="btn btn-sm <?php echo $filter_formular === '' ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="fas fa-th-list me-1"></i> Alle</a>
                            <?php
                                $p = $base_params;
                                $p['filter_formular'] = 'anwesenheitsliste';
                            ?>
                            <a href="?<?php echo http_build_query($p); ?>" class="btn btn-sm <?php echo $filter_formular === 'anwesenheitsliste' ? 'btn-info' : 'btn-outline-info'; ?>"><i class="fas fa-clipboard-list me-1"></i> Anwesenheitsliste (<?php echo $anwesenheitslisten_count; ?>)</a>
                            <?php
                                $p = $base_params;
                                $p['filter_formular'] = 'maengelbericht';
                            ?>
                            <a href="?<?php echo http_build_query($p); ?>" class="btn btn-sm <?php echo $filter_formular === 'maengelbericht' ? 'btn-warning text-dark' : 'btn-outline-warning'; ?>"><i class="fas fa-exclamation-triangle me-1"></i> Mängelbericht (<?php echo $maengelberichte_count; ?>)</a>
                            <?php
                                $p = $base_params;
                                $p['filter_formular'] = 'geraetewartmitteilung';
                            ?>
                            <a href="?<?php echo http_build_query($p); ?>" class="btn btn-sm <?php echo $filter_formular === 'geraetewartmitteilung' ? 'btn-secondary' : 'btn-outline-secondary'; ?>"><i class="fas fa-wrench me-1"></i> Gerätewartmitteilung (<?php echo $geraetewartmitteilungen_count; ?>)</a>
                            <?php foreach ($forms as $f):
                                $cnt = $form_counts_for_buttons[(int)$f['id']] ?? 0;
                                $p = $base_params;
                                $p['filter_formular'] = 'form_' . $f['id'];
                            ?>
                            <a href="?<?php echo http_build_query($p); ?>" class="btn btn-sm <?php echo $filter_formular === 'form_' . $f['id'] ? 'btn-success' : 'btn-outline-success'; ?>"><?php echo htmlspecialchars($f['title']); ?> (<?php echo $cnt; ?>)</a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if (empty($submissions) && empty($anwesenheitslisten) && empty($maengelberichte) && empty($geraetewartmitteilungen)): ?>
                        <p class="text-muted mb-0">Noch keine ausgefüllten Formulare, Anwesenheitslisten oder Mängelberichte vorhanden.<?php if ($filter_typ !== '' || $filter_datum_von !== '' || $filter_datum_bis !== '' || $filter_formular !== '' || $filter_beschreibung !== ''): ?> Versuchen Sie, die Filter zu ändern.<?php endif; ?></p>
                    <?php else: ?>
                        <div class="mb-3">
                            <input type="text" id="formularcenter-suche" class="form-control form-control-sm" style="max-width: 280px;" placeholder="Berichte durchsuchen (Titel, Typ, Benutzer…)" autocomplete="off">
                            <small class="text-muted">Die Suche filtert automatisch beim Tippen.</small>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover" id="formularcenter-tabelle">
                                <thead>
                                    <tr>
                                        <th>Formular / Anwesenheitsliste</th>
                                        <th>Typ</th>
                                        <th>Von</th>
                                        <th>Eingereicht</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $s):
                                        $s['created_at_display'] = format_datetime_berlin($s['created_at']);
                                        $search_text = strtolower(($s['form_title'] ?? '') . ' Formular ' . trim(($s['user_first_name'] ?? '') . ' ' . ($s['user_last_name'] ?? '')) . ' ' . ($s['created_at_display'] ?? ''));
                                    ?>
                                    <tr data-search="<?php echo htmlspecialchars($search_text); ?>">
                                        <td><i class="fas fa-file-alt text-muted me-1"></i> <?php echo htmlspecialchars($s['form_title']); ?></td>
                                        <td><span class="badge bg-secondary">Formular</span></td>
                                        <td><?php echo htmlspecialchars(trim($s['user_first_name'] . ' ' . $s['user_last_name']) ?: 'Unbekannt'); ?></td>
                                        <td><?php echo $s['created_at_display']; ?></td>
                                        <td>
                                            <a href="../formulare-ausfuellen.php?id=<?php echo (int)$s['form_id']; ?>&edit=<?php echo (int)$s['id']; ?>&return=formularcenter" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Bearbeiten</a>
                                            <form method="post" class="d-inline" data-delete-type="Formulareingabe">
                                                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                                                <input type="hidden" name="action" value="delete_submission">
                                                <input type="hidden" name="submission_id" value="<?php echo (int)$s['id']; ?>">
                                                <?php if ($filter_typ !== ''): ?><input type="hidden" name="filter_typ" value="<?php echo htmlspecialchars($filter_typ); ?>"><?php endif; ?>
                                                <?php if ($filter_datum_von !== ''): ?><input type="hidden" name="filter_datum_von" value="<?php echo htmlspecialchars($filter_datum_von); ?>"><?php endif; ?>
                                                <?php if ($filter_datum_bis !== ''): ?><input type="hidden" name="filter_datum_bis" value="<?php echo htmlspecialchars($filter_datum_bis); ?>"><?php endif; ?>
                                                <?php if ($filter_formular !== ''): ?><input type="hidden" name="filter_formular" value="<?php echo htmlspecialchars($filter_formular); ?>"><?php endif; ?>
                                                <?php if ($filter_beschreibung !== ''): ?><input type="hidden" name="filter_beschreibung" value="<?php echo htmlspecialchars($filter_beschreibung); ?>"><?php endif; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="openDeleteConfirm(this, 'Formulareingabe')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($anwesenheitslisten as $a):
                                        $bez = $a['bezeichnung'] ?? $a['dienst_bezeichnung'] ?? 'Anwesenheit';
                                        $titel = date('d.m.Y', strtotime($a['datum'])) . ' – ' . $bez;
                                        if (!empty($a['einsatzstichwort']) && ($a['typ'] ?? '') === 'einsatz') {
                                            $titel .= ' – ' . $a['einsatzstichwort'];
                                        }
                                        $typ_label = htmlspecialchars(get_anwesenheitsliste_typ_label($a));
                                        $search_text = strtolower($titel . ' ' . $typ_label . ' ' . trim(($a['user_first_name'] ?? '') . ' ' . ($a['user_last_name'] ?? '')) . ' ' . format_datetime_berlin($a['created_at']));
                                    ?>
                                    <tr data-search="<?php echo htmlspecialchars($search_text); ?>">
                                        <td><i class="fas fa-clipboard-list text-muted me-1"></i> <?php echo htmlspecialchars($titel); ?></td>
                                        <td><span class="badge bg-info"><?php echo $typ_label; ?></span></td>
                                        <td><?php echo htmlspecialchars(trim($a['user_first_name'] . ' ' . $a['user_last_name']) ?: 'Unbekannt'); ?></td>
                                        <td><?php echo format_datetime_berlin($a['created_at']); ?></td>
                                        <td>
                                            <a href="anwesenheitsliste-bearbeiten.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Anzeigen & Bearbeiten</a>
                                            <a href="../api/anwesenheitsliste-pdf.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-outline-success btn-sm" title="PDF herunterladen" download><i class="fas fa-file-pdf"></i> PDF</a>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" title="Drucken" onclick="druckenAnwesenheitsliste(<?php echo (int)$a['id']; ?>, this)"><i class="fas fa-print"></i> Drucken</button>
                                            <form method="post" class="d-inline" data-delete-type="Anwesenheitsliste">
                                                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                                                <input type="hidden" name="action" value="delete_anwesenheitsliste">
                                                <input type="hidden" name="anwesenheitsliste_id" value="<?php echo (int)$a['id']; ?>">
                                                <?php if ($filter_typ !== ''): ?><input type="hidden" name="filter_typ" value="<?php echo htmlspecialchars($filter_typ); ?>"><?php endif; ?>
                                                <?php if ($filter_datum_von !== ''): ?><input type="hidden" name="filter_datum_von" value="<?php echo htmlspecialchars($filter_datum_von); ?>"><?php endif; ?>
                                                <?php if ($filter_datum_bis !== ''): ?><input type="hidden" name="filter_datum_bis" value="<?php echo htmlspecialchars($filter_datum_bis); ?>"><?php endif; ?>
                                                <?php if ($filter_formular !== ''): ?><input type="hidden" name="filter_formular" value="<?php echo htmlspecialchars($filter_formular); ?>"><?php endif; ?>
                                                <?php if ($filter_beschreibung !== ''): ?><input type="hidden" name="filter_beschreibung" value="<?php echo htmlspecialchars($filter_beschreibung); ?>"><?php endif; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="openDeleteConfirm(this, 'Anwesenheitsliste')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($maengelberichte as $m):
                                        $titel = date('d.m.Y', strtotime($m['aufgenommen_am'])) . ' – ' . htmlspecialchars($m['standort']) . ' – ' . htmlspecialchars($m['mangel_an']);
                                        if (!empty($m['bezeichnung'])) $titel .= ' – ' . htmlspecialchars($m['bezeichnung']);
                                        $search_text = strtolower($titel . ' Mängelbericht ' . trim(($m['user_first_name'] ?? '') . ' ' . ($m['user_last_name'] ?? '')) . ' ' . format_datetime_berlin($m['created_at']));
                                    ?>
                                    <tr data-search="<?php echo htmlspecialchars($search_text); ?>">
                                        <td><i class="fas fa-exclamation-triangle text-warning me-1"></i> <?php echo $titel; ?></td>
                                        <td><span class="badge bg-warning text-dark">Mängelbericht</span></td>
                                        <td><?php echo htmlspecialchars(trim($m['user_first_name'] . ' ' . $m['user_last_name']) ?: 'Unbekannt'); ?></td>
                                        <td><?php echo format_datetime_berlin($m['created_at']); ?><?php if (!empty($m['email_sent_at'])): ?><br><small class="text-success"><i class="fas fa-envelope me-1"></i>Per E-Mail versendet: <?php echo format_datetime_berlin($m['email_sent_at']); ?></small><?php endif; ?></td>
                                        <td>
                                            <a href="maengelbericht-bearbeiten.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Anzeigen & Bearbeiten</a>
                                            <a href="../api/maengelbericht-pdf.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-outline-success btn-sm" title="PDF herunterladen" download><i class="fas fa-file-pdf"></i> PDF</a>
                                            <form method="post" class="d-inline" data-delete-type="Mängelbericht">
                                                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                                                <input type="hidden" name="action" value="delete_maengelbericht">
                                                <input type="hidden" name="maengelbericht_id" value="<?php echo (int)$m['id']; ?>">
                                                <?php if ($filter_datum_von !== ''): ?><input type="hidden" name="filter_datum_von" value="<?php echo htmlspecialchars($filter_datum_von); ?>"><?php endif; ?>
                                                <?php if ($filter_datum_bis !== ''): ?><input type="hidden" name="filter_datum_bis" value="<?php echo htmlspecialchars($filter_datum_bis); ?>"><?php endif; ?>
                                                <?php if ($filter_formular !== ''): ?><input type="hidden" name="filter_formular" value="<?php echo htmlspecialchars($filter_formular); ?>"><?php endif; ?>
                                                <?php if ($filter_beschreibung !== ''): ?><input type="hidden" name="filter_beschreibung" value="<?php echo htmlspecialchars($filter_beschreibung); ?>"><?php endif; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="openDeleteConfirm(this, 'Mängelbericht')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($geraetewartmitteilungen as $g):
                                        $titel = date('d.m.Y', strtotime($g['datum'])) . ' – ' . ($g['typ'] === 'einsatz' ? 'Einsatz' : 'Übung') . ' – ' . htmlspecialchars($g['einsatz_uebungsart']);
                                        $search_text = strtolower($titel . ' ' . trim(($g['user_first_name'] ?? '') . ' ' . ($g['user_last_name'] ?? '')) . ' ' . format_datetime_berlin($g['created_at']));
                                    ?>
                                    <tr data-search="<?php echo htmlspecialchars($search_text); ?>">
                                        <td><i class="fas fa-wrench text-info me-1"></i> <?php echo $titel; ?></td>
                                        <td><span class="badge bg-info"><?php echo $g['typ'] === 'einsatz' ? 'Einsatz' : 'Übung'; ?></span></td>
                                        <td><?php echo htmlspecialchars(trim($g['user_first_name'] . ' ' . $g['user_last_name']) ?: 'Unbekannt'); ?></td>
                                        <td><?php echo format_datetime_berlin($g['created_at']); ?></td>
                                        <td>
                                            <a href="geraetewartmitteilung-bearbeiten.php?id=<?php echo (int)$g['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Anzeigen & Bearbeiten</a>
                                            <a href="../api/geraetewartmitteilung-pdf.php?id=<?php echo (int)$g['id']; ?>" class="btn btn-outline-success btn-sm" title="PDF herunterladen" download><i class="fas fa-file-pdf"></i> PDF</a>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" title="Drucken" onclick="druckenGeraetewartmitteilung(<?php echo (int)$g['id']; ?>, this)"><i class="fas fa-print"></i> Drucken</button>
                                            <form method="post" class="d-inline" data-delete-type="Gerätewartmitteilung">
                                                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                                                <input type="hidden" name="action" value="delete_geraetewartmitteilung">
                                                <input type="hidden" name="geraetewartmitteilung_id" value="<?php echo (int)$g['id']; ?>">
                                                <?php if ($filter_datum_von !== ''): ?><input type="hidden" name="filter_datum_von" value="<?php echo htmlspecialchars($filter_datum_von); ?>"><?php endif; ?>
                                                <?php if ($filter_datum_bis !== ''): ?><input type="hidden" name="filter_datum_bis" value="<?php echo htmlspecialchars($filter_datum_bis); ?>"><?php endif; ?>
                                                <?php if ($filter_formular !== ''): ?><input type="hidden" name="filter_formular" value="<?php echo htmlspecialchars($filter_formular); ?>"><?php endif; ?>
                                                <?php if ($filter_beschreibung !== ''): ?><input type="hidden" name="filter_beschreibung" value="<?php echo htmlspecialchars($filter_beschreibung); ?>"><?php endif; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="openDeleteConfirm(this, 'Gerätewartmitteilung')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'dienstplan'): ?>
            <div class="card shadow">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="fas fa-calendar-alt"></i> Dienstplan</span>
                    <div class="d-flex align-items-center gap-2">
                        <form method="get" class="d-inline">
                            <input type="hidden" name="tab" value="dienstplan">
                            <select name="jahr" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                                <?php for ($y = date('Y') + 1; $y >= date('Y') - 2; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $dienstplan_jahr === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </form>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dienstplanModal" onclick="openDienstplanModal()">
                            <i class="fas fa-plus"></i> Neuer Eintrag
                        </button>
                        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#diveraImportModal">
                            <i class="fas fa-download"></i> Aus Divera importieren
                        </button>
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#diveraExportModal">
                            <i class="fas fa-upload"></i> Nach Divera exportieren
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($dienstplan_eintraege)): ?>
                        <p class="text-muted mb-0">Keine Einträge für <?php echo $dienstplan_jahr; ?>. Legen Sie Übungsdienste an.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Typ</th>
                                        <th>Thema</th>
                                        <th>Dienstbeginn</th>
                                        <th>Dienstende</th>
                                        <th>Ausbilder</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dienstplan_eintraege as $e):
                                        $ausbilder_namen = [];
                                        if (!empty($e['ausbilder_ids'])) {
                                            foreach (array_map('intval', explode(',', $e['ausbilder_ids'])) as $mid) {
                                                if ($mid > 0 && isset($members_by_id[$mid])) {
                                                    $ausbilder_namen[] = $members_by_id[$mid];
                                                }
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo date('d.m.Y', strtotime($e['datum'])); ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars(get_dienstplan_typ_label($e['typ'] ?? 'uebungsdienst')); ?></span></td>
                                        <td><?php echo htmlspecialchars($e['bezeichnung']); ?></td>
                                        <td><?php echo !empty($e['uhrzeit_dienstbeginn']) ? substr($e['uhrzeit_dienstbeginn'], 0, 5) : '—'; ?></td>
                                        <td><?php echo !empty($e['uhrzeit_dienstende']) ? substr($e['uhrzeit_dienstende'], 0, 5) : '—'; ?></td>
                                        <td><small><?php echo !empty($ausbilder_namen) ? htmlspecialchars(implode(', ', $ausbilder_namen)) : '—'; ?></small></td>
                                        <td>
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dienstplanModal" onclick='openDienstplanModal(<?php echo json_encode($e); ?>)'><i class="fas fa-edit"></i></button>
                                            <form method="post" class="d-inline" data-delete-type="Dienstplan-Eintrag">
                                                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                                                <input type="hidden" name="action" value="dienstplan_delete">
                                                <input type="hidden" name="dienstplan_id" value="<?php echo (int)$e['id']; ?>">
                                                <button type="button" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="openDeleteConfirm(this, 'Dienstplan-Eintrag')"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal: Dienstplan Eintrag -->
    <div class="modal fade" id="dienstplanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                    <input type="hidden" name="action" value="dienstplan_save">
                    <input type="hidden" name="dienstplan_id" id="dienstplan_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="dienstplanModalTitle">Neuer Eintrag</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="dienstplan_datum" class="form-label">Datum *</label>
                            <input type="date" class="form-control" id="dienstplan_datum" name="dienstplan_datum" required>
                        </div>
                        <div class="mb-3">
                            <label for="dienstplan_typ" class="form-label">Typ *</label>
                            <select class="form-select" id="dienstplan_typ" name="dienstplan_typ">
                                <?php foreach (get_dienstplan_typen_auswahl() as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="dienstplan_thema_wrap">
                            <label for="dienstplan_thema" class="form-label">Thema *</label>
                            <select class="form-select" id="dienstplan_thema" name="dienstplan_thema">
                                <option value="">— Bitte wählen oder neues Thema eingeben —</option>
                                <?php foreach ($dienstplan_themen as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                                <option value="__neu__">— Neues Thema eingeben —</option>
                            </select>
                            <div class="mt-2" id="dienstplan_thema_neu_wrap" style="display: none;">
                                <input type="text" class="form-control" id="dienstplan_thema_neu" name="dienstplan_thema_neu" placeholder="Neues Thema (wird für spätere Einträge gespeichert)">
                            </div>
                        </div>
                        <div class="mb-3" id="dienstplan_beschreibung_wrap" style="display: none;">
                            <label for="dienstplan_beschreibung" class="form-label">Beschreibung *</label>
                            <input type="text" class="form-control" id="dienstplan_beschreibung" name="dienstplan_beschreibung" placeholder="z.B. Jahreshauptversammlung, Geräteprüfung, ...">
                        </div>
                        <div class="mb-3">
                            <label for="dienstplan_uhrzeit" class="form-label">Uhrzeit Dienstbeginn</label>
                            <input type="time" class="form-control" id="dienstplan_uhrzeit" name="dienstplan_uhrzeit">
                            <div class="form-text">Wird automatisch in die Anwesenheitsliste übernommen (Uhrzeit von).</div>
                        </div>
                        <div class="mb-3">
                            <label for="dienstplan_uhrzeit_ende" class="form-label">Uhrzeit Dienstende</label>
                            <input type="time" class="form-control" id="dienstplan_uhrzeit_ende" name="dienstplan_uhrzeit_ende">
                            <div class="form-text">Wird in die Anwesenheitsliste übernommen (Uhrzeit bis).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ausbilder</label>
                            <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                <?php foreach ($members_for_ausbilder as $m): ?>
                                <div class="form-check">
                                    <input class="form-check-input dienstplan-ausbilder-cb" type="checkbox" name="dienstplan_ausbilder[]" value="<?php echo (int)$m['id']; ?>" id="ausb_<?php echo (int)$m['id']; ?>">
                                    <label class="form-check-label" for="ausb_<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></label>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($members_for_ausbilder)): ?>
                                <p class="text-muted small mb-0">Keine Mitglieder vorhanden.</p>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">Werden in der Anwesenheitsliste als Übungsleiter vorausgewählt.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Löschen bestätigen -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-body text-center py-4 px-4">
                    <div class="mb-3">
                        <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                            <i class="fas fa-trash-alt text-danger fa-2x"></i>
                        </div>
                    </div>
                    <h5 class="modal-title mb-2" id="deleteConfirmModalLabel">Wirklich löschen?</h5>
                    <p class="text-muted mb-0" id="deleteConfirmModalText">Möchten Sie diesen Eintrag wirklich unwiderruflich löschen? Diese Aktion kann nicht rückgängig gemacht werden.</p>
                </div>
                <div class="modal-footer justify-content-center gap-2 border-0 pb-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Abbrechen</button>
                    <button type="button" class="btn btn-danger" id="deleteConfirmBtn"><i class="fas fa-trash me-1"></i> Ja, löschen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Divera Import -->
    <div class="modal fade" id="diveraImportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-download"></i> Termine aus Divera importieren</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Zeitraum</label>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <input type="date" class="form-control" id="importFrom" value="<?php echo $dienstplan_jahr; ?>-01-01">
                            </div>
                            <div class="col-md-5">
                                <input type="date" class="form-control" id="importTo" value="<?php echo $dienstplan_jahr + 1; ?>-12-31">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-primary w-100" id="btnLoadDiveraEvents">
                                    <i class="fas fa-sync"></i> Laden
                                </button>
                            </div>
                        </div>
                        <small class="text-muted">Bei Problemen: <a href="api-dienstplan-divera.php?debug=1" target="_blank">Debug-Ausgabe öffnen</a></small>
                    </div>
                    <div id="importEventsList" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                        <p class="text-muted small mb-0">Klicken Sie auf „Laden“, um Termine von Divera abzurufen.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    <button type="button" class="btn btn-success" id="btnImportDivera" disabled>
                        <i class="fas fa-download"></i> Ausgewählte importieren
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Divera Export -->
    <div class="modal fade" id="diveraExportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-upload"></i> Termine nach Divera exportieren</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Empfänger-Gruppe (Divera)</label>
                        <select class="form-select" id="exportGroupId">
                            <option value="">– Keine Gruppe –</option>
                            <?php foreach ($divera_groups as $g):
                                $gid = (int)($g['id'] ?? 0);
                                $gval = $gid > 0 ? (string)$gid : '0';
                                $gname = htmlspecialchars($g['name'] ?? ($gid > 0 ? 'Gruppe ' . $gid : 'Alle des Standortes'));
                                $glabel = $gid > 0 ? $gname . ' (ID: ' . $gid . ')' : $gname;
                            ?>
                            <option value="<?php echo $gval; ?>" <?php echo $divera_default_group_id === $gval ? 'selected' : ''; ?>>
                                <?php echo $glabel; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="exportEntriesList" class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($dienstplan_eintraege)): ?>
                        <p class="text-muted small mb-0">Keine Dienstplan-Einträge im aktuellen Jahr vorhanden.</p>
                        <?php else: ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="exportSelectAll">
                            <label class="form-check-label" for="exportSelectAll">Alle auswählen</label>
                        </div>
                        <hr class="my-2">
                        <?php foreach ($dienstplan_eintraege as $e): ?>
                        <div class="form-check">
                            <input class="form-check-input export-entry-cb" type="checkbox" value="<?php echo (int)$e['id']; ?>">
                            <label class="form-check-label">
                                <?php echo htmlspecialchars($e['datum'] . ' – ' . $e['bezeichnung']); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                    <button type="button" class="btn btn-info" id="btnExportDivera" <?php echo empty($dienstplan_eintraege) ? 'disabled' : ''; ?>>
                        <i class="fas fa-upload"></i> Ausgewählte exportieren
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Eingabe bearbeiten -->
    <div class="modal fade" id="submissionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="submissionForm">
                    <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                    <input type="hidden" name="action" value="save_submission">
                    <input type="hidden" name="submission_id" id="submission_id" value="">
                    <input type="hidden" name="form_data" id="submission_form_data" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Eingabe bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="submissionModalBody">
                        <p class="text-muted">Laden...</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/includes/print-toast.inc.php'; ?>
    <script>
        var FORMULARCENTER_EINHEIT_ID = <?php echo $einheit_filter ? (int)$einheit_filter : 0; ?>;
        var deleteConfirmFormToSubmit = null;
        function openDeleteConfirm(btn, typeName) {
            var form = btn.closest('form');
            if (!form) return;
            deleteConfirmFormToSubmit = form;
            var textEl = document.getElementById('deleteConfirmModalText');
            if (textEl) textEl.textContent = 'Möchten Sie diese ' + typeName + ' wirklich unwiderruflich löschen? Diese Aktion kann nicht rückgängig gemacht werden.';
            var modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            modal.show();
        }
        document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
            if (deleteConfirmFormToSubmit) {
                deleteConfirmFormToSubmit.submit();
                deleteConfirmFormToSubmit = null;
            }
            bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();
        });
        document.getElementById('deleteConfirmModal').addEventListener('hidden.bs.modal', function() {
            deleteConfirmFormToSubmit = null;
        });

        function openFormModal(form) {
            document.getElementById('formEditModalTitle').textContent = form ? 'Formular bearbeiten' : 'Neues Formular';
            document.getElementById('form_id').value = form ? form.id : '';
            document.getElementById('form_title').value = form ? (form.title || '') : '';
            document.getElementById('form_description').value = form ? (form.description || '') : '';
            document.getElementById('form_schema_json').value = form && form.schema_json ? form.schema_json : '[]';
            document.getElementById('form_is_active').checked = form ? (form.is_active == 1) : true;
        }
        function openSubmissionModal(sub) {
            document.getElementById('submission_id').value = sub.id;
            document.getElementById('submission_form_data').value = sub.form_data || '{}';
            var data = {};
            try { data = JSON.parse(sub.form_data || '{}'); } catch(e) {}
            var formTitle = sub.form_title || 'Formular';
            var html = '<p><strong>' + escapeHtml(formTitle) + '</strong></p><p class="text-muted small">Von: ' + escapeHtml((sub.user_first_name || '') + ' ' + (sub.user_last_name || '')) + ', ' + (sub.created_at_display || sub.created_at || '') + '</p><div class="mb-3"><label class="form-label">Daten (JSON)</label><textarea class="form-control font-monospace" id="submission_data_edit" rows="12">' + escapeHtml(JSON.stringify(data, null, 2)) + '</textarea></div>';
            document.getElementById('submissionModalBody').innerHTML = html;
            document.getElementById('submissionForm').onsubmit = function() {
                try {
                    var j = JSON.parse(document.getElementById('submission_data_edit').value);
                    document.getElementById('submission_form_data').value = JSON.stringify(j);
                } catch(e) {
                    alert('Ungültiges JSON.');
                    return false;
                }
            };
        }
        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        function openDienstplanModal(entry) {
            document.getElementById('dienstplanModalTitle').textContent = entry ? 'Eintrag bearbeiten' : 'Neuer Eintrag';
            document.getElementById('dienstplan_id').value = entry ? entry.id : '';
            document.getElementById('dienstplan_datum').value = entry ? (entry.datum || '') : '';
            var uhrzeitEl = document.getElementById('dienstplan_uhrzeit');
            if (uhrzeitEl) uhrzeitEl.value = entry && entry.uhrzeit_dienstbeginn ? (entry.uhrzeit_dienstbeginn.length >= 5 ? entry.uhrzeit_dienstbeginn.substring(0, 5) : entry.uhrzeit_dienstbeginn) : '';
            var uhrzeitEndeEl = document.getElementById('dienstplan_uhrzeit_ende');
            if (uhrzeitEndeEl) uhrzeitEndeEl.value = entry && entry.uhrzeit_dienstende ? (entry.uhrzeit_dienstende.length >= 5 ? entry.uhrzeit_dienstende.substring(0, 5) : entry.uhrzeit_dienstende) : '';
            var ausbilderIds = [];
            if (entry) {
                if (Array.isArray(entry.ausbilder_member_ids)) ausbilderIds = entry.ausbilder_member_ids.map(function(x){return parseInt(x,10);});
                else if (entry.ausbilder_ids) ausbilderIds = String(entry.ausbilder_ids).split(',').map(function(x){return parseInt(x.trim(),10);}).filter(function(x){return !isNaN(x);});
            }
            document.querySelectorAll('.dienstplan-ausbilder-cb').forEach(function(cb) {
                cb.checked = ausbilderIds.indexOf(parseInt(cb.value, 10)) >= 0;
            });
            var typSel = document.getElementById('dienstplan_typ');
            if (typSel) typSel.value = entry && entry.typ ? entry.typ : 'uebungsdienst';
            updateDienstplanTypFields();
            var themaSel = document.getElementById('dienstplan_thema');
            var themaNeuWrap = document.getElementById('dienstplan_thema_neu_wrap');
            var themaNeu = document.getElementById('dienstplan_thema_neu');
            var beschreibungEl = document.getElementById('dienstplan_beschreibung');
            var typ = typSel ? typSel.value : 'uebungsdienst';
            if (entry && entry.bezeichnung) {
                if (typ === 'uebungsdienst') {
                    var opt = Array.from(themaSel.options).find(function(o) { return o.value === entry.bezeichnung; });
                    if (opt) {
                        themaSel.value = entry.bezeichnung;
                        themaNeuWrap.style.display = 'none';
                    } else {
                        themaSel.value = '__neu__';
                        themaNeu.value = entry.bezeichnung;
                        themaNeuWrap.style.display = 'block';
                    }
                } else {
                    if (beschreibungEl) beschreibungEl.value = entry.bezeichnung;
                }
            } else {
                themaSel.value = '';
                themaNeu.value = '';
                themaNeuWrap.style.display = 'none';
                if (beschreibungEl) beschreibungEl.value = '';
            }
            if (themaSel) themaSel.dispatchEvent(new Event('change'));
        }
        function updateDienstplanTypFields() {
            var typ = (document.getElementById('dienstplan_typ') || {}).value || 'uebungsdienst';
            var themaWrap = document.getElementById('dienstplan_thema_wrap');
            var beschreibungWrap = document.getElementById('dienstplan_beschreibung_wrap');
            if (themaWrap) themaWrap.style.display = (typ === 'uebungsdienst') ? 'block' : 'none';
            if (beschreibungWrap) beschreibungWrap.style.display = (typ === 'sonstiges' || typ === 'einsatz') ? 'block' : 'none';
            var thema = document.getElementById('dienstplan_thema');
            var beschreibung = document.getElementById('dienstplan_beschreibung');
            if (thema) thema.required = (typ === 'uebungsdienst');
            if (beschreibung) beschreibung.required = (typ === 'sonstiges' || typ === 'einsatz');
        }
        document.getElementById('dienstplan_typ').addEventListener('change', updateDienstplanTypFields);
        document.getElementById('dienstplan_thema').addEventListener('change', function() {
            var wrap = document.getElementById('dienstplan_thema_neu_wrap');
            wrap.style.display = this.value === '__neu__' ? 'block' : 'none';
        });
        <?php if ($edit_dienstplan): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openDienstplanModal(<?php echo json_encode($edit_dienstplan); ?>);
            var m = document.getElementById('dienstplanModal');
            if (m) new bootstrap.Modal(m).show();
        });
        <?php endif; ?>

        // Divera Import
        var diveraImportEvents = [];
        document.getElementById('btnLoadDiveraEvents').addEventListener('click', function() {
            var from = document.getElementById('importFrom').value;
            var to = document.getElementById('importTo').value;
            if (!from || !to) { alert('Bitte Zeitraum angeben.'); return; }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Laden...';
            fetch('api-dienstplan-divera.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + (FORMULARCENTER_EINHEIT_ID > 0 ? '&einheit_id=' + FORMULARCENTER_EINHEIT_ID : ''))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    diveraImportEvents = data.events || [];
                    var list = document.getElementById('importEventsList');
                    if (!data.success || diveraImportEvents.length === 0) {
                        list.innerHTML = '<p class="text-muted small mb-0">' + (data.message || 'Keine Termine gefunden.') + '</p>';
                    } else {
                        var html = '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="importSelectAll"><label class="form-check-label" for="importSelectAll">Alle auswählen</label></div><hr class="my-2">';
                        diveraImportEvents.forEach(function(ev) {
                            var d = new Date(ev.ts_start * 1000);
                            var dateStr = d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', {hour:'2-digit',minute:'2-digit'});
                            var thema = (ev.text || ev.title || '').trim();
                            html += '<div class="form-check"><input class="form-check-input import-event-cb" type="checkbox" value="' + ev.id + '"><label class="form-check-label">' + escapeHtml(dateStr + ' – ' + thema) + '</label></div>';
                        });
                        list.innerHTML = html;
                        document.getElementById('importSelectAll').addEventListener('change', function() {
                            document.querySelectorAll('.import-event-cb').forEach(function(cb) { cb.checked = this.checked; }, this);
                        });
                        document.querySelectorAll('.import-event-cb').forEach(function(cb) {
                            cb.addEventListener('change', updateImportBtn);
                        });
                        updateImportBtn();
                    }
                    document.getElementById('btnImportDivera').disabled = diveraImportEvents.length === 0;
                })
                .catch(function() {
                    document.getElementById('importEventsList').innerHTML = '<p class="text-danger small mb-0">Fehler beim Laden.</p>';
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sync"></i> Laden';
                });
        });
        function updateImportBtn() {
            var any = document.querySelector('.import-event-cb:checked');
            document.getElementById('btnImportDivera').disabled = !any;
        }
        document.getElementById('btnImportDivera').addEventListener('click', function() {
            var ids = [];
            document.querySelectorAll('.import-event-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });
            if (ids.length === 0) { alert('Bitte mindestens einen Termin auswählen.'); return; }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Importiere...';
            fetch('api-dienstplan-divera.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'import', event_ids: ids, einheit_id: FORMULARCENTER_EINHEIT_ID > 0 ? FORMULARCENTER_EINHEIT_ID : null })
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        alert(data.message || 'Import erfolgreich.');
                        location.reload();
                    } else {
                        alert(data.message || 'Import fehlgeschlagen.');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-download"></i> Ausgewählte importieren';
                    }
                })
                .catch(function() {
                    alert('Fehler beim Import.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-download"></i> Ausgewählte importieren';
                });
        });

        // Divera Export
        var exportSelectAll = document.getElementById('exportSelectAll');
        if (exportSelectAll) {
            exportSelectAll.addEventListener('change', function() {
                var checked = this.checked;
                document.querySelectorAll('.export-entry-cb').forEach(function(cb) { cb.checked = checked; });
            });
        }
        document.getElementById('btnExportDivera').addEventListener('click', function() {
            var ids = [];
            document.querySelectorAll('.export-entry-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });
            if (ids.length === 0) { alert('Bitte mindestens einen Eintrag auswählen.'); return; }
            var groupVal = document.getElementById('exportGroupId').value;
            var groupIds = groupVal !== '' ? [parseInt(groupVal, 10) || 0] : [];
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Exportiere...';
            fetch('api-dienstplan-divera.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'export', entry_ids: ids, group_ids: groupIds, einheit_id: FORMULARCENTER_EINHEIT_ID > 0 ? FORMULARCENTER_EINHEIT_ID : null })
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var msg = data.message || 'Export erfolgreich.';
                        if (data.errors && data.errors.length) msg += '\n\nHinweise: ' + data.errors.join('; ');
                        alert(msg);
                        location.reload();
                    } else {
                        alert(data.message || 'Export fehlgeschlagen.');
                    }
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload"></i> Ausgewählte exportieren';
                })
                .catch(function() {
                    alert('Fehler beim Export.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-upload"></i> Ausgewählte exportieren';
                });
        });

        function handlePrintResponse(data, btn, btnText) {
            if (data.success) {
                if (data.open_pdf && data.pdf_base64) {
                    var blob = null;
                    try {
                        var binary = atob(data.pdf_base64);
                        var bytes = new Uint8Array(binary.length);
                        for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                        blob = new Blob([bytes], { type: 'application/pdf' });
                    } catch (e) { showPrintToast('PDF konnte nicht geöffnet werden.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } return; }
                    var url = URL.createObjectURL(blob);
                    var w = window.open(url, '_blank', 'noopener');
                    if (w) {
                        w.onload = function() {
                            try { w.print(); } catch (e) {}
                            setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
                        };
                    }
                    showPrintToast('PDF wurde geöffnet. Der Druckdialog sollte sich öffnen – sonst Strg+P drücken.', true);
                } else {
                    showPrintToast('Druckauftrag wurde gesendet.', true);
                }
            } else {
                showPrintToast('Fehler: ' + (data.message || 'Unbekannter Fehler'), false);
            }
            if (btn) { btn.disabled = false; btn.innerHTML = btnText; }
        }
        function druckenAnwesenheitsliste(id, btn) {
            var btnText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
            var url = '../api/print-anwesenheitsliste.php?id=' + id + (FORMULARCENTER_EINHEIT_ID > 0 ? '&einheit_id=' + FORMULARCENTER_EINHEIT_ID : '');
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) { handlePrintResponse(data, btn, btnText); })
                .catch(function() { showPrintToast('Fehler beim Senden des Druckauftrags.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } });
        }
        function druckenAnwesenheitslisteAlle(query, btn) {
            var btnText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
            fetch('../api/print-anwesenheitsliste.php?alle=1' + (query ? '&' + query : ''))
                .then(function(r) { return r.json(); })
                .then(function(data) { handlePrintResponse(data, btn, btnText); })
                .catch(function() { showPrintToast('Fehler beim Senden des Druckauftrags.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } });
        }
        function druckenMaengelbericht(id, btn) {
            var btnText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
            var url = '../api/print-maengelbericht.php?id=' + id + (FORMULARCENTER_EINHEIT_ID > 0 ? '&einheit_id=' + FORMULARCENTER_EINHEIT_ID : '');
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) { handlePrintResponse(data, btn, btnText); })
                .catch(function() { showPrintToast('Fehler beim Senden des Druckauftrags.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } });
        }
        function druckenMaengelberichtAlle(query, btn) {
            var btnText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
            fetch('../api/print-maengelbericht.php?alle=1' + (query ? '&' + query : ''))
                .then(function(r) { return r.json(); })
                .then(function(data) { handlePrintResponse(data, btn, btnText); })
                .catch(function() { showPrintToast('Fehler beim Senden des Druckauftrags.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } });
        }
        function druckenGeraetewartmitteilung(id, btn) {
            var btnText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
            var url = '../api/print-geraetewartmitteilung.php?id=' + id + (FORMULARCENTER_EINHEIT_ID > 0 ? '&einheit_id=' + FORMULARCENTER_EINHEIT_ID : '');
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) { handlePrintResponse(data, btn, btnText); })
                .catch(function() { showPrintToast('Fehler beim Senden des Druckauftrags.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } });
        }
        function druckenGeraetewartmitteilungAlle(query, btn) {
            var btnText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
            fetch('../api/print-geraetewartmitteilung.php?alle=1' + (query ? '&' + query : ''))
                .then(function(r) { return r.json(); })
                .then(function(data) { handlePrintResponse(data, btn, btnText); })
                .catch(function() { showPrintToast('Fehler beim Senden des Druckauftrags.', false); if (btn) { btn.disabled = false; btn.innerHTML = btnText; } });
        }

        // Live-Suche für Berichte (filtert beim Tippen)
        (function() {
            var sucheEl = document.getElementById('formularcenter-suche');
            var table = document.getElementById('formularcenter-tabelle');
            if (!sucheEl || !table) return;
            var tbodyEl = table.querySelector('tbody');
            if (!tbodyEl) return;
            var rows = tbodyEl.querySelectorAll('tr[data-search]');
            var debounceTimer;
            sucheEl.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    var q = (sucheEl.value || '').toLowerCase().trim();
                    rows.forEach(function(tr) {
                        var text = (tr.getAttribute('data-search') || '').toLowerCase();
                        tr.style.display = (q === '' || text.indexOf(q) >= 0) ? '' : 'none';
                    });
                }, 80);
            });
        })();
    </script>
</body>
</html>
