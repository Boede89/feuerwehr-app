<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Mitgliederverwaltungs- und RIC-Berechtigung hat
if (!has_permission('members') || !has_permission('ric')) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Tabellen sicherstellen
try {
    // RIC-Codes Tabelle
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
    
    // Member-RIC Verknüpfungstabelle
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_ric (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            ric_id INT NOT NULL,
            status ENUM('pending', 'confirmed') DEFAULT 'confirmed',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (ric_id) REFERENCES ric_codes(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_member_ric (member_id, ric_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // Spalten hinzufügen falls Tabelle bereits existiert
    try {
        $db->exec("ALTER TABLE member_ric ADD COLUMN status ENUM('pending', 'confirmed') DEFAULT 'confirmed'");
    } catch (Exception $e) {
        // Spalte existiert bereits
    }
    try {
        $db->exec("ALTER TABLE member_ric ADD COLUMN created_by INT NULL");
    } catch (Exception $e) {
        // Spalte existiert bereits
    }
    try {
        $db->exec("ALTER TABLE member_ric ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) {
        // Foreign Key existiert bereits
    }
} catch (Exception $e) {
    error_log("Fehler beim Erstellen der Tabellen: " . $e->getMessage());
}

// Divera Admin laden
$divera_admin_user_id = null;
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ric_divera_admin_user_id' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    if ($result !== false && !empty($result)) {
        $divera_admin_user_id = (int)$result;
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden des Divera Admins: " . $e->getMessage());
}

$is_divera_admin = ($divera_admin_user_id && $_SESSION['user_id'] == $divera_admin_user_id);

// Aktueller Benutzer Info laden
$current_user = null;
try {
    $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fehler beim Laden des aktuellen Benutzers: " . $e->getMessage());
}

// RIC-Zuweisungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        try {
            $db->beginTransaction();
            
            if (isset($_POST['save_assignments'])) {
                $member_id = (int)($_POST['member_id'] ?? 0);
                $ric_ids = isset($_POST['ric_ids']) ? array_map('intval', $_POST['ric_ids']) : [];
                
                if ($member_id <= 0) {
                    $error = "Ungültige Mitglieds-ID.";
                } else {
                    // Alte Zuweisungen laden für Vergleich
                    $stmt = $db->prepare("SELECT ric_id FROM member_ric WHERE member_id = ?");
                    $stmt->execute([$member_id]);
                    $old_assignments = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Alte Zuweisungen löschen
                    $stmt = $db->prepare("DELETE FROM member_ric WHERE member_id = ?");
                    $stmt->execute([$member_id]);
                    
                    // Status bestimmen: confirmed wenn Divera Admin, sonst pending
                    $status = $is_divera_admin ? 'confirmed' : 'pending';
                    $created_by = $is_divera_admin ? null : $_SESSION['user_id'];
                    
                    // Neue Zuweisungen hinzufügen
                    if (!empty($ric_ids)) {
                        $stmt = $db->prepare("INSERT INTO member_ric (member_id, ric_id, status, created_by) VALUES (?, ?, ?, ?)");
                        foreach ($ric_ids as $ric_id) {
                            try {
                                $stmt->execute([$member_id, $ric_id, $status, $created_by]);
                            } catch (Exception $e) {
                                // Duplikat ignorieren
                            }
                        }
                    }
                    
                    // E-Mail an Divera Admin senden wenn nicht selbst geändert
                    if (!$is_divera_admin && $divera_admin_user_id && $current_user) {
                        // Mitglied Info laden
                        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
                        $stmt->execute([$member_id]);
                        $member = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        // Divera Admin Info laden
                        $stmt = $db->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                        $stmt->execute([$divera_admin_user_id]);
                        $divera_admin = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($divera_admin && !empty($divera_admin['email'])) {
                            // Änderungen bestimmen
                            $added_rics = array_diff($ric_ids, $old_assignments);
                            $removed_rics = array_diff($old_assignments, $ric_ids);
                            
                            // RIC-Codes Namen laden
                            $ric_names = [];
                            if (!empty($ric_ids) || !empty($old_assignments)) {
                                $all_ric_ids = array_unique(array_merge($ric_ids, $old_assignments));
                                if (!empty($all_ric_ids)) {
                                    $placeholders = implode(',', array_fill(0, count($all_ric_ids), '?'));
                                    $stmt = $db->prepare("SELECT id, kurztext FROM ric_codes WHERE id IN ($placeholders)");
                                    $stmt->execute($all_ric_ids);
                                    $ric_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($ric_data as $ric) {
                                        $ric_names[$ric['id']] = $ric['kurztext'];
                                    }
                                }
                            }
                            
                            $email_subject = 'RIC-Zuweisung erfordert Bestätigung';
                            $email_body = '
                            <html>
                            <head>
                                <meta charset="UTF-8">
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background-color: #ffc107; color: #000; padding: 20px; text-align: center; }
                                    .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                                    .info-box { background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107; }
                                    .info-box strong { color: #ffc107; }
                                    .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                                    .button { display: inline-block; background-color: #0d6efd; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 20px; }
                                </style>
                            </head>
                            <body>
                                <div class="container">
                                    <div class="header">
                                        <h1>RIC-Zuweisung erfordert Bestätigung</h1>
                                    </div>
                                    <div class="content">
                                        <p>Hallo ' . htmlspecialchars($divera_admin['first_name']) . ',</p>
                                        <p>Es wurde eine RIC-Zuweisung vorgenommen, die Ihre Bestätigung erfordert:</p>
                                        <div class="info-box">
                                            <p><strong>Geändert von:</strong> ' . htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']) . ' (' . htmlspecialchars($current_user['email']) . ')</p>
                                            <p><strong>Mitglied:</strong> ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . '</p>';
                            
                            if (!empty($added_rics)) {
                                $added_names = [];
                                foreach ($added_rics as $ric_id) {
                                    if (isset($ric_names[$ric_id])) {
                                        $added_names[] = htmlspecialchars($ric_names[$ric_id]);
                                    }
                                }
                                if (!empty($added_names)) {
                                    $email_body .= '<p><strong>Hinzugefügte RIC-Codes:</strong> ' . implode(', ', $added_names) . '</p>';
                                }
                            }
                            
                            if (!empty($removed_rics)) {
                                $removed_names = [];
                                foreach ($removed_rics as $ric_id) {
                                    if (isset($ric_names[$ric_id])) {
                                        $removed_names[] = htmlspecialchars($ric_names[$ric_id]);
                                    }
                                }
                                if (!empty($removed_names)) {
                                    $email_body .= '<p><strong>Entfernte RIC-Codes:</strong> ' . implode(', ', $removed_names) . '</p>';
                                }
                            }
                            
                            $email_body .= '
                                        </div>
                                        <p style="text-align: center; margin: 30px 0;">
                                            <a href="https://feuerwehr.boede89.selfhost.co/admin/ric-verwaltung.php" class="button">Zur RIC-Verwaltung</a>
                                        </p>
                                        <p>Bitte prüfen und bestätigen Sie die Änderung in der RIC-Verwaltung.</p>
                                    </div>
                                    <div class="footer">
                                        <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
                                    </div>
                                </div>
                            </body>
                            </html>';
                            
                            send_email($divera_admin['email'], $email_subject, $email_body, '', true);
                        }
                    }
                    
                    $db->commit();
                    if ($is_divera_admin) {
                        $message = "RIC-Zuweisungen wurden erfolgreich gespeichert.";
                    } else {
                        $message = "RIC-Zuweisungen wurden gespeichert und warten auf Bestätigung durch den Divera Admin.";
                    }
                }
            }
            
            // Bestätigung durch Divera Admin
            if (isset($_POST['confirm_assignment']) && $is_divera_admin) {
                $assignment_id = (int)($_POST['assignment_id'] ?? 0);
                
                if ($assignment_id > 0) {
                    $stmt = $db->prepare("UPDATE member_ric SET status = 'confirmed' WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$assignment_id]);
                    $message = "RIC-Zuweisung wurde erfolgreich bestätigt.";
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Fehler beim Speichern: " . $e->getMessage();
        }
    }
}

// Mitglieder laden
$members = [];
try {
    $stmt = $db->prepare("
        SELECT m.id, m.first_name, m.last_name, m.email, m.birthdate, m.phone
        FROM members m
        ORDER BY m.last_name ASC, m.first_name ASC
    ");
    $stmt->execute();
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Fehler beim Laden der Mitglieder: " . $e->getMessage();
}

// RIC-Codes laden
$ric_codes = [];
try {
    $stmt = $db->prepare("SELECT id, kurztext, beschreibung FROM ric_codes ORDER BY kurztext ASC");
    $stmt->execute();
    $ric_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Fehler beim Laden der RIC-Codes: " . $e->getMessage();
}

// Zuweisungen für alle Mitglieder laden (mit Status)
$member_ric_assignments = [];
$member_ric_statuses = [];
try {
    $stmt = $db->prepare("SELECT id, member_id, ric_id, status FROM member_ric");
    $stmt->execute();
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as $assignment) {
        if (!isset($member_ric_assignments[$assignment['member_id']])) {
            $member_ric_assignments[$assignment['member_id']] = [];
            $member_ric_statuses[$assignment['member_id']] = [];
        }
        $member_ric_assignments[$assignment['member_id']][] = $assignment['ric_id'];
        $member_ric_statuses[$assignment['member_id']][$assignment['ric_id']] = [
                            'status' => $assignment['status'],
                            'id' => $assignment['id']
                        ];
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden der Zuweisungen: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RIC Verwaltung - Feuerwehr App</title>
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
                    <i class="fas fa-broadcast-tower"></i> RIC Verwaltung (Divera)
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($ric_codes)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                Es sind noch keine RIC-Codes vorhanden. Bitte legen Sie zuerst RIC-Codes in den 
                <a href="settings-ric.php">Einstellungen</a> an.
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-users"></i> Mitglieder und RIC-Zuweisungen
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($members)): ?>
                                <p class="text-muted text-center py-4">
                                    <i class="fas fa-info-circle"></i> Noch keine Mitglieder vorhanden.
                                </p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>E-Mail</th>
                                                <th>Zugewiesene RIC-Codes</th>
                                                <th>Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $member): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                                <td>
                                                    <?php 
                                                    $assigned_rics = $member_ric_assignments[$member['id']] ?? [];
                                                    $statuses = $member_ric_statuses[$member['id']] ?? [];
                                                    if (empty($assigned_rics)): 
                                                    ?>
                                                        <span class="text-muted">Keine Zuweisungen</span>
                                                    <?php else: ?>
                                                        <?php foreach ($assigned_rics as $ric_id): 
                                                            $ric = array_filter($ric_codes, function($r) use ($ric_id) { return $r['id'] == $ric_id; });
                                                            $ric = reset($ric);
                                                            if ($ric):
                                                                $status = $statuses[$ric_id]['status'] ?? 'confirmed';
                                                                $assignment_id = $statuses[$ric_id]['id'] ?? 0;
                                                                $badge_class = ($status === 'pending') ? 'bg-warning' : 'bg-primary';
                                                                $badge_title = ($status === 'pending') ? 'Wartet auf Bestätigung' : htmlspecialchars($ric['beschreibung'] ?? '');
                                                        ?>
                                                            <span class="badge <?php echo $badge_class; ?> me-1" title="<?php echo $badge_title; ?>" style="<?php echo ($status === 'pending') ? 'background-color: #ffc107 !important;' : ''; ?>">
                                                                <?php echo htmlspecialchars($ric['kurztext']); ?>
                                                                <?php if ($status === 'pending' && $is_divera_admin && $assignment_id > 0): ?>
                                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Möchten Sie diese RIC-Zuweisung bestätigen?');">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                                                                        <button type="submit" name="confirm_assignment" class="btn btn-sm btn-success ms-1" style="padding: 0 5px; font-size: 0.7em;" title="Bestätigen">
                                                                            <i class="fas fa-check"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php 
                                                            endif;
                                                        endforeach; 
                                                        ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#assignRicModal"
                                                            data-member-id="<?php echo $member['id']; ?>"
                                                            data-member-name="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>"
                                                            onclick="loadMemberRics(<?php echo $member['id']; ?>)">
                                                        <i class="fas fa-edit"></i> RIC zuweisen
                                                    </button>
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

        <div class="row mt-4">
            <div class="col-12">
                <a href="members.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Zurück zur Mitgliederverwaltung
                </a>
                <?php if (hasAdminPermission()): ?>
                <a href="settings-ric.php" class="btn btn-outline-primary">
                    <i class="fas fa-cog"></i> RIC-Codes verwalten
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIC-Zuweisung Modal -->
    <div class="modal fade" id="assignRicModal" tabindex="-1" aria-labelledby="assignRicModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="assignRicModalLabel">
                        <i class="fas fa-broadcast-tower"></i> RIC-Codes zuweisen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="member_id" id="modal_member_id" value="">
                    <div class="modal-body">
                        <p><strong>Mitglied:</strong> <span id="modal_member_name"></span></p>
                        <div class="mb-3">
                            <label class="form-label">RIC-Codes auswählen:</label>
                            <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($ric_codes as $ric): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input ric-checkbox" 
                                           type="checkbox" 
                                           name="ric_ids[]" 
                                           value="<?php echo $ric['id']; ?>" 
                                           id="ric_<?php echo $ric['id']; ?>">
                                    <label class="form-check-label" for="ric_<?php echo $ric['id']; ?>">
                                        <strong><?php echo htmlspecialchars($ric['kurztext']); ?></strong>
                                        <?php if (!empty($ric['beschreibung'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($ric['beschreibung']); ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="cancelBtn" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Abbrechen
                        </button>
                        <button type="submit" name="save_assignments" class="btn btn-primary" id="saveAssignmentsBtn">
                            <i class="fas fa-save"></i> <span id="saveBtnText">Speichern</span>
                            <span id="saveBtnSpinner" class="spinner-border spinner-border-sm ms-2" role="status" aria-hidden="true" style="display: none;"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const memberRicAssignments = <?php echo json_encode($member_ric_assignments); ?>;
        const memberRicStatuses = <?php echo json_encode($member_ric_statuses); ?>;
        
        function loadMemberRics(memberId) {
            const memberName = document.querySelector(`[data-member-id="${memberId}"]`).dataset.memberName;
            document.getElementById('modal_member_id').value = memberId;
            document.getElementById('modal_member_name').textContent = memberName;
            
            // Alle Checkboxen zurücksetzen
            document.querySelectorAll('.ric-checkbox').forEach(function(checkbox) {
                checkbox.checked = false;
            });
            
            // Zugewiesene RIC-Codes markieren (nur confirmed oder wenn Divera Admin)
            const assignedRics = memberRicAssignments[memberId] || [];
            const statuses = memberRicStatuses[memberId] || [];
            const isDiveraAdmin = <?php echo $is_divera_admin ? 'true' : 'false'; ?>;
            
            assignedRics.forEach(function(ricId) {
                const status = statuses[ricId] ? statuses[ricId].status : 'confirmed';
                // Nur confirmed anzeigen, oder alle wenn Divera Admin
                if (status === 'confirmed' || isDiveraAdmin) {
                    const checkbox = document.getElementById('ric_' + ricId);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                }
            });
            
            // Button-Status zurücksetzen beim Öffnen des Modals
            resetSaveButton();
        }
        
        function resetSaveButton() {
            const saveBtn = document.getElementById('saveAssignmentsBtn');
            const saveBtnText = document.getElementById('saveBtnText');
            const saveBtnSpinner = document.getElementById('saveBtnSpinner');
            const cancelBtn = document.getElementById('cancelBtn');
            
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtnText.textContent = 'Speichern';
                saveBtnSpinner.style.display = 'none';
            }
            if (cancelBtn) {
                cancelBtn.disabled = false;
            }
        }
        
        function disableSaveButton() {
            const saveBtn = document.getElementById('saveAssignmentsBtn');
            const saveBtnText = document.getElementById('saveBtnText');
            const saveBtnSpinner = document.getElementById('saveBtnSpinner');
            const cancelBtn = document.getElementById('cancelBtn');
            
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtnText.textContent = 'Wird gespeichert...';
                saveBtnSpinner.style.display = 'inline-block';
            }
            if (cancelBtn) {
                cancelBtn.disabled = true;
            }
        }
        
        // Formular-Submit-Handler
        document.addEventListener('DOMContentLoaded', function() {
            const assignRicForm = document.querySelector('#assignRicModal form');
            const saveBtn = document.getElementById('saveAssignmentsBtn');
            let isSubmitting = false;
            
            if (saveBtn) {
                // Button-Click-Handler - deaktiviert den Button sofort
                saveBtn.addEventListener('click', function(e) {
                    if (isSubmitting || this.disabled) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                    
                    // Button sofort deaktivieren
                    isSubmitting = true;
                    disableSaveButton();
                    
                    // Formular wird normal abgesendet
                    // Der Button bleibt deaktiviert bis die Seite neu geladen wird
                });
            }
            
            if (assignRicForm) {
                // Zusätzlicher Schutz im Submit-Handler
                assignRicForm.addEventListener('submit', function(e) {
                    if (isSubmitting) {
                        // Wenn bereits gesendet, verhindere erneutes Absenden
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                    // Erstes Absenden erlauben
                    isSubmitting = true;
                });
            }
            
            // Modal wird geschlossen - Button-Status zurücksetzen
            const assignRicModal = document.getElementById('assignRicModal');
            if (assignRicModal) {
                assignRicModal.addEventListener('hidden.bs.modal', function() {
                    resetSaveButton();
                    isSubmitting = false;
                });
            }
        });
    </script>
</body>
</html>

