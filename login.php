<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Bereits eingeloggt? Weiterleitung zum Dashboard
if (is_logged_in()) {
    redirect('admin/dashboard.php');
}

$error = '';
$success_message = '';

// Prüfe ob Zugriff verweigert wurde
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    $error = "Zugriff verweigert. Sie müssen als Administrator angemeldet sein, um das Dashboard zu verwenden.";
}

// Passwort vergessen
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $email = sanitize_input($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Bitte geben Sie Ihre E-Mail-Adresse ein.";
    } else {
        try {
            // Prüfe ob E-Mail existiert
            $stmt = $db->prepare("SELECT id, username, email, first_name, last_name FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $error = "Diese E-Mail-Adresse existiert nicht im System.";
            } else {
                // Neues Passwort generieren (4 Zufallszahlen)
                $new_password = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $password_hash = hash_password($new_password);
                
                // Passwort in Datenbank aktualisieren
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $user['id']]);
                
                // E-Mail senden
                $email_subject = 'Ihr Passwort wurde zurückgesetzt';
                $email_body = '
                <html>
                <head>
                    <meta charset="UTF-8">
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                        .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                        .credentials { background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #dc3545; }
                        .credentials strong { color: #dc3545; }
                        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>Passwort zurückgesetzt</h1>
                        </div>
                        <div class="content">
                            <p>Hallo ' . htmlspecialchars($user['first_name']) . ',</p>
                            <p>Sie haben ein neues Passwort angefordert. Sie können sich nun mit folgenden Zugangsdaten anmelden:</p>
                            <div class="credentials">
                                <p><strong>Benutzername:</strong> ' . htmlspecialchars($user['username']) . '</p>
                                <p><strong>Neues Passwort:</strong> ' . htmlspecialchars($new_password) . '</p>
                            </div>
                            <p style="text-align: center; margin: 30px 0;">
                                <a href="https://feuerwehr.boede89.selfhost.co/" style="display: inline-block; background-color: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Zur Startseite</a>
                            </p>
                            <p>Bitte ändern Sie Ihr Passwort nach dem Login für mehr Sicherheit.</p>
                            <p>Bei Fragen wenden Sie sich bitte an den Administrator.</p>
                        </div>
                        <div class="footer">
                            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
                        </div>
                    </div>
                </body>
                </html>';
                
                if (send_email($user['email'], $email_subject, $email_body, '', true)) {
                    $success_message = "Ein neues Passwort wurde generiert und an Ihre E-Mail-Adresse gesendet.";
                    log_activity($user['id'], 'password_reset', "Passwort über 'Passwort vergessen' zurückgesetzt");
                } else {
                    $error = "Passwort wurde zurückgesetzt, aber die E-Mail konnte nicht gesendet werden. Bitte kontaktieren Sie den Administrator.";
                    error_log("Fehler beim Senden der Passwort-Reset-E-Mail an: " . $email);
                }
            }
        } catch(PDOException $e) {
            $error = "Fehler beim Zurücksetzen des Passworts: " . $e->getMessage();
            error_log("Fehler beim Passwort-Reset: " . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'forgot_password')) {
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
                            <div class="text-center mt-3">
                                <a href="#" class="text-muted text-decoration-none" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                                    <i class="fas fa-question-circle"></i> Passwort vergessen?
                                </a>
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

    <!-- Passwort vergessen Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">
                        <i class="fas fa-key me-2"></i>Passwort vergessen
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="forgot_password">
                    <div class="modal-body">
                        <p>Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen ein neues Passwort zu.</p>
                        <div class="mb-3">
                            <label for="forgot_email" class="form-label">E-Mail-Adresse</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" class="form-control" id="forgot_email" name="email" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Abbrechen
                        </button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-paper-plane me-1"></i>Passwort zurücksetzen
                        </button>
                    </div>
                </form>
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
