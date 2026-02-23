<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission() && !has_permission('members') && !has_permission('ric')) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;

// einheit_id aus Kontext wenn nicht übergeben
if ($einheit_id <= 0 && function_exists('get_current_einheit_id')) {
    $eid = get_current_einheit_id();
    if ($eid > 0) $einheit_id = (int)$eid;
}

// RIC-Codes Tabelle erstellen/erweitern (einheit_id für Einheitenspezifität)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ric_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kurztext VARCHAR(50) NOT NULL,
        beschreibung TEXT,
        einheit_id INT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE ric_codes ADD COLUMN einheit_id INT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("UPDATE ric_codes SET einheit_id = 0 WHERE einheit_id IS NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE ric_codes DROP INDEX unique_kurztext"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE ric_codes ADD UNIQUE KEY unique_einheit_kurztext (einheit_id, kurztext)"); } catch (Exception $e) {}
} catch (Exception $e) {
    error_log("Fehler RIC-Codes Tabelle: " . $e->getMessage());
}

// Einstellungen laden (global + einheitenspezifisch für Divera Admin)
$divera_admin_user_id = '';
if ($einheit_id > 0) {
    $settings = load_settings_for_einheit($db, $einheit_id);
    $divera_admin_user_id = $settings['ric_divera_admin_user_id'] ?? '';
}
if ($divera_admin_user_id === '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ric_divera_admin_user_id' LIMIT 1");
        $stmt->execute();
        $r = $stmt->fetchColumn();
        if ($r !== false && $r !== '') $divera_admin_user_id = $r;
    } catch (Exception $e) {}
}

// Benutzer laden für Divera Admin Auswahl
$users = [];
try {
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, is_active FROM users WHERE is_active = 1 ORDER BY last_name, first_name");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Benutzer: " . $e->getMessage());
}

// RIC-Codes laden (einheitenspezifisch)
$ric_codes = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, kurztext, beschreibung, created_at, updated_at FROM ric_codes WHERE einheit_id = ? ORDER BY kurztext ASC");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->prepare("SELECT id, kurztext, beschreibung, created_at, updated_at FROM ric_codes WHERE einheit_id = 0 OR einheit_id IS NULL ORDER BY kurztext ASC");
        $stmt->execute();
    }
    $ric_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Fehler beim Laden der RIC-Codes: ' . $e->getMessage();
}

