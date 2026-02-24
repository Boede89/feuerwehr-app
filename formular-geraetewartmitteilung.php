<?php
/**
 * Gerätewartmitteilung – Erfassung eingesetzter Fahrzeuge und Geräte bei Einsatz/Übung.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!has_permission('forms')) {
    header('Location: index.php?error=no_forms_access');
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
}
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';

// Tabellen anlegen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS geraetewartmitteilungen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            typ VARCHAR(20) NOT NULL,
            einsatz_uebungsart VARCHAR(50) NOT NULL,
            datum DATE NOT NULL,
            einsatzbereitschaft VARCHAR(30) NOT NULL,
            mangel_beschreibung TEXT NULL,
            einsatzleiter_member_id INT NULL,
            einsatzleiter_freitext VARCHAR(255) NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            KEY idx_datum (datum),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS geraetewartmitteilung_fahrzeuge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            geraetewartmitteilung_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            maschinist_member_id INT NULL,
            einheitsfuehrer_member_id INT NULL,
            equipment_used JSON NULL,
            defective_equipment JSON NULL,
            defective_freitext TEXT NULL,
            defective_mangel TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (geraetewartmitteilung_id) REFERENCES geraetewartmitteilungen(id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            UNIQUE KEY unique_gwm_vehicle (geraetewartmitteilung_id, vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('geraetewartmitteilungen: ' . $e->getMessage());
}
try {
    $db->exec("ALTER TABLE geraetewartmitteilungen ADD COLUMN einheit_id INT NULL");
} catch (Exception $e) {
    /* Spalte existiert ggf. bereits */
}
try {
    $db->exec("CREATE TABLE IF NOT EXISTS vehicle_equipment (id INT AUTO_INCREMENT PRIMARY KEY, vehicle_id INT NOT NULL, name VARCHAR(255) NOT NULL, category_id INT NULL, sort_order INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE, KEY idx_vehicle (vehicle_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE vehicle_equipment ADD COLUMN category_id INT NULL"); } catch (Exception $e2) {}
} catch (Exception $e) {}

$typ_options = ['einsatz' => 'Einsatz', 'uebung' => 'Übung'];
$art_options = ['Brandeinsatz' => 'Brandeinsatz', 'Techn. Hilfe' => 'Techn. Hilfe', 'CBRN' => 'CBRN', 'Sonstiges' => 'Sonstiges'];
$einsatzbereitschaft_options = ['hergestellt' => 'Einsatzbereitschaft wurde hergestellt', 'nicht_hergestellt' => 'Einsatzbereitschaft wurde nicht hergestellt'];

$vehicles = [];
$vehicles_with_equipment = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    }
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vehicles as $v) {
        $vid = (int)$v['id'];
        $stmt2 = $db->prepare("SELECT id, name FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
        $stmt2->execute([$vid]);
        $equipment = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $vehicles_with_equipment[$vid] = $equipment;
    }
} catch (Exception $e) {}

