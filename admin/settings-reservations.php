<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!is_logged_in()) {
    redirect('../login.php');
}

// Prüfe Admin-Berechtigung
if (!hasAdminPermission()) {
    redirect('../index.php');
}

$message = '';
$error = '';

// Sicherstellen, dass email_notifications Spalte existiert
try {
    $db->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}

// E-Mail-Benachrichtigungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notifications'])) {
    try {
        $db->beginTransaction();
        
        // Benutzer für E-Mail-Benachrichtigungen speichern
        $notification_users = $_POST['notification_users'] ?? [];
        
        // Alle Benutzer auf 0 setzen
        $stmt = $db->prepare("UPDATE users SET email_notifications = 0");
        $stmt->execute();
        
        // Ausgewählte Benutzer auf 1 setzen
        if (!empty($notification_users)) {
            $placeholders = str_repeat('?,', count($notification_users) - 1) . '?';
            $stmt = $db->prepare("UPDATE users SET email_notifications = 1 WHERE id IN ($placeholders)");
            $stmt->execute($notification_users);
        }
        
        $db->commit();
        $message = "E-Mail-Benachrichtigungseinstellungen erfolgreich gespeichert.";
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Fehler beim Speichern: " . $e->getMessage();
    }
}

// Alle Benutzer laden
try {
    $stmt = $db->query("SELECT id, first_name, last_name, email, user_role, email_notifications FROM users WHERE is_active = 1 ORDER BY first_name, last_name");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Fehler beim Laden der Benutzer: " . $e->getMessage();
    $users = [];
}

// Aktuelle E-Mail-Benachrichtigungseinstellungen laden
$notification_users = [];
try {
    $stmt = $db->query("SELECT id FROM users WHERE email_notifications = 1 AND is_active = 1");
    $notification_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Ignoriere Fehler
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fahrzeugreservierung - Einstellungen | Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
        }
        .btn-primary {
            background: linear-gradient(135deg, #007bff, #0056b3);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #0056b3, #004085);
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-fire me-2"></i>Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reservations.php">
                            <i class="fas fa-calendar-alt me-1"></i>Reservierungen
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-1"></i>Einstellungen
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-1"></i>Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-calendar-alt me-2"></i>Fahrzeugreservierung - Einstellungen
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- E-Mail-Benachrichtigungen -->
                        <form method="POST" action="">
                            <div class="card mb-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-envelope me-2"></i>E-Mail-Benachrichtigungen
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        Wählen Sie die Benutzer aus, die per E-Mail über neue Fahrzeugreservierungen benachrichtigt werden sollen.
                                    </p>
                                    
                                    <div class="row">
                                        <?php foreach ($users as $user): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="notification_users[]" 
                                                           value="<?php echo htmlspecialchars($user['id']); ?>"
                                                           id="user_<?php echo htmlspecialchars($user['id']); ?>"
                                                           <?php echo in_array($user['id'], $notification_users) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="user_<?php echo htmlspecialchars($user['id']); ?>">
                                                        <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($user['email']); ?>
                                                            <span class="badge bg-<?php echo $user['user_role'] === 'admin' ? 'danger' : 'primary'; ?> ms-1">
                                                                <?php echo htmlspecialchars(ucfirst($user['user_role'])); ?>
                                                            </span>
                                                        </small>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <?php if (empty($users)): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>Keine Benutzer gefunden.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Zurück zum Dashboard
                                </a>
                                <button type="submit" name="save_notifications" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Einstellungen speichern
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
