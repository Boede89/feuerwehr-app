<?php
/**
 * Mängelbericht – Formular zum Erfassen von Mängeln und Schäden.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!has_form_fill_permission()) {
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

// Tabelle anlegen
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
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
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
try {
    $db->exec("ALTER TABLE maengelberichte ADD COLUMN einheit_id INT NULL");
} catch (Exception $e) {
    /* Spalte existiert ggf. bereits */
}

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];

$settings = [];
try {
    require_once __DIR__ . '/includes/einheit-settings-helper.php';
    $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
} catch (Exception $e) {}
$standort_default = trim($settings['maengelbericht_standort_default'] ?? '');
if (!in_array($standort_default, $standort_options)) $standort_default = $standort_options[0];
$mangel_an_default = trim($settings['maengelbericht_mangel_an_default'] ?? '');
if (!in_array($mangel_an_default, $mangel_an_options)) $mangel_an_default = $mangel_an_options[0];

$members_list = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    }
    $members_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$vehicles_list = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    }
    $vehicles_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_maengelbericht'])) {
    $standort = trim($_POST['standort'] ?? '');
    $mangel_an = trim($_POST['mangel_an'] ?? '');
    $bezeichnung = trim($_POST['bezeichnung'] ?? '');
    $mangel_beschreibung = trim($_POST['mangel_beschreibung'] ?? '');
    $ursache = trim($_POST['ursache'] ?? '');
    $verbleib = trim($_POST['verbleib'] ?? '');
    $aufgenommen_durch = trim($_POST['aufgenommen_durch'] ?? '');
    $aufgenommen_durch_member_id = null;
    $aufgenommen_durch_text = null;
    if (preg_match('/^\d+$/', $aufgenommen_durch)) {
        $aufgenommen_durch_member_id = (int)$aufgenommen_durch;
    } else {
        $aufgenommen_durch_text = $aufgenommen_durch !== '' ? $aufgenommen_durch : null;
    }
    $aufgenommen_am = trim($_POST['aufgenommen_am'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aufgenommen_am)) $aufgenommen_am = date('Y-m-d');
    if (!in_array($standort, $standort_options)) $standort = $standort_options[0];
    if (!in_array($mangel_an, $mangel_an_options)) $mangel_an = $mangel_an_options[0];
    $vehicle_id = isset($_POST['vehicle_id']) && preg_match('/^\d+$/', (string)$_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
    try {
        $stmt = $db->prepare("
            INSERT INTO maengelberichte (standort, mangel_an, bezeichnung, mangel_beschreibung, ursache, verbleib, aufgenommen_durch_text, aufgenommen_durch_member_id, aufgenommen_am, vehicle_id, user_id, einheit_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$standort, $mangel_an, $bezeichnung ?: null, $mangel_beschreibung ?: null, $ursache ?: null, $verbleib ?: null, $aufgenommen_durch_text, $aufgenommen_durch_member_id, $aufgenommen_am, $vehicle_id, $_SESSION['user_id'], $einheit_id > 0 ? $einheit_id : null]);
        $id = $db->lastInsertId();

        require_once __DIR__ . '/includes/bericht-anhaenge-helper.php';
        bericht_anhaenge_save_for_maengelbericht($db, (int)$id, $_FILES);

        // Automatischer E-Mail-Versand (wenn in Einstellungen aktiviert)
        $email_auto = false;
        $email_recipients = [];
        $email_manual = '';
        try {
            $stmt_s = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('maengelbericht_email_auto', 'maengelbericht_email_recipients', 'maengelbericht_email_manual')");
            $stmt_s->execute();
            foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['setting_key'] === 'maengelbericht_email_auto') $email_auto = ($r['setting_value'] ?? '0') === '1';
                elseif ($r['setting_key'] === 'maengelbericht_email_recipients') $email_recipients = json_decode($r['setting_value'] ?? '[]', true) ?: [];
                elseif ($r['setting_key'] === 'maengelbericht_email_manual') $email_manual = trim($r['setting_value'] ?? '');
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
                $_GET['id'] = $id;
                $_GET['_return'] = '1';
                $GLOBALS['_mb_pdf_content'] = null;
                try {
                    ob_start();
                    require __DIR__ . '/api/maengelbericht-pdf.php';
                    ob_end_clean();
                    $pdf_content = $GLOBALS['_mb_pdf_content'] ?? null;
                } catch (Exception $e) { ob_end_clean(); }
                if ($pdf_content !== null && strlen($pdf_content) > 100) {
                    $titel = $bezeichnung ?: $mangel_an . ' – ' . $standort;
                    $filename = 'Maengelbericht_' . $aufgenommen_am . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $standort) . '.pdf';
                    $subject = 'Neuer Mängelbericht: ' . $titel . ' (' . date('d.m.Y', strtotime($aufgenommen_am)) . ')';
                    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Unbekannt';
                    $html = '<p>Ein neuer Mängelbericht wurde eingereicht.</p><p><strong>Standort:</strong> ' . htmlspecialchars($standort) . '<br><strong>Mangel an:</strong> ' . htmlspecialchars($mangel_an) . '<br><strong>Bezeichnung:</strong> ' . htmlspecialchars($bezeichnung ?: '-') . '<br><strong>Eingereicht von:</strong> ' . htmlspecialchars($user_name) . '</p><p>Der Mängelbericht ist dieser E-Mail als PDF angehängt.</p>';
                    foreach ($all_emails as $em) {
                        if (trim($em) !== '') send_email_with_pdf_attachment(trim($em), $subject, $html, $pdf_content, $filename);
                    }
                    try {
                        $db->prepare("UPDATE maengelberichte SET email_sent_at = NOW() WHERE id = ?")->execute([$id]);
                    } catch (Exception $e) {}
                }
            }
        }

        $print_after = !empty($_POST['print_after_save']);
        header('Location: formulare.php?message=maengelbericht_erfolg' . ($print_after ? '&print_maengelbericht=' . $id : ''));
        exit;
    } catch (Exception $e) {
        $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mängelbericht - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
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
                    <h3 class="mb-0"><i class="fas fa-exclamation-triangle text-warning"></i> Mängelbericht</h3>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <form method="post" id="maengelberichtForm" enctype="multipart/form-data">
                        <input type="hidden" name="save_maengelbericht" value="1">
                        <input type="hidden" name="print_after_save" id="print_after_save" value="1">
                        <div class="mb-3">
                            <label for="standort" class="form-label">Standort</label>
                            <select class="form-select" id="standort" name="standort" required>
                                <?php foreach ($standort_options as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $standort_default ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="mangel_an" class="form-label">Mangel/Wartung an</label>
                            <select class="form-select" id="mangel_an" name="mangel_an" required>
                                <?php foreach ($mangel_an_options as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $mangel_an_default ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="bezeichnung" class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                            <input type="text" class="form-control" id="bezeichnung" name="bezeichnung" placeholder="z.B. TLF 16/25, Gerätenummer 123">
                        </div>
                        <div class="mb-3">
                            <label for="vehicle_id" class="form-label">Fahrzeug (auf dem sich das Gerät befindet)</label>
                            <select class="form-select" id="vehicle_id" name="vehicle_id">
                                <option value="">— kein Fahrzeug / nicht zutreffend —</option>
                                <?php foreach ($vehicles_list as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Wichtig, da Geräte auf mehreren Fahrzeugen existieren können</small>
                        </div>
                        <div class="mb-3">
                            <label for="mangel_beschreibung" class="form-label">Mangel Beschreibung</label>
                            <textarea class="form-control" id="mangel_beschreibung" name="mangel_beschreibung" rows="3" placeholder="Beschreibung des Mangels"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="ursache" class="form-label">Ursache</label>
                            <input type="text" class="form-control" id="ursache" name="ursache" placeholder="Vermutete oder festgestellte Ursache">
                        </div>
                        <div class="mb-3">
                            <label for="verbleib" class="form-label">Verbleib</label>
                            <input type="text" class="form-control" id="verbleib" name="verbleib" placeholder="Verbleib">
                        </div>
                        <div class="mb-3">
                            <label for="aufgenommen_durch_display" class="form-label">Aufgenommen durch</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="aufgenommen_durch_display" placeholder="Buchstaben eingeben zum Filtern der Mitgliederliste" autocomplete="off" inputmode="text">
                                <input type="hidden" name="aufgenommen_durch" id="aufgenommen_durch" value="">
                                <div id="aufgenommen_durch_suggestions" class="list-group position-absolute w-100 mt-1 shadow" style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                            </div>
                            <small class="text-muted">Mitglied auswählen – Buchstaben eingeben, um die Liste zu filtern (kein Scrollen nötig).</small>
                        </div>
                        <div class="mb-3">
                            <label for="aufgenommen_am" class="form-label">Aufgenommen am</label>
                            <input type="date" class="form-control" id="aufgenommen_am" name="aufgenommen_am" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3 p-3 border rounded bg-light">
                            <label class="form-label fw-semibold"><i class="fas fa-paperclip"></i> Anhänge (optional)</label>
                            <p class="text-muted small mb-2">Fotos erscheinen im PDF auf einer <strong>eigenen Seite</strong> nach dem Mängelbericht (in der Regel Seite 2); zusätzliche PDF-Dateien werden hinten angehängt.</p>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="file" class="form-control form-control-sm" style="max-width:280px" name="maengelbericht_anhaenge[]" id="maengelbericht_anhaenge_files" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnKameraMbForm"><i class="fas fa-camera"></i> Foto</button>
                            </div>
                            <input type="file" class="d-none" id="maengelbericht_anhaenge_cam" accept="image/*" capture="environment">
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-success" id="btnSaveMaengelbericht"><i class="fas fa-save"></i> Mängelbericht speichern</button>
                            <a href="formulare.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Formulare</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<!-- Modal: Speichern bestätigen -->
<div class="modal fade" id="saveConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mängelbericht speichern</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Möchten Sie den Mängelbericht speichern?</p>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="cbPrintAfterSave" <?php echo (($settings['maengelbericht_print_after_save_default'] ?? '1') === '1') ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="cbPrintAfterSave">Nach Speichern drucken</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-success" id="btnConfirmSave"><i class="fas fa-check"></i> Ja, speichern</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var b = document.getElementById('btnKameraMbForm');
    var c = document.getElementById('maengelbericht_anhaenge_cam');
    var f = document.getElementById('maengelbericht_anhaenge_files');
    if (b && c && f) {
        b.addEventListener('click', function() { c.click(); });
        c.addEventListener('change', function() {
            if (!this.files || !this.files.length) return;
            try {
                var dt = new DataTransfer();
                var i;
                for (i = 0; i < f.files.length; i++) dt.items.add(f.files[i]);
                for (i = 0; i < this.files.length; i++) dt.items.add(this.files[i]);
                f.files = dt.files;
            } catch (e) {}
            this.value = '';
        });
    }
})();
</script>
<script>
(function() {
    var membersData = <?php echo json_encode(array_map(function($m) {
        return ['id' => (int)$m['id'], 'label' => trim($m['last_name'] . ', ' . $m['first_name'])];
    }, $members_list)); ?>;
    var displayInput = document.getElementById('aufgenommen_durch_display');
    var hiddenInput = document.getElementById('aufgenommen_durch');
    var suggestionsEl = document.getElementById('aufgenommen_durch_suggestions');
    function filterMembers(q) {
        q = (q || '').toLowerCase().trim();
        if (q === '') return membersData;
        return membersData.filter(function(m) {
            return (m.label || '').toLowerCase().indexOf(q) >= 0;
        });
    }
    function renderSuggestions(items) {
        suggestionsEl.innerHTML = '';
        items.forEach(function(item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action list-group-item-light text-start';
            btn.textContent = item.label;
            btn.dataset.id = item.id;
            btn.dataset.label = item.label;
            btn.addEventListener('click', function() {
                displayInput.value = this.dataset.label;
                if (hiddenInput) hiddenInput.value = this.dataset.id;
                suggestionsEl.style.display = 'none';
            });
            suggestionsEl.appendChild(btn);
        });
        suggestionsEl.style.display = items.length > 0 ? 'block' : 'none';
    }
    if (displayInput && suggestionsEl) {
        displayInput.addEventListener('input', function() {
            if (hiddenInput) hiddenInput.value = '';
            var q = displayInput.value.trim();
            renderSuggestions(filterMembers(q));
        });
        displayInput.addEventListener('focus', function() {
            var q = displayInput.value.trim();
            renderSuggestions(filterMembers(q));
        });
        displayInput.addEventListener('blur', function() {
            setTimeout(function() {
                var idVal = hiddenInput ? hiddenInput.value : '';
                if (!idVal && displayInput.value.trim() !== '') {
                    displayInput.value = '';
                }
                suggestionsEl.style.display = 'none';
            }, 200);
        });
        document.addEventListener('click', function(e) {
            if (!displayInput.contains(e.target) && !suggestionsEl.contains(e.target)) suggestionsEl.style.display = 'none';
        });
    }
    var form = document.getElementById('maengelberichtForm');
    if (form && hiddenInput) {
        form.addEventListener('submit', function() {
            var idVal = hiddenInput.value.trim();
            if (!idVal && displayInput && displayInput.value.trim()) {
                hiddenInput.value = '';
            } else {
                hiddenInput.value = idVal;
            }
        });
    }
})();
(function() {
    var btn = document.getElementById('btnSaveMaengelbericht');
    var form = document.getElementById('maengelberichtForm');
    var modal = document.getElementById('saveConfirmModal');
    if (btn && form) {
        btn.addEventListener('click', function() {
            if (modal) {
                new bootstrap.Modal(modal).show();
            } else {
                form.submit();
            }
        });
        var btnConfirm = document.getElementById('btnConfirmSave');
        var cbPrint = document.getElementById('cbPrintAfterSave');
        var inputPrint = document.getElementById('print_after_save');
        if (btnConfirm) {
            btnConfirm.addEventListener('click', function() {
                if (inputPrint) inputPrint.value = (cbPrint && cbPrint.checked) ? '1' : '0';
                form.submit();
            });
        }
    }
})();
</script>
</body>
</html>
