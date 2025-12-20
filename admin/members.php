<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Admin-Berechtigung hat
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Mitglieder laden
$members = [];
try {
    // Tabelle sicherstellen mit user_id Verknüpfung
    $db->exec(
        "CREATE TABLE IF NOT EXISTS members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NULL,
            birthdate DATE NULL,
            phone VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );
    
    // user_id Spalte hinzufügen falls nicht vorhanden
    try {
        $db->exec("ALTER TABLE members ADD COLUMN user_id INT NULL");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    
    // Unique Key hinzufügen falls nicht vorhanden
    try {
        $db->exec("ALTER TABLE members ADD UNIQUE KEY unique_user_id (user_id)");
    } catch (Exception $e) {
        // Key existiert bereits, ignoriere Fehler
    }
    
    // Foreign Key hinzufügen falls nicht vorhanden
    try {
        // Prüfe ob Foreign Key bereits existiert
        $stmt = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'");
        if (!$stmt->fetch()) {
            $db->exec("ALTER TABLE members ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        }
    } catch (Exception $e) {
        // Foreign Key existiert bereits oder Fehler, ignoriere
    }
    
    // Bestehende Benutzer synchronisieren (erstelle Mitglieder für Benutzer die noch kein Mitglied haben)
    try {
        $stmt = $db->query("
            INSERT INTO members (user_id, first_name, last_name, email)
            SELECT u.id, u.first_name, u.last_name, u.email
            FROM users u
            WHERE u.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM members m WHERE m.user_id = u.id)
        ");
    } catch (Exception $e) {
        // Fehler ignorieren
    }
    
    // Alle Mitglieder laden: Benutzer aus users + zusätzliche Mitglieder aus members
    // Zuerst alle Benutzer als Mitglieder
    $stmt = $db->query("
        SELECT 
            u.id as user_id,
            u.first_name,
            u.last_name,
            u.email,
            NULL as birthdate,
            NULL as phone,
            u.created_at,
            'user' as source
        FROM users u
        WHERE u.is_active = 1
        ORDER BY u.last_name, u.first_name
    ");
    $user_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Dann zusätzliche Mitglieder (ohne user_id Verknüpfung)
    $stmt = $db->query("
        SELECT 
            NULL as user_id,
            first_name,
            last_name,
            email,
            birthdate,
            phone,
            created_at,
            'member' as source
        FROM members
        WHERE user_id IS NULL
        ORDER BY last_name, first_name
    ");
    $additional_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Kombiniere beide Listen
    $members = array_merge($user_members, $additional_members);
    
    // Sortiere nach Nachname, dann Vorname
    usort($members, function($a, $b) {
        $cmp = strcmp($a['last_name'], $b['last_name']);
        if ($cmp === 0) {
            return strcmp($a['first_name'], $b['first_name']);
        }
        return $cmp;
    });
    
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Mitglieder: ' . $e->getMessage();
}

// Mitglied hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_member') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($first_name) || empty($last_name)) {
            $error = 'Bitte geben Sie Vorname und Nachname ein.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO members (first_name, last_name, email, birthdate, phone) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $first_name,
                    $last_name,
                    !empty($email) ? $email : null,
                    !empty($birthdate) ? $birthdate : null,
                    !empty($phone) ? $phone : null
                ]);
                $message = 'Mitglied wurde erfolgreich hinzugefügt.';
                
                // Mitglieder neu laden
                $stmt = $db->query("SELECT * FROM members ORDER BY last_name, first_name");
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        }
    }
}

// Aktuelle Liste anzeigen (Toggle)
$show_list = isset($_GET['show_list']) && $_GET['show_list'] == '1';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitgliederverwaltung - Feuerwehr App</title>
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
                    <i class="fas fa-users"></i> Mitgliederverwaltung
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Aktions-Buttons -->
        <div class="row mb-4">
            <div class="col-12 col-md-6 mb-2">
                <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    <i class="fas fa-user-plus"></i> Mitglied hinzufügen
                </button>
            </div>
            <div class="col-12 col-md-6 mb-2">
                <a href="?show_list=<?php echo $show_list ? '0' : '1'; ?>" class="btn btn-outline-primary w-100">
                    <i class="fas fa-list"></i> <?php echo $show_list ? 'Liste ausblenden' : 'Aktuelle Liste anzeigen'; ?>
                </a>
            </div>
        </div>

        <!-- Mitglieder-Liste -->
        <?php if ($show_list): ?>
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-users"></i> Aktuelle Mitgliederliste
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
                                            <th>Vorname</th>
                                            <th>Nachname</th>
                                            <th>E-Mail</th>
                                            <th>Geburtsdatum</th>
                                            <th>Telefon</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($members as $member): ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($member['first_name']); ?>
                                                <?php if ($member['source'] ?? '' === 'user'): ?>
                                                    <span class="badge bg-primary ms-2" title="Benutzer des Systems">Benutzer</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                            <td><?php echo $member['birthdate'] ? date('d.m.Y', strtotime($member['birthdate'])) : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($member['phone'] ?? '-'); ?></td>
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
    </div>

    <!-- Mitglied hinzufügen Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i> Mitglied hinzufügen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <?php echo generate_csrf_token(); ?>
                    <input type="hidden" name="action" value="add_member">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-user me-1"></i>Vorname <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-user me-1"></i>Nachname <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-envelope me-1"></i>E-Mail (optional)
                                </label>
                                <input type="email" class="form-control" name="email">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="fas fa-calendar me-1"></i>Geburtsdatum (optional)
                                </label>
                                <input type="date" class="form-control" name="birthdate">
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    <i class="fas fa-phone me-1"></i>Telefon (optional)
                                </label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Abbrechen
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modal zurücksetzen beim Schließen
        document.getElementById('addMemberModal').addEventListener('hidden.bs.modal', function() {
            const form = this.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    </script>
</body>
</html>

