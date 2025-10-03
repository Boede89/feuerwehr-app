<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

$message = '';
$error = '';

// Fahrzeuge laden
$vehicles = [];
try {
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
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
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">
                                <i class="fas fa-sign-out-alt"></i> Abmelden
                            </a>
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
                            <div class="row g-4">
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <div class="col-lg-4 col-md-6">
                                        <div class="card h-100 vehicle-card" onclick="selectVehicle(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['name']); ?>', '<?php echo htmlspecialchars($vehicle['type']); ?>', <?php echo $vehicle['capacity']; ?>, '<?php echo htmlspecialchars($vehicle['description']); ?>')">
                                            <div class="card-body text-center p-4">
                                                <div class="vehicle-icon mb-3">
                                                    <i class="fas fa-truck text-primary"></i>
                                                </div>
                                                <h5 class="card-title"><?php echo htmlspecialchars($vehicle['name']); ?></h5>
                                                <p class="text-muted mb-2"><?php echo htmlspecialchars($vehicle['type']); ?></p>
                                                <p class="card-text small"><?php echo htmlspecialchars($vehicle['description']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück zur Startseite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectVehicle(vehicleId, vehicleName, vehicleType, capacity, description) {
            // Fahrzeugdaten in Session Storage speichern
            const vehicleData = {
                id: vehicleId,
                name: vehicleName,
                type: vehicleType,
                capacity: capacity,
                description: description
            };
            
            sessionStorage.setItem('selectedVehicle', JSON.stringify(vehicleData));
            
            // Weiterleitung zur Reservierungsseite
            window.location.href = 'reservation.php';
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
        }
        
        .vehicle-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
        }
        
        .vehicle-icon {
            font-size: 3rem;
        }
        
        .vehicle-icon i {
            display: inline-block;
            padding: 1rem;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #ff6b6b);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
    </style>
</body>
</html>
