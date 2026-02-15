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
                    $stmt = $db->prepare("INSERT INTO vehicle_equipment_category (vehicle_id, name, sort_order) VALUES (?, ?, 0)");
                    $stmt->execute([$vehicle_id, $name]);
                    $message = 'Kategorie hinzugefügt.';
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
                    $stmt = $db->prepare("INSERT INTO vehicle_equipment (vehicle_id, name, category_id, sort_order) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$vehicle_id, $name, $category_id]);
                    $message = 'Gerät hinzugefügt.';
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
        }
    }
}

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

    <div class="card mb-4">
        <div class="card-header">Kategorien (optional – zum Sortieren der Geräte)</div>
        <div class="card-body">
            <form method="post" class="d-flex gap-2 mb-3">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="vehicle_id" value="<?php echo (int)$vehicle_id; ?>">
                <input type="hidden" name="action" value="add_category">
                <input type="text" class="form-control" name="category_name" placeholder="z.B. Hydraulik, Stromerzeuger">
                <button type="submit" class="btn btn-outline-primary"><i class="fas fa-plus"></i> Kategorie hinzufügen</button>
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

    <div class="card">
        <div class="card-header">Geräte dieses Fahrzeugs</div>
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
