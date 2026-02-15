<?php
/**
 * Geräte und Kategorien pro Fahrzeug verwalten (Einstellungen)
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    header('Location: ../login.php');
    exit;
}

$vehicle_id = (int)($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);
if ($vehicle_id <= 0) {
    header('Location: vehicles.php?error=invalid');
    exit;
}

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
} catch (Exception $e) {
    error_log('vehicle_equipment_category: ' . $e->getMessage());
}
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS vehicle_equipment (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            category_id INT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            KEY idx_vehicle (vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("ALTER TABLE vehicle_equipment ADD COLUMN category_id INT NULL");
    } catch (Exception $e) { /* Spalte existiert evtl. bereits */ }
} catch (Exception $e) {
    error_log('vehicle_equipment: ' . $e->getMessage());
}
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS vehicle_equipment_sonstiges_ignored (
            vehicle_id INT NOT NULL,
            name VARCHAR(191) NOT NULL,
            PRIMARY KEY (vehicle_id, name),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('vehicle_equipment_sonstiges_ignored: ' . $e->getMessage());
}

$vehicle = null;
try {
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (!$vehicle) {
    header('Location: vehicles.php?error=not_found');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_category') {
            $name = trim($_POST['category_name'] ?? '');
            if ($name !== '') {
                try {
                    $stmt = $db->prepare("SELECT id FROM vehicle_equipment_category WHERE vehicle_id = ? AND name = ?");
                    $stmt->execute([$vehicle_id, $name]);
                    if ($stmt->fetch()) {
                        $message = 'Diese Kategorie existiert bereits.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO vehicle_equipment_category (vehicle_id, name, sort_order) VALUES (?, ?, 0)");
                        $stmt->execute([$vehicle_id, $name]);
                        $message = 'Kategorie hinzugefügt.';
                    }
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_category') {
            $cat_id = (int)($_POST['category_id'] ?? 0);
            if ($cat_id > 0) {
                try {
                    $stmt = $db->prepare("SELECT vehicle_id FROM vehicle_equipment_category WHERE id = ?");
                    $stmt->execute([$cat_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && (int)$row['vehicle_id'] === $vehicle_id) {
                        $db->prepare("UPDATE vehicle_equipment SET category_id = NULL WHERE category_id = ?")->execute([$cat_id]);
                        $db->prepare("DELETE FROM vehicle_equipment_category WHERE id = ?")->execute([$cat_id]);
                        $message = 'Kategorie entfernt.';
                    }
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'add') {
            $name = trim($_POST['equipment_name'] ?? '');
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            if ($name !== '') {
                try {
                    if ($category_id > 0) {
                        $stmt = $db->prepare("SELECT vehicle_id FROM vehicle_equipment_category WHERE id = ? AND vehicle_id = ?");
                        $stmt->execute([$category_id, $vehicle_id]);
                        if (!$stmt->fetch()) $category_id = null;
                    } else {
                        $category_id = null;
                    }
                    $stmt = $db->prepare("SELECT id FROM vehicle_equipment WHERE vehicle_id = ? AND name = ? AND (category_id <=> ?)");
                    $stmt->execute([$vehicle_id, $name, $category_id]);
                    if ($stmt->fetch()) {
                        $message = 'Dieses Gerät existiert bereits in dieser Kategorie.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO vehicle_equipment (vehicle_id, name, category_id, sort_order) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$vehicle_id, $name, $category_id]);
                        $message = 'Gerät hinzugefügt.';
                    }
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $eq_id = (int)($_POST['equipment_id'] ?? 0);
            if ($eq_id > 0) {
                try {
                    $stmt = $db->prepare("DELETE FROM vehicle_equipment WHERE id = ? AND vehicle_id = ?");
                    $stmt->execute([$eq_id, $vehicle_id]);
                    $message = 'Gerät entfernt.';
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_all_equipment') {
            try {
                $stmt = $db->prepare("DELETE FROM vehicle_equipment WHERE vehicle_id = ?");
                $stmt->execute([$vehicle_id]);
                $message = 'Alle Geräte dieses Fahrzeugs wurden entfernt.';
            } catch (Exception $e) {
                $error = 'Fehler: ' . $e->getMessage();
            }
        } elseif ($action === 'add_from_sonstiges') {
            $name = trim($_POST['sonstiges_name'] ?? '');
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            if ($name !== '') {
                try {
                    if ($category_id > 0) {
                        $stmt = $db->prepare("SELECT vehicle_id FROM vehicle_equipment_category WHERE id = ? AND vehicle_id = ?");
                        $stmt->execute([$category_id, $vehicle_id]);
                        if (!$stmt->fetch()) $category_id = null;
                    } else {
                        $category_id = null;
                    }
                    $stmt = $db->prepare("SELECT id FROM vehicle_equipment WHERE vehicle_id = ? AND name = ? AND (category_id <=> ?)");
                    $stmt->execute([$vehicle_id, $name, $category_id]);
                    if ($stmt->fetch()) {
                        $message = 'Dieses Gerät existiert bereits in der Liste.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO vehicle_equipment (vehicle_id, name, category_id, sort_order) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$vehicle_id, $name, $category_id]);
                        $message = 'Gerät zur Liste hinzugefügt.';
                    }
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'ignore_sonstiges') {
            $name = trim($_POST['sonstiges_name'] ?? '');
            if ($name !== '') {
                try {
                    $stmt = $db->prepare("INSERT IGNORE INTO vehicle_equipment_sonstiges_ignored (vehicle_id, name) VALUES (?, ?)");
                    $stmt->execute([$vehicle_id, $name]);
                    $message = 'Eintrag wird nicht mehr angezeigt.';
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'update_category') {
            $eq_id = (int)($_POST['equipment_id'] ?? 0);
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            if ($eq_id > 0) {
                try {
                    if ($category_id > 0) {
                        $stmt = $db->prepare("SELECT vehicle_id FROM vehicle_equipment_category WHERE id = ? AND vehicle_id = ?");
                        $stmt->execute([$category_id, $vehicle_id]);
                        if (!$stmt->fetch()) $category_id = null;
                    } else {
                        $category_id = null;
                    }
                    $stmt = $db->prepare("UPDATE vehicle_equipment SET category_id = ? WHERE id = ? AND vehicle_id = ?");
                    $stmt->execute([$category_id, $eq_id, $vehicle_id]);
                    $message = 'Gerät aktualisiert.';
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'copy_from') {
            $source_vehicle_id = (int)($_POST['source_vehicle_id'] ?? 0);
            $copy_equipment_ids = isset($_POST['copy_equipment']) && is_array($_POST['copy_equipment'])
                ? array_filter(array_map('intval', $_POST['copy_equipment']), function($x) { return $x > 0; })
                : [];
            if ($source_vehicle_id > 0 && $source_vehicle_id !== $vehicle_id) {
                try {
                    $stmt = $db->prepare("SELECT id FROM vehicles WHERE id = ?");
                    $stmt->execute([$source_vehicle_id]);
                    if (!$stmt->fetch()) {
                        $error = 'Quell-Fahrzeug nicht gefunden.';
                    } else {
                        $cat_map = [];
                        $stmt = $db->prepare("SELECT id, name, sort_order FROM vehicle_equipment_category WHERE vehicle_id = ? ORDER BY sort_order, name");
                        $stmt->execute([$source_vehicle_id]);
                        $check_cat = $db->prepare("SELECT id FROM vehicle_equipment_category WHERE vehicle_id = ? AND name = ?");
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                            $check_cat->execute([$vehicle_id, $c['name']]);
                            $existing = $check_cat->fetch(PDO::FETCH_ASSOC);
                            if ($existing) {
                                $cat_map[(int)$c['id']] = (int)$existing['id'];
                            } else {
                                $ins = $db->prepare("INSERT INTO vehicle_equipment_category (vehicle_id, name, sort_order) VALUES (?, ?, ?)");
                                $ins->execute([$vehicle_id, $c['name'], (int)$c['sort_order']]);
                                $cat_map[(int)$c['id']] = (int)$db->lastInsertId();
                            }
                        }
                        $copied_count = 0;
                        $skipped_count = 0;
                        if (!empty($copy_equipment_ids)) {
                            $ph = implode(',', array_fill(0, count($copy_equipment_ids), '?'));
                            $stmt = $db->prepare("SELECT name, category_id, sort_order FROM vehicle_equipment WHERE vehicle_id = ? AND id IN ($ph) ORDER BY sort_order, name");
                            $stmt->execute(array_merge([$source_vehicle_id], $copy_equipment_ids));
                            $check_eq = $db->prepare("SELECT id FROM vehicle_equipment WHERE vehicle_id = ? AND name = ? AND (category_id <=> ?)");
                            $ins_eq = $db->prepare("INSERT INTO vehicle_equipment (vehicle_id, name, category_id, sort_order) VALUES (?, ?, ?, ?)");
                            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $eq) {
                                $new_cat = isset($cat_map[(int)$eq['category_id']]) ? $cat_map[(int)$eq['category_id']] : null;
                                $check_eq->execute([$vehicle_id, $eq['name'], $new_cat]);
                                if ($check_eq->fetch()) {
                                    $skipped_count++;
                                } else {
                                    $ins_eq->execute([$vehicle_id, $eq['name'], $new_cat, (int)$eq['sort_order']]);
                                    $copied_count++;
                                }
                            }
                        }
                        $msg = 'Kategorien wurden übernommen (bestehende übersprungen).';
                        if ($copied_count > 0 || $skipped_count > 0) {
                            $msg = $copied_count . ' Gerät(e) übernommen.';
                            if ($skipped_count > 0) $msg .= ' ' . $skipped_count . ' bereits vorhanden und übersprungen.';
                        }
                        $message = $msg;
                    }
                } catch (Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            } else {
                $error = 'Bitte wählen Sie ein anderes Fahrzeug aus.';
            }
        }
    }
}

$all_vehicles = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    $all_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$categories = [];
try {
    $stmt = $db->prepare("SELECT id, name, sort_order FROM vehicle_equipment_category WHERE vehicle_id = ? ORDER BY sort_order, name");
    $stmt->execute([$vehicle_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$equipment = [];
try {
    $stmt = $db->prepare("SELECT id, name, category_id, sort_order FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
    $stmt->execute([$vehicle_id]);
    $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$equipment_by_category = [];
$cat_map = [];
foreach ($categories as $c) {
    $equipment_by_category[(int)$c['id']] = ['name' => $c['name'], 'items' => []];
    $cat_map[(int)$c['id']] = $c['name'];
}
$equipment_by_category[0] = ['name' => 'Ohne Kategorie', 'items' => []];

foreach ($equipment as $eq) {
    $cid = !empty($eq['category_id']) ? (int)$eq['category_id'] : 0;
    if (!isset($equipment_by_category[$cid])) {
        $equipment_by_category[$cid] = ['name' => $cat_map[$cid] ?? 'Unbekannt', 'items' => []];
    }
    $equipment_by_category[$cid]['items'][] = $eq;
}
ksort($equipment_by_category);
if (isset($equipment_by_category[0])) {
    $uncat = $equipment_by_category[0];
    unset($equipment_by_category[0]);
    $equipment_by_category = [0 => $uncat] + $equipment_by_category;
}

$source_vehicle_id = (int)($_GET['source_vehicle_id'] ?? 0);
$source_vehicle = null;
$source_equipment_by_category = [];
if ($source_vehicle_id > 0 && $source_vehicle_id !== $vehicle_id) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE id = ?");
        $stmt->execute([$source_vehicle_id]);
        $source_vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($source_vehicle) {
            $src_cats = [];
            $stmt = $db->prepare("SELECT id, name, sort_order FROM vehicle_equipment_category WHERE vehicle_id = ? ORDER BY sort_order, name");
            $stmt->execute([$source_vehicle_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $src_cats[(int)$c['id']] = ['name' => $c['name'], 'items' => []];
            }
            $src_cats[0] = ['name' => 'Ohne Kategorie', 'items' => []];
            $stmt = $db->prepare("SELECT id, name, category_id, sort_order FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
            $stmt->execute([$source_vehicle_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $eq) {
                $cid = !empty($eq['category_id']) ? (int)$eq['category_id'] : 0;
                if (!isset($src_cats[$cid])) $src_cats[$cid] = ['name' => 'Unbekannt', 'items' => []];
                $src_cats[$cid]['items'][] = $eq;
            }
            ksort($src_cats);
            if (isset($src_cats[0])) {
                $u = $src_cats[0];
                unset($src_cats[0]);
                $src_cats = [0 => $u] + $src_cats;
            }
            $source_equipment_by_category = $src_cats;
        }
    } catch (Exception $e) {}
}

$sonstiges_from_anwesenheit = [];
$existing_names = [];
foreach ($equipment as $eq) {
    $existing_names[strtolower(trim($eq['name']))] = true;
}
try {
    $stmt_ignored = $db->prepare("SELECT id FROM vehicle_equipment_sonstiges_ignored WHERE vehicle_id = ? AND name = ?");
    $sources = [];
    $stmt = $db->query("SELECT custom_data FROM anwesenheitslisten WHERE custom_data IS NOT NULL AND custom_data != '' AND custom_data != 'null'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $raw = $row['custom_data'];
        $sources[] = is_array($raw) ? $raw : (is_string($raw) ? $raw : '');
    }
    try {
        $stmt2 = $db->query("SELECT draft_data FROM anwesenheitsliste_drafts WHERE draft_data IS NOT NULL AND draft_data != '' AND draft_data != 'null'");
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $raw = $row['draft_data'];
            $sources[] = is_array($raw) ? $raw : (is_string($raw) ? $raw : '');
        }
    } catch (Exception $e) {}
    foreach ($sources as $json_str) {
        $dec = is_array($json_str) ? $json_str : (is_string($json_str) ? json_decode($json_str, true) : null);
        if (!is_array($dec)) continue;
        $sonst = $dec['vehicle_equipment_sonstiges'] ?? [];
        if (!is_array($sonst)) continue;
        foreach ($sonst as $vid => $txt) {
            if ((int)$vid != (int)$vehicle_id) continue;
            $items = is_array($txt) ? $txt : ($txt !== '' && $txt !== null ? [trim((string)$txt)] : []);
            foreach ($items as $item) {
                $item = trim((string)$item);
                if ($item === '') continue;
                $key = strtolower($item);
                if (isset($existing_names[$key])) continue;
                $stmt_ignored->execute([$vehicle_id, $item]);
                if ($stmt_ignored->fetch()) continue;
                $sonstiges_from_anwesenheit[$key] = $item;
            }
        }
    }
    $sonstiges_from_anwesenheit = array_values(array_unique($sonstiges_from_anwesenheit));
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geräte – <?php echo htmlspecialchars($vehicle['name']); ?> - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="vehicles.php"><i class="fas fa-arrow-left"></i> Zurück zu Fahrzeugen</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h1 class="h3 mb-4"><i class="fas fa-tools"></i> Geräte – <?php echo htmlspecialchars($vehicle['name']); ?></h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php
    $other_vehicles = array_filter($all_vehicles, function($v) use ($vehicle_id) { return (int)$v['id'] !== $vehicle_id; });
    if (!empty($other_vehicles)):
    ?>
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-copy"></i> Geräte von anderem Fahrzeug übernehmen</div>
        <div class="card-body">
            <?php if ($source_vehicle): ?>
            <p class="text-muted small mb-3">Wählen Sie die Geräte aus, die von <strong><?php echo htmlspecialchars($source_vehicle['name']); ?></strong> übernommen werden sollen. Alle Kategorien werden automatisch übernommen.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                <input type="hidden" name="source_vehicle_id" value="<?php echo (int)$source_vehicle_id; ?>">
                <input type="hidden" name="action" value="copy_from">
                <?php if (empty($source_equipment_by_category) || array_sum(array_map(function($c) { return count($c['items']); }, $source_equipment_by_category)) === 0): ?>
                <p class="text-muted small">Das gewählte Fahrzeug hat keine Geräte hinterlegt. Es werden nur die Kategorien übernommen.</p>
                <?php else: ?>
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="document.querySelectorAll('.copy-eq-cb').forEach(function(cb){cb.checked=true})"><i class="fas fa-check-double"></i> Alle auswählen</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="document.querySelectorAll('.copy-eq-cb').forEach(function(cb){cb.checked=false})"><i class="fas fa-times"></i> Alle abwählen</button>
                </div>
                <?php foreach ($source_equipment_by_category as $cat_data): ?>
                <?php if (!empty($cat_data['items'])): ?>
                <div class="mb-2">
                    <strong class="text-muted small"><?php echo htmlspecialchars($cat_data['name']); ?></strong>
                    <div class="d-flex flex-wrap gap-2 mt-1">
                        <?php foreach ($cat_data['items'] as $eq): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input copy-eq-cb" type="checkbox" name="copy_equipment[]" value="<?php echo (int)$eq['id']; ?>" id="copy_eq_<?php echo (int)$eq['id']; ?>">
                            <label class="form-check-label" for="copy_eq_<?php echo (int)$eq['id']; ?>"><?php echo htmlspecialchars($eq['name']); ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php endif; ?>
                <div class="mt-3">
                    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-copy"></i> Ausgewählte übernehmen</button>
                    <a href="vehicles-geraete.php?vehicle_id=<?php echo (int)$vehicle_id; ?>" class="btn btn-secondary ms-2">Abbrechen</a>
                </div>
            </form>
            <?php else: ?>
            <p class="text-muted small mb-3">Wählen Sie ein Fahrzeug aus, dessen Geräte Sie übernehmen möchten. Anschließend können Sie die gewünschten Geräte auswählen.</p>
            <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                <div>
                    <label class="form-label small">Von Fahrzeug</label>
                    <select class="form-select form-select-sm" name="source_vehicle_id" required style="min-width:200px">
                        <option value="">— auswählen —</option>
                        <?php foreach ($other_vehicles as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline-primary"><i class="fas fa-arrow-right"></i> Geräte auswählen</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Gerät hinzufügen</div>
        <div class="card-body">
            <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                <input type="hidden" name="action" value="add">
                <div>
                    <label class="form-label small">Name</label>
                    <input type="text" class="form-control" name="equipment_name" placeholder="z.B. Schlauch, Strahlrohr" required>
                </div>
                <div>
                    <label class="form-label small">Kategorie (optional)</label>
                    <select class="form-select" name="category_id">
                        <option value="">— keine —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Hinzufügen</button>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-list-alt"></i> Aus Anwesenheitslisten (Sonstiges)</div>
        <div class="card-body">
            <p class="text-muted small mb-3">Diese Geräte wurden bei Anwesenheitslisten unter „Sonstiges“ eingegeben (auch aus Entwürfen). Sie können sie zur Auswahlliste hinzufügen oder verwerfen.</p>
            <?php if (empty($sonstiges_from_anwesenheit)): ?>
            <p class="text-muted mb-0">Noch keine Einträge. Wenn Sie bei einer Anwesenheitsliste unter „Sonstiges“ Geräte eingeben (ein Gerät pro Zeile) und die Liste absenden oder speichern, erscheinen sie hier.</p>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($sonstiges_from_anwesenheit as $txt): ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><?php echo htmlspecialchars($txt); ?></span>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <form method="post" class="d-inline-flex flex-wrap gap-1 align-items-center">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                            <input type="hidden" name="action" value="add_from_sonstiges">
                            <input type="hidden" name="sonstiges_name" value="<?php echo htmlspecialchars($txt); ?>">
                            <select class="form-select form-select-sm" name="category_id" style="width:auto; min-width:120px">
                                <option value="">— keine Kategorie —</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-plus"></i> Zur Liste hinzufügen</button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Eintrag verwerfen? Er wird nicht mehr angezeigt.');">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                            <input type="hidden" name="action" value="ignore_sonstiges">
                            <input type="hidden" name="sonstiges_name" value="<?php echo htmlspecialchars($txt); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Nicht mehr anzeigen"><i class="fas fa-times"></i> Verwerfen</button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Kategorien (optional – zum Sortieren der Geräte)</div>
        <div class="card-body">
            <form method="post" class="d-flex gap-2 mb-3 align-items-center">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                <input type="hidden" name="action" value="add_category">
                <input type="text" class="form-control form-control-sm" name="category_name" placeholder="z.B. Hydraulik" style="max-width:180px">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus"></i> Kategorie hinzufügen</button>
            </form>
            <?php if (!empty($categories)): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($categories as $cat): ?>
                <span class="badge bg-secondary d-inline-flex align-items-center gap-1">
                    <?php echo htmlspecialchars($cat['name']); ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Kategorie wirklich entfernen?');">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                        <button type="submit" class="btn btn-link p-0 text-white" style="font-size:0.9em"><i class="fas fa-times"></i></button>
                    </form>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Geräte dieses Fahrzeugs</span>
            <?php if (!empty($equipment)): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Wirklich alle Geräte dieses Fahrzeugs löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                <input type="hidden" name="action" value="delete_all_equipment">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i> Alle Geräte löschen</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($equipment)): ?>
                <p class="text-muted mb-0">Noch keine Geräte hinterlegt. Diese werden bei der Anwesenheitsliste pro Fahrzeug zur Auswahl angezeigt.</p>
            <?php else: ?>
                <?php foreach ($equipment_by_category as $cat_id => $cat_data): ?>
                <div class="mb-3">
                    <strong class="text-muted small"><?php echo htmlspecialchars($cat_data['name']); ?></strong>
                    <ul class="list-group list-group-flush mt-1">
                        <?php foreach ($cat_data['items'] as $eq): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <?php echo htmlspecialchars($eq['name']); ?>
                            <div class="d-flex align-items-center gap-2">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                                    <input type="hidden" name="action" value="update_category">
                                    <input type="hidden" name="equipment_id" value="<?php echo (int)$eq['id']; ?>">
                                    <select class="form-select form-select-sm" name="category_id" style="width:auto" onchange="this.form.submit()">
                                        <option value="">— keine —</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)($eq['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Gerät wirklich entfernen?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="equipment_id" value="<?php echo (int)$eq['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
