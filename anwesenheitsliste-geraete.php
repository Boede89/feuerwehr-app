<?php
/**
 * Anwesenheitsliste – Geräte: eingesetzte Geräte pro Fahrzeug auswählen.
 * Zeigt nur Fahrzeuge, die unter Fahrzeuge oder Personal ausgewählt wurden.
 * Auswahl per farblicher Markierung (Klick). Sonstiges mit Freitext pro Fahrzeug.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';

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

$vehicle_equipment_table_exists = false;
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS vehicle_equipment_category (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            KEY idx_vehicle (vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
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
    try { $db->exec("ALTER TABLE vehicle_equipment ADD COLUMN category_id INT NULL"); } catch (Exception $e2) {}
    $vehicle_equipment_table_exists = true;
} catch (Exception $e) {
    error_log('vehicle_equipment: ' . $e->getMessage());
}

$selected_vehicle_ids = array_unique(array_merge(
    $draft['vehicles'] ?? [],
    array_values(array_filter($draft['member_vehicle'] ?? []))
));
$selected_vehicle_ids = array_filter(array_map('intval', $selected_vehicle_ids), function($v) { return $v > 0; });

$vehicles_with_equipment = [];
if ($vehicle_equipment_table_exists && !empty($selected_vehicle_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_vehicle_ids), '?'));
    try {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute(array_values($selected_vehicle_ids));
        $vehicles_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vehicles_raw as $v) {
            $vid = (int)$v['id'];
            try {
                $stmt2 = $db->prepare("
                    SELECT e.id, e.name, e.category_id, c.name AS category_name
                    FROM vehicle_equipment e
                    LEFT JOIN vehicle_equipment_category c ON c.id = e.category_id
                    WHERE e.vehicle_id = ?
                    ORDER BY COALESCE(c.sort_order, 999), c.name, e.sort_order, e.name
                ");
                $stmt2->execute([$vid]);
                $equipment_raw = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                $stmt2 = $db->prepare("SELECT id, name, NULL AS category_id, NULL AS category_name FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
                $stmt2->execute([$vid]);
                $equipment_raw = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }
            $by_cat = [];
            foreach ($equipment_raw as $eq) {
                $cat = trim($eq['category_name'] ?? '') !== '' ? $eq['category_name'] : null;
                if ($cat === null) $cat = '';
                if (!isset($by_cat[$cat])) $by_cat[$cat] = [];
                $by_cat[$cat][] = $eq;
            }
            $vehicles_with_equipment[$vid] = [
                'name' => $v['name'],
                'equipment_by_category' => $by_cat
            ];
        }
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['vehicle_equipment'] = [];
    $draft['vehicle_equipment_sonstiges'] = [];
    foreach ($selected_vehicle_ids as $vid) {
        $selected = isset($_POST['equipment'][$vid]) && is_array($_POST['equipment'][$vid])
            ? array_map('intval', array_filter($_POST['equipment'][$vid], function($x) { return $x !== '' && ctype_digit((string)$x); }))
            : [];
        if (!empty($selected)) {
            $draft['vehicle_equipment'][$vid] = array_values(array_filter($selected, function($x) { return $x > 0; }));
        }
        $sonstiges_raw = $_POST['equipment_sonstiges'][$vid] ?? '';
        $sonstiges_list = [];
        if (is_array($sonstiges_raw)) {
            foreach ($sonstiges_raw as $s) {
                $s = trim((string)$s);
                if ($s !== '') $sonstiges_list[] = $s;
            }
        } else {
            $lines = preg_split('/[\r\n]+/', trim((string)$sonstiges_raw), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $s) {
                $s = trim($s);
                if ($s !== '') $sonstiges_list[] = $s;
            }
        }
        if (!empty($sonstiges_list)) {
            $draft['vehicle_equipment_sonstiges'][$vid] = array_values(array_unique($sonstiges_list));
        }
    }
    $maengel = [];
    if (!empty($_POST['maengel']) && is_array($_POST['maengel'])) {
        $standort_opts = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
        $mangel_an_opts = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];
        foreach ($_POST['maengel'] as $m) {
            $standort = in_array(trim($m['standort'] ?? ''), $standort_opts) ? trim($m['standort']) : $standort_opts[0];
            $mangel_an = in_array(trim($m['mangel_an'] ?? ''), $mangel_an_opts) ? trim($m['mangel_an']) : $mangel_an_opts[0];
            $bezeichnung = trim($m['bezeichnung'] ?? '');
            $mangel_beschreibung = trim($m['mangel_beschreibung'] ?? '');
            $ursache = trim($m['ursache'] ?? '');
            $verbleib = trim($m['verbleib'] ?? '');
            $aufgenommen_durch = trim($m['aufgenommen_durch'] ?? '');
            if ($bezeichnung !== '' || $mangel_beschreibung !== '' || $ursache !== '' || $verbleib !== '' || $aufgenommen_durch !== '') {
                $maengel[] = ['standort' => $standort, 'mangel_an' => $mangel_an, 'bezeichnung' => $bezeichnung ?: null, 'mangel_beschreibung' => $mangel_beschreibung ?: null, 'ursache' => $ursache ?: null, 'verbleib' => $verbleib ?: null, 'aufgenommen_durch' => $aufgenommen_durch ?: null];
            }
        }
    }
    $draft['maengel'] = $maengel;
    anwesenheitsliste_draft_persist($db, $draft, (int)$_SESSION['user_id']);
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}

$saved_selection = $draft['vehicle_equipment'] ?? [];
if (!is_array($saved_selection)) $saved_selection = [];
$saved_sonstiges = $draft['vehicle_equipment_sonstiges'] ?? [];
if (!is_array($saved_sonstiges)) $saved_sonstiges = [];

if (!isset($draft['maengel']) || !is_array($draft['maengel'])) $draft['maengel'] = [];

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];
$settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('maengelbericht_standort_default', 'maengelbericht_mangel_an_default')");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $settings[$r['setting_key']] = $r['setting_value']; }
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

$selected_equipment_for_modal = [];
foreach ($vehicles_with_equipment as $vid => $vdata) {
    $vname = $vdata['name'];
    if (isset($saved_selection[$vid])) {
        foreach ($vdata['equipment_by_category'] ?? [] as $items) {
            foreach ($items as $eq) {
                if (in_array((int)$eq['id'], $saved_selection[$vid])) {
                    $selected_equipment_for_modal[] = ['id' => 'eq_' . $eq['id'], 'name' => $eq['name'] . ' (' . $vname . ')', 'bezeichnung' => $eq['name']];
                }
            }
        }
    }
    if (!empty($saved_sonstiges[$vid])) {
        foreach ($saved_sonstiges[$vid] as $s) {
            $s = trim((string)$s);
            if ($s !== '') $selected_equipment_for_modal[] = ['id' => 'sonst_' . $vid . '_' . md5($s), 'name' => 'Sonstiges: ' . $s . ' (' . $vname . ')', 'bezeichnung' => $s];
        }
    }
}

$berichtersteller = $draft['berichtersteller'] ?? null;
$berichtersteller_vehicle = '';
if ($berichtersteller !== '' && $berichtersteller !== null && preg_match('/^\d+$/', (string)$berichtersteller)) {
    $ber_vid = $draft['member_vehicle'][(int)$berichtersteller] ?? null;
    if ($ber_vid && isset($vehicles_with_equipment[$ber_vid])) {
        $berichtersteller_vehicle = $vehicles_with_equipment[$ber_vid]['name'] ?? '';
    } elseif ($ber_vid) {
        try {
            $stmt = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
            $stmt->execute([(int)$ber_vid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $berichtersteller_vehicle = $row['name'];
        } catch (Exception $e) {}
    }
}
if ($berichtersteller_vehicle === '' && !empty($vehicles_with_equipment)) {
    $first = reset($vehicles_with_equipment);
    $berichtersteller_vehicle = $first['name'] ?? '';
}
$berichtersteller_display = '';
if ($berichtersteller !== '' && $berichtersteller !== null) {
    if (preg_match('/^\d+$/', (string)$berichtersteller)) {
        foreach ($members_list as $m) {
            if ((int)$m['id'] === (int)$berichtersteller) {
                $berichtersteller_display = trim($m['last_name'] . ', ' . $m['first_name']);
                break;
            }
        }
    }
    if ($berichtersteller_display === '') $berichtersteller_display = (string)$berichtersteller;
}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Geräte - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .geraete-item { cursor: pointer; padding: 0.5rem 0.75rem; border-radius: 6px; border: 2px solid #e9ecef; transition: all 0.2s; display: inline-block; margin: 0.2rem; }
        .geraete-item:hover { background: #f8f9fa; border-color: #dee2e6; }
        .geraete-item-selected { background: #0d6efd !important; color: #fff !important; border-color: #0d6efd !important; }
        .geraete-cat-header { cursor: pointer; padding: 0.5rem 0.75rem; border-radius: 6px; border: 2px solid #dee2e6; background: #f8f9fa; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between; }
        .geraete-cat-header:hover { background: #e9ecef; }
        .geraete-cat-header .fa-chevron-down { transition: transform 0.2s; }
        .geraete-cat-header.expanded .fa-chevron-down { transform: rotate(180deg); }
        .geraete-cat-content { display: none; padding-left: 0.5rem; margin-bottom: 0.75rem; }
        .geraete-cat-content.expanded { display: block; }
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
                    <h3 class="mb-0"><i class="fas fa-tools"></i> Geräte – eingesetzte Gerätschaften pro Fahrzeug</h3>
                    <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?> – Klicken Sie auf die Geräte zur Auswahl (farbliche Markierung).</p>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($selected_vehicle_ids)): ?>
                        <p class="text-muted">Bitte wählen Sie zuerst unter <strong>Fahrzeuge</strong> oder <strong>Personal</strong> mindestens ein Fahrzeug aus.</p>
                        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
                    <?php else: ?>
                    <form method="post" id="geraeteForm">
                        <p class="text-muted small">Klicken Sie auf eine Kategorie, um die Geräte anzuzeigen. Dann klicken Sie auf die Geräte zur Auswahl. Bei „Sonstiges“ öffnet sich ein Textfeld.</p>
                        <?php foreach ($vehicles_with_equipment as $vid => $data): ?>
                        <div class="card mb-3">
                            <div class="card-header py-2"><strong><?php echo htmlspecialchars($data['name']); ?></strong></div>
                            <div class="card-body py-3">
                                <?php if (empty($data['equipment_by_category'])): ?>
                                    <p class="text-muted small mb-2">Keine Geräte hinterlegt. <a href="admin/vehicles-geraete.php?vehicle_id=<?php echo (int)$vid; ?>">Geräte verwalten</a></p>
                                <?php else: ?>
                                    <?php foreach ($data['equipment_by_category'] as $cat_name => $items): ?>
                                    <?php $cat_label = $cat_name !== '' ? $cat_name : 'Ohne Kategorie'; $cat_id_attr = 'cat_' . (int)$vid . '_' . md5($cat_label); ?>
                                    <div class="geraete-cat-block mb-2">
                                        <div class="geraete-cat-header" data-target="<?php echo htmlspecialchars($cat_id_attr); ?>" role="button" tabindex="0">
                                            <span><?php echo htmlspecialchars($cat_label); ?></span>
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                        <div class="geraete-cat-content" id="<?php echo htmlspecialchars($cat_id_attr); ?>">
                                            <div class="d-flex flex-wrap">
                                                <?php foreach ($items as $eq):
                                                    $checked = isset($saved_selection[$vid]) && in_array((int)$eq['id'], $saved_selection[$vid]);
                                                ?>
                                                <div class="geraete-item geraete-equipment <?php echo $checked ? 'geraete-item-selected' : ''; ?>" data-vid="<?php echo (int)$vid; ?>" data-eq-id="<?php echo (int)$eq['id']; ?>" role="button" tabindex="0">
                                                    <?php echo htmlspecialchars($eq['name']); ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="mt-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <div class="geraete-item geraete-sonstiges-trigger <?php echo !empty($saved_sonstiges[$vid]) ? 'geraete-item-selected' : ''; ?>" data-vid="<?php echo (int)$vid; ?>" role="button" tabindex="0">
                                            <i class="fas fa-plus-circle"></i> Sonstiges
                                        </div>
                                    <div class="mt-2 geraete-sonstiges-wrap" id="sonstiges_<?php echo (int)$vid; ?>" style="<?php echo !empty($saved_sonstiges[$vid]) ? '' : 'display:none'; ?>">
                                        <?php
                                        $sonst_val = $saved_sonstiges[$vid] ?? '';
                                        $sonst_lines = is_array($sonst_val) ? $sonst_val : ($sonst_val !== '' ? [$sonst_val] : []);
                                        $sonst_display = implode("\n", $sonst_lines);
                                        ?>
                                        <textarea class="form-control form-control-sm" name="equipment_sonstiges[<?php echo (int)$vid; ?>]" placeholder="Weitere Geräte manuell eingeben (ein Gerät pro Zeile)" rows="3" style="max-width:400px"><?php echo htmlspecialchars($sonst_display); ?></textarea>
                                        <small class="text-muted">Ein Gerät pro Zeile</small>
                                    </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-warning btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#mangelMeldenModal"><i class="fas fa-exclamation-triangle"></i> Mangel melden</button>
                                </div>
                                <div class="geraete-hidden-inputs" data-vid="<?php echo (int)$vid; ?>">
                                    <?php if (isset($saved_selection[$vid])): foreach ($saved_selection[$vid] as $eqid): ?>
                                    <input type="hidden" name="equipment[<?php echo (int)$vid; ?>][]" value="<?php echo (int)$eqid; ?>">
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div id="maengelHiddenContainer">
                            <?php foreach ($draft['maengel'] as $idx => $m): ?>
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][standort]" value="<?php echo htmlspecialchars($m['standort'] ?? $standort_default); ?>">
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][mangel_an]" value="<?php echo htmlspecialchars($m['mangel_an'] ?? $mangel_an_default); ?>">
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][bezeichnung]" value="<?php echo htmlspecialchars($m['bezeichnung'] ?? ''); ?>">
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][mangel_beschreibung]" value="<?php echo htmlspecialchars($m['mangel_beschreibung'] ?? ''); ?>">
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][ursache]" value="<?php echo htmlspecialchars($m['ursache'] ?? ''); ?>">
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][verbleib]" value="<?php echo htmlspecialchars($m['verbleib'] ?? ''); ?>">
                            <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][aufgenommen_durch]" value="<?php echo htmlspecialchars($m['aufgenommen_durch'] ?? ''); ?>">
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Übernehmen und zurück</button>
                            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Mangel melden -->
<div class="modal fade" id="mangelMeldenModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning"></i> Mangel melden</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="mangelModalBereitsErfasst" class="mb-3" style="display: none;">
                    <label class="form-label">Bereits erfasste Mängel</label>
                    <ul id="mangelModalBereitsListe" class="list-group list-group-flush small"></ul>
                </div>
                <div class="mb-3">
                    <label class="form-label">Material mit Mangel</label>
                    <select class="form-select" id="mangelModalMaterial">
                        <option value="">-- Bitte wählen --</option>
                        <option value="__anderes__">Anderes Material</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                    <input type="text" class="form-control" id="mangelModalBezeichnung" placeholder="Wird bei Auswahl vorbelegt, bearbeitbar">
                </div>
                <div class="mb-3">
                    <label class="form-label">Mangel Beschreibung <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="mangelModalMangelBeschreibung" rows="2" required></textarea>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Ursache</label>
                        <input type="text" class="form-control" id="mangelModalUrsache">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Verbleib</label>
                        <input type="text" class="form-control" id="mangelModalVerbleib" placeholder="Wird bei Auswahl vorbelegt (Fahrzeug), bearbeitbar">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Aufgenommen durch <span class="text-danger">*</span></label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="mangelModalAufgenommenDisplay" placeholder="Buchstaben eingeben zum Filtern" autocomplete="off">
                        <input type="hidden" id="mangelModalAufgenommenHidden">
                        <div class="list-group position-absolute w-100 mt-1 shadow" id="mangelModalAufgenommenSuggestions" style="z-index: 1055; max-height: 180px; overflow-y: auto; display: none;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                <button type="button" class="btn btn-warning text-dark" id="mangelModalHinzufuegen"><i class="fas fa-plus"></i> Mangel hinzufügen</button>
                <button type="button" class="btn btn-outline-warning text-dark" id="mangelModalWeiterer"><i class="fas fa-plus-circle"></i> Weiterer Mangel</button>
            </div>
        </div>
    </div>
</div>

<footer class="bg-light mt-5 py-4">
    <div class="container text-center">
        <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    document.querySelectorAll('.geraete-cat-header').forEach(function(header) {
        header.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var content = document.getElementById(targetId);
            if (content) {
                content.classList.toggle('expanded');
                this.classList.toggle('expanded');
            }
        });
        header.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
        });
    });
    function syncHiddenInputs(vid) {
        var container = document.querySelector('.geraete-hidden-inputs[data-vid="' + vid + '"]');
        if (!container) return;
        container.innerHTML = '';
        document.querySelectorAll('.geraete-equipment.geraete-item-selected[data-vid="' + vid + '"]').forEach(function(el) {
            var eqId = el.getAttribute('data-eq-id');
            if (eqId) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'equipment[' + vid + '][]';
                inp.value = eqId;
                container.appendChild(inp);
            }
        });
    }
    document.querySelectorAll('.geraete-equipment').forEach(function(el) {
        el.addEventListener('click', function() {
            this.classList.toggle('geraete-item-selected');
            syncHiddenInputs(this.getAttribute('data-vid'));
        });
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
        });
    });
    document.querySelectorAll('.geraete-sonstiges-trigger').forEach(function(el) {
        el.addEventListener('click', function() {
            var vid = this.getAttribute('data-vid');
            var wrap = document.getElementById('sonstiges_' + vid);
            if (wrap) {
                var show = wrap.style.display === 'none';
                wrap.style.display = show ? 'block' : 'none';
                this.classList.toggle('geraete-item-selected', show);
                if (show) { var ta = wrap.querySelector('textarea'); if (ta) ta.focus(); }
            }
        });
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
        });
    });

    // Modal Mangel melden
    var membersData = <?php echo json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'label' => trim($m['last_name'] . ', ' . $m['first_name'])]; }, $members_list)); ?>;
    var standortDefault = <?php echo json_encode($standort_default); ?>;
    var mangelAnDefault = <?php echo json_encode($mangel_an_default); ?>;
    var maengelIndex = <?php echo count($draft['maengel']); ?>;
    var berichterstellerDisplay = <?php echo json_encode($berichtersteller_display); ?>;
    var berichterstellerId = <?php echo json_encode($berichtersteller); ?>;
    var berichterstellerVehicle = <?php echo json_encode($berichtersteller_vehicle); ?>;

    var matSelect = document.getElementById('mangelModalMaterial');
    var bezeichnungInput = document.getElementById('mangelModalBezeichnung');
    var mangelBeschr = document.getElementById('mangelModalMangelBeschreibung');
    var ursacheInput = document.getElementById('mangelModalUrsache');
    var verbleibInput = document.getElementById('mangelModalVerbleib');
    var aufgenommenDisplay = document.getElementById('mangelModalAufgenommenDisplay');
    var aufgenommenHidden = document.getElementById('mangelModalAufgenommenHidden');
    var aufgenommenSuggestions = document.getElementById('mangelModalAufgenommenSuggestions');
    var modal = document.getElementById('mangelMeldenModal');
    var hinzufuegenBtn = document.getElementById('mangelModalHinzufuegen');
    var weitererBtn = document.getElementById('mangelModalWeiterer');
    var bereitsWrap = document.getElementById('mangelModalBereitsErfasst');
    var bereitsListe = document.getElementById('mangelModalBereitsListe');

    function filterMembers(q) {
        q = (q || '').toLowerCase().trim();
        if (q === '') return membersData;
        return membersData.filter(function(m) { return (m.label || '').toLowerCase().indexOf(q) >= 0; });
    }
    function renderSuggestions(items) {
        aufgenommenSuggestions.innerHTML = '';
        items.forEach(function(item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action list-group-item-light text-start';
            btn.textContent = item.label;
            btn.dataset.id = item.id;
            btn.dataset.label = item.label;
            btn.addEventListener('click', function() {
                aufgenommenDisplay.value = this.dataset.label;
                aufgenommenHidden.value = this.dataset.id;
                aufgenommenSuggestions.style.display = 'none';
            });
            aufgenommenSuggestions.appendChild(btn);
        });
        aufgenommenSuggestions.style.display = items.length > 0 ? 'block' : 'none';
    }
    aufgenommenDisplay.addEventListener('input', function() {
        aufgenommenHidden.value = '';
        renderSuggestions(filterMembers(aufgenommenDisplay.value.trim()));
    });
    aufgenommenDisplay.addEventListener('focus', function() { renderSuggestions(filterMembers(aufgenommenDisplay.value.trim())); });
    aufgenommenDisplay.addEventListener('blur', function() { setTimeout(function() { aufgenommenSuggestions.style.display = 'none'; }, 200); });

    function buildMaterialOptions() {
        var options = [];
        var seen = {};
        document.querySelectorAll('.geraete-equipment.geraete-item-selected').forEach(function(el) {
            var eqName = (el.textContent || '').trim();
            var card = el.closest('.card');
            var vname = card && card.querySelector('.card-header') ? (card.querySelector('.card-header').textContent || '').trim() : '';
            var key = 'eq_' + (el.getAttribute('data-eq-id') || '') + '_' + (el.getAttribute('data-vid') || '');
            if (eqName && !seen[key]) {
                seen[key] = true;
                options.push({ bezeichnung: eqName, label: eqName + (vname ? ' (' + vname + ')' : ''), fahrzeug: vname });
            }
        });
        document.querySelectorAll('textarea[name^="equipment_sonstiges"]').forEach(function(ta) {
            var lines = (ta.value || '').split(/\r?\n/).map(function(s) { return s.trim(); }).filter(Boolean);
            var card = ta.closest('.card');
            var vname = card && card.querySelector('.card-header') ? (card.querySelector('.card-header').textContent || '').trim() : '';
            lines.forEach(function(line) {
                var key = 'sonst_' + line;
                if (!seen[key]) {
                    seen[key] = true;
                    options.push({ bezeichnung: line, label: 'Sonstiges: ' + line + (vname ? ' (' + vname + ')' : ''), fahrzeug: vname });
                }
            });
        });
        return options;
    }

    function populateMaterialSelect() {
        var opts = buildMaterialOptions();
        while (matSelect.options.length > 2) matSelect.remove(2);
        opts.forEach(function(o) {
            var opt = document.createElement('option');
            opt.value = o.bezeichnung;
            opt.dataset.bezeichnung = o.bezeichnung;
            opt.dataset.fahrzeug = o.fahrzeug || '';
            opt.textContent = o.label;
            matSelect.insertBefore(opt, matSelect.options[matSelect.options.length - 1]);
        });
    }

    matSelect.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (this.value === '__anderes__') {
            bezeichnungInput.value = '';
            verbleibInput.value = berichterstellerVehicle || '';
        } else if (opt && opt.dataset) {
            bezeichnungInput.value = opt.dataset.bezeichnung || this.value;
            verbleibInput.value = opt.dataset.fahrzeug || '';
        }
    });

    function getExistingMaengel() {
        var container = document.getElementById('maengelHiddenContainer');
        if (!container) return [];
        var items = [];
        var bezeichnungen = container.querySelectorAll('input[name$="[bezeichnung]"]');
        var beschreibungen = container.querySelectorAll('input[name$="[mangel_beschreibung]"]');
        for (var i = 0; i < Math.max(bezeichnungen.length, beschreibungen.length); i++) {
            var bez = bezeichnungen[i] ? bezeichnungen[i].value : '';
            var beschr = beschreibungen[i] ? beschreibungen[i].value : '';
            if (bez || beschr) items.push({ bezeichnung: bez, beschreibung: beschr });
        }
        return items;
    }

    function renderBereitsErfasst() {
        var items = getExistingMaengel();
        bereitsListe.innerHTML = '';
        if (items.length === 0) {
            bereitsWrap.style.display = 'none';
            return;
        }
        bereitsWrap.style.display = 'block';
        items.forEach(function(m) {
            var li = document.createElement('li');
            li.className = 'list-group-item py-2';
            li.textContent = (m.bezeichnung ? m.bezeichnung + ': ' : '') + (m.beschreibung || '');
            if (li.textContent.length > 80) li.textContent = li.textContent.substring(0, 77) + '...';
            bereitsListe.appendChild(li);
        });
    }

    function resetMangelModal(keepAufgenommen) {
        populateMaterialSelect();
        matSelect.value = '';
        bezeichnungInput.value = '';
        mangelBeschr.value = '';
        ursacheInput.value = '';
        verbleibInput.value = '';
        if (!keepAufgenommen) {
            aufgenommenDisplay.value = berichterstellerDisplay || '';
            aufgenommenHidden.value = berichterstellerId || '';
        }
        renderBereitsErfasst();
    }
    if (modal) {
        modal.addEventListener('show.bs.modal', function() {
            resetMangelModal(false);
        });
    }

    function doAddMangel(closeAfter) {
        var bezeichnung = bezeichnungInput.value.trim();
        var mangelBeschrVal = mangelBeschr.value.trim();
        var ursache = ursacheInput.value.trim();
        var verbleib = verbleibInput.value.trim();
        var aufgenommen = aufgenommenHidden.value.trim() || aufgenommenDisplay.value.trim();
        if (!mangelBeschrVal || !aufgenommen) {
            alert('Bitte füllen Sie Mangel Beschreibung und Aufgenommen durch aus.');
            return;
        }
        var idx = maengelIndex++;
        var container = document.getElementById('maengelHiddenContainer');
        if (!container) return;
        var frag = document.createDocumentFragment();
        ['standort','mangel_an','bezeichnung','mangel_beschreibung','ursache','verbleib','aufgenommen_durch'].forEach(function(k) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'maengel[' + idx + '][' + k + ']';
            inp.value = k === 'standort' ? standortDefault : k === 'mangel_an' ? mangelAnDefault : k === 'bezeichnung' ? bezeichnung : k === 'mangel_beschreibung' ? mangelBeschrVal : k === 'ursache' ? ursache : k === 'verbleib' ? verbleib : aufgenommen;
            frag.appendChild(inp);
        });
        container.appendChild(frag);
        if (closeAfter) {
            var bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) bsModal.hide();
        } else {
            resetMangelModal(true);
        }
    }

    hinzufuegenBtn.addEventListener('click', function() { doAddMangel(true); });
    if (weitererBtn) weitererBtn.addEventListener('click', function() { doAddMangel(false); });
})();
window.addEventListener('beforeunload', function() {
    var form = document.getElementById('geraeteForm');
    if (form) {
        var fd = new FormData(form);
        fd.append('form_type', 'geraete');
        navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
    } else {
        navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
    }
});
</script>
</body>
</html>
