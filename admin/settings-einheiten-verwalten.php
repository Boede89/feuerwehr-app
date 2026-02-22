<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';

// Tabelle einheiten sicherstellen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS einheiten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    $error = 'Fehler beim Erstellen der Einheiten-Tabelle: ' . $e->getMessage();
}

// Einheiten laden
$einheiten = [];
try {
    $stmt = $db->query("SELECT id, name, sort_order, created_at FROM einheiten ORDER BY sort_order ASC, name ASC");
    $einheiten = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ?: ('Fehler beim Laden der Einheiten: ' . $e->getMessage());
}

// POST: Einheit hinzufügen, bearbeiten oder löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            if (isset($_POST['add_einheit'])) {
                $name = trim(sanitize_input($_POST['name'] ?? ''));
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                if (empty($name)) {
                    $error = 'Bitte geben Sie einen Namen für die Einheit ein.';
                } else {
                    $stmt = $db->prepare("INSERT INTO einheiten (name, sort_order) VALUES (?, ?)");
                    $stmt->execute([$name, $sort_order]);
                    $message = 'Einheit wurde erfolgreich angelegt.';
                    $stmt = $db->query("SELECT id, name, sort_order, created_at FROM einheiten ORDER BY sort_order ASC, name ASC");
                    $einheiten = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } elseif (isset($_POST['edit_einheit'])) {
                $id = (int)$_POST['einheit_id'];
                $name = trim(sanitize_input($_POST['name'] ?? ''));
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                if (empty($name)) {
                    $error = 'Bitte geben Sie einen Namen für die Einheit ein.';
                } elseif ($id > 0) {
                    $stmt = $db->prepare("UPDATE einheiten SET name = ?, sort_order = ? WHERE id = ?");
                    $stmt->execute([$name, $sort_order, $id]);
                    $message = 'Einheit wurde erfolgreich aktualisiert.';
                    $stmt = $db->query("SELECT id, name, sort_order, created_at FROM einheiten ORDER BY sort_order ASC, name ASC");
                    $einheiten = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } elseif (isset($_POST['delete_einheit'])) {
                $id = (int)$_POST['einheit_id'];
                if ($id > 0) {
                    $stmt = $db->prepare("DELETE FROM einheiten WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = 'Einheit wurde gelöscht.';
                    $stmt = $db->query("SELECT id, name, sort_order, created_at FROM einheiten ORDER BY sort_order ASC, name ASC");
                    $einheiten = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einheiten verwalten</title>
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

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-cog"></i> Einheiten verwalten</h1>
        <a href="settings-einheiten.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
    </div>

    <?php if ($message): ?>
        <?php echo show_success($message); ?>
    <?php endif; ?>
    <?php if ($error): ?>
        <?php echo show_error($error); ?>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-list"></i> Löschzüge / Einheiten</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Legen Sie hier Ihre Einheiten (z. B. Löschzüge) an. Jede Einheit kann später eigene Mitglieder, Fahrzeuge und weitere Daten haben.</p>
                    <?php if (empty($einheiten)): ?>
                        <p class="text-muted text-center py-4">
                            <i class="fas fa-info-circle"></i> Noch keine Einheiten angelegt. Legen Sie Ihre erste Einheit an.
                        </p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Reihenfolge</th>
                                        <th>Name</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($einheiten as $e): ?>
                                    <tr>
                                        <td><?php echo (int)$e['sort_order']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($e['name']); ?></strong></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary edit-einheit-btn"
                                                    data-id="<?php echo (int)$e['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($e['name']); ?>"
                                                    data-sort="<?php echo (int)$e['sort_order']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Einheit „<?php echo htmlspecialchars(addslashes($e['name'])); ?>“ wirklich löschen?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                <input type="hidden" name="einheit_id" value="<?php echo (int)$e['id']; ?>">
                                                <button type="submit" name="delete_einheit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-success text-white" id="einheit_form_title">
                    <h5 class="card-title mb-0"><i class="fas fa-plus"></i> Einheit anlegen</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="einheitForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="einheit_id" id="einheit_id" value="">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name der Einheit *</label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   placeholder="z. B. Löschzug 1, Löschzug 2">
                        </div>
                        <div class="mb-3">
                            <label for="sort_order" class="form-label">Reihenfolge</label>
                            <input type="number" class="form-control" id="sort_order" name="sort_order" value="0" min="0">
                            <div class="form-text">Kleinere Zahl = weiter oben in der Liste</div>
                        </div>
                        <button type="submit" name="add_einheit" class="btn btn-success" id="btnAddEinheit">
                            <i class="fas fa-plus"></i> Einheit anlegen
                        </button>
                        <div id="editButtons" style="display: none;">
                            <button type="submit" name="edit_einheit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Speichern
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btnCancelEdit">
                                <i class="fas fa-times"></i> Abbrechen
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var nameInput = document.getElementById('name');
    var sortInput = document.getElementById('sort_order');
    var einheitIdInput = document.getElementById('einheit_id');
    var formTitle = document.getElementById('einheit_form_title');
    var btnAdd = document.getElementById('btnAddEinheit');
    var editButtons = document.getElementById('editButtons');
    var cancelBtn = document.getElementById('btnCancelEdit');

    document.querySelectorAll('.edit-einheit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            einheitIdInput.value = this.dataset.id;
            nameInput.value = this.dataset.name;
            sortInput.value = this.dataset.sort;
            formTitle.innerHTML = '<h5 class="card-title mb-0"><i class="fas fa-edit"></i> Einheit bearbeiten</h5>';
            btnAdd.style.display = 'none';
            editButtons.style.display = 'inline-block';
        });
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            einheitIdInput.value = '';
            nameInput.value = '';
            sortInput.value = '0';
            formTitle.innerHTML = '<h5 class="card-title mb-0"><i class="fas fa-plus"></i> Einheit anlegen</h5>';
            btnAdd.style.display = 'inline-block';
            editButtons.style.display = 'none';
        });
    }
})();
</script>
</body>
</html>
