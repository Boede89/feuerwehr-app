<?php
/**
 * Formularcenter: Formulare verwalten, ausgefüllte Formulare anzeigen und bearbeiten.
 * Nur für Benutzer mit Berechtigung "Formularcenter" (can_forms).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('forms')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Tabellen anlegen falls nicht vorhanden
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS app_forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            schema_json LONGTEXT NOT NULL COMMENT 'JSON: Felder mit name, label, type, required, options',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS app_form_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NOT NULL,
            user_id INT NOT NULL,
            form_data LONGTEXT NOT NULL COMMENT 'JSON: ausgefüllte Werte',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (form_id) REFERENCES app_forms(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Formularcenter Tabellen: ' . $e->getMessage());
}
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS dienstplan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL,
            typ VARCHAR(50) DEFAULT 'uebungsdienst',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('Dienstplan Tabelle: ' . $e->getMessage());
}

$message = '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'forms';
$dienstplan_jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');

// CSRF-Token erzeugen
if (empty($_SESSION['form_center_csrf'])) {
    $_SESSION['form_center_csrf'] = bin2hex(random_bytes(32));
}

// POST: Formular anlegen/aktualisieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_center_csrf']) && $_POST['form_center_csrf'] === $_SESSION['form_center_csrf']) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_form') {
        $form_id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $schema_json = $_POST['schema_json'] ?? '[]';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if (empty($title)) {
            $error = 'Bitte einen Formulartitel angeben.';
        } else {
            $decoded = json_decode($schema_json, true);
            if ($decoded === null && $schema_json !== '[]') {
                $error = 'Ungültiges Feld-Schema (JSON).';
            } else {
                try {
                    if ($form_id) {
                        $stmt = $db->prepare("UPDATE app_forms SET title = ?, description = ?, schema_json = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$title, $description, $schema_json, $is_active, $form_id]);
                        $message = 'Formular wurde aktualisiert.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO app_forms (title, description, schema_json, is_active) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$title, $description, $schema_json, $is_active]);
                        $message = 'Formular wurde angelegt.';
                    }
                } catch (Exception $e) {
                    $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
                }
            }
        }
    }
    if ($action === 'save_submission' && !$error) {
        $submission_id = (int)($_POST['submission_id'] ?? 0);
        $form_data_json = $_POST['form_data'] ?? '{}';
        if ($submission_id && json_decode($form_data_json) !== null) {
            try {
                $stmt = $db->prepare("UPDATE app_form_submissions SET form_data = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$form_data_json, $submission_id]);
                $message = 'Eingabe wurde gespeichert.';
                $active_tab = 'submissions';
            } catch (Exception $e) {
                $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'dienstplan_save' && !$error) {
        $id = isset($_POST['dienstplan_id']) ? (int)$_POST['dienstplan_id'] : 0;
        $datum = trim($_POST['dienstplan_datum'] ?? '');
        $thema = trim($_POST['dienstplan_thema'] ?? '');
        $thema_neu = trim($_POST['dienstplan_thema_neu'] ?? '');
        $typ = 'uebungsdienst';
        $thema_value = $thema === '__neu__' ? $thema_neu : $thema;
        if (empty($datum) || $thema_value === '') {
            $error = 'Datum und Thema sind erforderlich.';
        } else {
            try {
                if ($id) {
                    $stmt = $db->prepare("UPDATE dienstplan SET datum = ?, bezeichnung = ?, typ = ? WHERE id = ?");
                    $stmt->execute([$datum, $thema_value, $typ, $id]);
                    $message = 'Dienstplan-Eintrag wurde aktualisiert.';
                } else {
                    $stmt = $db->prepare("INSERT INTO dienstplan (datum, bezeichnung, typ) VALUES (?, ?, ?)");
                    $stmt->execute([$datum, $thema_value, $typ]);
                    $message = 'Dienstplan-Eintrag wurde angelegt.';
                }
                $active_tab = 'dienstplan';
            } catch (Exception $e) {
                $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
    if ($action === 'dienstplan_delete' && !$error) {
        $id = (int)($_POST['dienstplan_id'] ?? 0);
        if ($id) {
            try {
                $db->prepare("DELETE FROM dienstplan WHERE id = ?")->execute([$id]);
                $message = 'Dienstplan-Eintrag wurde gelöscht.';
                $active_tab = 'dienstplan';
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
}

// Formulare laden
$forms = [];
try {
    $stmt = $db->query("SELECT * FROM app_forms ORDER BY title");
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ?: 'Formulare konnten nicht geladen werden.';
}

// Eingaben laden (mit Formtitel und Benutzer)
$submissions = [];
try {
    $stmt = $db->query("
        SELECT s.*, f.title AS form_title,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM app_form_submissions s
        JOIN app_forms f ON f.id = s.form_id
        LEFT JOIN users u ON u.id = s.user_id
        ORDER BY s.updated_at DESC
    ");
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle kann fehlen
}

// Dienstplan-Einträge und Themen für Dropdown
$dienstplan_eintraege = [];
$dienstplan_themen = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM dienstplan
        WHERE datum >= ? AND datum <= ?
        ORDER BY datum, bezeichnung
    ");
    $stmt->execute([$dienstplan_jahr . '-01-01', $dienstplan_jahr . '-12-31']);
    $dienstplan_eintraege = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->query("SELECT DISTINCT bezeichnung FROM dienstplan ORDER BY bezeichnung");
    $dienstplan_themen = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // ignore
}

$edit_form = null;
$edit_submission = null;
$edit_dienstplan = null;
if (isset($_GET['edit_form'])) {
    $id = (int)$_GET['edit_form'];
    foreach ($forms as $f) {
        if ((int)$f['id'] === $id) { $edit_form = $f; break; }
    }
}
if (isset($_GET['edit_dienstplan'])) {
    $id = (int)$_GET['edit_dienstplan'];
    foreach ($dienstplan_eintraege as $e) {
        if ((int)$e['id'] === $id) { $edit_dienstplan = $e; break; }
    }
    if (!$edit_dienstplan && $id) {
        $stmt = $db->prepare("SELECT * FROM dienstplan WHERE id = ?");
        $stmt->execute([$id]);
        $edit_dienstplan = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
if (isset($_GET['edit_submission'])) {
    $id = (int)$_GET['edit_submission'];
    foreach ($submissions as $s) {
        if ((int)$s['id'] === $id) { $edit_submission = $s; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formularcenter - Feuerwehr App</title>
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
        <h1 class="h3 mb-4"><i class="fas fa-file-alt"></i> Formularcenter</h1>

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

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'forms' ? 'active' : ''; ?>" href="?tab=forms">
                    <i class="fas fa-list"></i> Formulare verwalten
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'submissions' ? 'active' : ''; ?>" href="?tab=submissions">
                    <i class="fas fa-inbox"></i> Eingegangene Formulare (<?php echo count($submissions); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'dienstplan' ? 'active' : ''; ?>" href="?tab=dienstplan">
                    <i class="fas fa-calendar-alt"></i> Dienstplan
                </a>
            </li>
        </ul>

        <?php if ($active_tab === 'forms'): ?>
            <div class="card shadow">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list"></i> Formulare</span>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formEditModal" onclick="openFormModal()">
                        <i class="fas fa-plus"></i> Neues Formular
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($forms)): ?>
                        <p class="text-muted mb-0">Noch keine Formulare angelegt. Legen Sie ein Formular an, damit es auf der Formulare-Seite erscheint.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Titel</th>
                                        <th>Aktiv</th>
                                        <th>Eingaben</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $f):
                                        $count_stmt = $db->prepare("SELECT COUNT(*) FROM app_form_submissions WHERE form_id = ?");
                                        $count_stmt->execute([$f['id']]);
                                        $sub_count = (int)$count_stmt->fetchColumn();
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($f['title']); ?></td>
                                        <td><?php echo $f['is_active'] ? '<span class="badge bg-success">Ja</span>' : '<span class="badge bg-secondary">Nein</span>'; ?></td>
                                        <td><?php echo $sub_count; ?></td>
                                        <td>
                                            <a href="?tab=forms&edit_form=<?php echo (int)$f['id']; ?>#formEditModal" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#formEditModal" onclick='openFormModal(<?php echo json_encode($f); ?>)'><i class="fas fa-edit"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'submissions'): ?>
            <div class="card shadow">
                <div class="card-header"><i class="fas fa-inbox"></i> Eingegangene Formulare</div>
                <div class="card-body">
                    <?php if (empty($submissions)): ?>
                        <p class="text-muted mb-0">Noch keine ausgefüllten Formulare vorhanden.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Formular</th>
                                        <th>Von</th>
                                        <th>Eingereicht</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $s): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($s['form_title']); ?></td>
                                        <td><?php echo htmlspecialchars(trim($s['user_first_name'] . ' ' . $s['user_last_name']) ?: 'Unbekannt'); ?></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($s['created_at'])); ?></td>
                                        <td>
                                            <a href="?tab=submissions&edit_submission=<?php echo (int)$s['id']; ?>#submissionModal" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#submissionModal" onclick='openSubmissionModal(<?php echo json_encode($s); ?>)'><i class="fas fa-edit"></i> Bearbeiten</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'dienstplan'): ?>
            <div class="card shadow">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="fas fa-calendar-alt"></i> Dienstplan</span>
                    <div class="d-flex align-items-center gap-2">
                        <form method="get" class="d-inline">
                            <input type="hidden" name="tab" value="dienstplan">
                            <select name="jahr" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                                <?php for ($y = date('Y') + 1; $y >= date('Y') - 2; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $dienstplan_jahr === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </form>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dienstplanModal" onclick="openDienstplanModal()">
                            <i class="fas fa-plus"></i> Neuer Eintrag
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($dienstplan_eintraege)): ?>
                        <p class="text-muted mb-0">Keine Einträge für <?php echo $dienstplan_jahr; ?>. Legen Sie Übungsdienste an.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Typ</th>
                                        <th>Thema</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dienstplan_eintraege as $e): ?>
                                    <tr>
                                        <td><?php echo date('d.m.Y', strtotime($e['datum'])); ?></td>
                                        <td><span class="badge bg-primary">Übungsdienst</span></td>
                                        <td><?php echo htmlspecialchars($e['bezeichnung']); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dienstplanModal" onclick='openDienstplanModal(<?php echo json_encode($e); ?>)'><i class="fas fa-edit"></i></button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Eintrag wirklich löschen?');">
                                                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                                                <input type="hidden" name="action" value="dienstplan_delete">
                                                <input type="hidden" name="dienstplan_id" value="<?php echo (int)$e['id']; ?>">
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
        <?php endif; ?>
    </div>

    <!-- Modal: Dienstplan Eintrag -->
    <div class="modal fade" id="dienstplanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                    <input type="hidden" name="action" value="dienstplan_save">
                    <input type="hidden" name="dienstplan_id" id="dienstplan_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="dienstplanModalTitle">Neuer Eintrag</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="dienstplan_datum" class="form-label">Datum *</label>
                            <input type="date" class="form-control" id="dienstplan_datum" name="dienstplan_datum" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Typ</label>
                            <input type="text" class="form-control" value="Übungsdienst" readonly disabled>
                            <input type="hidden" name="dienstplan_typ" value="uebungsdienst">
                        </div>
                        <div class="mb-3">
                            <label for="dienstplan_thema" class="form-label">Thema *</label>
                            <select class="form-select" id="dienstplan_thema" name="dienstplan_thema">
                                <option value="">— Bitte wählen oder neues Thema eingeben —</option>
                                <?php foreach ($dienstplan_themen as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                                <option value="__neu__">— Neues Thema eingeben —</option>
                            </select>
                            <div class="mt-2" id="dienstplan_thema_neu_wrap" style="display: none;">
                                <input type="text" class="form-control" id="dienstplan_thema_neu" name="dienstplan_thema_neu" placeholder="Neues Thema (wird für spätere Einträge gespeichert)">
                            </div>
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

    <!-- Modal: Formular anlegen/bearbeiten -->
    <div class="modal fade" id="formEditModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                    <input type="hidden" name="action" value="save_form">
                    <input type="hidden" name="form_id" id="form_id" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="formEditModalTitle">Neues Formular</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="form_title" class="form-label">Titel *</label>
                            <input type="text" class="form-control" id="form_title" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="form_description" class="form-label">Beschreibung</label>
                            <textarea class="form-control" id="form_description" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="form_schema_json" class="form-label">Feld-Schema (JSON)</label>
                            <textarea class="form-control font-monospace" id="form_schema_json" name="schema_json" rows="10" placeholder='[{"name":"feldname","label":"Anzeigename","type":"text","required":1}]'></textarea>
                            <small class="text-muted">Typen: text, textarea, number, date, email, select, checkbox. Bei select: "options": ["A","B"]</small>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active" value="1" checked>
                            <label class="form-check-label" for="form_is_active">Formular ist aktiv (auf Formulare-Seite sichtbar)</label>
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

    <!-- Modal: Eingabe bearbeiten -->
    <div class="modal fade" id="submissionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="submissionForm">
                    <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf']); ?>">
                    <input type="hidden" name="action" value="save_submission">
                    <input type="hidden" name="submission_id" id="submission_id" value="">
                    <input type="hidden" name="form_data" id="submission_form_data" value="">
                    <div class="modal-header">
                        <h5 class="modal-title">Eingabe bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="submissionModalBody">
                        <p class="text-muted">Laden...</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openFormModal(form) {
            document.getElementById('formEditModalTitle').textContent = form ? 'Formular bearbeiten' : 'Neues Formular';
            document.getElementById('form_id').value = form ? form.id : '';
            document.getElementById('form_title').value = form ? (form.title || '') : '';
            document.getElementById('form_description').value = form ? (form.description || '') : '';
            document.getElementById('form_schema_json').value = form && form.schema_json ? form.schema_json : '[]';
            document.getElementById('form_is_active').checked = form ? (form.is_active == 1) : true;
        }
        function openSubmissionModal(sub) {
            document.getElementById('submission_id').value = sub.id;
            document.getElementById('submission_form_data').value = sub.form_data || '{}';
            var data = {};
            try { data = JSON.parse(sub.form_data || '{}'); } catch(e) {}
            var formTitle = sub.form_title || 'Formular';
            var html = '<p><strong>' + escapeHtml(formTitle) + '</strong></p><p class="text-muted small">Von: ' + escapeHtml((sub.user_first_name || '') + ' ' + (sub.user_last_name || '')) + ', ' + (sub.created_at || '') + '</p><div class="mb-3"><label class="form-label">Daten (JSON)</label><textarea class="form-control font-monospace" id="submission_data_edit" rows="12">' + escapeHtml(JSON.stringify(data, null, 2)) + '</textarea></div>';
            document.getElementById('submissionModalBody').innerHTML = html;
            document.getElementById('submissionForm').onsubmit = function() {
                try {
                    var j = JSON.parse(document.getElementById('submission_data_edit').value);
                    document.getElementById('submission_form_data').value = JSON.stringify(j);
                } catch(e) {
                    alert('Ungültiges JSON.');
                    return false;
                }
            };
        }
        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        function openDienstplanModal(entry) {
            document.getElementById('dienstplanModalTitle').textContent = entry ? 'Eintrag bearbeiten' : 'Neuer Eintrag';
            document.getElementById('dienstplan_id').value = entry ? entry.id : '';
            document.getElementById('dienstplan_datum').value = entry ? (entry.datum || '') : '';
            var themaSel = document.getElementById('dienstplan_thema');
            var themaNeuWrap = document.getElementById('dienstplan_thema_neu_wrap');
            var themaNeu = document.getElementById('dienstplan_thema_neu');
            if (entry && entry.bezeichnung) {
                var opt = Array.from(themaSel.options).find(function(o) { return o.value === entry.bezeichnung; });
                if (opt) {
                    themaSel.value = entry.bezeichnung;
                    themaNeuWrap.style.display = 'none';
                } else {
                    themaSel.value = '__neu__';
                    themaNeu.value = entry.bezeichnung;
                    themaNeuWrap.style.display = 'block';
                }
            } else {
                themaSel.value = '';
                themaNeu.value = '';
                themaNeuWrap.style.display = 'none';
            }
            themaSel.onchange();
        }
        document.getElementById('dienstplan_thema').addEventListener('change', function() {
            var wrap = document.getElementById('dienstplan_thema_neu_wrap');
            wrap.style.display = this.value === '__neu__' ? 'block' : 'none';
        });
        <?php if ($edit_dienstplan): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openDienstplanModal(<?php echo json_encode($edit_dienstplan); ?>);
            var m = document.getElementById('dienstplanModal');
            if (m) new bootstrap.Modal(m).show();
        });
        <?php endif; ?>
        <?php if ($edit_form): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openFormModal(<?php echo json_encode($edit_form); ?>);
            var m = document.getElementById('formEditModal');
            if (m) new bootstrap.Modal(m).show();
        });
        <?php endif; ?>
        <?php if ($edit_submission): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openSubmissionModal(<?php echo json_encode($edit_submission); ?>);
            var m = document.getElementById('submissionModal');
            if (m) new bootstrap.Modal(m).show();
        });
        <?php endif; ?>
    </script>
</body>
</html>