// RIC-Code hinzufügen/bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $db->beginTransaction();
            
            if (isset($_POST['add_ric']) || isset($_POST['edit_ric'])) {
                $ric_id = isset($_POST['edit_ric']) ? (int)$_POST['ric_id'] : null;
                $kurztext = trim(sanitize_input($_POST['kurztext'] ?? ''));
                $beschreibung = trim(sanitize_input($_POST['beschreibung'] ?? ''));
                $add_einheit_id = (int)($_POST['einheit_id'] ?? $einheit_id);
                
                if (empty($kurztext)) {
                    $error = 'Bitte geben Sie einen Kurztext ein.';
                } else {
                    if ($ric_id !== null) {
                        if ($add_einheit_id > 0) {
                            $stmt = $db->prepare("UPDATE ric_codes SET kurztext = ?, beschreibung = ? WHERE id = ? AND einheit_id = ?");
                            $stmt->execute([$kurztext, $beschreibung, $ric_id, $add_einheit_id]);
                        } else {
                            $stmt = $db->prepare("UPDATE ric_codes SET kurztext = ?, beschreibung = ? WHERE id = ? AND (einheit_id = 0 OR einheit_id IS NULL)");
                            $stmt->execute([$kurztext, $beschreibung, $ric_id]);
                        }
                        $message = 'RIC-Code wurde erfolgreich bearbeitet.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO ric_codes (kurztext, beschreibung, einheit_id) VALUES (?, ?, ?)");
                        $stmt->execute([$kurztext, $beschreibung, $add_einheit_id > 0 ? $add_einheit_id : 0]);
                        $message = 'RIC-Code wurde erfolgreich hinzugefügt.';
                    }
                }
            }
            
            // Divera Admin speichern (einheitenspezifisch oder global)
            if (isset($_POST['save_divera_admin'])) {
                $divera_admin_user_id = !empty($_POST['divera_admin_user_id']) ? (int)$_POST['divera_admin_user_id'] : '';
                $save_einheit_id = (int)($_POST['einheit_id'] ?? $einheit_id);
                if ($save_einheit_id > 0) {
                    save_setting_for_einheit($db, $save_einheit_id, 'ric_divera_admin_user_id', (string)$divera_admin_user_id);
                } else {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute(['ric_divera_admin_user_id', $divera_admin_user_id]);
                }
                $message = 'Divera Admin wurde erfolgreich gespeichert.';
            }
            
            // RIC-Code löschen
            if (isset($_POST['delete_ric'])) {
                $ric_id = (int)$_POST['ric_id'];
                $del_einheit_id = (int)($_POST['einheit_id'] ?? $einheit_id);
                
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS member_ric (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        member_id INT NOT NULL,
                        ric_id INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                        FOREIGN KEY (ric_id) REFERENCES ric_codes(id) ON DELETE CASCADE,
                        UNIQUE KEY unique_member_ric (member_id, ric_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (Exception $e) {}
                
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM member_ric WHERE ric_id = ?");
                $stmt->execute([$ric_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    $error = 'Dieser RIC-Code ist noch Mitgliedern zugewiesen und kann nicht gelöscht werden.';
                } else {
                    if ($del_einheit_id > 0) {
                        $stmt = $db->prepare("DELETE FROM ric_codes WHERE id = ? AND einheit_id = ?");
                        $stmt->execute([$ric_id, $del_einheit_id]);
                    } else {
                        $stmt = $db->prepare("DELETE FROM ric_codes WHERE id = ? AND (einheit_id = 0 OR einheit_id IS NULL)");
                        $stmt->execute([$ric_id]);
                    }
                    $message = 'RIC-Code wurde erfolgreich gelöscht.';
                }
            }
            
            $db->commit();
            
            // RIC-Codes neu laden
            if ($einheit_id > 0) {
                $stmt = $db->prepare("SELECT id, kurztext, beschreibung, created_at, updated_at FROM ric_codes WHERE einheit_id = ? ORDER BY kurztext ASC");
                $stmt->execute([$einheit_id]);
            } else {
                $stmt = $db->prepare("SELECT id, kurztext, beschreibung, created_at, updated_at FROM ric_codes WHERE einheit_id = 0 OR einheit_id IS NULL ORDER BY kurztext ASC");
                $stmt->execute();
            }
            $ric_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RIC Verwaltung - Einstellungen - Feuerwehr App</title>
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
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-broadcast-tower"></i> RIC Verwaltung - Einstellungen
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-shield"></i> Divera Admin
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="diveraAdminForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                            <div class="row">
                                <div class="col-md-8">
                                    <label for="divera_admin_user_id" class="form-label">Divera Admin auswählen</label>
                                    <select class="form-select" id="divera_admin_user_id" name="divera_admin_user_id">
                                        <option value="">-- Kein Divera Admin festgelegt --</option>
                                        <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo ($divera_admin_user_id == $user['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                            <?php if (!empty($user['email'])): ?>
                                                (<?php echo htmlspecialchars($user['email']); ?>)
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">Wählen Sie einen Benutzer aus, der als Divera Admin fungieren soll.</small>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" name="save_divera_admin" class="btn btn-primary w-100">
                                        <i class="fas fa-save"></i> Speichern
                                    </button>
                                </div>
                            </div>
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
                            <i class="fas fa-list"></i> RIC-Codes verwalten
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ric_codes)): ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-info-circle"></i> Noch keine RIC-Codes vorhanden.
                            </p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Kurztext</th>
                                            <th>Beschreibung</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ric_codes as $ric): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($ric['kurztext']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($ric['beschreibung'] ?: '-'); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary edit-ric-btn" 
                                                        data-ric-id="<?php echo $ric['id']; ?>"
                                                        data-kurztext="<?php echo htmlspecialchars($ric['kurztext']); ?>"
                                                        data-beschreibung="<?php echo htmlspecialchars($ric['beschreibung']); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Möchten Sie diesen RIC-Code wirklich löschen?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                    <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                                                    <input type="hidden" name="ric_id" value="<?php echo $ric['id']; ?>">
                                                    <button type="submit" name="delete_ric" class="btn btn-sm btn-outline-danger">
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
                        <h5 class="card-title mb-0" id="ric_form_title">
                            <i class="fas fa-plus"></i> Neuen RIC-Code hinzufügen
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="ricForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                            <input type="hidden" name="ric_id" id="ric_id" value="">
                            
                            <div class="mb-3">
                                <label for="kurztext" class="form-label">Kurztext <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="kurztext" name="kurztext" required maxlength="50">
                                <small class="form-text text-muted">Eindeutiger Kurztext für den RIC-Code (z.B. "MTF", "HLF", etc.)</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="beschreibung" class="form-label">Beschreibung</label>
                                <textarea class="form-control" id="beschreibung" name="beschreibung" rows="4"></textarea>
                                <small class="form-text text-muted">Detaillierte Beschreibung des RIC-Codes</small>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" name="add_ric" class="btn btn-primary" id="add_ric_btn">
                                    <i class="fas fa-plus"></i> Hinzufügen
                                </button>
                                <button type="submit" name="edit_ric" class="btn btn-warning" id="edit_ric_btn" style="display: none;">
                                    <i class="fas fa-save"></i> Speichern
                                </button>
                                <button type="button" class="btn btn-secondary" id="cancel_edit_btn" style="display: none;">
                                    <i class="fas fa-times"></i> Abbrechen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <a href="members.php<?php echo $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Zurück
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // RIC-Code bearbeiten
            document.querySelectorAll('.edit-ric-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const ricId = this.dataset.ricId;
                    const kurztext = this.dataset.kurztext;
                    const beschreibung = this.dataset.beschreibung;
                    
                    document.getElementById('ric_id').value = ricId;
                    document.getElementById('kurztext').value = kurztext;
                    document.getElementById('beschreibung').value = beschreibung || '';
                    
                    document.getElementById('ric_form_title').innerHTML = '<i class="fas fa-edit"></i> RIC-Code bearbeiten';
                    document.getElementById('add_ric_btn').style.display = 'none';
                    document.getElementById('edit_ric_btn').style.display = 'inline-block';
                    document.getElementById('cancel_edit_btn').style.display = 'inline-block';
                    
                    document.getElementById('kurztext').focus();
                });
            });
            
            // Bearbeitung abbrechen
            document.getElementById('cancel_edit_btn').addEventListener('click', function() {
                document.getElementById('ricForm').reset();
                document.getElementById('ric_id').value = '';
                document.getElementById('ric_form_title').innerHTML = '<i class="fas fa-plus"></i> Neuen RIC-Code hinzufügen';
                document.getElementById('add_ric_btn').style.display = 'inline-block';
                document.getElementById('edit_ric_btn').style.display = 'none';
                this.style.display = 'none';
            });
        });
    </script>
</body>
</html>

