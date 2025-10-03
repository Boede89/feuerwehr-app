<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

$message = '';
$error = '';
$selectedVehicle = null;

// Ausgewähltes Fahrzeug aus Session Storage laden (wird per JavaScript übertragen)
if (isset($_POST['vehicle_data'])) {
    $selectedVehicle = json_decode($_POST['vehicle_data'], true);
} elseif (isset($_SESSION['selected_vehicle'])) {
    $selectedVehicle = $_SESSION['selected_vehicle'];
} else {
    // JavaScript wird die Daten übertragen, daher erstmal weiterleiten
    // Das JavaScript wird die Daten dann per POST übertragen
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reservation'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($csrf_token)) {
        $error = "Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.";
    } else {
        $vehicle_id = $selectedVehicle['id'];
        $requester_name = sanitize_input($_POST['requester_name'] ?? '');
        $requester_email = sanitize_input($_POST['requester_email'] ?? '');
        $reason = sanitize_input($_POST['reason'] ?? '');
        
        // Mehrere Datum/Zeit-Paare verarbeiten
        $date_times = [];
        $i = 0;
        while (isset($_POST["start_datetime_$i"]) && isset($_POST["end_datetime_$i"])) {
            $start_datetime = sanitize_input($_POST["start_datetime_$i"] ?? '');
            $end_datetime = sanitize_input($_POST["end_datetime_$i"] ?? '');
            
            if (!empty($start_datetime) && !empty($end_datetime)) {
                $date_times[] = [
                    'start' => $start_datetime,
                    'end' => $end_datetime
                ];
            }
            $i++;
        }
        
        // Validierung
        if (empty($requester_name) || empty($requester_email) || empty($reason) || empty($date_times)) {
            $error = "Bitte füllen Sie alle Felder aus und geben Sie mindestens einen Zeitraum an.";
        } elseif (!validate_email($requester_email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            $success_count = 0;
            $errors = [];
            
            foreach ($date_times as $index => $dt) {
                $start_datetime = $dt['start'];
                $end_datetime = $dt['end'];
                
                if (!validate_datetime($start_datetime) || !validate_datetime($end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Bitte geben Sie gültige Datum und Uhrzeit ein.";
                    continue;
                }
                
                if (strtotime($start_datetime) >= strtotime($end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das Enddatum muss nach dem Startdatum liegen.";
                    continue;
                }
                
                if (strtotime($start_datetime) < time()) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das Startdatum darf nicht in der Vergangenheit liegen.";
                    continue;
                }
                
                if (check_vehicle_conflict($vehicle_id, $start_datetime, $end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das ausgewählte Fahrzeug ist in diesem Zeitraum bereits reserviert.";
                    continue;
                }
                
                // Reservierung speichern
                try {
                    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $start_datetime, $end_datetime]);
                    $success_count++;
                } catch(PDOException $e) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Fehler beim Speichern - " . $e->getMessage();
                }
            }
            
            if ($success_count > 0) {
                // E-Mail an Admins und Genehmiger mit aktivierten Benachrichtigungen senden
                $admin_emails = [];
                $stmt = $db->prepare("SELECT email FROM users WHERE user_role IN ('admin', 'approver') AND is_active = 1 AND email_notifications = 1");
                $stmt->execute();
                $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($admin_emails)) {
                    // Alle Zeiträume für diesen Antrag laden
                    $stmt = $db->prepare("SELECT start_datetime, end_datetime FROM reservations WHERE vehicle_id = ? AND requester_email = ? AND reason = ? ORDER BY start_datetime");
                    $stmt->execute([$vehicle_id, $requester_email, $reason]);
                    $timeframes = $stmt->fetchAll();
                    
                    $subject = "🔔 Neue Fahrzeugreservierung - " . htmlspecialchars($selectedVehicle['name']);
                    
                    // Zeiträume-Liste erstellen
                    $timeframes_html = "";
                    if (!empty($timeframes)) {
                        $timeframes_html = "<div style='margin-top: 10px;'>";
                        foreach ($timeframes as $index => $timeframe) {
                            $timeframes_html .= "<div style='background-color: #f8f9fa; padding: 10px; margin: 5px 0; border-radius: 4px; border-left: 3px solid #007bff;'>";
                            $timeframes_html .= "<strong>Zeitraum " . ($index + 1) . ":</strong> ";
                            $timeframes_html .= format_datetime($timeframe['start_datetime']) . " - " . format_datetime($timeframe['end_datetime']);
                            $timeframes_html .= "</div>";
                        }
                        $timeframes_html .= "</div>";
                    }
                    
                    $message_content = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa; padding: 20px;'>
                        <div style='background-color: #007bff; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
                            <h1 style='margin: 0; font-size: 24px;'>🔔 Neue Reservierung eingegangen</h1>
                        </div>
                        <div style='background-color: white; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <p style='font-size: 16px; color: #333; margin-bottom: 25px;'>Ein neuer Antrag für eine Fahrzeugreservierung ist eingegangen und wartet auf Ihre Bearbeitung.</p>
                            
                            <div style='background-color: #e3f2fd; border-left: 4px solid #007bff; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                                <h3 style='margin: 0 0 15px 0; color: #007bff; font-size: 18px;'>📋 Antragsdetails</h3>
                                <table style='width: 100%; border-collapse: collapse;'>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeug:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($selectedVehicle['name']) . "</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>👤 Antragsteller:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($requester_name) . "</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📧 E-Mail:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($requester_email) . "</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📝 Grund:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($reason) . "</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555; vertical-align: top;'>📅 Zeiträume:</td>
                                        <td style='padding: 8px 0; color: #333;'>
                                            <strong>$success_count Zeitraum(e) beantragt</strong>
                                            $timeframes_html
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                                <p style='margin: 0; color: #856404; font-size: 14px;'>
                                    <strong>⏰ Wichtig:</strong> Bitte bearbeiten Sie diesen Antrag zeitnah, damit der Antragsteller eine schnelle Rückmeldung erhält.
                                </p>
                            </div>
                            
                            <div style='text-align: center; margin: 25px 0;'>
                                <a href='http://" . $_SERVER['HTTP_HOST'] . "/admin/reservations.php' 
                                   style='background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>
                                    🔗 Antrag bearbeiten
                                </a>
                            </div>
                            
                            <p style='font-size: 14px; color: #666; margin-top: 25px;'>
                                Mit freundlichen Grüßen,<br>
                                Ihr Feuerwehr-System
                            </p>
                        </div>
                    </div>
                    ";
                    
                    foreach ($admin_emails as $admin_email) {
                        send_email($admin_email, $subject, $message_content);
                    }
                }
                
                if (empty($errors)) {
                    $message = "Alle $success_count Reservierungen wurden erfolgreich eingereicht. Sie erhalten eine E-Mail, sobald über Ihre Anträge entschieden wurde.";
                    // Weiterleitung zur Startseite nach 3 Sekunden
                    $redirect_to_home = true;
                } else {
                    $message = "$success_count Reservierungen wurden erfolgreich eingereicht. " . implode(' ', $errors);
                }
            } else {
                $error = "Keine Reservierungen konnten gespeichert werden. " . implode(' ', $errors);
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
                        <p class="text-muted mb-0">Ausgewähltes Fahrzeug: <strong><?php echo htmlspecialchars($selectedVehicle['name']); ?></strong></p>
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
                            <input type="hidden" name="vehicle_data" value="<?php echo htmlspecialchars(json_encode($selectedVehicle)); ?>">
                            
                            <!-- Fahrzeug-Info -->
                            <div class="alert alert-info">
                                <h6><i class="fas fa-truck"></i> Ausgewähltes Fahrzeug</h6>
                                <p class="mb-0">
                                    <strong><?php echo htmlspecialchars($selectedVehicle['name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($selectedVehicle['description']); ?></small>
                                </p>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="requester_name" class="form-label">Ihr Name *</label>
                                    <input type="text" class="form-control" id="requester_name" name="requester_name" 
                                           value="<?php echo htmlspecialchars($_POST['requester_name'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="requester_email" class="form-label">E-Mail Adresse *</label>
                                    <input type="email" class="form-control" id="requester_email" name="requester_email" 
                                           value="<?php echo htmlspecialchars($_POST['requester_email'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="reason" class="form-label">Grund der Reservierung *</label>
                                <input type="text" class="form-control" id="reason" name="reason" 
                                       value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>" 
                                       placeholder="z.B. Übung, Einsatz, Veranstaltung" required>
                            </div>
                            
                            <!-- Zeiträume -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6><i class="fas fa-calendar"></i> Zeiträume *</h6>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-timeframe">
                                        <i class="fas fa-plus"></i> Weitere Zeit hinzufügen
                                    </button>
                                </div>
                                
                                <div id="timeframes">
                                    <div class="timeframe-row row mb-3">
                                        <div class="col-md-5">
                                            <label class="form-label">Von (Datum & Uhrzeit) *</label>
                                            <input type="datetime-local" class="form-control start-datetime" name="start_datetime_0" step="900" required>
                                            <div class="form-text">Zeitauswahl in 15-Minuten-Intervallen</div>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label">Bis (Datum & Uhrzeit) *</label>
                                            <input type="datetime-local" class="form-control end-datetime" name="end_datetime_0" step="900" required>
                                            <div class="form-text">Zeitauswahl in 15-Minuten-Intervallen</div>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-timeframe" style="display: none;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="vehicle-selection.php" class="btn btn-outline-secondary me-md-2">
                                    <i class="fas fa-arrow-left"></i> Fahrzeug ändern
                                </a>
                                <button type="submit" name="submit_reservation" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Reservierung beantragen
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
        // Fahrzeugdaten aus Session Storage laden und übertragen
        window.addEventListener('load', function() {
            const selectedVehicle = sessionStorage.getItem('selectedVehicle');
            if (selectedVehicle) {
                const vehicleData = JSON.parse(selectedVehicle);
                document.querySelector('input[name="vehicle_data"]').value = JSON.stringify(vehicleData);
                
                // Fahrzeug-Info anzeigen
                const vehicleInfo = document.querySelector('.alert-info p');
                if (vehicleInfo) {
                    vehicleInfo.innerHTML = `
                        <strong>${vehicleData.name}</strong> 
                        (${vehicleData.type})<br>
                        <small>${vehicleData.description}</small>
                    `;
                }
            } else {
                // Kein Fahrzeug ausgewählt, zurück zur Auswahl
                window.location.href = 'vehicle-selection.php';
            }
        });
        
        let timeframeCount = 1;
        
        // Weitere Zeit hinzufügen
        document.getElementById('add-timeframe').addEventListener('click', function() {
            const timeframesDiv = document.getElementById('timeframes');
            const newTimeframe = document.createElement('div');
            newTimeframe.className = 'timeframe-row row mb-3';
            newTimeframe.innerHTML = `
                <div class="col-md-5">
                    <label class="form-label">Von (Datum & Uhrzeit) *</label>
                    <input type="datetime-local" class="form-control start-datetime" name="start_datetime_${timeframeCount}" step="900" required>
                    <div class="form-text">Zeitauswahl in 15-Minuten-Intervallen</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Bis (Datum & Uhrzeit) *</label>
                    <input type="datetime-local" class="form-control end-datetime" name="end_datetime_${timeframeCount}" step="900" required>
                    <div class="form-text">Zeitauswahl in 15-Minuten-Intervallen</div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-timeframe">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            
            timeframesDiv.appendChild(newTimeframe);
            timeframeCount++;
            
            // Entfernen-Button für alle Zeiträume anzeigen
            document.querySelectorAll('.remove-timeframe').forEach(btn => {
                btn.style.display = 'block';
            });
            
            // Event Listener für neuen Zeitraum
            setupTimeframeValidation(newTimeframe);
        });
        
        // Zeitraum entfernen
        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-timeframe')) {
                const timeframeRow = e.target.closest('.timeframe-row');
                timeframeRow.remove();
                
                // Entfernen-Button verstecken wenn nur noch ein Zeitraum vorhanden
                if (document.querySelectorAll('.timeframe-row').length === 1) {
                    document.querySelectorAll('.remove-timeframe').forEach(btn => {
                        btn.style.display = 'none';
                    });
                }
            }
        });
        
        // Datum-Validierung für Zeiträume
        function setupTimeframeValidation(timeframeRow) {
            const startInput = timeframeRow.querySelector('.start-datetime');
            const endInput = timeframeRow.querySelector('.end-datetime');
            
            startInput.addEventListener('change', function() {
                const startDate = new Date(this.value);
                const endDate = new Date(endInput.value);
                
                if (endInput.value && endDate <= startDate) {
                    endInput.value = '';
                    alert('Das Enddatum muss nach dem Startdatum liegen.');
                }
            });
            
            // Mindestdatum setzen
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            const minDateTime = now.toISOString().slice(0, 16);
            startInput.min = minDateTime;
        }
        
        // Initiale Validierung für ersten Zeitraum
        setupTimeframeValidation(document.querySelector('.timeframe-row'));
        
        // Automatische Weiterleitung zur Startseite nach erfolgreicher Reservierung
        <?php if (isset($redirect_to_home) && $redirect_to_home): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Nach 3 Sekunden zur Startseite weiterleiten
            setTimeout(function() {
                window.location.href = 'index.php';
            }, 3000);
            
            // Countdown-Anzeige
            let countdown = 3;
            const messageElement = document.querySelector('.alert-success');
            if (messageElement) {
                const originalMessage = messageElement.innerHTML;
                const countdownInterval = setInterval(function() {
                    messageElement.innerHTML = originalMessage + '<br><small class="text-muted">Weiterleitung zur Startseite in ' + countdown + ' Sekunden...</small>';
                    countdown--;
                    
                    if (countdown < 0) {
                        clearInterval(countdownInterval);
                    }
                }, 1000);
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
