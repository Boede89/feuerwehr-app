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

// Tabellen erstellen
try {
    // Lehrgänge Tabelle
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
    
    // Lehrgangsanforderungen Tabelle (welche Lehrgänge sind Voraussetzung für einen anderen)
    $db->exec("
        CREATE TABLE IF NOT EXISTS course_requirements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            required_course_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY (required_course_id) REFERENCES courses(id) ON DELETE CASCADE,
            UNIQUE KEY unique_requirement (course_id, required_course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    error_log("Fehler beim Erstellen der Lehrgangs-Tabellen: " . $e->getMessage());
}

// Lehrgang hinzufügen/bearbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $name = sanitize_input($_POST['name'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $requirements = $_POST['requirements'] ?? [];
        
        if (empty($name)) {
            $error = "Bitte geben Sie einen Lehrgangsnamen ein.";
        } else {
            try {
                $db->beginTransaction();
                
                // Prüfe ob Name bereits existiert (nur beim Hinzufügen)
                if ($action == 'add') {
                    $stmt_check = $db->prepare("SELECT id FROM courses WHERE name = ?");
                    $stmt_check->execute([$name]);
                    if ($stmt_check->fetch()) {
                        $error = "Ein Lehrgang mit diesem Namen existiert bereits.";
                        $db->rollBack();
                    } else {
                        $stmt = $db->prepare("INSERT INTO courses (name, description) VALUES (?, ?)");
                        $stmt->execute([$name, $description]);
                        $course_id = $db->lastInsertId();
                        $message = "Lehrgang wurde erfolgreich hinzugefügt.";
                    }
                } elseif ($action == 'edit') {
                    // Prüfe ob Name bereits von einem anderen Lehrgang verwendet wird
                    $stmt_check = $db->prepare("SELECT id FROM courses WHERE name = ? AND id != ?");
                    $stmt_check->execute([$name, $course_id]);
                    if ($stmt_check->fetch()) {
                        $error = "Ein Lehrgang mit diesem Namen existiert bereits.";
                        $db->rollBack();
                    } else {
                        $stmt = $db->prepare("UPDATE courses SET name = ?, description = ? WHERE id = ?");
                        $stmt->execute([$name, $description, $course_id]);
                        $message = "Lehrgang wurde erfolgreich aktualisiert.";
                    }
                }
                
                // Nur weiter machen wenn kein Fehler aufgetreten ist
                if (empty($error)) {
                
                    // Anforderungen aktualisieren
                    if ($course_id) {
                        // Alte Anforderungen löschen
                        $stmt = $db->prepare("DELETE FROM course_requirements WHERE course_id = ?");
                        $stmt->execute([$course_id]);
                        
                        // Neue Anforderungen hinzufügen
                        if (!empty($requirements) && is_array($requirements)) {
                            $stmt = $db->prepare("INSERT INTO course_requirements (course_id, required_course_id) VALUES (?, ?)");
                            foreach ($requirements as $req_id) {
                                $req_id = (int)$req_id;
                                if ($req_id > 0 && $req_id != $course_id) { // Verhindere Selbstreferenz
                                    try {
                                        $stmt->execute([$course_id, $req_id]);
                                    } catch (Exception $e) {
                                        // Duplikat ignorieren
                                        error_log("Fehler beim Einfügen der Anforderung: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                    
                    $db->commit();
                    header("Location: settings-courses.php?success=" . ($action == 'add' ? 'added' : 'updated'));
                    exit();
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Fehler: " . $e->getMessage();
                error_log("Fehler beim Speichern des Lehrgangs: " . $e->getMessage());
            }
        }
    }
}

// Lehrgang löschen
if (isset($_GET['delete'])) {
    $course_id = (int)$_GET['delete'];
    if (validate_csrf_token($_GET['csrf_token'] ?? '')) {
        try {
            $stmt = $db->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);
            $message = "Lehrgang wurde erfolgreich gelöscht.";
            header("Location: settings-courses.php?success=deleted");
            exit();
        } catch (Exception $e) {
            $error = "Fehler beim Löschen: " . $e->getMessage();
            error_log("Fehler beim Löschen des Lehrgangs: " . $e->getMessage());
        }
    }
}

// Erfolgsmeldung anzeigen
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Lehrgang wurde erfolgreich hinzugefügt.";
            break;
        case 'updated':
            $message = "Lehrgang wurde erfolgreich aktualisiert.";
            break;
        case 'deleted':
            $message = "Lehrgang wurde erfolgreich gelöscht.";
            break;
    }
}

// Lehrgänge laden
$courses = [];
try {
    $stmt = $db->prepare("SELECT id, name, description, created_at, updated_at FROM courses ORDER BY name ASC");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Anforderungen für jeden Lehrgang laden
    foreach ($courses as $key => $course) {
        $stmt_req = $db->prepare("
            SELECT cr.required_course_id, c.name 
            FROM course_requirements cr
            JOIN courses c ON c.id = cr.required_course_id
            WHERE cr.course_id = ?
        ");
        $stmt_req->execute([$course['id']]);
        $courses[$key]['requirements'] = $stmt_req->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgänge: " . $e->getMessage());
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lehrgangsverwaltung - Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .badge-purple {
            background-color: #6f42c1;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">Einstellungen</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">Abmelden</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-graduation-cap"></i> Lehrgangsverwaltung - Einstellungen
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-plus-circle"></i> Lehrgang hinzufügen
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="add" id="action">
                            <input type="hidden" name="course_id" value="" id="course_id">
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Lehrgangsname *</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Beschreibung</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="requirements" class="form-label">Anforderungen (Voraussetzungen)</label>
                                <select class="form-select" id="requirements" name="requirements[]" multiple size="5">
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Halten Sie Strg (Windows) oder Cmd (Mac) gedrückt, um mehrere Lehrgänge auszuwählen.</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" id="submitButton">
                                <i class="fas fa-save"></i> Hinzufügen
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                <i class="fas fa-times"></i> Abbrechen
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Vorhandene Lehrgänge
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($courses)): ?>
                            <p class="text-muted">Noch keine Lehrgänge definiert.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Beschreibung</th>
                                            <th>Anforderungen</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($courses as $course): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($course['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($course['description'] ?? ''); ?></td>
                                                <td>
                                                    <?php if (!empty($course['requirements'])): ?>
                                                        <?php foreach ($course['requirements'] as $req): ?>
                                                            <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($req['name']); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Keine</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="editCourse(<?php echo $course['id']; ?>, <?php echo json_encode($course['name']); ?>, <?php echo json_encode($course['description'] ?? ''); ?>, [<?php echo !empty($course['requirements']) ? implode(',', array_column($course['requirements'], 'required_course_id')) : ''; ?>])">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete=<?php echo $course['id']; ?>&csrf_token=<?php echo $csrf_token; ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Möchten Sie diesen Lehrgang wirklich löschen?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Formular zurücksetzen wenn Seite mit success-Parameter geladen wird
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success')) {
                resetForm();
            }
        });
        
        function editCourse(id, name, description, requirementIds) {
            document.getElementById('action').value = 'edit';
            document.getElementById('course_id').value = id;
            document.getElementById('name').value = name || '';
            document.getElementById('description').value = description || '';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-save"></i> Aktualisieren';
            document.getElementById('submitButton').classList.remove('btn-primary');
            document.getElementById('submitButton').classList.add('btn-success');
            
            // Anforderungen auswählen
            const requirementsSelect = document.getElementById('requirements');
            if (requirementsSelect && requirementIds && requirementIds.length > 0) {
                for (let i = 0; i < requirementsSelect.options.length; i++) {
                    const optionValue = parseInt(requirementsSelect.options[i].value);
                    requirementsSelect.options[i].selected = requirementIds.includes(optionValue);
                }
            } else {
                // Alle zurücksetzen wenn keine Anforderungen
                for (let i = 0; i < requirementsSelect.options.length; i++) {
                    requirementsSelect.options[i].selected = false;
                }
            }
            
            // Zum Formular scrollen
            document.querySelector('.card.shadow').scrollIntoView({ behavior: 'smooth' });
        }
        
        function resetForm() {
            document.getElementById('action').value = 'add';
            document.getElementById('course_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('description').value = '';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-save"></i> Hinzufügen';
            document.getElementById('submitButton').classList.remove('btn-success');
            document.getElementById('submitButton').classList.add('btn-primary');
            
            // Anforderungen zurücksetzen
            const requirementsSelect = document.getElementById('requirements');
            if (requirementsSelect) {
                for (let i = 0; i < requirementsSelect.options.length; i++) {
                    requirementsSelect.options[i].selected = false;
                }
            }
        }
        
        // Formular-Reset nach erfolgreichem Submit
        document.querySelector('form')?.addEventListener('submit', function() {
            // Formular wird nach Redirect automatisch zurückgesetzt durch DOMContentLoaded
        });
    </script>
</body>
</html>

