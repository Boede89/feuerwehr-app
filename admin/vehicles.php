<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer
if (!is_logged_in()) {
    redirect('../login.php');
}

$message = '';
$error = '';

// Erfolgsmeldungen von GET-Parameter
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $message = "Fahrzeug wurde erfolgreich hinzugefügt.";
    } elseif ($_GET['success'] == 'updated') {
        $message = "Fahrzeug wurde erfolgreich aktualisiert.";
    }
}

// Fahrzeug hinzufügen/bearbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    
    // Debug: CSRF-Token prüfen
    if (isset($_GET['debug'])) {
        echo "<div class='alert alert-info'>";
        echo "<strong>CSRF-Token Debug:</strong><br>";
        echo "POST Token: " . ($_POST['csrf_token'] ?? 'NICHT GESETZT') . "<br>";
        echo "Session Token: " . ($_SESSION['csrf_token'] ?? 'NICHT GESETZT') . "<br>";
        echo "Token gültig: " . (validate_csrf_token($_POST['csrf_token'] ?? '') ? 'JA' : 'NEIN') . "<br>";
        echo "</div>";
    }
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $name = sanitize_input($_POST['name'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name)) {
            $error = "Name ist erforderlich.";
        } else {
            try {
                if ($action == 'add') {
                    $stmt = $db->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $description, $is_active]);
                    $message = "Fahrzeug wurde erfolgreich hinzugefügt.";
                    log_activity($_SESSION['user_id'], 'vehicle_added', "Fahrzeug '$name' hinzugefügt");
                    
                    // Weiterleitung um POST-Problem zu vermeiden
                    header("Location: vehicles.php?success=added");
                    exit();
                    
                } elseif ($action == 'edit') {
                    $stmt = $db->prepare("UPDATE vehicles SET name = ?, description = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $is_active, $vehicle_id]);
                    $message = "Fahrzeug wurde erfolgreich aktualisiert.";
                    log_activity($_SESSION['user_id'], 'vehicle_updated', "Fahrzeug '$name' aktualisiert");
                    
                    // Weiterleitung um POST-Problem zu vermeiden
                    header("Location: vehicles.php?success=updated");
                    exit();
                }
            } catch(PDOException $e) {
                $error = "Fehler beim Speichern des Fahrzeugs: " . $e->getMessage();
            }
        }
    }
}

// Fahrzeug löschen
if (isset($_GET['delete'])) {
    $vehicle_id = (int)$_GET['delete'];
    
    try {
        // Prüfen ob Fahrzeug in Reservierungen verwendet wird
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE vehicle_id = ?");
        $stmt->execute([$vehicle_id]);
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            $error = "Das Fahrzeug kann nicht gelöscht werden, da es in Reservierungen verwendet wird.";
        } else {
            $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            $message = "Fahrzeug wurde erfolgreich gelöscht.";
            log_activity($_SESSION['user_id'], 'vehicle_deleted', "Fahrzeug ID $vehicle_id gelöscht");
        }
    } catch(PDOException $e) {
        $error = "Fehler beim Löschen des Fahrzeugs: " . $e->getMessage();
    }
}

// Fahrzeuge laden
try {
    $stmt = $db->prepare("SELECT * FROM vehicles ORDER BY name");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    
    // Debug: Fahrzeuge anzeigen
    if (isset($_GET['debug'])) {
        echo "<div class='alert alert-info'>";
        echo "<strong>Debug Info:</strong><br>";
        echo "Anzahl Fahrzeuge: " . count($vehicles) . "<br>";
        foreach ($vehicles as $v) {
            echo "ID: {$v['id']}, Name: {$v['name']}, Aktiv: " . ($v['is_active'] ? 'Ja' : 'Nein') . "<br>";
        }
        echo "<br><strong>POST-Daten:</strong><br>";
        echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "<br>";
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            echo "POST-Daten: " . print_r($_POST, true) . "<br>";
        }
        echo "</div>";
    }
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Fahrzeuge: " . $e->getMessage();
    $vehicles = [];
}

