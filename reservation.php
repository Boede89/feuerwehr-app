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

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($csrf_token)) {
        $error = "Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.";
    } else {
        $vehicle_id = sanitize_input($_POST['vehicle_id'] ?? '');
        $requester_name = sanitize_input($_POST['requester_name'] ?? '');
        $requester_email = sanitize_input($_POST['requester_email'] ?? '');
        $reason = sanitize_input($_POST['reason'] ?? '');
        $start_datetime = sanitize_input($_POST['start_datetime'] ?? '');
        $end_datetime = sanitize_input($_POST['end_datetime'] ?? '');
        
        // Validierung
        if (empty($vehicle_id) || empty($requester_name) || empty($requester_email) || empty($reason) || empty($start_datetime) || empty($end_datetime)) {
            $error = "Bitte füllen Sie alle Felder aus.";
        } elseif (!validate_email($requester_email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } elseif (!validate_datetime($start_datetime) || !validate_datetime($end_datetime)) {
            $error = "Bitte geben Sie gültige Datum und Uhrzeit ein.";
        } elseif (strtotime($start_datetime) >= strtotime($end_datetime)) {
            $error = "Das Enddatum muss nach dem Startdatum liegen.";
        } elseif (strtotime($start_datetime) < time()) {
            $error = "Das Startdatum darf nicht in der Vergangenheit liegen.";
        } else {
            // Kollisionsprüfung
            if (check_vehicle_conflict($vehicle_id, $start_datetime, $end_datetime)) {
                $error = "Das ausgewählte Fahrzeug ist in diesem Zeitraum bereits reserviert.";
            } else {
                // Reservierung speichern
                try {
                    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $start_datetime, $end_datetime]);
                    
                    $reservation_id = $db->lastInsertId();
                    
                    // E-Mail an Admins senden
                    $admin_emails = [];
                    $stmt = $db->prepare("SELECT email FROM users WHERE is_admin = 1 AND is_active = 1");
                    $stmt->execute();
                    $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($admin_emails)) {
                        $vehicle_name = '';
                        $stmt = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
                        $stmt->execute([$vehicle_id]);
                        $vehicle = $stmt->fetch();
                        if ($vehicle) {
                            $vehicle_name = $vehicle['name'];
                        }
                        
                        $subject = "Neue Fahrzeugreservierung - " . $vehicle_name;
                        $message_content = "
                        <h2>Neue Fahrzeugreservierung</h2>
                        <p><strong>Fahrzeug:</strong> " . htmlspecialchars($vehicle_name) . "</p>
                        <p><strong>Antragsteller:</strong> " . htmlspecialchars($requester_name) . "</p>
                        <p><strong>E-Mail:</strong> " . htmlspecialchars($requester_email) . "</p>
                        <p><strong>Grund:</strong> " . htmlspecialchars($reason) . "</p>
                        <p><strong>Von:</strong> " . format_datetime($start_datetime) . "</p>
                        <p><strong>Bis:</strong> " . format_datetime($end_datetime) . "</p>
                        <p><a href='" . $_SERVER['HTTP_HOST'] . "/admin/reservations.php'>Antrag bearbeiten</a></p>
                        ";
                        
                        foreach ($admin_emails as $admin_email) {
                            send_email($admin_email, $subject, $message_content);
                        }
                    }
                    
                    $message = "Ihr Antrag wurde erfolgreich eingereicht. Sie erhalten eine E-Mail, sobald über Ihren Antrag entschieden wurde.";
                    
                } catch(PDOException $e) {
                    $error = "Fehler beim Speichern der Reservierung: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fahrzeug Reservierung - Feuerwehr App</title>
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
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-truck"></i> Fahrzeug Reservierung
                        </h3>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($message): ?>
                            <?php echo show_success($message); ?>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <?php echo show_error($error); ?>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="vehicle_id" class="form-label">Fahrzeug auswählen *</label>
                                    <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                        <option value="">Bitte wählen...</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['id']; ?>" 
                                                    data-capacity="<?php echo $vehicle['capacity']; ?>"
                                                    data-description="<?php echo htmlspecialchars($vehicle['description']); ?>">
                                                <?php echo htmlspecialchars($vehicle['name'] . ' (' . $vehicle['type'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text" id="vehicle-info"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="requester_name" class="form-label">Ihr Name *</label>
                                    <input type="text" class="form-control" id="requester_name" name="requester_name" 
                                           value="<?php echo htmlspecialchars($_POST['requester_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="requester_email" class="form-label">E-Mail Adresse *</label>
                                    <input type="email" class="form-control" id="requester_email" name="requester_email" 
                                           value="<?php echo htmlspecialchars($_POST['requester_email'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="reason" class="form-label">Grund der Reservierung *</label>
                                    <input type="text" class="form-control" id="reason" name="reason" 
                                           value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>" 
                                           placeholder="z.B. Übung, Einsatz, Veranstaltung" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_datetime" class="form-label">Von (Datum & Uhrzeit) *</label>
                                    <input type="datetime-local" class="form-control" id="start_datetime" name="start_datetime" 
                                           value="<?php echo htmlspecialchars($_POST['start_datetime'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="end_datetime" class="form-label">Bis (Datum & Uhrzeit) *</label>
                                    <input type="datetime-local" class="form-control" id="end_datetime" name="end_datetime" 
                                           value="<?php echo htmlspecialchars($_POST['end_datetime'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        Ich bestätige, dass die angegebenen Daten korrekt sind und ich für die Reservierung verantwortlich bin.
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="index.php" class="btn btn-outline-secondary me-md-2">
                                    <i class="fas fa-arrow-left"></i> Zurück
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Fahrzeug beantragen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fahrzeug-Info anzeigen
        document.getElementById('vehicle_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const infoDiv = document.getElementById('vehicle-info');
            
            if (selectedOption.value) {
                const capacity = selectedOption.dataset.capacity;
                const description = selectedOption.dataset.description;
                infoDiv.innerHTML = `
                    <small class="text-muted">
                        <i class="fas fa-users"></i> Kapazität: ${capacity} Personen<br>
                        <i class="fas fa-info-circle"></i> ${description}
                    </small>
                `;
            } else {
                infoDiv.innerHTML = '';
            }
        });
        
        // Datum-Validierung
        document.getElementById('start_datetime').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endInput = document.getElementById('end_datetime');
            const endDate = new Date(endInput.value);
            
            if (endInput.value && endDate <= startDate) {
                endInput.value = '';
                alert('Das Enddatum muss nach dem Startdatum liegen.');
            }
        });
        
        // Mindestdatum auf heute setzen
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        const minDateTime = now.toISOString().slice(0, 16);
        document.getElementById('start_datetime').min = minDateTime;
    </script>
</body>
</html>
