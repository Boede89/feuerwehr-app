<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

// Lade aktuellen Benutzer
try {
    $stmt = $db->prepare('SELECT id, username, email, first_name, last_name, password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $error = 'Benutzer nicht gefunden.';
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden des Profils: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'update_email') {
                $new_email = trim($_POST['email'] ?? '');
                if (empty($new_email) || !validate_email($new_email)) {
                    $error = 'Bitte eine gültige E-Mail-Adresse eingeben.';
                } else {
                    $stmt = $db->prepare('UPDATE users SET email = ? WHERE id = ?');
                    $stmt->execute([$new_email, $user['id']]);
                    $_SESSION['email'] = $new_email;
                    $message = 'E-Mail-Adresse wurde aktualisiert.';
                    $user['email'] = $new_email;
                }
            } elseif ($action === 'update_password') {
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $error = 'Bitte alle Passwortfelder ausfüllen.';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'Neue Passwörter stimmen nicht überein.';
                } elseif (strlen($new_password) < 4) {
                    $error = 'Das neue Passwort muss mindestens 4 Zeichen lang sein.';
                } elseif (!verify_password($current_password, $user['password_hash'])) {
                    $error = 'Aktuelles Passwort ist falsch.';
                } else {
                    $new_hash = hash_password($new_password);
                    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([$new_hash, $user['id']]);
                    $message = 'Passwort wurde aktualisiert.';
                }
            }
        } catch (Exception $e) {
            $error = 'Fehler beim Aktualisieren: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link active" href="profile.php"><i class="fas fa-user"></i> Profil</a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
            </ul>
        </div>
    </div>
    </nav>

<div class="container mt-4">
    <h1 class="h3 mb-4"><i class="fas fa-user"></i> Mein Profil</h1>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-at"></i> E-Mail-Adresse</div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Aktuelle E-Mail</label>
                            <input class="form-control" type="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" name="email" required>
                        </div>
                        <input type="hidden" name="action" value="update_email">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-lock"></i> Passwort ändern</div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Aktuelles Passwort</label>
                            <input class="form-control" type="password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Neues Passwort (min. 4 Zeichen)</label>
                            <input class="form-control" type="password" name="new_password" minlength="4" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Neues Passwort bestätigen</label>
                            <input class="form-control" type="password" name="confirm_password" minlength="4" required>
                        </div>
                        <input type="hidden" name="action" value="update_password">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-key"></i> Passwort aktualisieren</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


