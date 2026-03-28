<?php
/**
 * Mängelbericht anzeigen und bearbeiten.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bericht-anhaenge-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('forms')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

if (empty($_SESSION['form_center_csrf'])) {
    $_SESSION['form_center_csrf'] = bin2hex(random_bytes(32));
}

$id = (int)($_GET['id'] ?? $_POST['maengelbericht_id'] ?? 0);
if ($id <= 0) {
    header('Location: formularcenter.php?tab=submissions');
    exit;
}

$bericht = null;
try {
    $stmt = $db->prepare("
        SELECT m.*, COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM maengelberichte m
        LEFT JOIN users u ON u.id = m.user_id
        WHERE m.id = ?
    ");
    $stmt->execute([$id]);
    $bericht = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $bericht = null;
}
if (!$bericht) {
    header('Location: formularcenter.php?tab=submissions&error=not_found');
    exit;
}

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];

$members_list = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$vehicles_list = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    $vehicles_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$aufgenommen_durch_display = '';
if (!empty($bericht['aufgenommen_durch_member_id'])) {
    try {
        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
        $stmt->execute([$bericht['aufgenommen_durch_member_id']]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) $aufgenommen_durch_display = trim($m['last_name'] . ', ' . $m['first_name']);
    } catch (Exception $e) {}
}
if ($aufgenommen_durch_display === '' && !empty($bericht['aufgenommen_durch_text'])) {
    $aufgenommen_durch_display = $bericht['aufgenommen_durch_text'];
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_anhang_maengelbericht') {
    if (!has_permission_write('forms')) {
        $error = 'Sie haben keine Schreibrechte für das Formularcenter.';
    } elseif (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $del_aid = (int)($_POST['anhang_id'] ?? 0);
        if ($del_aid > 0 && bericht_anhaenge_delete_by_id($db, $del_aid, 'maengelbericht', $id)) {
            header('Location: maengelbericht-bearbeiten.php?id=' . (int)$id . '&message=anhang_deleted');
            exit;
        }
        $error = 'Anhang konnte nicht gelöscht werden.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission_write('forms')) {
        $error = 'Sie haben keine Schreibrechte für das Formularcenter.';
    } elseif ($_POST['action'] === 'delete_maengelbericht') {
        if (!empty($_SESSION['form_center_csrf']) && isset($_POST['form_center_csrf']) && $_POST['form_center_csrf'] === $_SESSION['form_center_csrf']) {
            try {
                $db->prepare("DELETE FROM maengelberichte WHERE id = ?")->execute([$id]);
                header('Location: formularcenter.php?tab=submissions&filter_formular=maengelbericht&message=' . urlencode('Mängelbericht wurde gelöscht.'));
                exit;
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    } elseif ($_POST['action'] === 'save_maengelbericht' && !$error && validate_csrf_token($_POST['csrf_token'] ?? '')) {
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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aufgenommen_am)) $aufgenommen_am = $bericht['aufgenommen_am'];
        if (!in_array($standort, $standort_options)) $standort = $standort_options[0];
        if (!in_array($mangel_an, $mangel_an_options)) $mangel_an = $mangel_an_options[0];
        $vehicle_id = isset($_POST['vehicle_id']) && preg_match('/^\d+$/', (string)$_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
        try {
            $stmt = $db->prepare("
                UPDATE maengelberichte SET standort=?, mangel_an=?, bezeichnung=?, mangel_beschreibung=?, ursache=?, verbleib=?,
                    aufgenommen_durch_text=?, aufgenommen_durch_member_id=?, aufgenommen_am=?, vehicle_id=?
                WHERE id=?
            ");
            $stmt->execute([$standort, $mangel_an, $bezeichnung ?: null, $mangel_beschreibung ?: null, $ursache ?: null, $verbleib ?: null, $aufgenommen_durch_text, $aufgenommen_durch_member_id, $aufgenommen_am, $vehicle_id, $id]);
            bericht_anhaenge_save_for_maengelbericht($db, (int)$id, $_FILES);
            $bericht = array_merge($bericht, [
                'standort' => $standort, 'mangel_an' => $mangel_an, 'bezeichnung' => $bezeichnung,
                'mangel_beschreibung' => $mangel_beschreibung, 'ursache' => $ursache, 'verbleib' => $verbleib,
                'aufgenommen_durch_text' => $aufgenommen_durch_text, 'aufgenommen_durch_member_id' => $aufgenommen_durch_member_id,
                'aufgenommen_am' => $aufgenommen_am, 'vehicle_id' => $vehicle_id
            ]);
            if ($aufgenommen_durch_member_id) {
                $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
                $stmt->execute([$aufgenommen_durch_member_id]);
                $m = $stmt->fetch(PDO::FETCH_ASSOC);
                $aufgenommen_durch_display = $m ? trim($m['last_name'] . ', ' . $m['first_name']) : '';
            } else {
                $aufgenommen_durch_display = $aufgenommen_durch_text ?? '';
            }
            $message = 'Mängelbericht gespeichert.';
        } catch (Exception $e) {
            $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['message']) && $_GET['message'] === 'anhang_deleted') {
    $message = 'Anhang wurde gelöscht.';
}
$anhang_liste = [];
try {
    $anhang_liste = bericht_anhaenge_fetch_for_entity($db, 'maengelbericht', $id);
} catch (Exception $e) {
    $anhang_liste = [];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mängelbericht bearbeiten - Feuerwehr App</title>
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

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-exclamation-triangle text-warning"></i> Mängelbericht bearbeiten</h1>
        <div class="d-flex gap-2">
            <a href="../api/maengelbericht-pdf.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-success" download><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card shadow">
        <div class="card-body p-4">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="save_maengelbericht">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Standort</label>
                        <select class="form-select" name="standort">
                            <?php foreach ($standort_options as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($bericht['standort'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mangel/Wartung an</label>
                        <select class="form-select" name="mangel_an">
                            <?php foreach ($mangel_an_options as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($bericht['mangel_an'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                        <input type="text" class="form-control" name="bezeichnung" value="<?php echo htmlspecialchars($bericht['bezeichnung'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fahrzeug (auf dem sich das Gerät befindet)</label>
                        <select class="form-select" name="vehicle_id">
                            <option value="">— kein Fahrzeug —</option>
                            <?php foreach ($vehicles_list as $v): ?>
                            <option value="<?php echo (int)$v['id']; ?>" <?php echo (isset($bericht['vehicle_id']) && (int)($bericht['vehicle_id'] ?? 0) === (int)$v['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Mangel Beschreibung</label>
                        <textarea class="form-control" name="mangel_beschreibung" rows="3"><?php echo htmlspecialchars($bericht['mangel_beschreibung'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ursache</label>
                        <input type="text" class="form-control" name="ursache" value="<?php echo htmlspecialchars($bericht['ursache'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Verbleib</label>
                        <input type="text" class="form-control" name="verbleib" value="<?php echo htmlspecialchars($bericht['verbleib'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Aufgenommen durch</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="aufgenommen_durch_display" value="<?php echo htmlspecialchars($aufgenommen_durch_display); ?>" placeholder="Buchstaben eingeben zum Filtern der Mitgliederliste" autocomplete="off" inputmode="text">
                            <input type="hidden" name="aufgenommen_durch" id="aufgenommen_durch" value="<?php echo htmlspecialchars($bericht['aufgenommen_durch_member_id'] ? (string)$bericht['aufgenommen_durch_member_id'] : ''); ?>">
                            <div id="aufgenommen_durch_suggestions" class="list-group position-absolute w-100 mt-1 shadow" style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                        </div>
                        <small class="text-muted">Mitglied auswählen – Buchstaben eingeben, um die Liste zu filtern.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Aufgenommen am</label>
                        <input type="date" class="form-control" name="aufgenommen_am" value="<?php echo htmlspecialchars($bericht['aufgenommen_am'] ?? date('Y-m-d')); ?>">
                    </div>
                    <div class="col-12">
                        <div class="p-3 border rounded bg-light">
                            <label class="form-label fw-semibold"><i class="fas fa-paperclip"></i> Anhänge (Fotos, PDF)</label>
                            <p class="text-muted small mb-2">Bis zu <?php echo (int)BERICHT_ANHAENGE_MAX_FILES; ?> Dateien. Fotos erscheinen im PDF auf der ersten Seite.</p>
                            <?php if (empty($anhang_liste)): ?>
                            <p class="text-muted small">Keine gespeicherten Anhänge.</p>
                            <?php else: ?>
                            <ul class="list-group list-group-flush mb-3">
                                <?php foreach ($anhang_liste as $anh): ?>
                                <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
                                    <span><?php echo htmlspecialchars($anh['filename_original'] ?? 'Datei'); ?> <small class="text-muted">(<?php echo htmlspecialchars($anh['mime_type'] ?? ''); ?>)</small></span>
                                    <span class="d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="../api/maengelbericht-anhang-download.php?maengel_id=<?php echo (int)$id; ?>&amp;id=<?php echo (int)($anh['id'] ?? 0); ?>"><i class="fas fa-eye"></i> Anzeigen</a>
                                        <?php if (has_permission_write('forms')): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Anhang wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete_anhang_maengelbericht">
                                            <input type="hidden" name="anhang_id" value="<?php echo (int)($anh['id'] ?? 0); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> Löschen</button>
                                        </form>
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                            <?php if (has_permission_write('forms')): ?>
                            <label class="form-label">Weitere Dateien hinzufügen</label>
                            <input type="file" class="form-control" name="maengelbericht_anhaenge[]" id="maengelbericht_anhaenge_edit" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    <a href="formularcenter.php?tab=submissions&filter_formular=maengelbericht" class="btn btn-secondary">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4 border-danger">
        <div class="card-body">
            <form method="post" onsubmit="return confirm('Mängelbericht wirklich löschen?');">
                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf'] ?? ''); ?>">
                <input type="hidden" name="action" value="delete_maengelbericht">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Mängelbericht löschen</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
                suggestionsEl.style.display = 'none';
            }, 200);
        });
        document.addEventListener('click', function(e) {
            if (!displayInput.contains(e.target) && !suggestionsEl.contains(e.target)) suggestionsEl.style.display = 'none';
        });
    }
    var form = document.querySelector('form input[name="action"][value="save_maengelbericht"]');
    form = form ? form.closest('form') : null;
    if (form && hiddenInput) {
        form.addEventListener('submit', function() {
            var idVal = hiddenInput.value.trim();
            if (idVal) {
                hiddenInput.value = idVal;
            } else if (displayInput && displayInput.value.trim()) {
                hiddenInput.value = displayInput.value.trim();
            } else {
                hiddenInput.value = '';
            }
        });
    }
})();
</script>
</body>
</html>
