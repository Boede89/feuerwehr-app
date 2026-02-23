<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('members')) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
$einheit = null;
if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'ric';
if (!in_array($active_tab, ['ric', 'qualifikationen', 'lehrgaenge'])) {
    $active_tab = 'ric';
}

// Tabelle member_qualifications sicherstellen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_qualifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Lehrgänge-Tabellen
    $db->exec("CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        qualification_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE courses ADD COLUMN qualification_id INT NULL"); } catch (Exception $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS course_requirements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        required_course_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (required_course_id) REFERENCES courses(id) ON DELETE CASCADE,
        UNIQUE KEY unique_requirement (course_id, required_course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("Fehler beim Erstellen der member_qualifications Tabelle: " . $e->getMessage());
}

// Qualifikationen laden
$qualifications = [];
try {
    $stmt = $db->query("SELECT id, name, sort_order FROM member_qualifications ORDER BY sort_order, name");
    if ($stmt) {
        $qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Qualifikationen: ' . $e->getMessage();
}

// Standardqualifikation laden
$member_settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
$default_qualification_id = $member_settings['member_default_qualification_id'] ?? '';

// Qualifikation hinzufügen/bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $db->beginTransaction();

            if (isset($_POST['add_qual']) || isset($_POST['edit_qual'])) {
                $qual_id = isset($_POST['edit_qual']) ? (int)$_POST['qual_id'] : null;
                $name = trim(sanitize_input($_POST['name'] ?? ''));
                $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

                if (empty($name)) {
                    $error = 'Bitte geben Sie einen Namen für die Qualifikation ein.';
                } else {
                    if ($qual_id !== null) {
                        $stmt = $db->prepare("UPDATE member_qualifications SET name = ?, sort_order = ? WHERE id = ?");
                        $stmt->execute([$name, $sort_order, $qual_id]);
                        $message = 'Qualifikation wurde erfolgreich bearbeitet.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO member_qualifications (name, sort_order) VALUES (?, ?)");
                        $stmt->execute([$name, $sort_order]);
                        $message = 'Qualifikation wurde erfolgreich hinzugefügt.';
                    }
                }
            }

            if (isset($_POST['delete_qual'])) {
                $qual_id = (int)$_POST['qual_id'];
                if ((string)$qual_id === (string)$default_qualification_id) {
                    $error = 'Die Standardqualifikation kann nicht gelöscht werden. Setzen Sie zuerst eine andere Standardqualifikation oder „Keine“.';
                } else {
                    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM members WHERE qualification_id = ?");
                    $stmt->execute([$qual_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row['cnt'] > 0) {
                        $error = 'Diese Qualifikation ist noch Mitgliedern zugewiesen und kann nicht gelöscht werden.';
                    } else {
                        $stmt = $db->prepare("DELETE FROM member_qualifications WHERE id = ?");
                        $stmt->execute([$qual_id]);
                        $message = 'Qualifikation wurde erfolgreich gelöscht.';
                    }
                }
            }

            // Standardqualifikation speichern
            if (isset($_POST['save_default_qual'])) {
                $new_default = trim($_POST['default_qualification_id'] ?? '');
                $save_einheit_id = (int)($_POST['einheit_id'] ?? 0);
                if ($save_einheit_id > 0) {
                    save_setting_for_einheit($db, $save_einheit_id, 'member_default_qualification_id', $new_default);
                } else {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute(['member_default_qualification_id', $new_default]);
                }
                $default_qualification_id = $new_default;
                $message = 'Standardqualifikation wurde gespeichert.';
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Qualifikationen nach Änderung neu laden (für Aktualisierung der Liste ohne Reload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && (isset($_POST['add_qual']) || isset($_POST['edit_qual']) || isset($_POST['delete_qual']))) {
    $qualifications = [];
    try {
        $stmt = $db->query("SELECT id, name, sort_order FROM member_qualifications ORDER BY sort_order, name");
        if ($stmt) {
            $qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // ignore
    }
}

// Lehrgang hinzufügen/bearbeiten (aus settings-courses)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit'], true)) {
    $course_action = $_POST['action'];
    $course_id = (int)($_POST['course_id'] ?? 0);
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $name = trim(sanitize_input($_POST['name'] ?? ''));
        $description = trim(sanitize_input($_POST['description'] ?? ''));
        $requirements = $_POST['requirements'] ?? [];
        $qualification_id = !empty($_POST['qualification_id']) ? (int)$_POST['qualification_id'] : null;
        if (empty($name)) {
            $error = "Bitte geben Sie einen Lehrgangsnamen ein.";
        } else {
            try {
                $db->beginTransaction();
                if ($course_action === 'add') {
                    $stmt_check = $db->prepare("SELECT id FROM courses WHERE name = ?");
                    $stmt_check->execute([$name]);
                    if ($stmt_check->fetch()) {
                        $error = "Ein Lehrgang mit diesem Namen existiert bereits.";
                        $db->rollBack();
                    } else {
                        $stmt = $db->prepare("INSERT INTO courses (name, description, qualification_id) VALUES (?, ?, ?)");
                        $stmt->execute([$name, $description, $qualification_id]);
                        $course_id = $db->lastInsertId();
                        $message = "Lehrgang wurde erfolgreich hinzugefügt.";
                    }
                } elseif ($course_action === 'edit' && $course_id > 0) {
                    $stmt_check = $db->prepare("SELECT id FROM courses WHERE name = ? AND id != ?");
                    $stmt_check->execute([$name, $course_id]);
                    if ($stmt_check->fetch()) {
                        $error = "Ein Lehrgang mit diesem Namen existiert bereits.";
                        $db->rollBack();
                    } else {
                        $stmt = $db->prepare("UPDATE courses SET name = ?, description = ?, qualification_id = ? WHERE id = ?");
                        $stmt->execute([$name, $description, $qualification_id, $course_id]);
                        $message = "Lehrgang wurde erfolgreich aktualisiert.";
                    }
                }
                if (empty($error) && $course_id > 0) {
                    $stmt = $db->prepare("DELETE FROM course_requirements WHERE course_id = ?");
                    $stmt->execute([$course_id]);
                    if (!empty($requirements) && is_array($requirements)) {
                        $stmt = $db->prepare("INSERT INTO course_requirements (course_id, required_course_id) VALUES (?, ?)");
                        foreach ($requirements as $req_id) {
                            $req_id = (int)$req_id;
                            if ($req_id > 0 && $req_id != $course_id) {
                                try { $stmt->execute([$course_id, $req_id]); } catch (Exception $e) {}
                            }
                        }
                    }
                }
                if (empty($error)) $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
    if (empty($error)) {
        $redirect = 'settings-members.php?tab=lehrgaenge';
        if ($einheit_id > 0) $redirect .= '&einheit_id=' . (int)$einheit_id;
        header("Location: $redirect");
        exit;
    }
}

// Lehrgang löschen
if (isset($_GET['delete']) && isset($_GET['tab']) && $_GET['tab'] === 'lehrgaenge') {
    $course_id = (int)$_GET['delete'];
    if (validate_csrf_token($_GET['csrf_token'] ?? '')) {
        try {
            $stmt = $db->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);
            $message = "Lehrgang wurde erfolgreich gelöscht.";
            $redirect = 'settings-members.php?tab=lehrgaenge';
            if ($einheit_id > 0) $redirect .= '&einheit_id=' . (int)$einheit_id;
            header("Location: $redirect");
            exit;
        } catch (Exception $e) {
            $error = "Fehler beim Löschen: " . $e->getMessage();
        }
    }
}

// Lehrgänge laden
$courses = [];
$qualifications_for_courses = [];
try {
    $q = $db->query("SELECT id, name FROM member_qualifications ORDER BY sort_order, name");
    if ($q) $qualifications_for_courses = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $stmt = $db->prepare("SELECT c.id, c.name, c.description, c.qualification_id, c.created_at, c.updated_at, q.name AS qualification_name FROM courses c LEFT JOIN member_qualifications q ON q.id = c.qualification_id ORDER BY c.name ASC");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($courses as $key => $course) {
        $stmt_req = $db->prepare("SELECT cr.required_course_id, c.name FROM course_requirements cr JOIN courses c ON c.id = cr.required_course_id WHERE cr.course_id = ?");
        $stmt_req->execute([$course['id']]);
        $courses[$key]['requirements'] = $stmt_req->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$tab_param = $einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '';

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitgliederverwaltung – Einstellungen – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0"><i class="fas fa-users-cog"></i> Mitgliederverwaltung – Einstellungen<?php if ($einheit): ?> <span class="text-muted">(<?php echo htmlspecialchars($einheit['name']); ?>)</span><?php endif; ?></h1>
            <a href="<?php echo $einheit_id > 0 ? 'settings-einheit.php?id=' . (int)$einheit_id : 'settings.php'; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
        </div>

        <?php if ($message): ?>
            <?php echo show_success($message); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php echo show_error($error); ?>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'ric' ? 'active' : ''; ?>" href="?tab=ric<?php echo $tab_param; ?>">
                    <i class="fas fa-broadcast-tower"></i> RIC Verwaltung
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'qualifikationen' ? 'active' : ''; ?>" href="?tab=qualifikationen<?php echo $tab_param; ?>">
                    <i class="fas fa-certificate"></i> Qualifikationen
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab === 'lehrgaenge' ? 'active' : ''; ?>" href="?tab=lehrgaenge<?php echo $tab_param; ?>">
                    <i class="fas fa-graduation-cap"></i> Lehrgänge
                </a>
            </li>
        </ul>

        <?php if ($active_tab === 'ric'): ?>
        <div class="row mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="card-title mb-0"><i class="fas fa-broadcast-tower"></i> RIC Verwaltung</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">RIC-Codes verwalten, Divera Admin festlegen und RIC-Zuweisungen für Mitglieder vornehmen.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="settings-ric.php?einheit_id=<?php echo (int)$einheit_id; ?>" class="btn btn-warning">
                                <i class="fas fa-cog"></i> RIC-Codes & Divera Admin
                            </a>
                            <a href="ric-verwaltung.php?einheit_id=<?php echo (int)$einheit_id; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-broadcast-tower"></i> RIC-Zuweisungen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($active_tab === 'qualifikationen'): ?>
        <div class="row mb-4">
            <div class="col-12">
                <a href="members.php" class="btn btn-primary me-2">
                    <i class="fas fa-users"></i> Mitglieder verwalten
                </a>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-star"></i> Standardqualifikation</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Diese Qualifikation wird bei neuen Mitgliedern vorausgewählt.</p>
                        <form method="POST" action="?tab=qualifikationen<?php echo $tab_param; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                            <div class="mb-3">
                                <label for="default_qualification_id" class="form-label">Standardqualifikation</label>
                                <select class="form-select" name="default_qualification_id" id="default_qualification_id">
                                    <option value="">— Keine —</option>
                                    <?php foreach ($qualifications as $q): ?>
                                    <option value="<?php echo (int)$q['id']; ?>" <?php echo ($default_qualification_id === (string)$q['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($q['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="save_default_qual" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12 col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-certificate"></i> Qualifikationen
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Diese Qualifikationen können bei Mitgliedern im Dropdown „Qualifikation“ ausgewählt werden. Die <strong>Reihenfolge</strong> legt fest, welche Qualifikation bei einem Mitglied mit mehreren Lehrgängen (mit unterschiedlichen Qualifikationen) angezeigt wird: Die Qualifikation mit der kleinsten Reihenfolge-Nummer (1 = höchste Stufe) gilt.</p>
                        <?php if (empty($qualifications)): ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-info-circle"></i> Noch keine Qualifikationen angelegt.
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
                                        <?php foreach ($qualifications as $q): ?>
                                        <tr>
                                            <td><?php echo (int)$q['sort_order']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($q['name']); ?></strong></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary edit-qual-btn"
                                                        data-id="<?php echo $q['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($q['name']); ?>"
                                                        data-sort="<?php echo (int)$q['sort_order']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" action="?tab=qualifikationen<?php echo $tab_param; ?>" style="display: inline;" onsubmit="return confirm('Diese Qualifikation wirklich löschen?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                    <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                                                    <input type="hidden" name="qual_id" value="<?php echo $q['id']; ?>">
                                                    <button type="submit" name="delete_qual" class="btn btn-sm btn-outline-danger">
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
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0" id="qual_form_title">
                            <i class="fas fa-plus"></i> Qualifikation hinzufügen
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?tab=qualifikationen<?php echo $tab_param; ?>" id="qualForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                            <input type="hidden" name="qual_id" id="qual_id" value="">

                            <div class="mb-3">
                                <label for="qual_name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="qual_name" name="name" required maxlength="255">
                            </div>

                            <div class="mb-3">
                                <label for="qual_sort_order" class="form-label">Reihenfolge</label>
                                <input type="number" class="form-control" id="qual_sort_order" name="sort_order" value="0" min="0">
                                <small class="form-text text-muted">Kleinere Zahl = weiter oben in der Auswahl. Wenn ein Mitglied mehrere Lehrgänge mit unterschiedlichen Qualifikationen hat, wird automatisch die Qualifikation mit der <strong>kleinsten Reihenfolge-Nummer</strong> (1 = höchste Stufe) als Anzeige-Qualifikation des Mitglieds verwendet.</small>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" name="add_qual" class="btn btn-primary" id="add_qual_btn">
                                    <i class="fas fa-plus"></i> Hinzufügen
                                </button>
                                <button type="submit" name="edit_qual" class="btn btn-warning" id="edit_qual_btn" style="display: none;">
                                    <i class="fas fa-save"></i> Speichern
                                </button>
                                <button type="button" class="btn btn-secondary" id="cancel_qual_btn" style="display: none;">
                                    <i class="fas fa-times"></i> Abbrechen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($active_tab === 'lehrgaenge'): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-plus-circle"></i> Lehrgang hinzufügen</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?tab=lehrgaenge<?php echo $tab_param; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                            <input type="hidden" name="action" value="add" id="course_action">
                            <input type="hidden" name="course_id" value="" id="course_id">
                            <div class="mb-3">
                                <label for="course_name" class="form-label">Lehrgangsname *</label>
                                <input type="text" class="form-control" id="course_name" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label for="course_description" class="form-label">Beschreibung</label>
                                <textarea class="form-control" id="course_description" name="description" rows="3"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="course_qualification_id" class="form-label">Qualifikation</label>
                                <select class="form-select" id="course_qualification_id" name="qualification_id">
                                    <option value="">— keine —</option>
                                    <?php foreach ($qualifications_for_courses as $q): ?>
                                    <option value="<?php echo (int)$q['id']; ?>"><?php echo htmlspecialchars($q['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Wenn gesetzt, wird diese Qualifikation beim Mitglied gesetzt, sobald der Lehrgang dem Mitglied zugewiesen wird.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Anforderungen (Voraussetzungen)</label>
                                <div class="border rounded p-3" id="requirementsContainer" style="max-height: 200px; overflow-y: auto;">
                                    <?php if (empty($courses)): ?>
                                        <p class="text-muted mb-0">Noch keine Lehrgänge vorhanden.</p>
                                    <?php else: ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($courses as $course): ?>
                                            <button type="button" class="btn btn-outline-secondary requirement-btn" data-requirement-id="<?php echo $course['id']; ?>" id="req_btn_<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['name']); ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <small class="form-text text-muted">Klicken Sie auf die Lehrgänge, die als Voraussetzung gelten sollen.</small>
                            </div>
                            <button type="submit" class="btn btn-primary" id="course_submit_btn"><i class="fas fa-save"></i> Hinzufügen</button>
                            <button type="button" class="btn btn-secondary" onclick="courseResetForm()"><i class="fas fa-times"></i> Abbrechen</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-list"></i> Vorhandene Lehrgänge</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($courses)): ?>
                            <p class="text-muted">Noch keine Lehrgänge definiert.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead><tr><th>Name</th><th>Beschreibung</th><th>Qualifikation</th><th>Anforderungen</th><th>Aktionen</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($courses as $course): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($course['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($course['description'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($course['qualification_name'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($course['requirements'])): ?>
                                                    <?php foreach ($course['requirements'] as $req): ?><span class="badge bg-secondary me-1"><?php echo htmlspecialchars($req['name']); ?></span><?php endforeach; ?>
                                                <?php else: ?><span class="text-muted">Keine</span><?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary edit-course-btn" data-course-id="<?php echo $course['id']; ?>" data-course-name="<?php echo htmlspecialchars($course['name'], ENT_QUOTES); ?>" data-course-description="<?php echo htmlspecialchars($course['description'] ?? '', ENT_QUOTES); ?>" data-course-qualification-id="<?php echo (int)($course['qualification_id'] ?? 0); ?>" data-course-requirements="<?php echo htmlspecialchars(json_encode(!empty($course['requirements']) ? array_column($course['requirements'], 'required_course_id') : []), ENT_QUOTES); ?>"><i class="fas fa-edit"></i></button>
                                                <a href="?tab=lehrgaenge&delete=<?php echo $course['id']; ?>&csrf_token=<?php echo htmlspecialchars(generate_csrf_token()); ?><?php echo $tab_param; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Möchten Sie diesen Lehrgang wirklich löschen?')"><i class="fas fa-trash"></i></a>
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
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.edit-qual-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('qual_id').value = this.dataset.id;
                    document.getElementById('qual_name').value = this.dataset.name;
                    document.getElementById('qual_sort_order').value = this.dataset.sort;
                    document.getElementById('qual_form_title').innerHTML = '<i class="fas fa-edit"></i> Qualifikation bearbeiten';
                    document.getElementById('add_qual_btn').style.display = 'none';
                    document.getElementById('edit_qual_btn').style.display = 'inline-block';
                    document.getElementById('cancel_qual_btn').style.display = 'inline-block';
                    document.getElementById('qual_name').focus();
                });
            });
            document.getElementById('cancel_qual_btn').addEventListener('click', function() {
                document.getElementById('qualForm').reset();
                document.getElementById('qual_id').value = '';
                document.getElementById('qual_sort_order').value = '0';
                document.getElementById('qual_form_title').innerHTML = '<i class="fas fa-plus"></i> Qualifikation hinzufügen';
                document.getElementById('add_qual_btn').style.display = 'inline-block';
                document.getElementById('edit_qual_btn').style.display = 'none';
                this.style.display = 'none';
            });
            document.querySelectorAll('.edit-course-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.dataset.courseId, name = this.dataset.courseName || '', desc = this.dataset.courseDescription || '', qid = this.dataset.courseQualificationId || '', reqs = [];
                    try { reqs = JSON.parse(this.dataset.courseRequirements || '[]'); } catch(e) {}
                    document.getElementById('course_action').value = 'edit';
                    document.getElementById('course_id').value = id;
                    document.getElementById('course_name').value = name;
                    document.getElementById('course_description').value = desc;
                    var qsel = document.getElementById('course_qualification_id');
                    if (qsel) qsel.value = qid;
                    document.getElementById('course_submit_btn').innerHTML = '<i class="fas fa-save"></i> Aktualisieren';
                    document.querySelectorAll('.requirement-btn').forEach(function(b) { b.classList.remove('btn-success'); b.classList.add('btn-outline-secondary'); });
                    document.querySelectorAll('.requirement-input').forEach(function(i) { i.remove(); });
                    reqs.forEach(function(rid) {
                        var b = document.getElementById('req_btn_' + rid);
                        if (b) { b.classList.remove('btn-outline-secondary'); b.classList.add('btn-success'); var inp = document.createElement('input'); inp.type='hidden'; inp.name='requirements[]'; inp.value=rid; inp.className='requirement-input'; var form = b.closest('form'); if (form) form.appendChild(inp); }
                    });
                });
            });
            document.querySelectorAll('.requirement-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var rid = this.dataset.requirementId, inp = document.getElementById('req_' + rid);
                    if (this.classList.contains('btn-success')) {
                        if (inp) inp.remove();
                        this.classList.remove('btn-success'); this.classList.add('btn-outline-secondary');
                    } else {
                        var hi = document.createElement('input'); hi.type='hidden'; hi.name='requirements[]'; hi.value=rid; hi.id='req_'+rid; hi.className='requirement-input';
                        var form = this.closest('form');
                        if (form) form.appendChild(hi);
                        this.classList.remove('btn-outline-secondary'); this.classList.add('btn-success');
                    }
                });
            });
        });
        function courseResetForm() {
            document.getElementById('course_action').value = 'add';
            document.getElementById('course_id').value = '';
            document.getElementById('course_name').value = '';
            document.getElementById('course_description').value = '';
            var q = document.getElementById('course_qualification_id'); if (q) q.value = '';
            document.getElementById('course_submit_btn').innerHTML = '<i class="fas fa-save"></i> Hinzufügen';
            document.querySelectorAll('.requirement-btn').forEach(function(b) { b.classList.remove('btn-success'); b.classList.add('btn-outline-secondary'); });
            document.querySelectorAll('.requirement-input').forEach(function(i) { i.remove(); });
        }
    </script>
</body>
</html>