$members_for_einsatzleiter = anwesenheitsliste_members_for_leiter($db, [], $einheit_id);
$members_all = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    }
    $members_all = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_geraetewartmitteilung'])) {
    $typ = in_array(trim($_POST['typ'] ?? ''), ['einsatz', 'uebung']) ? trim($_POST['typ']) : 'uebung';
    $art = trim($_POST['einsatz_uebungsart'] ?? '');
    if (!isset($art_options[$art])) $art = 'Brandeinsatz';
    $datum = trim($_POST['datum'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = date('Y-m-d');
    $einsatzbereitschaft = in_array(trim($_POST['einsatzbereitschaft'] ?? ''), ['hergestellt', 'nicht_hergestellt']) ? trim($_POST['einsatzbereitschaft']) : 'hergestellt';
    $mangel_beschreibung = trim($_POST['mangel_beschreibung'] ?? '') ?: null;
    $einsatzleiter = trim($_POST['einsatzleiter'] ?? '');
    $einsatzleiter_member_id = null;
    $einsatzleiter_freitext = null;
    if ($einsatzleiter === '__freitext__') {
        $einsatzleiter_freitext = trim($_POST['einsatzleiter_freitext'] ?? '') ?: null;
    } elseif (preg_match('/^\d+$/', $einsatzleiter)) {
        $einsatzleiter_member_id = (int)$einsatzleiter;
    }

    $vehicle_ids = isset($_POST['vehicle_id']) && is_array($_POST['vehicle_id']) ? array_filter(array_map('intval', $_POST['vehicle_id']), fn($x) => $x > 0) : [];
    if (empty($vehicle_ids)) {
        $error = 'Bitte wählen Sie mindestens ein Fahrzeug aus.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO geraetewartmitteilungen (typ, einsatz_uebungsart, datum, einsatzbereitschaft, mangel_beschreibung, einsatzleiter_member_id, einsatzleiter_freitext, user_id, einheit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$typ, $art, $datum, $einsatzbereitschaft, $mangel_beschreibung, $einsatzleiter_member_id, $einsatzleiter_freitext, $_SESSION['user_id'], $einheit_id > 0 ? $einheit_id : null]);
            $gwm_id = $db->lastInsertId();

            $stmt_f = $db->prepare("INSERT INTO geraetewartmitteilung_fahrzeuge (geraetewartmitteilung_id, vehicle_id, maschinist_member_id, einheitsfuehrer_member_id, equipment_used, defective_equipment, defective_freitext, defective_mangel) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($vehicle_ids as $vid) {
                $masch = isset($_POST['maschinist'][$vid]) && preg_match('/^\d+$/', $_POST['maschinist'][$vid]) ? (int)$_POST['maschinist'][$vid] : null;
                $einh = isset($_POST['einheitsfuehrer'][$vid]) && preg_match('/^\d+$/', $_POST['einheitsfuehrer'][$vid]) ? (int)$_POST['einheitsfuehrer'][$vid] : null;
                $equipment_used = isset($_POST['equipment'][$vid]) && is_array($_POST['equipment'][$vid]) ? array_values(array_filter(array_map('intval', $_POST['equipment'][$vid]), fn($x) => $x > 0)) : [];
                $defective = isset($_POST['defective_equipment'][$vid]) && is_array($_POST['defective_equipment'][$vid]) ? array_values(array_filter(array_map('intval', $_POST['defective_equipment'][$vid]), fn($x) => $x > 0)) : [];
                $defective_freitext = trim($_POST['defective_freitext'][$vid] ?? '') ?: null;
                $defective_mangel = trim($_POST['defective_mangel'][$vid] ?? '') ?: null;
                $stmt_f->execute([$gwm_id, $vid, $masch, $einh, json_encode($equipment_used), json_encode($defective), $defective_freitext, $defective_mangel]);
            }

            // Automatischer E-Mail-Versand (wenn in Einstellungen aktiviert)
            $email_auto = false;
            $email_recipients = [];
            $email_manual = '';
            try {
                $stmt_s = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('geraetewartmitteilung_email_auto', 'geraetewartmitteilung_email_recipients', 'geraetewartmitteilung_email_manual')");
                $stmt_s->execute();
                foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['setting_key'] === 'geraetewartmitteilung_email_auto') $email_auto = ($r['setting_value'] ?? '0') === '1';
                    elseif ($r['setting_key'] === 'geraetewartmitteilung_email_recipients') $email_recipients = json_decode($r['setting_value'] ?? '[]', true) ?: [];
                    elseif ($r['setting_key'] === 'geraetewartmitteilung_email_manual') $email_manual = trim($r['setting_value'] ?? '');
                }
            } catch (Exception $e) {}
            $all_emails = [];
            if ($email_auto && (is_array($email_recipients) && !empty($email_recipients) || $email_manual !== '')) {
                if (!empty($email_recipients)) {
                    $ph = implode(',', array_fill(0, count($email_recipients), '?'));
                    $stmt_u = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND email IS NOT NULL AND email != ''");
                    $stmt_u->execute(array_map('intval', $email_recipients));
                    foreach ($stmt_u->fetchAll(PDO::FETCH_COLUMN) as $em) { $all_emails[] = trim($em); }
                }
                if ($email_manual !== '') {
                    foreach (preg_split('/[\r\n,;]+/', $email_manual, -1, PREG_SPLIT_NO_EMPTY) as $em) {
                        $em = trim($em);
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) $all_emails[] = $em;
                    }
                }
                $all_emails = array_unique(array_filter($all_emails));
                if (!empty($all_emails) && function_exists('send_email_with_pdf_attachment')) {
                    $pdf_content = null;
                    $_GET['id'] = $gwm_id;
                    $_GET['_return'] = '1';
                    $GLOBALS['_gwm_pdf_content'] = null;
                    try {
                        ob_start();
                        require __DIR__ . '/api/geraetewartmitteilung-pdf.php';
                        ob_end_clean();
                        $pdf_content = $GLOBALS['_gwm_pdf_content'] ?? null;
                    } catch (Exception $e) { ob_end_clean(); }
                    if ($pdf_content !== null && strlen($pdf_content) > 100) {
                        $titel = ($typ === 'einsatz' ? 'Einsatz' : 'Übung') . ' – ' . $art;
                        $filename = 'Geraetewartmitteilung_' . $datum . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $art) . '.pdf';
                        $subject = 'Neue Gerätewartmitteilung: ' . $titel . ' (' . date('d.m.Y', strtotime($datum)) . ')';
                        $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Unbekannt';
                        $html = '<p>Eine neue Gerätewartmitteilung wurde eingereicht.</p><p><strong>Typ:</strong> ' . htmlspecialchars($typ === 'einsatz' ? 'Einsatz' : 'Übung') . '<br><strong>Art:</strong> ' . htmlspecialchars($art) . '<br><strong>Datum:</strong> ' . date('d.m.Y', strtotime($datum)) . '<br><strong>Eingereicht von:</strong> ' . htmlspecialchars($user_name) . '</p><p>Die Gerätewartmitteilung ist dieser E-Mail als PDF angehängt.</p>';
                        foreach ($all_emails as $em) {
                            if (trim($em) !== '') send_email_with_pdf_attachment(trim($em), $subject, $html, $pdf_content, $filename);
                        }
                    }
                }
            }

            header('Location: formulare.php?message=geraetewartmitteilung_erfolg');
            exit;
        } catch (Exception $e) {
            $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerätewartmitteilung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .gwm-vehicle-card .vehicle-select { min-width: 220px; }
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
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>
<main class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-wrench text-info"></i> Gerätewartmitteilung</h3>
                    <p class="text-muted mb-0 mt-1 small">Einsatz oder Übung – eingesetzte Fahrzeuge und Geräte erfassen</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <form method="post" id="gwmForm">
                        <input type="hidden" name="save_geraetewartmitteilung" value="1">

                        <div class="card mb-4">
                            <div class="card-header bg-light"><strong>Art des Einsatzes / der Übung</strong></div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Einsatz oder Übung</label>
                                        <div class="d-flex gap-3">
                                            <?php foreach ($typ_options as $k => $v): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="typ" id="typ_<?php echo $k; ?>" value="<?php echo $k; ?>" <?php echo ($_POST['typ'] ?? 'uebung') === $k ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="typ_<?php echo $k; ?>"><?php echo htmlspecialchars($v); ?></label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="einsatz_uebungsart" class="form-label">Einsatz-/Übungsart</label>
                                        <select class="form-select" name="einsatz_uebungsart" id="einsatz_uebungsart" required>
                                            <?php foreach ($art_options as $k => $v): ?>
                                            <option value="<?php echo htmlspecialchars($k); ?>" <?php echo ($_POST['einsatz_uebungsart'] ?? 'Brandeinsatz') === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="datum" class="form-label">Datum</label>
                                        <input type="date" class="form-control" name="datum" id="datum" value="<?php echo htmlspecialchars($_POST['datum'] ?? date('Y-m-d')); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header bg-light"><strong>Eingesetzte Fahrzeuge</strong></div>
                            <div class="card-body">
                                <?php if (empty($vehicles)): ?>
                                <p class="text-muted">Keine Fahrzeuge in der Datenbank. Bitte in der Fahrzeugverwaltung anlegen.</p>
                                <?php else: ?>
                                <p class="text-muted small mb-3">Wählen Sie die eingesetzten Fahrzeuge aus und legen Sie Maschinist sowie Einheitsführer fest. Pro Fahrzeug können Sie die genutzten Geräte auswählen. Tipp: In den Dropdown-Feldern können Sie den ersten Buchstaben tippen, um schnell zur passenden Option zu springen.</p>
                                <div class="row g-3">
                                    <?php foreach ($vehicles as $v): $vid = (int)$v['id']; $eq_list = $vehicles_with_equipment[$vid] ?? []; ?>
                                    <div class="col-12">
                                        <div class="card gwm-vehicle-card vehicle-row" data-vehicle-id="<?php echo $vid; ?>">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                                    <div class="form-check mb-0">
                                                        <input type="checkbox" class="form-check-input vehicle-check" name="vehicle_selected[<?php echo $vid; ?>]" value="1" data-vid="<?php echo $vid; ?>" id="vchk_<?php echo $vid; ?>">
                                                        <label class="form-check-label fw-bold" for="vchk_<?php echo $vid; ?>"><?php echo htmlspecialchars($v['name']); ?></label>
                                                    </div>
                                                    <input type="hidden" name="vehicle_id[]" value="<?php echo $vid; ?>" class="vehicle-id-input" disabled>
                                                    <div class="vehicle-fields ms-md-auto d-flex flex-column flex-md-row gap-3 flex-wrap d-none">
                                                        <div style="min-width: 220px;">
                                                            <label class="form-label small mb-0">Maschinist</label>
                                                            <select class="form-select vehicle-select vehicle-maschinist" name="maschinist[<?php echo $vid; ?>]" disabled>
                                                                <option value="">— keine Auswahl —</option>
                                                                <?php foreach ($members_all as $m): ?>
                                                                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div style="min-width: 220px;">
                                                            <label class="form-label small mb-0">Einheitsführer</label>
                                                            <select class="form-select vehicle-select vehicle-einheitsfuehrer" name="einheitsfuehrer[<?php echo $vid; ?>]" disabled>
                                                                <option value="">— keine Auswahl —</option>
                                                                <?php foreach ($members_all as $m): ?>
                                                                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="equipment-cell mt-2 vehicle-fields d-none">
                                                    <?php if (!empty($eq_list)): ?>
                                                    <div class="equipment-checkboxes" data-vid="<?php echo $vid; ?>">
                                                        <label class="form-label small mb-1">Eingesetzte Geräte</label>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php foreach ($eq_list as $eq): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="equipment[<?php echo $vid; ?>][]" value="<?php echo (int)$eq['id']; ?>" id="eq_<?php echo $vid; ?>_<?php echo $eq['id']; ?>">
                                                                <label class="form-check-label small" for="eq_<?php echo $vid; ?>_<?php echo $eq['id']; ?>"><?php echo htmlspecialchars($eq['name']); ?></label>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-muted small">Keine Geräte hinterlegt</span>
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

                        <div class="card mb-4" id="defectiveCard" style="display:none">
                            <div class="card-header bg-light"><strong>Defekte Geräte</strong></div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Pro ausgewähltem Fahrzeug können Sie defekte Geräte angeben – aus den eingesetzten Geräten oder per Freitext (immer möglich).</p>
                                <div id="defectiveContainer"></div>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header bg-light"><strong>Weitere Angaben</strong></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Einsatzbereitschaft</label>
                                    <div class="d-flex gap-3">
                                        <?php foreach ($einsatzbereitschaft_options as $k => $v): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="einsatzbereitschaft" id="eb_<?php echo $k; ?>" value="<?php echo $k; ?>" <?php echo ($_POST['einsatzbereitschaft'] ?? 'hergestellt') === $k ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="eb_<?php echo $k; ?>"><?php echo htmlspecialchars($v); ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="mangel_beschreibung" class="form-label">Mangel beschreiben</label>
                                    <textarea class="form-control" name="mangel_beschreibung" id="mangel_beschreibung" rows="3" placeholder="Beschreibung von Mängeln oder Auffälligkeiten"><?php echo htmlspecialchars($_POST['mangel_beschreibung'] ?? ''); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="einsatzleiter" class="form-label">Einsatzleiter</label>
                                    <select class="form-select" id="einsatzleiter" name="einsatzleiter">
                                        <option value="">— keine Auswahl —</option>
                                        <?php foreach ($members_for_einsatzleiter as $m): ?>
                                        <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                        <?php endforeach; ?>
                                        <option value="__freitext__">— Freitext (z. B. andere Feuerwehr) —</option>
                                    </select>
                                    <div class="mt-2" id="einsatzleiter_freitext_wrap" style="display:none">
                                        <input type="text" class="form-control" name="einsatzleiter_freitext" id="einsatzleiter_freitext" placeholder="Name Einsatzleiter" value="<?php echo htmlspecialchars($_POST['einsatzleiter_freitext'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Gerätewartmitteilung speichern</button>
                            <a href="formulare.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Formulare</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var vehicles = <?php echo json_encode(array_map(function($v) { return ['id' => (int)$v['id'], 'name' => $v['name']]; }, $vehicles)); ?>;
    var vehiclesWithEq = <?php echo json_encode($vehicles_with_equipment); ?>;

    function toggleVehicleRow(checkbox) {
        var vid = parseInt(checkbox.dataset.vid, 10);
        var card = checkbox.closest('.vehicle-row');
        var hid = card.querySelector('.vehicle-id-input');
        var fields = card.querySelectorAll('.vehicle-fields');
        var selects = card.querySelectorAll('.vehicle-select');
        var eqBox = card.querySelector('.equipment-checkboxes');
        if (checkbox.checked) {
            if (hid) { hid.disabled = false; }
            fields.forEach(function(f) { f.classList.remove('d-none'); });
            selects.forEach(function(s) { s.disabled = false; });
        } else {
            if (hid) { hid.disabled = true; }
            fields.forEach(function(f) { f.classList.add('d-none'); });
            selects.forEach(function(s) { s.disabled = true; s.value = ''; });
            if (eqBox) { eqBox.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; }); }
        }
        updateDefectiveSection();
    }

    function getUsedEquipmentForVehicle(vid) {
        var row = document.querySelector('.vehicle-row[data-vehicle-id="' + vid + '"]');
        if (!row) return [];
        var eqBox = row.querySelector('.equipment-checkboxes');
        if (!eqBox) return [];
        var used = [];
        eqBox.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
            var eqId = parseInt(cb.value, 10);
            var eqList = vehiclesWithEq[vid] || [];
            var eq = eqList.find(function(x) { return parseInt(x.id, 10) === eqId; });
            if (eq) used.push(eq);
        });
        return used;
    }

    function updateDefectiveSection() {
        var selected = [];
        document.querySelectorAll('.vehicle-check:checked').forEach(function(cb) {
            selected.push(parseInt(cb.dataset.vid, 10));
        });
        var card = document.getElementById('defectiveCard');
        var container = document.getElementById('defectiveContainer');
        if (selected.length === 0) {
            card.style.display = 'none';
            container.innerHTML = '';
            return;
        }
        card.style.display = 'block';
        container.innerHTML = '';
        selected.forEach(function(vid) {
            var v = vehicles.find(function(x) { return x.id === vid; });
            var usedEq = getUsedEquipmentForVehicle(vid);
            var div = document.createElement('div');
            div.className = 'mb-4 p-3 border rounded';
            div.innerHTML = '<h6 class="mb-2">' + (v ? v.name : 'Fahrzeug') + '</h6>';
            var defDiv = document.createElement('div');
            defDiv.className = 'mb-2';
            defDiv.innerHTML = '<label class="form-label small">Defekte Geräte (aus eingesetzten)</label>';
            var cbDiv = document.createElement('div');
            cbDiv.className = 'mb-2';
            if (usedEq.length > 0) {
                usedEq.forEach(function(eq) {
                    var lbl = document.createElement('label');
                    lbl.className = 'form-check form-check-inline';
                    lbl.innerHTML = '<input type="checkbox" name="defective_equipment[' + vid + '][]" value="' + eq.id + '" class="form-check-input"> ' + eq.name;
                    cbDiv.appendChild(lbl);
                });
            } else {
                cbDiv.innerHTML = '<span class="text-muted small">Keine eingesetzten Geräte – nutzen Sie Freitext unten.</span>';
            }
            defDiv.appendChild(cbDiv);
            var freitextDiv = document.createElement('div');
            freitextDiv.className = 'mb-2';
            freitextDiv.innerHTML = '<label class="form-label small">Freitext (defektes Gerät, immer möglich)</label><input type="text" class="form-control form-control-sm" name="defective_freitext[' + vid + ']" placeholder="z.B. Schlauch 123">';
            var mangelDiv = document.createElement('div');
            mangelDiv.innerHTML = '<label class="form-label small">Mangel beschreiben (für dieses Fahrzeug)</label><textarea class="form-control form-control-sm" name="defective_mangel[' + vid + ']" rows="2" placeholder="Beschreibung des Mangels"></textarea>';
            div.appendChild(defDiv);
            div.appendChild(freitextDiv);
            div.appendChild(mangelDiv);
            container.appendChild(div);
        });
    }

    document.querySelectorAll('.vehicle-check').forEach(function(cb) {
        cb.addEventListener('change', function() { toggleVehicleRow(this); });
    });

    document.addEventListener('change', function(e) {
        if (e.target && e.target.matches && e.target.matches('.equipment-checkboxes input[type="checkbox"]')) {
            updateDefectiveSection();
        }
    });

    document.getElementById('einsatzleiter').addEventListener('change', function() {
        document.getElementById('einsatzleiter_freitext_wrap').style.display = this.value === '__freitext__' ? 'block' : 'none';
    });
    if (document.getElementById('einsatzleiter').value === '__freitext__') {
        document.getElementById('einsatzleiter_freitext_wrap').style.display = 'block';
    }

    document.getElementById('gwmForm').addEventListener('submit', function() {
        var checked = document.querySelectorAll('.vehicle-check:checked');
        if (checked.length === 0) {
            alert('Bitte wählen Sie mindestens ein Fahrzeug aus.');
            return false;
        }
        checked.forEach(function(cb) {
            var hid = cb.closest('.vehicle-row').querySelector('.vehicle-id-input');
            if (hid) hid.disabled = false;
        });
        return true;
    });
})();
</script>
</body>
</html>
