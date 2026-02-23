<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/einheiten-setup.php';

$message = '';
$error = '';

// Einheit aus URL setzen (Session aktualisieren)
$einheit_id_url = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id_url > 0) {
    require_once __DIR__ . '/includes/einheiten-setup.php';
    if (is_logged_in() && !is_system_user()) {
        if (function_exists('user_has_einheit_access') && user_has_einheit_access($_SESSION['user_id'], $einheit_id_url)) {
            $_SESSION['current_einheit_id'] = $einheit_id_url;
        }
    } else {
        try {
            $stmt = $db->prepare("SELECT id FROM einheiten WHERE id = ? AND is_active = 1");
            $stmt->execute([$einheit_id_url]);
            if ($stmt->fetch()) $_SESSION['current_einheit_id'] = $einheit_id_url;
        } catch (Exception $e) {}
    }
}

// Fahrzeuge laden mit Sortierung (gefiltert nach Einheit)
$vehicles = [];
$einheit_filter = $einheit_id_url > 0 ? $einheit_id_url : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : null);
try {
    // Sortier-Modus aus Einstellungen laden
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'vehicle_sort_mode'");
    $stmt->execute();
    $sort_mode = $stmt->fetchColumn() ?: 'manual';
    
    // SQL-Query basierend auf Sortier-Modus
    switch ($sort_mode) {
        case 'name':
            $order_by = "ORDER BY name ASC";
            break;
        case 'created':
            $order_by = "ORDER BY created_at ASC";
            break;
        case 'manual':
        default:
            $order_by = "ORDER BY sort_order ASC, name ASC";
            break;
    }
    
    $sql = "SELECT * FROM vehicles WHERE is_active = 1";
    $params = [];
    if ($einheit_filter > 0) {
        $sql .= " AND (einheit_id = ? OR einheit_id IS NULL)";
        $params[] = $einheit_filter;
    }
    $sql .= " $order_by";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Fahrzeuge: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fahrzeug auswählen - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-home"></i> Startseite
                        </a>
                    </li>
                    <?php if (is_logged_in()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="admin/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">
                                <i class="fas fa-sign-in-alt"></i> Anmelden
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-truck"></i> Fahrzeug auswählen
                        </h3>
                        <p class="text-muted mb-0">Wählen Sie das Fahrzeug aus, das Sie reservieren möchten</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <?php echo show_error($error); ?>
                        <?php endif; ?>
                        
                        <?php if (empty($vehicles)): ?>
                            <div class="alert alert-warning text-center">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Keine Fahrzeuge verfügbar</strong><br>
                                Es sind derzeit keine Fahrzeuge zur Reservierung verfügbar.
                            </div>
                        <?php else: ?>
                            <div class="row justify-content-center g-4">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <div class="col-lg-4 col-md-6 col-sm-8 col-10">
                                        <div class="card h-100 vehicle-card shadow-sm" onclick="selectVehicle(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['name']); ?>', '<?php echo htmlspecialchars($vehicle['description']); ?>')">
                                            <div class="card-body text-center p-4">
                                                <div class="vehicle-icon mb-3">
                                                    <i class="fas fa-truck"></i>
                                                </div>
                                                <h5 class="card-title fw-bold"><?php echo htmlspecialchars($vehicle['name']); ?></h5>
                                                <p class="card-text text-muted"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                                                <div class="vehicle-action mt-3">
                                                    <span class="badge bg-primary">Auswählen</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center">
                        <a href="index.php<?php echo $einheit_filter > 0 ? '?einheit_id=' . (int)$einheit_filter : ''; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück zur Startseite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const einheitId = <?php echo json_encode($einheit_filter > 0 ? (int)$einheit_filter : null); ?>;
        function selectVehicle(vehicleId, vehicleName, description) {
            console.log('🔍 Fahrzeug ausgewählt:', {id: vehicleId, name: vehicleName, description: description});
            
            // Fahrzeugdaten in Session Storage speichern
            const vehicleData = {
                id: vehicleId,
                name: vehicleName,
                description: description
            };
            
            sessionStorage.setItem('selectedVehicle', JSON.stringify(vehicleData));
            console.log('✅ Fahrzeug in SessionStorage gespeichert:', vehicleData);
            
            // Prüfe ob SessionStorage funktioniert
            const stored = sessionStorage.getItem('selectedVehicle');
            console.log('🔍 SessionStorage Inhalt:', stored);
            
            // Weiterleitung zur Reservierungsseite (mit einheit_id falls ausgewählt)
            const resUrl = 'reservation.php' + (einheitId ? '?einheit_id=' + einheitId : '');
            console.log('🔄 Weiterleitung zu', resUrl);
            window.location.href = resUrl;
        }
        
        // Hover-Effekt für Fahrzeugkarten
        document.querySelectorAll('.vehicle-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.15)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            });
        });
    </script>
    
    <style>
        .vehicle-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            border-radius: 15px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .vehicle-card:hover {
            border-color: #0d6efd;
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1) !important;
        }
        
        .vehicle-icon {
            font-size: 3.5rem;
            margin-bottom: 1rem;
        }
        
        .vehicle-icon i {
            display: inline-block;
            padding: 1.2rem;
            border-radius: 50%;
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            color: white;
            box-shadow: 0 8px 25px rgba(13, 110, 253, 0.3);
            transition: all 0.3s ease;
        }
        
        .vehicle-card:hover .vehicle-icon i {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(13, 110, 253, 0.4);
        }
        
        .vehicle-action {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .vehicle-card:hover .vehicle-action {
            opacity: 1;
        }
        
        .card-title {
            color: #212529;
            margin-bottom: 0.75rem;
        }
        
        .card-text {
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        /* Responsive Anpassungen */
        @media (max-width: 768px) {
            .vehicle-icon {
                font-size: 3rem;
            }
            
            .vehicle-icon i {
                padding: 1rem;
            }
        }
        
        /* Zentrierte Darstellung für weniger Fahrzeuge */
        .row.justify-content-center {
            justify-content: center !important;
        }
        
        /* Mindestbreite für Fahrzeugkarten */
        .vehicle-card {
            min-width: 280px;
        }
    </style>
</body>
</html>
