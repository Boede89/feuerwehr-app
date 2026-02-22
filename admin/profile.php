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

// Lade aktuellen Benutzer (inkl. Divera Access Key für Termin-Übergabe bei Reservierungs-Genehmigung)
try {
    $stmt = $db->prepare('SELECT id, username, email, first_name, last_name, password_hash, divera_access_key FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $error = 'Benutzer nicht gefunden.';
    }
} catch (Exception $e) {
    $user = [];
    if (strpos($e->getMessage(), 'divera_access_key') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        try {
            $db->exec('ALTER TABLE users ADD COLUMN divera_access_key VARCHAR(512) NULL DEFAULT NULL');
            $stmt = $db->prepare('SELECT id, username, email, first_name, last_name, password_hash, divera_access_key FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($user)) $error = 'Benutzer nicht gefunden.';
        } catch (Exception $e2) {
            $error = 'Fehler beim Laden des Profils: ' . $e2->getMessage();
        }
    } else {
        $error = 'Fehler beim Laden des Profils: ' . $e->getMessage();
    }
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
            } elseif ($action === 'update_divera_key') {
                // Key bereinigen: Trim + unsichtbare Zeichen (z. B. beim Kopieren) entfernen
                $new_key = trim((string) ($_POST['divera_access_key'] ?? ''));
                $new_key = preg_replace('/[\r\n\t\v]+/', '', $new_key);
                // Leer = nicht ändern (Key beibehalten oder bewusst leer lassen)
                if ($new_key !== '') {
                    $stmt = $db->prepare('UPDATE users SET divera_access_key = ? WHERE id = ?');
                    $stmt->execute([$new_key, $user['id']]);
                    $user['divera_access_key'] = $new_key;
                    $message = 'Divera Access Key wurde gespeichert (' . strlen($new_key) . ' Zeichen).';
                } else {
                    $message = 'Kein neuer Key eingegeben – bisheriger Key unverändert.';
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
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
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
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-calendar-check"></i> Divera 24/7 Access Key</div>
                <div class="card-body">
                    <p class="text-muted small">Wenn Sie Fahrzeugreservierungen genehmigen, wird der Termin mit Ihrem persönlichen Access Key an Divera 24/7 übermittelt. Ohne Key erscheint beim Genehmigen ein Hinweis.</p>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Divera Access Key (persönlich)</label>
                            <input class="form-control" type="password" name="divera_access_key" value="" placeholder="<?php echo !empty($user['divera_access_key'] ?? '') ? 'Leer lassen zum Beibehalten' : 'Key eintragen'; ?>" autocomplete="off">
                            <small class="text-muted">In Divera 24/7: Einstellungen → Debug-Tab → Benutzer-Accesskey. Key ohne Leerzeichen/Zeilenumbrüche eintragen. Leer lassen = bisherigen Key beibehalten. Ohne Key wird der Einheits-Key aus den Divera-Einstellungen verwendet.</small>
                        </div>
                        <input type="hidden" name="action" value="update_divera_key">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