// Fahrzeug für Bearbeitung laden
$edit_vehicle = null;
if (isset($_GET['edit'])) {
    $vehicle_id = (int)$_GET['edit'];
    try {
        $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $edit_vehicle = $stmt->fetch();
    } catch(PDOException $e) {
        $error = "Fehler beim Laden des Fahrzeugs: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fahrzeuge - Feuerwehr App</title>
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
                        <a class="nav-link active" href="vehicles.php">
                            <i class="fas fa-truck"></i> Fahrzeuge
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
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
                        <i class="fas fa-truck"></i> Fahrzeuge
                    </h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#vehicleModal">
                        <i class="fas fa-plus"></i> Neues Fahrzeug
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

        <!-- Fahrzeuge Tabelle -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Beschreibung</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($vehicle['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($vehicle['description']); ?></td>
                                            <td>
                                                <?php if ($vehicle['is_active']): ?>
                                                    <span class="badge bg-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo format_date($vehicle['created_at']); ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#vehicleModal"
                                                            data-vehicle-id="<?php echo $vehicle['id']; ?>"
                                                            data-vehicle-name="<?php echo htmlspecialchars($vehicle['name']); ?>"
                                                            data-vehicle-description="<?php echo htmlspecialchars($vehicle['description']); ?>"
                                                            data-vehicle-active="<?php echo $vehicle['is_active']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete=<?php echo $vehicle['id']; ?>" 
                                                       class="btn btn-outline-danger btn-sm"
                                                       onclick="return confirm('Sind Sie sicher, dass Sie dieses Fahrzeug löschen möchten?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
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

    <!-- Fahrzeug Modal -->
    <div class="modal fade" id="vehicleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="vehicleForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="vehicleModalTitle">Neues Fahrzeug</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Beschreibung</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    Aktiv
                                </label>
                            </div>
                        </div>
                        <input type="hidden" name="vehicle_id" id="vehicle_id">
                        <input type="hidden" name="action" id="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary" id="submitButton">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Warten bis DOM geladen ist
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM geladen, JavaScript wird initialisiert...');
            
            // Modal Event Listener
            const modal = document.getElementById('vehicleModal');
            if (modal) {
                modal.addEventListener('show.bs.modal', function (event) {
                    console.log('Modal wird geöffnet...');
                    const button = event.relatedTarget;
                    
                    if (button) {
                        // Bearbeitung
                        const vehicleId = button.getAttribute('data-vehicle-id');
                        const vehicleName = button.getAttribute('data-vehicle-name');
                        const vehicleDescription = button.getAttribute('data-vehicle-description');
                        const vehicleActive = button.getAttribute('data-vehicle-active');
                        
                        document.getElementById('vehicleModalTitle').textContent = 'Fahrzeug bearbeiten';
                        document.getElementById('vehicle_id').value = vehicleId;
                        document.getElementById('name').value = vehicleName;
                        document.getElementById('description').value = vehicleDescription;
                        document.getElementById('is_active').checked = vehicleActive == '1';
                        document.getElementById('action').value = 'edit';
                        document.getElementById('submitButton').textContent = 'Aktualisieren';
                    } else {
                        // Neues Fahrzeug
                        console.log('Neues Fahrzeug wird erstellt...');
                        document.getElementById('vehicleModalTitle').textContent = 'Neues Fahrzeug';
                        document.getElementById('vehicle_id').value = '';
                        document.getElementById('name').value = '';
                        document.getElementById('description').value = '';
                        document.getElementById('is_active').checked = true;
                        document.getElementById('action').value = 'add';
                        document.getElementById('submitButton').textContent = 'Hinzufügen';
                    }
                });
                console.log('Modal Event Listener hinzugefügt');
            } else {
                console.log('Modal nicht gefunden!');
            }
            
            // Debug: Formular-Absendung überwachen
            const form = document.getElementById('vehicleForm');
            if (form) {
                form.addEventListener('submit', function(event) {
                    console.log('Formular wird abgesendet!');
                    console.log('Action:', document.getElementById('action').value);
                    console.log('Name:', document.getElementById('name').value);
                    console.log('Description:', document.getElementById('description').value);
                    console.log('Is Active:', document.getElementById('is_active').checked);
                });
                console.log('Form Event Listener hinzugefügt');
            } else {
                console.log('Formular nicht gefunden!');
            }
            
            // Debug: Submit-Button klicken
            const submitBtn = document.getElementById('submitButton');
            if (submitBtn) {
                submitBtn.addEventListener('click', function(event) {
                    console.log('Submit-Button wurde geklickt!');
                    console.log('Formular wird abgesendet...');
                });
                console.log('Submit Button Event Listener hinzugefügt');
            } else {
                console.log('Submit Button nicht gefunden!');
            }
            
            console.log('Alle Event Listener initialisiert!');
        });
    </script>
</body>
</html>
