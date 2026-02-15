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
if (!has_permission('forms')) {
    header('Location: index.php?error=no_forms_access');
    exit;
}

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
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            KEY idx_aufgenommen_am (aufgenommen_am),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('maengelberichte Tabelle: ' . $e->getMessage());
}

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];

$settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('maengelbericht_standort_default')");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}
$standort_default = trim($settings['maengelbericht_standort_default'] ?? '');
if (!in_array($standort_default, $standort_options)) $standort_default = $standort_options[0];

$members_list = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    try {
        $stmt = $db->prepare("
            INSERT INTO maengelberichte (standort, mangel_an, bezeichnung, mangel_beschreibung, ursache, verbleib, aufgenommen_durch_text, aufgenommen_durch_member_id, aufgenommen_am, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$standort, $mangel_an, $bezeichnung ?: null, $mangel_beschreibung ?: null, $ursache ?: null, $verbleib ?: null, $aufgenommen_durch_text, $aufgenommen_durch_member_id, $aufgenommen_am, $_SESSION['user_id']]);
        $id = $db->lastInsertId();
        $print_after = !empty($_POST['print_after_save']);
        header('Location: formulare.php?message=maengelbericht_erfolg&print_maengelbericht=' . ($print_after ? $id : ''));
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
            <a class="navbar-brand" href="index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Startseite</a></li>
                    <li class="nav-item"><a class="nav-link" href="formulare.php"><i class="fas fa-file-alt"></i> Formulare</a></li>
                    <?php if (!is_system_user()): ?><li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li><?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="fas fa-user"></i> <?php echo htmlspecialchars(trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name'])); ?></a>
                        <ul class="dropdown-menu">
                            <?php if (!is_system_user()): ?><li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li><li><hr class="dropdown-divider"></li><?php endif; ?>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
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
                    <form method="post" id="maengelberichtForm">
                        <input type="hidden" name="save_maengelbericht" value="1">
                        <input type="hidden" name="print_after_save" id="print_after_save" value="0">
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
                                <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="bezeichnung" class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                            <input type="text" class="form-control" id="bezeichnung" name="bezeichnung" placeholder="z.B. TLF 16/25, Gerätenummer 123">
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
                                <input type="text" class="form-control" id="aufgenommen_durch_display" placeholder="Mitglied suchen oder Name eingeben" autocomplete="off">
                                <input type="hidden" name="aufgenommen_durch" id="aufgenommen_durch" value="">
                                <div id="aufgenommen_durch_suggestions" class="list-group position-absolute w-100 mt-1 shadow" style="z-index: 1050; max-height: 200px; overflow-y: auto; display: none;"></div>
                            </div>
                            <small class="text-muted">Tippen Sie zum Suchen nach Mitgliedern oder geben Sie einen Namen ein.</small>
                        </div>
                        <div class="mb-3">
                            <label for="aufgenommen_am" class="form-label">Aufgenommen am</label>
                            <input type="date" class="form-control" id="aufgenommen_am" name="aufgenommen_am" value="<?php echo date('Y-m-d'); ?>" required>
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
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="cbPrintAfterSave" checked>
                    <label class="form-check-label" for="cbPrintAfterSave">Mängelbericht drucken</label>
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
    var displayInput = document.getElementById('aufgenommen_durch_display');
    var hiddenInput = document.getElementById('aufgenommen_durch');
    var suggestionsEl = document.getElementById('aufgenommen_durch_suggestions');
    var debounceTimer;
    if (displayInput && suggestionsEl) {
        displayInput.addEventListener('input', function() {
            if (hiddenInput) hiddenInput.value = '';
            clearTimeout(debounceTimer);
            var q = displayInput.value.trim();
            if (q.length < 2) { suggestionsEl.style.display = 'none'; suggestionsEl.innerHTML = ''; return; }
            debounceTimer = setTimeout(function() {
                fetch('api/search-members.php?q=' + encodeURIComponent(q) + '&limit=15')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        suggestionsEl.innerHTML = '';
                        if (!data || data.length === 0) { suggestionsEl.style.display = 'none'; return; }
                        data.forEach(function(item) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'list-group-item list-group-item-action list-group-item-light text-start';
                            btn.textContent = item.label || item.value;
                            btn.dataset.id = item.id;
                            btn.dataset.label = item.label || item.value;
                            btn.addEventListener('click', function() {
                                displayInput.value = this.dataset.label;
                                if (hiddenInput) hiddenInput.value = this.dataset.id;
                                suggestionsEl.style.display = 'none';
                                suggestionsEl.innerHTML = '';
                            });
                            suggestionsEl.appendChild(btn);
                        });
                        suggestionsEl.style.display = 'block';
                    })
                    .catch(function() { suggestionsEl.style.display = 'none'; });
            }, 300);
        });
        displayInput.addEventListener('blur', function() { setTimeout(function() { suggestionsEl.style.display = 'none'; }, 200); });
        document.addEventListener('click', function(e) {
            if (!displayInput.contains(e.target) && !suggestionsEl.contains(e.target)) suggestionsEl.style.display = 'none';
        });
    }
    var form = document.getElementById('maengelberichtForm');
    if (form && hiddenInput) {
        form.addEventListener('submit', function() {
            var idVal = hiddenInput.value.trim();
            var textVal = displayInput ? displayInput.value.trim() : '';
            hiddenInput.value = idVal || textVal;
        });
    }
})();
(function() {
    var btn = document.getElementById('btnSaveMaengelbericht');
    var form = document.getElementById('maengelberichtForm');
    var modal = document.getElementById('saveConfirmModal');
    var cbPrint = document.getElementById('cbPrintAfterSave');
    var inputPrint = document.getElementById('print_after_save');
    if (btn && form) {
        btn.addEventListener('click', function() {
            if (modal) {
                new bootstrap.Modal(modal).show();
            } else {
                form.submit();
            }
        });
        var btnConfirm = document.getElementById('btnConfirmSave');
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
