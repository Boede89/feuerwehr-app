<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer mit Admin-Zugriff
if (!has_admin_access()) {
    redirect('../login.php');
}

$message = '';
$error = '';

// Erfolgsmeldungen von GET-Parameter
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $message = "Benutzer wurde erfolgreich hinzugefügt.";
    } elseif ($_GET['success'] == 'updated') {
        $message = "Benutzer wurde erfolgreich aktualisiert.";
    }
}

// Benutzer hinzufügen/bearbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        $user_role = sanitize_input($_POST['user_role'] ?? 'user');
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
            $error = "Alle Felder sind erforderlich.";
        } elseif (!validate_email($email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            try {
                if ($action == 'add') {
                    if (empty($password)) {
                        $error = "Passwort ist erforderlich.";
                    } else {
                        $password_hash = hash_password($password);
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, email_notifications, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $user_role, $email_notifications, $is_active]);
                        $message = "Benutzer wurde erfolgreich hinzugefügt.";
                        log_activity($_SESSION['user_id'], 'user_added', "Benutzer '$username' hinzugefügt");
                        
                        // Weiterleitung um POST-Problem zu vermeiden
                        header("Location: users.php?success=added");
                        exit();
                    }
                } elseif ($action == 'edit') {
                    if (!empty($password)) {
                        $password_hash = hash_password($password);
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, first_name = ?, last_name = ?, user_role = ?, email_notifications = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $user_role, $email_notifications, $is_active, $user_id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, user_role = ?, email_notifications = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $first_name, $last_name, $user_role, $email_notifications, $is_active, $user_id]);
                    }
                    $message = "Benutzer wurde erfolgreich aktualisiert.";
                    log_activity($_SESSION['user_id'], 'user_updated', "Benutzer '$username' aktualisiert");
                    
                    // Weiterleitung um POST-Problem zu vermeiden
                    header("Location: users.php?success=updated");
                    exit();
                }
            } catch(PDOException $e) {
                $error = "Fehler beim Speichern des Benutzers: " . $e->getMessage();
            }
        }
    }
}

// Benutzer löschen
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    
    try {
        // Nicht sich selbst löschen
        if ($user_id == $_SESSION['user_id']) {
            $error = "Sie können sich nicht selbst löschen.";
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "Benutzer wurde erfolgreich gelöscht.";
            log_activity($_SESSION['user_id'], 'user_deleted', "Benutzer ID $user_id gelöscht");
        }
    } catch(PDOException $e) {
        $error = "Fehler beim Löschen des Benutzers: " . $e->getMessage();
    }
}

// Benutzer laden
try {
    $stmt = $db->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Benutzer: " . $e->getMessage();
    $users = [];
}

// Benutzer für Bearbeitung laden
$edit_user = null;
if (isset($_GET['edit'])) {
    $user_id = (int)$_GET['edit'];
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $edit_user = $stmt->fetch();
    } catch(PDOException $e) {
        $error = "Fehler beim Laden des Benutzers: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzer - Feuerwehr App</title>
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
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reservations.php">
                            <i class="fas fa-calendar-check"></i> Reservierungen
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicles.php">
                            <i class="fas fa-truck"></i> Fahrzeuge
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="users.php">
                            <i class="fas fa-users"></i> Benutzer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog"></i> Einstellungen
                        </a>
                    </li>
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-users"></i> Benutzer
                    </h1>
                        <button type="button" class="btn btn-primary" onclick="openUserModal()">
                            <i class="fas fa-plus"></i> Neuer Benutzer
                        </button>
                </div>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Benutzer Tabelle -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Benutzername</th>
                                        <th>E-Mail</th>
                                        <th>Name</th>
                                        <th>Admin</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                            <td>
                                                <?php if ($user['is_admin']): ?>
                                                    <span class="badge bg-danger">Admin</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Benutzer</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge bg-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo format_date($user['created_at']); ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                                            onclick="editUser(this)"
                                                            data-user-id="<?php echo $user['id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                            data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                                            data-first-name="<?php echo htmlspecialchars($user['first_name']); ?>"
                                                            data-last-name="<?php echo htmlspecialchars($user['last_name']); ?>"
                                                            data-is-admin="<?php echo $user['is_admin']; ?>"
                                                            data-is-active="<?php echo $user['is_active']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <a href="?delete=<?php echo $user['id']; ?>" 
                                                           class="btn btn-outline-danger btn-sm"
                                                           onclick="return confirm('Sind Sie sicher, dass Sie diesen Benutzer löschen möchten?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Benutzer Modal -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="userForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="userModalTitle">Neuer Benutzer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Benutzername *</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">E-Mail *</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="first_name" class="form-label">Vorname *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="last_name" class="form-label">Nachname *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Passwort <span id="password-required">*</span></label>
                            <input type="password" class="form-control" id="password" name="password">
                            <div class="form-text" id="password-help">Leer lassen um nicht zu ändern (nur bei Bearbeitung)</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin">
                                    <label class="form-check-label" for="is_admin">
                                        Administrator
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                    <label class="form-check-label" for="is_active">
                                        Aktiv
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="user_id" id="user_id">
                        <input type="hidden" name="action" id="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Abbrechen</button>
                        <button type="submit" class="btn btn-primary" id="submitButton">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Einfache Funktionen ohne Bootstrap-Event-Listener
        function openUserModal() {
            // Modal anzeigen
            const modal = document.getElementById('userModal');
            if (modal) {
                // Neuer Benutzer vorbereiten
                document.getElementById('userModalTitle').textContent = 'Neuer Benutzer';
                document.getElementById('user_id').value = '';
                document.getElementById('username').value = '';
                document.getElementById('email').value = '';
                document.getElementById('first_name').value = '';
                document.getElementById('last_name').value = '';
                document.getElementById('is_admin').checked = false;
                document.getElementById('is_active').checked = true;
                document.getElementById('action').value = 'add';
                document.getElementById('submitButton').textContent = 'Hinzufügen';
                document.getElementById('password-required').textContent = '*';
                document.getElementById('password-help').style.display = 'none';
                
                // Modal anzeigen
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');
            }
        }
        
        function closeUserModal() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
            }
        }
        
        function editUser(button) {
            // Modal anzeigen
            const modal = document.getElementById('userModal');
            if (modal) {
                // Bearbeitung vorbereiten
                const userId = button.getAttribute('data-user-id');
                const username = button.getAttribute('data-username');
                const email = button.getAttribute('data-email');
                const firstName = button.getAttribute('data-first-name');
                const lastName = button.getAttribute('data-last-name');
                const isAdmin = button.getAttribute('data-is-admin');
                const isActive = button.getAttribute('data-is-active');
                
                document.getElementById('userModalTitle').textContent = 'Benutzer bearbeiten';
                document.getElementById('user_id').value = userId;
                document.getElementById('username').value = username;
                document.getElementById('email').value = email;
                document.getElementById('first_name').value = firstName;
                document.getElementById('last_name').value = lastName;
                document.getElementById('is_admin').checked = isAdmin == '1';
                document.getElementById('is_active').checked = isActive == '1';
                document.getElementById('action').value = 'edit';
                document.getElementById('submitButton').textContent = 'Aktualisieren';
                document.getElementById('password-required').textContent = '';
                document.getElementById('password-help').style.display = 'block';
                
                // Modal anzeigen
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');
            }
        }
    </script>
</body>
</html>
