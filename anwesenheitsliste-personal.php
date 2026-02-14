<?php
/**
 * Anwesenheitsliste – Personal: anwesende Mitglieder auswählen und Fahrzeug zuordnen.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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

// Mitglieder laden (alle aus members, sortiert)
$members = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle members evtl. in anderem Schema
}
// Fahrzeuge laden
$vehicles = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
}

// POST: Auswahl speichern und zurück (inkl. Rolle Maschinist/Einheitsführer pro Fahrzeug)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['members'] = [];
    $draft['member_vehicle'] = [];
    if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
        foreach ($_POST['member_id'] as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $draft['members'][] = $mid;
                $vid = isset($_POST['vehicle'][$mid]) ? (int)$_POST['vehicle'][$mid] : 0;
                if ($vid > 0) {
                    $draft['member_vehicle'][$mid] = $vid;
                }
            }
        }
    }
    // Rollen nur für Fahrzeuge aktualisieren, die in member_vehicle vorkommen (ein Maschinist / ein Einheitsführer pro Fahrzeug)
    $vehicles_in_personal = array_unique(array_values($draft['member_vehicle']));
    foreach ($vehicles_in_personal as $vid) {
        if ($vid <= 0) continue;
        $draft['vehicle_maschinist'][$vid] = null;
        $draft['vehicle_einheitsfuehrer'][$vid] = null;
    }
    if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
        foreach ($_POST['member_id'] as $mid) {
            $mid = (int)$mid;
            $vid = isset($_POST['vehicle'][$mid]) ? (int)$_POST['vehicle'][$mid] : 0;
            $role = isset($_POST['role'][$mid]) ? trim($_POST['role'][$mid]) : '';
            if ($vid > 0 && $role === 'maschinist') {
                $draft['vehicle_maschinist'][$vid] = $mid;
            }
            if ($vid > 0 && $role === 'einheitsfuehrer') {
                $draft['vehicle_einheitsfuehrer'][$vid] = $mid;
            }
        }
    }
    // Entfernen von null-Einträgen
    $draft['vehicle_maschinist'] = array_filter($draft['vehicle_maschinist'] ?? []);
    $draft['vehicle_einheitsfuehrer'] = array_filter($draft['vehicle_einheitsfuehrer'] ?? []);
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
$selected_ids = array_flip($draft['members']);
$member_vehicle = $draft['member_vehicle'];
$vehicle_maschinist = $draft['vehicle_maschinist'] ?? [];
$vehicle_einheitsfuehrer = $draft['vehicle_einheitsfuehrer'] ?? [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Personal - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        tr.anw-row { cursor: pointer; }
        .anw-row .no-click { cursor: default; }
    </style>
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
                        <h3 class="mb-0"><i class="fas fa-users"></i> Personal – Anwesende auswählen</h3>
                        <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?></p>
                    </div>
                    <div class="card-body p-4">
                        <form method="post" id="personalForm">
                            <p class="text-muted small">Klicken Sie auf einen Namen, um die Person als anwesend auszuwählen (nochmal klicken zum Abwählen). Optional: Fahrzeug zuordnen und Rolle (Maschinist/Einheitsführer) – pro Fahrzeug nur je eine Person.</p>
                            <?php if (empty($members)): ?>
                                <p class="text-muted">Keine Mitglieder in der Datenbank. Bitte zuerst in der Mitgliederverwaltung anlegen.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover anwesenheit-tabelle">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Fahrzeug (optional)</th>
                                                <th>Rolle auf Fahrzeug</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $m):
                                                $vid_cur = isset($member_vehicle[$m['id']]) ? (int)$member_vehicle[$m['id']] : 0;
                                                $role_cur = '';
                                                if ($vid_cur && isset($vehicle_maschinist[$vid_cur]) && (int)$vehicle_maschinist[$vid_cur] === (int)$m['id']) {
                                                    $role_cur = 'maschinist';
                                                } elseif ($vid_cur && isset($vehicle_einheitsfuehrer[$vid_cur]) && (int)$vehicle_einheitsfuehrer[$vid_cur] === (int)$m['id']) {
                                                    $role_cur = 'einheitsfuehrer';
                                                }
                                            ?>
                                            <?php $row_selected = isset($selected_ids[$m['id']]); $row_bg = $row_selected ? ' style="background-color: #b6d4fe;"' : ''; ?>
                                            <tr class="anw-row <?php echo $row_selected ? 'selected' : ''; ?>" data-member-id="<?php echo (int)$m['id']; ?>">
                                                <td class="name-cell"<?php echo $row_bg; ?>>
                                                    <input type="hidden" name="member_id[]" value="<?php echo (int)$m['id']; ?>" class="member-id-input" <?php echo $row_selected ? '' : 'disabled'; ?>>
                                                    <span class="d-block py-1 <?php echo $row_selected ? 'fw-bold' : ''; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></span>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="vehicle[<?php echo (int)$m['id']; ?>]">
                                                        <option value="">— kein Fahrzeug —</option>
                                                        <?php foreach ($vehicles as $v): ?>
                                                        <option value="<?php echo (int)$v['id']; ?>" <?php echo $vid_cur === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="role[<?php echo (int)$m['id']; ?>]">
                                                        <option value="">— keine —</option>
                                                        <option value="maschinist" <?php echo $role_cur === 'maschinist' ? 'selected' : ''; ?>>Maschinist</option>
                                                        <option value="einheitsfuehrer" <?php echo $role_cur === 'einheitsfuehrer' ? 'selected' : ''; ?>>Einheitsführer</option>
                                                    </select>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Übernehmen und zurück</button>
                                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                            </div>
                        </form>
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
    <script>
    window.addEventListener('beforeunload', function() {
        var form = document.getElementById('personalForm');
        if (form) {
            var fd = new FormData();
            fd.append('form_type', 'personal');
            form.querySelectorAll('.anw-row.selected').forEach(function(row) {
                var midInput = row.querySelector('.member-id-input');
                var vehicleSelect = row.querySelector('select[name^="vehicle"]');
                var roleSelect = row.querySelector('select[name^="role"]');
                if (midInput) {
                    fd.append('member_id[]', midInput.value);
                    if (vehicleSelect) fd.append('vehicle[' + midInput.value + ']', vehicleSelect.value);
                    if (roleSelect) fd.append('role[' + midInput.value + ']', roleSelect.value);
                }
            });
            navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
        } else {
            navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
        }
    });
    </script>
    <script>
        document.querySelectorAll('.anw-row').forEach(function(row) {
            var nameCell = row.querySelector('.name-cell');
            var hiddenInput = row.querySelector('.member-id-input');
            var vehicleSelect = row.querySelector('select[name^="vehicle"]');
            var roleSelect = row.querySelector('select[name^="role"]');
            if (!nameCell || !hiddenInput) return;
            function markRowSelected(selected) {
                var cells = row.querySelectorAll('td');
                if (selected) {
                    hiddenInput.disabled = false;
                    row.classList.add('selected');
                    cells.forEach(function(td) { td.style.backgroundColor = '#b6d4fe'; });
                    var span = nameCell.querySelector('.d-block');
                    if (span) span.classList.add('fw-bold');
                } else {
                    hiddenInput.disabled = true;
                    row.classList.remove('selected');
                    cells.forEach(function(td) { td.style.backgroundColor = ''; });
                    var span = nameCell.querySelector('.d-block');
                    if (span) span.classList.remove('fw-bold');
                }
            }
            nameCell.addEventListener('click', function(e) {
                e.preventDefault();
                markRowSelected(hiddenInput.disabled);
            });
            if (vehicleSelect) vehicleSelect.addEventListener('change', function() {
                if (vehicleSelect.value && vehicleSelect.value !== '') markRowSelected(true);
            });
            if (roleSelect) roleSelect.addEventListener('change', function() {
                if (roleSelect.value && roleSelect.value !== '') markRowSelected(true);
            });
        });
    </script>
</body>
</html>
