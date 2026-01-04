<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Lehrgangsverwaltungs-Berechtigung hat
if (!has_permission('courses')) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Erfolgsmeldung anzeigen
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'course_assigned':
            $message = "Lehrgang wurde erfolgreich bei den Mitgliedern hinterlegt.";
            break;
    }
}

// Tabellen sicherstellen
try {
    // Courses Tabelle
    $db->exec("
        CREATE TABLE IF NOT EXISTS courses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // Course Requirements Tabelle
    $db->exec("
        CREATE TABLE IF NOT EXISTS course_requirements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            required_course_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY (required_course_id) REFERENCES courses(id) ON DELETE CASCADE,
            UNIQUE KEY unique_course_requirement (course_id, required_course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // Member Courses Tabelle
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_courses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            course_id INT NOT NULL,
            completed_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            UNIQUE KEY unique_member_course (member_id, course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    error_log("Fehler beim Erstellen der Tabellen: " . $e->getMessage());
}

// Lehrgang hinterlegen (POST-Handler)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_course'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        try {
            $db->beginTransaction();
            
            $course_id = (int)($_POST['course_id'] ?? 0);
            $member_ids = isset($_POST['member_ids']) ? array_map('intval', $_POST['member_ids']) : [];
            $completion_year = (int)($_POST['completion_year'] ?? 0);
            
            error_log("=== POST assign_course ===");
            error_log("POST course_id (raw): " . var_export($_POST['course_id'] ?? 'NOT SET', true));
            error_log("POST course_id (int): $course_id");
            error_log("POST member_ids (raw): " . var_export($_POST['member_ids'] ?? 'NOT SET', true));
            error_log("POST member_ids (processed): " . print_r($member_ids, true));
            error_log("POST completion_year (raw): " . var_export($_POST['completion_year'] ?? 'NOT SET', true));
            error_log("POST completion_year (int): $completion_year");
            
            if ($course_id <= 0) {
                $error = "Bitte wählen Sie einen Lehrgang aus.";
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            } elseif (empty($member_ids)) {
                $error = "Bitte wählen Sie mindestens ein Mitglied aus.";
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            } elseif ($completion_year < 1950 || $completion_year > (int)date('Y')) {
                $error = "Bitte wählen Sie ein gültiges Abschlussjahr aus.";
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            } else {
                // Jahr als DATE speichern (1. Januar des Jahres)
                $completed_date = $completion_year . '-01-01';
                $stmt = $db->prepare("INSERT INTO member_courses (member_id, course_id, completed_date) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE completed_date = ?");
                $inserted_count = 0;
                $error_count = 0;
                foreach ($member_ids as $member_id) {
                    if ($member_id > 0) {
                        try {
                            error_log("Versuche INSERT: member_id=$member_id, course_id=$course_id, completed_date=$completed_date");
                            $stmt->execute([$member_id, $course_id, $completed_date, $completed_date]);
                            $inserted_count++;
                            error_log("✓ Erfolgreich: Lehrgang $course_id wurde Mitglied $member_id zugewiesen (Jahr: $completion_year)");
                        } catch (Exception $e) {
                            $error_count++;
                            error_log("✗ Fehler beim Zuweisen des Lehrgangs $course_id an Mitglied $member_id: " . $e->getMessage());
                        }
                    }
                }
                
                if ($inserted_count > 0) {
                    $db->commit();
                    $message = "Lehrgang wurde erfolgreich bei $inserted_count Mitglied(ern) hinterlegt.";
                    header("Location: courses.php?success=course_assigned");
                    exit();
                } else {
                    $db->rollBack();
                    $error = "Fehler: Keine Lehrgänge konnten zugewiesen werden.";
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Fehler beim Hinterlegen des Lehrgangs: " . $e->getMessage();
            error_log("Fehler beim Hinterlegen des Lehrgangs: " . $e->getMessage());
        }
    }
}

// Lehrgänge laden
$courses = [];
try {
    $stmt = $db->query("SELECT id, name, description FROM courses ORDER BY name");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgänge: " . $e->getMessage());
}

// Aktive Ansicht bestimmen
$view = $_GET['view'] ?? 'list'; // 'list', 'assign', 'planning'
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lehrgangsverwaltung - Feuerwehr App</title>
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
                <h1 class="mb-4">
                    <i class="fas fa-graduation-cap"></i> Lehrgangsverwaltung
                </h1>
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="row g-2 mb-4">
            <div class="col-12 col-md-4">
                <a href="courses.php?view=list" class="btn <?php echo $view === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?> w-100">
                    <i class="fas fa-list"></i> Liste anzeigen
                </a>
            </div>
            <div class="col-12 col-md-4">
                <a href="courses.php?view=assign" class="btn <?php echo $view === 'assign' ? 'btn-success' : 'btn-outline-success'; ?> w-100">
                    <i class="fas fa-plus-circle"></i> Lehrgang hinterlegen
                </a>
            </div>
            <div class="col-12 col-md-4">
                <a href="courses.php?view=planning" class="btn <?php echo $view === 'planning' ? 'btn-info' : 'btn-outline-info'; ?> w-100">
                    <i class="fas fa-calendar-alt"></i> Lehrgangsplanung
                </a>
            </div>
        </div>

        <!-- Meldungen -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Inhalt je nach Ansicht -->
        <?php if ($view === 'list'): ?>
            <?php include 'courses-list.php'; ?>
        <?php elseif ($view === 'assign'): ?>
            <?php include 'courses-assign.php'; ?>
        <?php elseif ($view === 'planning'): ?>
            <?php include 'courses-planning.php'; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

