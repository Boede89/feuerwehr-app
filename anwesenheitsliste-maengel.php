<?php
/**
 * Anwesenheitsliste – Mängel: Mängelbericht-Felder erfassen.
 * Beim Speichern der Anwesenheitsliste werden automatisch Mängelberichte erstellt.
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

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : '';
$auswahl = isset($_GET['auswahl']) ? trim($_GET['auswahl']) : '';
if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $auswahl === '') {
    header('Location: anwesenheitsliste.php?error=datum');
    exit;
}

$draft_key = 'anwesenheit_draft';
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}

$draft = &$_SESSION[$draft_key];
if (!isset($draft['maengel']) || !is_array($draft['maengel'])) {
    $draft['maengel'] = [];
}

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];

$settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('maengelbericht_standort_default', 'maengelbericht_mangel_an_default')");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (Exception $e) {}
$standort_default = trim($settings['maengelbericht_standort_default'] ?? '');
if (!in_array($standort_default, $standort_options)) $standort_default = $standort_options[0];
$mangel_an_default = trim($settings['maengelbericht_mangel_an_default'] ?? '');
if (!in_array($mangel_an_default, $mangel_an_options)) $mangel_an_default = $mangel_an_options[0];

$members_list = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
$message = '';
$error = '';

// POST: Mängel in Draft speichern und zurück
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_maengel_draft'])) {
    $maengel = [];
    if (!empty($_POST['maengel']) && is_array($_POST['maengel'])) {
        foreach ($_POST['maengel'] as $idx => $m) {
            $standort = trim($m['standort'] ?? '');
            $mangel_an = trim($m['mangel_an'] ?? '');
            $bezeichnung = trim($m['bezeichnung'] ?? '');
            $mangel_beschreibung = trim($m['mangel_beschreibung'] ?? '');
            $ursache = trim($m['ursache'] ?? '');
            $verbleib = trim($m['verbleib'] ?? '');
            $aufgenommen_durch = trim($m['aufgenommen_durch'] ?? '');
            $aufgenommen_am = trim($m['aufgenommen_am'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aufgenommen_am)) $aufgenommen_am = $datum;
            if (!in_array($standort, $standort_options)) $standort = $standort_options[0];
            if (!in_array($mangel_an, $mangel_an_options)) $mangel_an = $mangel_an_options[0];
            if ($bezeichnung !== '' || $mangel_beschreibung !== '' || $ursache !== '' || $verbleib !== '' || $aufgenommen_durch !== '') {
                $maengel[] = [
                    'standort' => $standort,
                    'mangel_an' => $mangel_an,
                    'bezeichnung' => $bezeichnung ?: null,
                    'mangel_beschreibung' => $mangel_beschreibung ?: null,
                    'ursache' => $ursache ?: null,
                    'verbleib' => $verbleib ?: null,
                    'aufgenommen_durch' => $aufgenommen_durch ?: null,
                    'aufgenommen_am' => $aufgenommen_am,
                ];
            }
        }
    }
    $draft['maengel'] = $maengel;
    $_SESSION[$draft_key] = $draft;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS anwesenheitsliste_drafts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, datum DATE NOT NULL, auswahl VARCHAR(50) NOT NULL, dienstplan_id INT NULL, typ VARCHAR(50) NOT NULL DEFAULT 'dienst', bezeichnung VARCHAR(255) NULL, draft_data JSON NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_datum_auswahl (datum, auswahl)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $db->prepare("INSERT INTO anwesenheitsliste_drafts (user_id, datum, auswahl, dienstplan_id, typ, bezeichnung, draft_data) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), dienstplan_id=VALUES(dienstplan_id), typ=VALUES(typ), bezeichnung=VALUES(bezeichnung), draft_data=VALUES(draft_data), updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([
            (int)($_SESSION['user_id'] ?? 0),
            $draft['datum'],
            $draft['auswahl'],
            $draft['dienstplan_id'] ?? null,
            $draft['typ'] ?? 'dienst',
            $draft['bezeichnung_sonstige'] ?? $draft['thema'] ?? null,
            json_encode($draft)
        ]);
    } catch (Exception $e) { error_log('anwesenheitsliste-maengel draft save: ' . $e->getMessage()); }
    header('Location: ' . $back_url);
    exit;
}

$maengel_draft = $draft['maengel'];
if (empty($maengel_draft)) {
    $maengel_draft = [['standort' => $standort_default, 'mangel_an' => $mangel_an_default, 'bezeichnung' => '', 'mangel_beschreibung' => '', 'ursache' => '', 'verbleib' => '', 'aufgenommen_durch' => '', 'aufgenommen_am' => $datum]];
}

$members_json = json_encode(array_map(function($m) {
    return ['id' => (int)$m['id'], 'label' => trim($m['last_name'] . ', ' . $m['first_name'])];
}, $members_list));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Mängel - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Startseite</a></li>
                <li class="nav-item"><a class="nav-link" href="formulare.php"><i class="fas fa-file-alt"></i> Formulare</a></li>
                <?php if (!is_system_user()): ?>
                <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <?php if (!is_system_user()): ?>
                        <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-exclamation-triangle text-warning"></i> Mängel – festhalten</h3>
                    <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?> – Mängel erfassen (werden beim Speichern der Anwesenheitsliste als Mängelberichte angelegt)</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                    <form method="post" id="maengelForm">
                        <input type="hidden" name="save_maengel_draft" value="1">
                        <div id="maengelContainer">
                            <?php foreach ($maengel_draft as $idx => $m): ?>
                            <div class="maengel-block card mb-4" data-index="<?php echo (int)$idx; ?>">
                                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">Mangel <?php echo (int)$idx + 1; ?></span>
                                    <?php if ($idx > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-maengel"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Standort</label>
                                            <select class="form-select" name="maengel[<?php echo (int)$idx; ?>][standort]">
                                                <?php foreach ($standort_options as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($m['standort'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Mangel/Wartung an</label>
                                            <select class="form-select" name="maengel[<?php echo (int)$idx; ?>][mangel_an]">
                                                <?php foreach ($mangel_an_options as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($m['mangel_an'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                                            <input type="text" class="form-control" name="maengel[<?php echo (int)$idx; ?>][bezeichnung]" value="<?php echo htmlspecialchars($m['bezeichnung'] ?? ''); ?>" placeholder="z.B. TLF 16/25">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Mangel Beschreibung</label>
                                            <textarea class="form-control" name="maengel[<?php echo (int)$idx; ?>][mangel_beschreibung]" rows="2"><?php echo htmlspecialchars($m['mangel_beschreibung'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Ursache</label>
                                            <input type="text" class="form-control" name="maengel[<?php echo (int)$idx; ?>][ursache]" value="<?php echo htmlspecialchars($m['ursache'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Verbleib</label>
                                            <input type="text" class="form-control" name="maengel[<?php echo (int)$idx; ?>][verbleib]" value="<?php echo htmlspecialchars($m['verbleib'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Aufgenommen durch</label>
                                            <div class="position-relative">
                                                <input type="text" class="form-control aufgenommen-durch-display" placeholder="Buchstaben eingeben zum Filtern" autocomplete="off" value="<?php echo htmlspecialchars(_maengel_aufgenommen_display($m['aufgenommen_durch'] ?? '', $members_list)); ?>">
                                                <input type="hidden" class="aufgenommen-durch-hidden" name="maengel[<?php echo (int)$idx; ?>][aufgenommen_durch]" value="<?php echo htmlspecialchars($m['aufgenommen_durch'] ?? ''); ?>">
                                                <div class="list-group position-absolute w-100 mt-1 shadow aufgenommen-suggestions" style="z-index: 1050; max-height: 180px; overflow-y: auto; display: none;"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Aufgenommen am</label>
                                            <input type="date" class="form-control" name="maengel[<?php echo (int)$idx; ?>][aufgenommen_am]" value="<?php echo htmlspecialchars($m['aufgenommen_am'] ?? $datum); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-secondary" id="btnAddMaengel"><i class="fas fa-plus"></i> Weiteren Mangel hinzufügen</button>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save"></i> Speichern & Zurück</button>
                            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
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
    var membersData = <?php echo $members_json; ?>;
    var nextIndex = <?php echo count($maengel_draft); ?>;
    var standortOpts = <?php echo json_encode($standort_options); ?>;
    var mangelAnOpts = <?php echo json_encode($mangel_an_options); ?>;
    var datum = <?php echo json_encode($datum); ?>;

    function filterMembers(q) {
        q = (q || '').toLowerCase().trim();
        if (q === '') return membersData;
        return membersData.filter(function(m) { return (m.label || '').toLowerCase().indexOf(q) >= 0; });
    }

    function initAufgenommenDurch(block) {
        var display = block.querySelector('.aufgenommen-durch-display');
        var hidden = block.querySelector('.aufgenommen-durch-hidden');
        var suggestions = block.querySelector('.aufgenommen-suggestions');
        if (!display || !suggestions) return;
        function render(items) {
            suggestions.innerHTML = '';
            items.forEach(function(item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action list-group-item-light text-start';
                btn.textContent = item.label;
                btn.dataset.id = item.id;
                btn.dataset.label = item.label;
                btn.addEventListener('click', function() {
                    display.value = this.dataset.label;
                    if (hidden) hidden.value = this.dataset.id;
                    suggestions.style.display = 'none';
                });
                suggestions.appendChild(btn);
            });
            suggestions.style.display = items.length > 0 ? 'block' : 'none';
        }
        display.addEventListener('input', function() {
            if (hidden) hidden.value = '';
            render(filterMembers(display.value.trim()));
        });
        display.addEventListener('focus', function() { render(filterMembers(display.value.trim())); });
        display.addEventListener('blur', function() { setTimeout(function() { suggestions.style.display = 'none'; }, 200); });
        document.addEventListener('click', function(e) {
            if (!display.contains(e.target) && !suggestions.contains(e.target)) suggestions.style.display = 'none';
        });
    }

    document.querySelectorAll('.maengel-block').forEach(function(b) { initAufgenommenDurch(b); });

    document.getElementById('btnAddMaengel').addEventListener('click', function() {
        var idx = nextIndex++;
        var html = '<div class="maengel-block card mb-4" data-index="' + idx + '">' +
            '<div class="card-header py-2 d-flex justify-content-between align-items-center">' +
            '<span class="fw-bold">Mangel ' + (idx + 1) + '</span>' +
            '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-maengel"><i class="fas fa-trash"></i></button>' +
            '</div><div class="card-body"><div class="row g-3">' +
            '<div class="col-md-6"><label class="form-label">Standort</label><select class="form-select" name="maengel[' + idx + '][standort]">' +
            standortOpts.map(function(o){ return '<option value="' + o + '">' + o + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="col-md-6"><label class="form-label">Mangel/Wartung an</label><select class="form-select" name="maengel[' + idx + '][mangel_an]">' +
            mangelAnOpts.map(function(o){ return '<option value="' + o + '">' + o + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="col-12"><label class="form-label">Bezeichnung, ggf. Gerätenummer</label><input type="text" class="form-control" name="maengel[' + idx + '][bezeichnung]"></div>' +
            '<div class="col-12"><label class="form-label">Mangel Beschreibung</label><textarea class="form-control" name="maengel[' + idx + '][mangel_beschreibung]" rows="2"></textarea></div>' +
            '<div class="col-md-6"><label class="form-label">Ursache</label><input type="text" class="form-control" name="maengel[' + idx + '][ursache]"></div>' +
            '<div class="col-md-6"><label class="form-label">Verbleib</label><input type="text" class="form-control" name="maengel[' + idx + '][verbleib]"></div>' +
            '<div class="col-md-6"><label class="form-label">Aufgenommen durch</label><div class="position-relative">' +
            '<input type="text" class="form-control aufgenommen-durch-display" placeholder="Buchstaben eingeben zum Filtern" autocomplete="off">' +
            '<input type="hidden" class="aufgenommen-durch-hidden" name="maengel[' + idx + '][aufgenommen_durch]">' +
            '<div class="list-group position-absolute w-100 mt-1 shadow aufgenommen-suggestions" style="z-index:1050;max-height:180px;overflow-y:auto;display:none;"></div></div></div>' +
            '<div class="col-md-6"><label class="form-label">Aufgenommen am</label><input type="date" class="form-control" name="maengel[' + idx + '][aufgenommen_am]" value="' + datum + '"></div>' +
            '</div></div></div>';
        var div = document.createElement('div');
        div.innerHTML = html;
        var block = div.firstElementChild;
        document.getElementById('maengelContainer').appendChild(block);
        initAufgenommenDurch(block);
    });

    document.getElementById('maengelContainer').addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-remove-maengel');
        if (btn) {
            var block = btn.closest('.maengel-block');
            if (block && document.querySelectorAll('.maengel-block').length > 1) block.remove();
        }
    });

    document.getElementById('maengelForm').addEventListener('submit', function() {
        document.querySelectorAll('.maengel-block').forEach(function(block) {
            var hidden = block.querySelector('.aufgenommen-durch-hidden');
            var display = block.querySelector('.aufgenommen-durch-display');
            if (hidden && display) {
                var idVal = hidden.value.trim();
                if (!idVal && display.value.trim()) hidden.value = display.value.trim();
            }
        });
    });
})();
</script>
</body>
</html>
<?php
function _maengel_aufgenommen_display($val, $members_list) {
    if (empty($val)) return '';
    if (preg_match('/^\d+$/', $val)) {
        $id = (int)$val;
        foreach ($members_list as $m) {
            if ((int)$m['id'] === $id) return trim($m['last_name'] . ', ' . $m['first_name']);
        }
    }
    return $val;
}
