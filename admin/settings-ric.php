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

// RIC-Codes Tabelle erstellen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS ric_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kurztext VARCHAR(50) NOT NULL,
            beschreibung TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_kurztext (kurztext)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    error_log("Fehler beim Erstellen der RIC-Codes Tabelle: " . $e->getMessage());
}

// RIC-Codes laden
$ric_codes = [];
try {
    $stmt = $db->prepare("SELECT id, kurztext, beschreibung, created_at, updated_at FROM ric_codes ORDER BY kurztext ASC");
    $stmt->execute();
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
                
                if (empty($kurztext)) {
                    $error = 'Bitte geben Sie einen Kurztext ein.';
                } else {
                    if ($ric_id !== null) {
                        // RIC-Code bearbeiten
                        $stmt = $db->prepare("UPDATE ric_codes SET kurztext = ?, beschreibung = ? WHERE id = ?");
                        $stmt->execute([$kurztext, $beschreibung, $ric_id]);
                        $message = 'RIC-Code wurde erfolgreich bearbeitet.';
                    } else {
                        // Neuen RIC-Code hinzufügen
                        $stmt = $db->prepare("INSERT INTO ric_codes (kurztext, beschreibung) VALUES (?, ?)");
                        $stmt->execute([$kurztext, $beschreibung]);
                        $message = 'RIC-Code wurde erfolgreich hinzugefügt.';
                    }
                }
            }
            
            // RIC-Code löschen
            if (isset($_POST['delete_ric'])) {
                $ric_id = (int)$_POST['ric_id'];
                
                // Prüfe ob RIC-Code noch zugewiesen ist
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
                } catch (Exception $e) {
                    // Tabelle existiert bereits
                }
                
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM member_ric WHERE ric_id = ?");
                $stmt->execute([$ric_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    $error = 'Dieser RIC-Code ist noch Mitgliedern zugewiesen und kann nicht gelöscht werden.';
                } else {
                    $stmt = $db->prepare("DELETE FROM ric_codes WHERE id = ?");
                    $stmt->execute([$ric_id]);
                    $message = 'RIC-Code wurde erfolgreich gelöscht.';
                }
            }
            
            $db->commit();
            
            // RIC-Codes neu laden
            $stmt = $db->prepare("SELECT id, kurztext, beschreibung, created_at, updated_at FROM ric_codes ORDER BY kurztext ASC");
            $stmt->execute();
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
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
                                                    <?php echo generate_csrf_token(); ?>
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
                            <?php echo generate_csrf_token(); ?>
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
                <a href="settings.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Zurück zu Einstellungen
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

