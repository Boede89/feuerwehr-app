<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Session-Fix für die App
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    // Lade Admin-Benutzer aus der Datenbank
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['first_name'] = $admin_user['first_name'];
        $_SESSION['last_name'] = $admin_user['last_name'];
        $_SESSION['username'] = $admin_user['username'];
        $_SESSION['email'] = $admin_user['email'];
    }
}

$message = '';
$error = '';
$selectedVehicle = null;

// Browser Console Logging für Debugging
echo '<script>';
echo 'console.log("🔍 Reservation Page Debug");';
echo 'console.log("Zeitstempel:", new Date().toLocaleString());';
echo 'console.log("Session user_id:", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');';
echo 'console.log("Session role:", ' . json_encode($_SESSION['role'] ?? 'nicht gesetzt') . ');';
echo 'console.log("Selected Vehicle:", ' . json_encode($selectedVehicle ?? 'nicht gesetzt') . ');';
echo 'console.log("Message:", ' . json_encode($message ?? '') . ');';
echo 'console.log("Error:", ' . json_encode($error ?? '') . ');';
echo 'console.log("POST Data:", ' . json_encode($_POST ?? []) . ');';
echo '</script>';

// Ausgewähltes Fahrzeug aus POST-Daten oder Session laden
if (isset($_POST['vehicle_data'])) {
    $selectedVehicle = json_decode($_POST['vehicle_data'], true);
    echo '<script>console.log("✅ Fahrzeug aus POST-Daten geladen:", ' . json_encode($selectedVehicle) . ');</script>';
    
    // Prüfe ob Fahrzeug korrekt geladen wurde
    if (!$selectedVehicle || !isset($selectedVehicle['id'])) {
        echo '<script>console.log("❌ Fahrzeug-Daten sind unvollständig:", ' . json_encode($selectedVehicle) . ');</script>';
        $error = "Fehler beim Laden der Fahrzeug-Daten. Bitte wählen Sie erneut ein Fahrzeug aus.";
    }
} elseif (isset($_SESSION['selected_vehicle'])) {
    $selectedVehicle = $_SESSION['selected_vehicle'];
    echo '<script>console.log("✅ Fahrzeug aus Session geladen:", ' . json_encode($selectedVehicle) . ');</script>';
} else {
    // Kein Fahrzeug ausgewählt, zeige Fehlermeldung und Weiterleitung
    echo '<script>console.log("❌ Kein Fahrzeug ausgewählt - Prüfe SessionStorage");</script>';
    $error = "Bitte wählen Sie zuerst ein Fahrzeug aus.";
    echo '<script>setTimeout(function() { window.location.href = "vehicle-selection.php"; }, 3000);</script>';
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reservation'])) {
    echo '<script>console.log("🔍 Formular wird verarbeitet...");</script>';
    
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($csrf_token)) {
        $error = "Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.";
        echo '<script>console.log("❌ CSRF Token ungültig");</script>';
    } else {
        echo '<script>console.log("✅ CSRF Token gültig");</script>';
        
        // Prüfe ob Fahrzeug verfügbar ist
        if (!isset($selectedVehicle['id'])) {
            $error = "Fahrzeug-Daten sind nicht verfügbar. Bitte wählen Sie erneut ein Fahrzeug aus.";
            echo '<script>console.log("❌ Fahrzeug-ID nicht verfügbar");</script>';
        } else {
            $vehicle_id = $selectedVehicle['id'];
            $requester_name = sanitize_input($_POST['requester_name'] ?? '');
            $requester_email = sanitize_input($_POST['requester_email'] ?? '');
            $reason = sanitize_input($_POST['reason'] ?? '');
            $location = sanitize_input($_POST['location'] ?? '');
            
            echo '<script>console.log("✅ Formular-Daten geladen:", {vehicle_id: ' . $vehicle_id . ', requester_name: "' . $requester_name . '", reason: "' . $reason . '"});</script>';
        
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
        
        echo '<script>console.log("🔍 Zeiträume gefunden:", ' . count($date_times) . ');</script>';
        
        // Validierung
        if (empty($requester_name) || empty($requester_email) || empty($reason) || empty($location) || empty($date_times)) {
            $error = "Bitte füllen Sie alle Felder aus und geben Sie mindestens einen Zeitraum an.";
            echo '<script>console.log("❌ Validierung fehlgeschlagen - Felder unvollständig");</script>';
        } elseif (!validate_email($requester_email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
            echo '<script>console.log("❌ Validierung fehlgeschlagen - E-Mail ungültig");</script>';
        } else {
            echo '<script>console.log("✅ Validierung erfolgreich - Starte Reservierung-Speicherung");</script>';
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
            // Kalender-Konflikte prüfen
            $conflicts = [];
            if (function_exists('check_calendar_conflicts')) {
                $conflicts = check_calendar_conflicts($selectedVehicle['name'], $start_datetime, $end_datetime);
                echo '<script>console.log("🔍 Kalender-Konflikte geprüft:", ' . json_encode($conflicts) . ');</script>';
            }
            
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode($conflicts)]);
            $success_count++;
            echo '<script>console.log("✅ Reservierung gespeichert - Zeitraum " . ($index + 1) . "");</script>';
        } catch(PDOException $e) {
            $errors[] = "Zeitraum " . ($index + 1) . ": Fehler beim Speichern - " . $e->getMessage();
            echo '<script>console.log("❌ Fehler beim Speichern - Zeitraum " . ($index + 1) . ":", ' . json_encode($e->getMessage()) . ');</script>';
        }
            }
            
            if ($success_count > 0) {
                echo '<script>console.log("✅ Reservierungen erfolgreich gespeichert - Sende E-Mails");</script>';
                
                // E-Mail an Admins und Genehmiger mit aktivierten Benachrichtigungen senden
                $admin_emails = [];
                try {
                    $stmt = $db->prepare("SELECT email FROM users WHERE user_role IN ('admin', 'approver') AND is_active = 1 AND email_notifications = 1");
                    $stmt->execute();
                    $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    echo '<script>console.log("🔍 Admin-E-Mails gefunden:", ' . count($admin_emails) . ');</script>';
                } catch (Exception $e) {
                    echo '<script>console.log("❌ Fehler beim Laden der Admin-E-Mails:", ' . json_encode($e->getMessage()) . ');</script>';
                }
                
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
                    echo '<script>console.log("✅ Erfolgreiche Reservierung - Weiterleitung zur Startseite");</script>';
                    // Weiterleitung zur Startseite nach 3 Sekunden
                    $redirect_to_home = true;
                } else {
                    $message = "$success_count Reservierungen wurden erfolgreich eingereicht. " . implode(' ', $errors);
                    echo '<script>console.log("⚠️ Teilweise erfolgreiche Reservierung mit Fehlern");</script>';
                }
            } else {
                $error = "Keine Reservierungen konnten gespeichert werden. " . implode(' ', $errors);
                echo '<script>console.log("❌ Keine Reservierungen gespeichert");</script>';
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
                        <p class="text-muted mb-0">Ausgewähltes Fahrzeug: <strong><?php echo isset($selectedVehicle['name']) ? htmlspecialchars($selectedVehicle['name']) : 'Kein Fahrzeug ausgewählt'; ?></strong></p>
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
                            <?php if (isset($selectedVehicle['name'])): ?>
                            <div class="alert alert-info">
                                <h6><i class="fas fa-truck"></i> Ausgewähltes Fahrzeug</h6>
                                <p class="mb-0">
                                    <strong><?php echo htmlspecialchars($selectedVehicle['name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($selectedVehicle['description'] ?? ''); ?></small>
                                </p>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Kein Fahrzeug ausgewählt</h6>
                                <p class="mb-0">Bitte wählen Sie zuerst ein Fahrzeug aus der Fahrzeugauswahl aus.</p>
                                <a href="vehicle-selection.php" class="btn btn-primary btn-sm mt-2">
                                    <i class="fas fa-truck"></i> Fahrzeug auswählen
                                </a>
                            </div>
                            <?php endif; ?>
                            
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
                            
                            <div class="mb-3">
                                <label for="location" class="form-label">Ort der Reservierung *</label>
                                <input type="text" class="form-control" id="location" name="location" 
                                       value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" 
                                       placeholder="z.B. Feuerwehrhaus, Übungsplatz, Veranstaltungsort" required>
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
                                            <input type="datetime-local" class="form-control start-datetime" name="start_datetime_0" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label">Bis (Datum & Uhrzeit) *</label>
                                            <input type="datetime-local" class="form-control end-datetime" name="end_datetime_0" required>
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
                                <?php if (isset($selectedVehicle['name'])): ?>
                                <button type="submit" name="submit_reservation" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Reservierung beantragen
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-primary" disabled>
                                    <i class="fas fa-paper-plane"></i> Bitte wählen Sie zuerst ein Fahrzeug
                                </button>
                                <?php endif; ?>
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
            console.log('🔍 Lade Fahrzeug aus SessionStorage...');
            const selectedVehicle = sessionStorage.getItem('selectedVehicle');
            console.log('SessionStorage selectedVehicle:', selectedVehicle);
            
            if (selectedVehicle) {
                try {
                    const vehicleData = JSON.parse(selectedVehicle);
                    console.log('✅ Fahrzeug aus SessionStorage geladen:', vehicleData);
                    
                    // Fahrzeug-Daten in verstecktes Feld übertragen
                    const vehicleDataInput = document.querySelector('input[name="vehicle_data"]');
                    if (vehicleDataInput) {
                        vehicleDataInput.value = JSON.stringify(vehicleData);
                        console.log('✅ Fahrzeug-Daten in Formular übertragen:', vehicleDataInput.value);
                    } else {
                        console.log('❌ Verstecktes Feld vehicle_data nicht gefunden');
                    }
                    
                    // Fahrzeug-Info anzeigen
                    const vehicleInfo = document.querySelector('.alert-info p');
                    if (vehicleInfo) {
                        vehicleInfo.innerHTML = `
                            <strong>${vehicleData.name}</strong><br>
                            <small>${vehicleData.description}</small>
                        `;
                        console.log('✅ Fahrzeug-Info angezeigt');
                    } else {
                        console.log('❌ Fahrzeug-Info Element nicht gefunden');
                    }
                    
                    // Prüfe ob PHP das Fahrzeug erkannt hat
                    const phpVehicleName = document.querySelector('.card-header p strong').textContent;
                    console.log('🔍 PHP Fahrzeug-Name:', phpVehicleName);
                    
                    if (phpVehicleName === 'Kein Fahrzeug ausgewählt') {
                        console.log('🔄 PHP hat Fahrzeug nicht erkannt - Lade Seite neu...');
                        // Formular mit Fahrzeug-Daten absenden
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'reservation.php';
                        
                        const vehicleInput = document.createElement('input');
                        vehicleInput.type = 'hidden';
                        vehicleInput.name = 'vehicle_data';
                        vehicleInput.value = JSON.stringify(vehicleData);
                        form.appendChild(vehicleInput);
                        
                        document.body.appendChild(form);
                        form.submit();
                    }
                } catch (e) {
                    console.log('❌ Fehler beim Parsen der Fahrzeug-Daten:', e);
                }
            } else {
                console.log('❌ Kein Fahrzeug in SessionStorage gefunden');
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
                    <input type="datetime-local" class="form-control start-datetime" name="start_datetime_${timeframeCount}" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Bis (Datum & Uhrzeit) *</label>
                    <input type="datetime-local" class="form-control end-datetime" name="end_datetime_${timeframeCount}" required>
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
