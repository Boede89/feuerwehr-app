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
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
$einheit = null;
$show_einheit_placeholder = false;
if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
        $show_einheit_placeholder = $einheit && is_einheit_waldniel($db, $einheit_id);
    } catch (Exception $e) {}
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
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute(['member_default_qualification_id', $new_default]);
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

        <?php if ($show_einheit_placeholder): ?>
        <div class="card shadow">
            <div class="card-body text-center py-5">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <p class="text-muted mb-0">Einstellungen für diese Einheit – noch nicht konfiguriert.</p>
                <p class="text-muted small mt-2">Die Konfiguration wird in Kürze verfügbar.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="row mb-4">
            <div class="col-12">
                <a href="members.php" class="btn btn-primary me-2">
                    <i class="fas fa-users"></i> Mitglieder verwalten
                </a>
                <a href="<?php echo $einheit_id > 0 ? 'settings-einheit.php?id=' . (int)$einheit_id : 'settings.php'; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Zurück
                </a>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-star"></i> Standardqualifikation
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Diese Qualifikation wird bei neuen Mitgliedern vorausgewählt und gespeichert, wenn beim Anlegen oder Bearbeiten keine andere gewählt wird.</p>
                        <form method="POST" action="">
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
                            <button type="submit" name="save_default_qual" class="btn btn-primary">
                                <i class="fas fa-save"></i> Speichern
                            </button>
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
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Diese Qualifikation wirklich löschen?');">
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
                        <form method="POST" action="" id="qualForm">
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
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$show_einheit_placeholder): ?>
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
        });
    </script>
    <?php endif; ?>
</body>
</html>
