<?php
/**
 * Anwesenheitsliste – Fahrzeuge: eingesetzte Fahrzeuge, Maschinist und Einheitsführer pro Fahrzeug.
 * Personen, die hier ausgewählt werden, werden ggf. automatisch dem Personal hinzugefügt.
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

// Mitglieder für Dropdowns (bevorzugt: die diesem Fahrzeug zugeordneten)
$members = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    //
}
$vehicles = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    //
}

// POST: Besatzung hinzufügen/entfernen (Modal) – vor dem Hauptformular prüfen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_self = 'Location: anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
    if (!empty($_POST['add_crew_member'])) {
        $vid = (int)($_POST['besatzung_vehicle_id'] ?? 0);
        $mid = (int)($_POST['besatzung_member_id'] ?? 0);
        if ($vid > 0 && $mid > 0) {
            $draft['member_vehicle'][$mid] = $vid;
            if (!in_array($mid, $draft['members'])) {
                $draft['members'][] = $mid;
            }
        }
        header($redirect_self);
        exit;
    }
    if (!empty($_POST['remove_crew_member'])) {
        $vid = (int)($_POST['besatzung_vehicle_id'] ?? 0);
        $mid = (int)($_POST['besatzung_member_id'] ?? 0);
        if ($vid > 0 && $mid > 0) {
            unset($draft['member_vehicle'][$mid]);
            if (isset($draft['vehicle_maschinist'][$vid]) && (int)$draft['vehicle_maschinist'][$vid] === $mid) {
                unset($draft['vehicle_maschinist'][$vid]);
            }
            if (isset($draft['vehicle_einheitsfuehrer'][$vid]) && (int)$draft['vehicle_einheitsfuehrer'][$vid] === $mid) {
                unset($draft['vehicle_einheitsfuehrer'][$vid]);
            }
        }
        header($redirect_self);
        exit;
    }
}

// POST: Fahrzeuge + Maschinist/Einheitsführer speichern; Personen die nur hier gewählt wurden dem Personal hinzufügen inkl. Fahrzeugzuordnung und Rolle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['vehicles'] = [];
    $draft['vehicle_maschinist'] = [];
    $draft['vehicle_einheitsfuehrer'] = [];
    $members_set = array_flip($draft['members']);
    if (!empty($_POST['vehicle_id']) && is_array($_POST['vehicle_id'])) {
        foreach ($_POST['vehicle_id'] as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) {
                $draft['vehicles'][] = $vid;
                $masch = isset($_POST['maschinist'][$vid]) ? (int)$_POST['maschinist'][$vid] : 0;
                $einh = isset($_POST['einheitsfuehrer'][$vid]) ? (int)$_POST['einheitsfuehrer'][$vid] : 0;
                if ($masch > 0) {
                    $draft['vehicle_maschinist'][$vid] = $masch;
                    $draft['member_vehicle'][$masch] = $vid;
                    if (!isset($members_set[$masch])) {
                        $draft['members'][] = $masch;
                    }
                }
                if ($einh > 0) {
                    $draft['vehicle_einheitsfuehrer'][$vid] = $einh;
                    $draft['member_vehicle'][$einh] = $vid;
                    if (!isset($members_set[$einh])) {
                        $draft['members'][] = $einh;
                    }
                }
            }
        }
    }
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
// Angezeigte Auswahl: explizit gewählte Fahrzeuge + Fahrzeuge, die unter Personal zugeordnet wurden
$selected_vehicles = array_flip($draft['vehicles']);
foreach ($draft['member_vehicle'] as $mid => $vid) {
    if ($vid > 0) {
        $selected_vehicles[$vid] = true;
    }
}

// Pro Fahrzeug: Nur Mitglieder anzeigen, die diesem Fahrzeug zugeordnet sind oder noch keinem Fahrzeug zugeordnet sind.
// Eine Person, die bereits einem anderen Fahrzeug zugeordnet ist, erscheint nicht in der Liste anderer Fahrzeuge.
function members_for_vehicle_dropdown($members, $member_vehicle, $vehicle_id) {
    $on_vehicle = [];
    $others = [];
    $vehicle_id = (int)$vehicle_id;
    foreach ($members as $m) {
        $assigned_vehicle = isset($member_vehicle[$m['id']]) ? (int)$member_vehicle[$m['id']] : 0;
        if ($assigned_vehicle === $vehicle_id) {
            $on_vehicle[] = $m;
        } elseif ($assigned_vehicle === 0) {
            $others[] = $m;
        }
        // Wenn $assigned_vehicle !== 0 und !== $vehicle_id: Person ist anderem Fahrzeug zugeordnet → nicht anzeigen
    }
    return ['on_vehicle' => $on_vehicle, 'others' => $others];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Fahrzeuge - Feuerwehr App</title>
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
                    <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
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
                        <h3 class="mb-0"><i class="fas fa-truck"></i> Fahrzeuge – eingesetzte Fahrzeuge</h3>
                        <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?> – Maschinist und Einheitsführer pro Fahrzeug. Personen, die Sie hier auswählen, werden automatisch dem Personal hinzugefügt.</p>
                    </div>
                    <div class="card-body p-4">
                        <form method="post" id="fahrzeugeForm">
                            <p class="text-muted small">Klicken Sie auf einen Fahrzeugnamen, um es als eingesetzt auszuwählen (nochmal klicken zum Abwählen). Pro Fahrzeug können Sie Maschinist und Einheitsführer festlegen.</p>
                            <?php if (empty($vehicles)): ?>
                                <p class="text-muted">Keine Fahrzeuge in der Datenbank. Bitte in der Fahrzeugverwaltung anlegen.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover anwesenheit-tabelle">
                                        <thead>
                                            <tr>
                                                <th>Fahrzeug</th>
                                                <th>Maschinist</th>
                                                <th>Einheitsführer</th>
                                                <th class="text-center">Besatzung</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vehicles as $v): ?>
                                            <?php
                                            $groups = members_for_vehicle_dropdown($members, $draft['member_vehicle'], $v['id']);
                                            $masch_val = isset($draft['vehicle_maschinist'][$v['id']]) ? $draft['vehicle_maschinist'][$v['id']] : '';
                                            $einh_val = isset($draft['vehicle_einheitsfuehrer'][$v['id']]) ? $draft['vehicle_einheitsfuehrer'][$v['id']] : '';
                                            $is_selected = isset($selected_vehicles[$v['id']]);
                                            $row_bg = $is_selected ? ' style="background-color: #b6d4fe;"' : '';
                                            $crew_for_vehicle = $groups['on_vehicle'];
                                            $available_for_vehicle = $groups['others'];
                                            $crew_json = htmlspecialchars(json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'name' => $m['last_name'] . ', ' . $m['first_name']]; }, $crew_for_vehicle)), ENT_QUOTES, 'UTF-8');
                                            $available_json = htmlspecialchars(json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'name' => $m['last_name'] . ', ' . $m['first_name']]; }, $available_for_vehicle)), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr class="anw-row <?php echo $is_selected ? 'selected' : ''; ?>" data-vehicle-id="<?php echo (int)$v['id']; ?>">
                                                <td class="name-cell"<?php echo $row_bg; ?>>
                                                    <input type="hidden" name="vehicle_id[]" value="<?php echo (int)$v['id']; ?>" class="vehicle-id-input" <?php echo $is_selected ? '' : 'disabled'; ?>>
                                                    <span class="d-block py-1 fw-bold"><?php echo htmlspecialchars($v['name']); ?></span>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="maschinist[<?php echo (int)$v['id']; ?>]">
                                                        <option value="">— keine Auswahl —</option>
                                                        <?php foreach (array_merge($groups['on_vehicle'], $groups['others']) as $m): ?>
                                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)$masch_val === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="einheitsfuehrer[<?php echo (int)$v['id']; ?>]">
                                                        <option value="">— keine Auswahl —</option>
                                                        <?php foreach (array_merge($groups['on_vehicle'], $groups['others']) as $m): ?>
                                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)$einh_val === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="no-click text-center"<?php echo $row_bg; ?>>
                                                    <button type="button" class="btn btn-sm btn-outline-primary besatzung-btn" data-vehicle-id="<?php echo (int)$v['id']; ?>" data-vehicle-name="<?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?>" data-crew="<?php echo $crew_json; ?>" data-available="<?php echo $available_json; ?>" title="Besatzung anzeigen und verwalten">
                                                        <i class="fas fa-users"></i> Besatzung
                                                    </button>
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

        <!-- Modal: Besatzung pro Fahrzeug -->
        <div class="modal fade" id="besatzungModal" tabindex="-1" data-datum="<?php echo htmlspecialchars($datum); ?>" data-auswahl="<?php echo htmlspecialchars($auswahl); ?>">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="besatzungModalTitle"><i class="fas fa-users"></i> Besatzung</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <h6 class="mb-2">Aktuelle Besatzung</h6>
                        <ul class="list-group list-group-flush mb-3" id="besatzungCrewList"></ul>
                        <hr>
                        <h6 class="mb-2">Person hinzufügen</h6>
                        <form method="post" id="besatzungAddForm" class="d-flex gap-2 flex-wrap align-items-end" action="anwesenheitsliste-fahrzeuge.php?datum=<?php echo urlencode($datum); ?>&auswahl=<?php echo urlencode($auswahl); ?>">
                            <input type="hidden" name="add_crew_member" value="1">
                            <input type="hidden" name="besatzung_vehicle_id" id="besatzungVehicleIdInput" value="">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($datum); ?>">
                            <input type="hidden" name="auswahl" value="<?php echo htmlspecialchars($auswahl); ?>">
                            <div class="flex-grow-1" style="min-width: 180px;">
                                <select class="form-select form-select-sm" name="besatzung_member_id" id="besatzungMemberSelect">
                                    <option value="">— Person wählen —</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Hinzufügen</button>
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
        var form = document.getElementById('fahrzeugeForm');
        if (form) {
            var fd = new FormData();
            fd.append('form_type', 'fahrzeuge');
            form.querySelectorAll('.anw-row.selected').forEach(function(row) {
                var vidInput = row.querySelector('.vehicle-id-input');
                var maschSelect = row.querySelector('select[name^="maschinist"]');
                var einhSelect = row.querySelector('select[name^="einheitsfuehrer"]');
                if (vidInput) {
                    fd.append('vehicle_id[]', vidInput.value);
                    if (maschSelect) fd.append('maschinist[' + vidInput.value + ']', maschSelect.value);
                    if (einhSelect) fd.append('einheitsfuehrer[' + vidInput.value + ']', einhSelect.value);
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
            var hiddenInput = row.querySelector('.vehicle-id-input');
            if (!nameCell || !hiddenInput) return;
            nameCell.addEventListener('click', function(e) {
                e.preventDefault();
                var cells = row.querySelectorAll('td');
                if (hiddenInput.disabled) {
                    hiddenInput.disabled = false;
                    row.classList.add('selected');
                    cells.forEach(function(td) { td.style.backgroundColor = '#b6d4fe'; });
                } else {
                    hiddenInput.disabled = true;
                    row.classList.remove('selected');
                    cells.forEach(function(td) { td.style.backgroundColor = ''; });
                }
            });
        });

        document.querySelectorAll('.besatzung-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var vid = this.getAttribute('data-vehicle-id');
                var vname = this.getAttribute('data-vehicle-name');
                var crew = [];
                var available = [];
                try {
                    crew = JSON.parse(this.getAttribute('data-crew') || '[]');
                    available = JSON.parse(this.getAttribute('data-available') || '[]');
                } catch (err) {}
                var modal = document.getElementById('besatzungModal');
                var titleEl = document.getElementById('besatzungModalTitle');
                var listEl = document.getElementById('besatzungCrewList');
                var selectEl = document.getElementById('besatzungMemberSelect');
                var vehicleInput = document.getElementById('besatzungVehicleIdInput');
                titleEl.textContent = 'Besatzung: ' + (vname || '');
                vehicleInput.value = vid || '';
                listEl.innerHTML = '';
                crew.forEach(function(p) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center';
                    li.innerHTML = '<span>' + (p.name || '') + '</span>';
                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = 'anwesenheitsliste-fahrzeuge.php?datum=' + encodeURIComponent(modal.dataset.datum || '') + '&auswahl=' + encodeURIComponent(modal.dataset.auswahl || '');
                    form.style.display = 'inline';
                    form.innerHTML = '<input type="hidden" name="remove_crew_member" value="1">' +
                        '<input type="hidden" name="besatzung_vehicle_id" value="' + (vid || '') + '">' +
                        '<input type="hidden" name="besatzung_member_id" value="' + (p.id || '') + '">' +
                        '<button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>';
                    li.appendChild(form);
                    listEl.appendChild(li);
                });
                if (crew.length === 0) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item text-muted';
                    li.textContent = 'Noch keine Besatzung zugewiesen.';
                    listEl.appendChild(li);
                }
                selectEl.innerHTML = '<option value="">— Person wählen —</option>';
                available.forEach(function(p) {
                    var opt = document.createElement('option');
                    opt.value = p.id || '';
                    opt.textContent = p.name || '';
                    selectEl.appendChild(opt);
                });
                new bootstrap.Modal(modal).show();
            });
        });
    </script>
</body>
</html>
