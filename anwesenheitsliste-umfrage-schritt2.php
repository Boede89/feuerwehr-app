<?php
/**
 * Anwesenheitsliste – Umfrage Schritt 2: Personal oder Fahrzeuge
 * Nach dem Einsatzdaten-Schritt: Zwei Buttons. Personal -> Personenkacheln. Fahrzeuge -> Fahrzeugkacheln, bei Klick Besatzung (Personenkacheln).
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';
require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';

if (!$db) {
    header('Location: anwesenheitsliste.php');
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
if ($einheit_id <= 0) $einheit_id = isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0;
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
}
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_param = $einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '';

if (!function_exists('is_admin') || !is_admin()) {
    header('Location: anwesenheitsliste.php' . ($einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''));
    exit;
}

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : (isset($_POST['datum']) ? trim($_POST['datum']) : date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    $datum = date('Y-m-d');
}
$auswahl = 'einsatz';
$draft_key = 'anwesenheit_draft';

if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    header('Location: anwesenheitsliste-umfrage.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . '&step=1' . $einheit_param);
    exit;
}
$draft = &$_SESSION[$draft_key];

$mode = isset($_GET['mode']) ? trim($_GET['mode']) : '';
$selected_vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$besatzung_sort = isset($_GET['sort']) && $_GET['sort'] === 'name' ? 'name' : 'ki';

// POST: Besatzung speichern (wenn von Besatzungs-Ansicht)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_besatzung'])) {
    $vid = (int)($_POST['besatzung_vehicle_id'] ?? 0);
    if ($vid > 0) {
        // Alte Besatzung dieses Fahrzeugs entfernen
        foreach ($draft['member_vehicle'] ?? [] as $mid => $assigned_vid) {
            if ((int)$assigned_vid === $vid) {
                unset($draft['member_vehicle'][$mid]);
            }
        }
        if (isset($draft['vehicle_maschinist'][$vid])) unset($draft['vehicle_maschinist'][$vid]);
        if (isset($draft['vehicle_einheitsfuehrer'][$vid])) unset($draft['vehicle_einheitsfuehrer'][$vid]);
        $draft['vehicles'] = array_values(array_unique(array_merge($draft['vehicles'] ?? [], [$vid])));
        $crew = [];
        if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
            foreach ($_POST['member_id'] as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) {
                    $draft['members'] = array_values(array_unique(array_merge($draft['members'] ?? [], [$mid])));
                    $draft['member_vehicle'][$mid] = $vid;
                    $crew[] = $mid;
                }
            }
        }
        $role = $_POST['role'] ?? [];
        foreach ($crew as $mid) {
            $r = trim($role[$mid] ?? '');
            if ($r === 'maschinist') {
                $draft['vehicle_maschinist'][$vid] = $mid;
            } elseif ($r === 'einheitsfuehrer') {
                $draft['vehicle_einheitsfuehrer'][$vid] = $mid;
            }
        }
        $pa_ids = [];
        foreach ($crew as $mid) {
            if (!empty($_POST['member_pa'][$mid])) {
                $pa_ids[] = $mid;
            }
        }
        $draft['member_pa'] = array_values(array_unique(array_merge($draft['member_pa'] ?? [], $pa_ids)));
    }
    header('Location: anwesenheitsliste-umfrage-schritt2.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . '&mode=fahrzeuge' . $einheit_param);
    exit;
}

// Mitglieder und Fahrzeuge laden
$members = [];
$vehicles = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name ASC");
        $stmt->execute([$einheit_id]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Mitglieder: bei Besatzungsansicht nach Fahrzeug- und Gesamthäufigkeit oder nach Name sortieren
    if ($selected_vehicle_id > 0 && $einheit_id > 0) {
        if ($besatzung_sort === 'name') {
            $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE (einheit_id = ? OR einheit_id IS NULL) ORDER BY last_name, first_name");
            $stmt->execute([$einheit_id]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare("
                SELECT m.id, m.first_name, m.last_name,
                    COALESCE(vf.cnt, 0) AS vehicle_freq,
                    COALESCE(gf.cnt, 0) AS total_freq
                FROM members m
                LEFT JOIN (
                    SELECT am.member_id, COUNT(*) AS cnt
                    FROM anwesenheitsliste_mitglieder am
                    INNER JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id
                    WHERE am.vehicle_id = ? AND (a.einheit_id = ? OR a.einheit_id IS NULL)
                    GROUP BY am.member_id
                ) vf ON vf.member_id = m.id
                LEFT JOIN (
                    SELECT am.member_id, COUNT(*) AS cnt
                    FROM anwesenheitsliste_mitglieder am
                    INNER JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id
                    WHERE (a.einheit_id = ? OR a.einheit_id IS NULL)
                    GROUP BY am.member_id
                ) gf ON gf.member_id = m.id
                WHERE (m.einheit_id = ? OR m.einheit_id IS NULL)
                ORDER BY COALESCE(vf.cnt, 0) DESC, COALESCE(gf.cnt, 0) DESC, m.last_name, m.first_name
            ");
            $stmt->execute([$selected_vehicle_id, $einheit_id, $einheit_id, $einheit_id]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($selected_vehicle_id > 0) {
        if ($besatzung_sort === 'name') {
            $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare("
                SELECT m.id, m.first_name, m.last_name,
                    COALESCE(vf.cnt, 0) AS vehicle_freq,
                    COALESCE(gf.cnt, 0) AS total_freq
                FROM members m
                LEFT JOIN (
                    SELECT am.member_id, COUNT(*) AS cnt
                    FROM anwesenheitsliste_mitglieder am
                    WHERE am.vehicle_id = ?
                    GROUP BY am.member_id
                ) vf ON vf.member_id = m.id
                LEFT JOIN (
                    SELECT am.member_id, COUNT(*) AS cnt
                    FROM anwesenheitsliste_mitglieder am
                    GROUP BY am.member_id
                ) gf ON gf.member_id = m.id
                ORDER BY COALESCE(vf.cnt, 0) DESC, COALESCE(gf.cnt, 0) DESC, m.last_name, m.first_name
            ");
            $stmt->execute([$selected_vehicle_id]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$selected_ids = array_flip($draft['members'] ?? []);
$member_vehicle = $draft['member_vehicle'] ?? [];
$vehicle_maschinist = $draft['vehicle_maschinist'] ?? [];
$vehicle_einheitsfuehrer = $draft['vehicle_einheitsfuehrer'] ?? [];
$member_pa = array_flip($draft['member_pa'] ?? []);

$base_url = 'anwesenheitsliste-umfrage-schritt2.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $einheit_param;
$personal_url = 'anwesenheitsliste-personal.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . '&umfrage=1' . $einheit_param;
$ts = trim($draft['bezeichnung_sonstige'] ?? 'Einsatz');
$typ_key = array_search($ts, get_dienstplan_typen_auswahl());
if ($typ_key !== false) {
    $personal_url .= '&typ_sonstige=' . urlencode($typ_key);
    foreach ($draft['uebungsleiter_member_ids'] ?? [] as $uid) {
        if ((int)$uid > 0) $personal_url .= '&uebungsleiter[]=' . (int)$uid;
    }
}

$selected_vehicle = null;
if ($selected_vehicle_id > 0) {
    foreach ($vehicles as $v) {
        if ((int)$v['id'] === $selected_vehicle_id) {
            $selected_vehicle = $v;
            break;
        }
    }
}
$crew_for_vehicle = [];
if ($selected_vehicle_id > 0) {
    foreach ($member_vehicle as $mid => $vid) {
        if ((int)$vid === $selected_vehicle_id) {
            $crew_for_vehicle[] = $mid;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal & Fahrzeuge – Umfrage - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .umfrage-btn-card { min-height: 140px; cursor: pointer; transition: all 0.2s ease; }
        .umfrage-btn-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
        .vehicle-card, .person-card { cursor: pointer; transition: all 0.2s ease; }
        .vehicle-card:hover, .person-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .vehicle-card.selected, .person-card.selected { background-color: #b6d4fe !important; box-shadow: 0 0 0 2px var(--bs-primary); }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''; ?>"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . ($einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '');
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php include __DIR__ . '/includes/system-user-nav.inc.php'; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-users"></i> Personal & Fahrzeuge</h3>
                        <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?></p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($mode === ''): ?>
                        <!-- Auswahl: Personal oder Fahrzeuge -->
                        <p class="text-muted mb-4">Wählen Sie, wie Sie Personal und Fahrzeuge erfassen möchten:</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <a href="<?php echo htmlspecialchars($personal_url); ?>" class="btn btn-outline-primary w-100 h-100 umfrage-btn-card d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                    <i class="fas fa-users fa-3x mb-2 text-primary"></i>
                                    <span class="fw-bold fs-5">Personal</span>
                                    <span class="text-muted small">Personen als Kacheln auswählen</span>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="<?php echo htmlspecialchars($base_url . '&mode=fahrzeuge'); ?>" class="btn btn-outline-success w-100 h-100 umfrage-btn-card d-flex flex-column align-items-center justify-content-center p-4 text-decoration-none">
                                    <i class="fas fa-truck fa-3x mb-2 text-success"></i>
                                    <span class="fw-bold fs-5">Fahrzeuge</span>
                                    <span class="text-muted small">Fahrzeuge auswählen, dann Besatzung</span>
                                </a>
                            </div>
                        </div>

                        <?php elseif ($mode === 'fahrzeuge' && $selected_vehicle_id <= 0): ?>
                        <!-- Fahrzeug-Kacheln -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="text-muted mb-0">Klicken Sie auf ein Fahrzeug, um die Besatzung auszuwählen.</p>
                            <a href="<?php echo htmlspecialchars($base_url); ?>" class="btn btn-outline-secondary btn-sm">Zurück</a>
                        </div>
                        <div class="row g-3">
                            <?php foreach ($vehicles as $v):
                                $crew_count = 0;
                                foreach ($member_vehicle as $mid => $vid) {
                                    if ((int)$vid === (int)$v['id']) $crew_count++;
                                }
                            ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <a href="<?php echo htmlspecialchars($base_url . '&mode=besatzung&vehicle_id=' . (int)$v['id']); ?>" class="card vehicle-card h-100 text-decoration-none text-dark border">
                                    <div class="card-body text-center p-4">
                                        <i class="fas fa-truck fa-2x text-success mb-2"></i>
                                        <span class="d-block fw-bold"><?php echo htmlspecialchars($v['name']); ?></span>
                                        <?php if ($crew_count > 0): ?>
                                        <span class="badge bg-primary mt-1"><?php echo $crew_count; ?> Besatzung</span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (empty($vehicles)): ?>
                        <p class="text-muted">Keine Fahrzeuge in der Datenbank.</p>
                        <?php endif; ?>
                        <div class="mt-4">
                            <a href="anwesenheitsliste-geraete.php?datum=<?php echo urlencode($datum); ?>&auswahl=<?php echo urlencode($auswahl); ?>&umfrage=1<?php echo $einheit_param; ?>" class="btn btn-primary">Weiter zu Geräte</a>
                        </div>

                        <?php elseif ($mode === 'besatzung' && $selected_vehicle): ?>
                        <!-- Besatzung für ausgewähltes Fahrzeug: Personenkacheln -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="mb-0">
                                <a href="<?php echo htmlspecialchars($base_url . '&mode=fahrzeuge'); ?>" class="btn btn-outline-secondary btn-sm me-2"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                                <strong>Besatzung: <?php echo htmlspecialchars($selected_vehicle['name']); ?></strong>
                            </p>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <div class="input-group" style="min-width: 200px; max-width: 300px;">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="besatzungSearch" placeholder="Person suchen..." autocomplete="off">
                            </div>
                            <?php
                            $besatzung_sort_base = $base_url . '&mode=besatzung&vehicle_id=' . (int)$selected_vehicle_id;
                            ?>
                            <div class="btn-group" role="group">
                                <a href="<?php echo htmlspecialchars($besatzung_sort_base . '&sort=ki'); ?>" class="btn btn-sm <?php echo $besatzung_sort === 'ki' ? 'btn-primary' : 'btn-outline-secondary'; ?>">KI</a>
                                <a href="<?php echo htmlspecialchars($besatzung_sort_base . '&sort=name'); ?>" class="btn btn-sm <?php echo $besatzung_sort === 'name' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Nach Name</a>
                            </div>
                        </div>
                        <form method="post">
                            <input type="hidden" name="save_besatzung" value="1">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($datum); ?>">
                            <input type="hidden" name="besatzung_vehicle_id" value="<?php echo (int)$selected_vehicle_id; ?>">
                            <p class="text-muted small mb-3">Klicken Sie auf eine Person, um sie zur Besatzung hinzuzufügen (nochmal klicken zum Entfernen).</p>
                            <div class="row g-3" id="besatzungCardsContainer">
                                <?php foreach ($members as $m):
                                    $crew_selected = in_array((int)$m['id'], $crew_for_vehicle);
                                    $full_name = $m['last_name'] . ', ' . $m['first_name'];
                                    $search_text = strtolower($full_name . ' ' . ($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                    $role_cur = '';
                                    if ($crew_selected) {
                                        if (isset($vehicle_maschinist[$selected_vehicle_id]) && (int)$vehicle_maschinist[$selected_vehicle_id] === (int)$m['id']) {
                                            $role_cur = 'maschinist';
                                        } elseif (isset($vehicle_einheitsfuehrer[$selected_vehicle_id]) && (int)$vehicle_einheitsfuehrer[$selected_vehicle_id] === (int)$m['id']) {
                                            $role_cur = 'einheitsfuehrer';
                                        }
                                    }
                                    $pa_checked = isset($member_pa[$m['id']]);
                                ?>
                                <div class="col-6 col-md-4 col-lg-3 besatzung-card-wrapper" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <div class="card person-card h-100 <?php echo $crew_selected ? 'selected border-primary' : ''; ?>" data-member-id="<?php echo (int)$m['id']; ?>" style="<?php echo $crew_selected ? 'background-color: #b6d4fe; box-shadow: 0 0 0 2px var(--bs-primary);' : ''; ?>">
                                        <div class="card-body p-3">
                                            <input type="hidden" name="member_id[]" value="<?php echo (int)$m['id']; ?>" class="member-id-input" <?php echo $crew_selected ? '' : 'disabled'; ?>>
                                            <div class="name-cell text-center mb-2">
                                                <span class="d-block small <?php echo $crew_selected ? 'fw-bold' : ''; ?>"><?php echo htmlspecialchars($full_name); ?></span>
                                            </div>
                                            <div class="card-details no-click" style="display: <?php echo $crew_selected ? 'block' : 'none'; ?>;">
                                                <div class="mb-1">
                                                    <label class="form-label small mb-0">Rolle</label>
                                                    <select class="form-select form-select-sm" name="role[<?php echo (int)$m['id']; ?>]">
                                                        <option value="">— keine —</option>
                                                        <option value="maschinist" <?php echo $role_cur === 'maschinist' ? 'selected' : ''; ?>>Maschinist</option>
                                                        <option value="einheitsfuehrer" <?php echo $role_cur === 'einheitsfuehrer' ? 'selected' : ''; ?>>Einheitsführer</option>
                                                    </select>
                                                </div>
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input member-pa-check" name="member_pa[<?php echo (int)$m['id']; ?>]" value="1" <?php echo $pa_checked ? 'checked' : ''; ?> <?php echo $crew_selected ? '' : 'disabled'; ?> id="pa_<?php echo (int)$m['id']; ?>">
                                                    <label class="form-check-label small" for="pa_<?php echo (int)$m['id']; ?>">PA</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">Besatzung übernehmen</button>
                                <a href="<?php echo htmlspecialchars($base_url . '&mode=fahrzeuge'); ?>" class="btn btn-outline-secondary">Abbrechen</a>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="anwesenheitsliste-umfrage.php?datum=<?php echo urlencode($datum); ?>&auswahl=<?php echo urlencode($auswahl); ?>&step=1<?php echo $einheit_param; ?>" class="btn btn-link mt-2">Zurück zu Einsatzdaten</a>
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
    (function() {
        var searchInput = document.getElementById('besatzungSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var q = this.value.trim().toLowerCase();
                document.querySelectorAll('.besatzung-card-wrapper').forEach(function(wrap) {
                    wrap.style.display = (q === '' || (wrap.getAttribute('data-search') || '').indexOf(q) >= 0) ? '' : 'none';
                });
            });
        }
        document.querySelectorAll('.person-card').forEach(function(card) {
            var nameCell = card.querySelector('.name-cell');
            var hiddenInput = card.querySelector('.member-id-input');
            var detailsEl = card.querySelector('.card-details');
            if (!hiddenInput) return;
            function toggle(selected) {
                hiddenInput.disabled = !selected;
                card.classList.toggle('selected', selected);
                card.style.backgroundColor = selected ? '#b6d4fe' : '';
                card.style.boxShadow = selected ? '0 0 0 2px var(--bs-primary)' : '';
                if (nameCell) {
                    var span = nameCell.querySelector('.d-block');
                    if (span) span.classList.toggle('fw-bold', selected);
                }
                var paCheck = card.querySelector('.member-pa-check');
                if (paCheck) paCheck.disabled = !selected;
                if (paCheck && !selected) paCheck.checked = false;
                if (detailsEl) detailsEl.style.display = selected ? 'block' : 'none';
            }
            (nameCell || card).addEventListener('click', function(e) {
                if (detailsEl && detailsEl.contains(e.target)) return;
                if (e.target.closest('.no-click')) return;
                e.preventDefault();
                toggle(hiddenInput.disabled);
            });
            if (detailsEl) detailsEl.addEventListener('click', function(e) { e.stopPropagation(); });
        });
    })();
    </script>
</body>
</html>
