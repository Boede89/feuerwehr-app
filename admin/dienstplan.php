<?php
/**
 * Dienstplan: Dienste für das Jahr anlegen und verwalten.
 * Für Anwesenheitsliste: Vorschlag "Dienst für heute" beim Ausfüllen.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !has_permission('forms')) {
    header('Location: dashboard.php');
    exit;
}

// Tabellen anlegen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS dienstplan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL,
            typ VARCHAR(50) DEFAULT 'dienst',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_datum (datum),
            KEY idx_jahr (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Dienstplan Tabelle: ' . $e->getMessage());
}

$message = '';
$error = '';
$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

// POST: Eintrag anlegen / bearbeiten / löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $datum = trim($_POST['datum'] ?? '');
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');
        $typ = in_array($_POST['typ'] ?? '', ['dienst', 'uebung']) ? $_POST['typ'] : 'dienst';
        if (empty($datum) || empty($bezeichnung)) {
            $error = 'Datum und Bezeichnung sind erforderlich.';
        } else {
            try {
                if ($id) {
                    $stmt = $db->prepare("UPDATE dienstplan SET datum = ?, bezeichnung = ?, typ = ? WHERE id = ?");
                    $stmt->execute([$datum, $bezeichnung, $typ, $id]);
                    $message = 'Eintrag wurde aktualisiert.';
                } else {
                    $stmt = $db->prepare("INSERT INTO dienstplan (datum, bezeichnung, typ) VALUES (?, ?, ?)");
                    $stmt->execute([$datum, $bezeichnung, $typ]);
                    $message = 'Eintrag wurde angelegt.';
                }
            } catch (Exception $e) {
                $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'delete' && !$error) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM dienstplan WHERE id = ?")->execute([$id]);
                $message = 'Eintrag wurde gelöscht.';
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
}

// Einträge für gewähltes Jahr laden
$eintraege = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM dienstplan
        WHERE datum >= ? AND datum <= ?
        ORDER BY datum, bezeichnung
    ");
    $stmt->execute([$jahr . '-01-01', $jahr . '-12-31']);
    $eintraege = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ?: 'Einträge konnten nicht geladen werden.';
}

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($edit_id) {
    foreach ($eintraege as $e) {
        if ((int)$e['id'] === $edit_id) { $edit = $e; break; }
    }
    if (!$edit) {
        $stmt = $db->prepare("SELECT * FROM dienstplan WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dienstplan - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a class="nav-link" href="formularcenter.php"><i class="fas fa-file-alt"></i> Formularcenter</a>
                <a class="nav-link active" href="dienstplan.php"><i class="fas fa-calendar-alt"></i> Dienstplan</a>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h1 class="h3 mb-4"><i class="fas fa-calendar-alt"></i> Dienstplan</h1>
        <p class="text-muted">Tragen Sie hier die Dienste und Übungen für das Jahr ein. Beim Ausfüllen der Anwesenheitsliste wird für den jeweiligen Tag automatisch der passende Dienst vorgeschlagen.</p>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fas fa-list"></i> Einträge für Jahr</span>
                <div class="d-flex align-items-center gap-2">
                    <form method="get" class="d-inline">
                        <input type="hidden" name="jahr" value="<?php echo $jahr; ?>">
                        <select name="jahr" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                            <?php for ($y = date('Y') + 1; $y >= date('Y') - 2; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $jahr === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal" onclick="openModal()">
                        <i class="fas fa-plus"></i> Neuer Eintrag
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($eintraege)): ?>
                    <p class="text-muted mb-0">Keine Einträge für <?php echo $jahr; ?>. Legen Sie Dienste und Übungen an.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Bezeichnung</th>
                                    <th>Typ</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eintraege as $e): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y', strtotime($e['datum'])); ?></td>
                                    <td><?php echo htmlspecialchars($e['bezeichnung']); ?></td>
                                    <td>
                                        <?php
                                        echo $e['typ'] === 'uebung' ? '<span class="badge bg-info">Übung</span>' : '<span class="badge bg-primary">Dienst</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal" onclick='openModal(<?php echo json_encode($e); ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Eintrag wirklich löschen?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
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

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="dienstplan_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalTitle">Neuer Eintrag</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="datum" class="form-label">Datum *</label>
                            <input type="date" class="form-control" id="datum" name="datum" required>
                        </div>
                        <div class="mb-3">
                            <label for="bezeichnung" class="form-label">Bezeichnung *</label>
                            <input type="text" class="form-control" id="bezeichnung" name="bezeichnung" placeholder="z. B. Gruppendienst, Übung" required>
                        </div>
                        <div class="mb-3">
                            <label for="typ" class="form-label">Typ</label>
                            <select class="form-select" id="typ" name="typ">
                                <option value="dienst">Dienst</option>
                                <option value="uebung">Übung</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openModal(entry) {
            var title = document.getElementById('editModalTitle');
            var idEl = document.getElementById('dienstplan_id');
            var datum = document.getElementById('datum');
            var bezeichnung = document.getElementById('bezeichnung');
            var typ = document.getElementById('typ');
            if (entry) {
                title.textContent = 'Eintrag bearbeiten';
                idEl.value = entry.id;
                datum.value = entry.datum || '';
                bezeichnung.value = entry.bezeichnung || '';
                typ.value = entry.typ || 'dienst';
            } else {
                title.textContent = 'Neuer Eintrag';
                idEl.value = '';
                datum.value = '';
                bezeichnung.value = '';
                typ.value = 'dienst';
            }
        }
        <?php if ($edit): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openModal(<?php echo json_encode($edit); ?>);
            var m = document.getElementById('editModal');
            if (m) new bootstrap.Modal(m).show();
        });
        <?php endif; ?>
    </script>
</body>
</html>
