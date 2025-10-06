<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Bereits eingeloggt? Weiterleitung zum Dashboard
if (is_logged_in()) {
    redirect('admin/dashboard.php');
}

$error = '';

// Prüfe ob Zugriff verweigert wurde
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    $error = "Zugriff verweigert. Sie müssen als Administrator angemeldet sein, um das Dashboard zu verwenden.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = "Bitte geben Sie Benutzername und Passwort ein.";
    } else {
        try {
            $stmt = $db->prepare("SELECT id, username, email, password_hash, first_name, last_name, is_admin, is_active, user_role, email_notifications, can_reservations, can_users, can_settings, can_vehicles FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && $user['is_active'] && verify_password($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['role'] = $user['user_role'] ?? 'user';
                $_SESSION['email_notifications'] = $user['email_notifications'] ?? 1;
                $_SESSION['can_reservations'] = $user['can_reservations'] ?? 0;
                $_SESSION['can_users'] = $user['can_users'] ?? 0;
                $_SESSION['can_settings'] = $user['can_settings'] ?? 0;
                $_SESSION['can_vehicles'] = $user['can_vehicles'] ?? 0;
                
                // Aktivität loggen
                log_activity($user['id'], 'login', 'Benutzer angemeldet');
                
                redirect('admin/dashboard.php');
            } else {
                $error = "Ungültige Anmeldedaten oder Benutzer ist deaktiviert.";
            }
        } catch(PDOException $e) {
            $error = "Fehler bei der Anmeldung: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center min-vh-100 align-items-center">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow">
                    <div class="card-header text-center">
                        <h3 class="mb-0">
                            <i class="fas fa-fire text-primary"></i><br>
                            Feuerwehr App
                        </h3>
                        <p class="text-muted mb-0">Anmelden</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <?php echo show_error($error); ?>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Benutzername oder E-Mail</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($username ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Passwort</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt"></i> Anmelden
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer text-center">
                        <a href="index.php" class="text-decoration-none">
                            <i class="fas fa-arrow-left"></i> Zurück zur Startseite
                        </a>
                    </div>
                </div>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        Standard Admin: admin / admin123
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Passwort anzeigen/verstecken
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    </script>
</body>
</html>
