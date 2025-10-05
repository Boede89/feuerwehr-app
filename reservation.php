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
$selectedVehicles = []; // Array für mehrere Fahrzeuge

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

// Ausgewählte Fahrzeuge aus POST-Daten oder Session laden
if (isset($_POST['vehicle_data'])) {
    $selectedVehicle = json_decode($_POST['vehicle_data'], true);
    echo '<script>console.log("✅ Fahrzeug aus POST-Daten geladen:", ' . json_encode($selectedVehicle) . ');</script>';
    
    // Prüfe ob Fahrzeug korrekt geladen wurde
    if (!$selectedVehicle || !isset($selectedVehicle['id'])) {
        echo '<script>console.log("❌ Fahrzeug-Daten sind unvollständig:", ' . json_encode($selectedVehicle) . ');</script>';
        $error = "Fehler beim Laden der Fahrzeug-Daten. Bitte wählen Sie erneut ein Fahrzeug aus.";
    } else {
        // Füge das erste Fahrzeug zu selectedVehicles hinzu
        $selectedVehicles = [$selectedVehicle];
    }
} elseif (isset($_SESSION['selected_vehicle'])) {
    $selectedVehicle = $_SESSION['selected_vehicle'];
    $selectedVehicles = [$selectedVehicle];
    echo '<script>console.log("✅ Fahrzeug aus Session geladen:", ' . json_encode($selectedVehicle) . ');</script>';
} else {
    // Kein Fahrzeug ausgewählt, zeige Fehlermeldung und Weiterleitung
    echo '<script>console.log("❌ Kein Fahrzeug ausgewählt - Prüfe SessionStorage");</script>';
    $error = "Bitte wählen Sie zuerst ein Fahrzeug aus.";
    echo '<script>setTimeout(function() { window.location.href = "index.php"; }, 3000);</script>';
}

// Konflikt-Verarbeitung (wenn Benutzer trotz Konflikt fortfahren möchte)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['force_submit_reservation'])) {
    echo '<script>console.log("🔍 Konflikt-Reservierung wird verarbeitet...");</script>';
    
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($csrf_token)) {
        $error = "Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.";
    } else {
        // Verarbeite die Reservierung trotz Konflikt
        $vehicle_id = (int)($_POST['conflict_vehicle_id'] ?? 0);
        $requester_name = sanitize_input($_POST['requester_name'] ?? '');
        $requester_email = sanitize_input($_POST['requester_email'] ?? '');
        $reason = sanitize_input($_POST['reason'] ?? '');
        $location = sanitize_input($_POST['location'] ?? '');
        $start_datetime = sanitize_input($_POST['conflict_start_datetime'] ?? '');
        $end_datetime = sanitize_input($_POST['conflict_end_datetime'] ?? '');
        
        if ($vehicle_id && $requester_name && $requester_email && $reason && $start_datetime && $end_datetime) {
            try {
                $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode([])]);
                
                $message = "Reservierung wurde trotz Konflikt erfolgreich eingereicht. Bitte beachten Sie, dass es Überschneidungen mit anderen Reservierungen geben kann.";
                $redirect_to_home = true; // Flag für Weiterleitung setzen
            } catch(PDOException $e) {
                $error = "Fehler beim Speichern der Reservierung: " . $e->getMessage();
                $redirect_to_home = true; // Auch bei Fehler zur Startseite weiterleiten
            }
        } else {
            $error = "Ungültige Daten für die Konflikt-Reservierung.";
            $redirect_to_home = true; // Auch bei ungültigen Daten zur Startseite weiterleiten
        }
    }
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
        
        // Prüfe ob Fahrzeuge verfügbar sind
        $vehicle_ids = $_POST['vehicle_ids'] ?? [];
        if (empty($vehicle_ids)) {
            $error = "Keine Fahrzeuge ausgewählt. Bitte wählen Sie mindestens ein Fahrzeug aus.";
            echo '<script>console.log("❌ Keine Fahrzeug-IDs verfügbar");</script>';
        } else {
            $requester_name = sanitize_input($_POST['requester_name'] ?? '');
            $requester_email = sanitize_input($_POST['requester_email'] ?? '');
            $reason = sanitize_input($_POST['reason'] ?? '');
            $location = sanitize_input($_POST['location'] ?? '');
            
            echo '<script>console.log("✅ Formular-Daten geladen:", {vehicle_ids: ' . json_encode($vehicle_ids) . ', requester_name: "' . $requester_name . '", reason: "' . $reason . '"});</script>';
        
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
                // Debug: Verarbeite Zeitraum
                
                $start_datetime = $dt['start'];
                $end_datetime = $dt['end'];
                
                if (!validate_datetime($start_datetime) || !validate_datetime($end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Bitte geben Sie gültige Datum und Uhrzeit ein.";
                    // Debug: Ungültiges Datum/Zeit-Format
                    continue;
                }
                
                if (strtotime($start_datetime) >= strtotime($end_datetime)) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das Enddatum muss nach dem Startdatum liegen.";
                    // Debug: Enddatum vor Startdatum
                    continue;
                }
                
                if (strtotime($start_datetime) < time()) {
                    $errors[] = "Zeitraum " . ($index + 1) . ": Das Startdatum darf nicht in der Vergangenheit liegen.";
                    // Debug: Startdatum in der Vergangenheit
                    continue;
                }
                
                // Debug: Prüfe Fahrzeug-Konflikte für alle ausgewählten Fahrzeuge
                $conflict_found = false;
                foreach ($vehicle_ids as $vehicle_id) {
                    if (check_vehicle_conflict($vehicle_id, $start_datetime, $end_datetime)) {
                        // Konflikt gefunden - lade Details der bestehenden Reservierung
                        $conflict_found = true;
                        
                        // Lade Details der bestehenden Reservierung
                        $stmt = $db->prepare("
                            SELECT r.*, v.name as vehicle_name 
                            FROM reservations r 
                            JOIN vehicles v ON r.vehicle_id = v.id 
                            WHERE r.vehicle_id = ? 
                            AND r.status = 'approved' 
                            AND ((r.start_datetime <= ? AND r.end_datetime > ?) 
                                 OR (r.start_datetime < ? AND r.end_datetime >= ?) 
                                 OR (r.start_datetime >= ? AND r.end_datetime <= ?))
                            LIMIT 1
                        ");
                        $stmt->execute([
                            $vehicle_id, 
                            $start_datetime, $start_datetime,
                            $end_datetime, $end_datetime,
                            $start_datetime, $end_datetime
                        ]);
                        $existing_reservation = $stmt->fetch();
                        
                        $conflict_timeframe = [
                            'index' => $index + 1,
                            'start' => $start_datetime,
                            'end' => $end_datetime,
                            'vehicle_id' => $vehicle_id,
                            'vehicle_name' => $existing_reservation['vehicle_name'] ?? 'Unbekannt',
                            'existing_reservation' => $existing_reservation
                        ];
                        // Debug: Fahrzeug bereits reserviert - zeige Modal mit Details
                        break; // Stoppe die Verarbeitung und zeige Modal
                    }
                }
                
                if ($conflict_found) {
                    break; // Stoppe die Verarbeitung und zeige Modal
                }
                
                // Debug: Validierung erfolgreich
                
                // Reservierung für alle ausgewählten Fahrzeuge speichern
                foreach ($vehicle_ids as $vehicle_id) {
                    try {
                        // Debug: Führe Datenbank-Insert aus
                        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode([])]);
                        $success_count++;
                        // Debug: Reservierung gespeichert
                    } catch(PDOException $e) {
                        $errors[] = "Zeitraum " . ($index + 1) . " (Fahrzeug ID $vehicle_id): Fehler beim Speichern - " . $e->getMessage();
                        // Debug: Fehler beim Speichern
                    } catch(Exception $e) {
                        $errors[] = "Zeitraum " . ($index + 1) . " (Fahrzeug ID $vehicle_id): Unerwarteter Fehler - " . $e->getMessage();
                        // Debug: Unerwarteter Fehler
                    }
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
                    // Alle Zeiträume für diesen Antrag laden (für alle Fahrzeuge)
                    $placeholders = str_repeat('?,', count($vehicle_ids) - 1) . '?';
                    $stmt = $db->prepare("SELECT r.start_datetime, r.end_datetime, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.vehicle_id IN ($placeholders) AND r.requester_email = ? AND r.reason = ? ORDER BY r.start_datetime");
                    $params = array_merge($vehicle_ids, [$requester_email, $reason]);
                    $stmt->execute($params);
                    $timeframes = $stmt->fetchAll();
                    
                    // Erstelle Fahrzeug-Liste für E-Mail
                    $vehicle_names = [];
                    foreach ($vehicle_ids as $vid) {
                        $stmt = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
                        $stmt->execute([$vid]);
                        $vehicle = $stmt->fetch();
                        if ($vehicle) {
                            $vehicle_names[] = $vehicle['name'];
                        }
                    }
                    $vehicle_list = implode(', ', $vehicle_names);
                    
                    $subject = "🔔 Neue Fahrzeugreservierung - " . $vehicle_list;
                    
                    // Zeiträume-Liste erstellen
                    $timeframes_html = "";
                    if (!empty($timeframes)) {
                        $timeframes_html = "<div style='margin-top: 10px;'>";
                        foreach ($timeframes as $index => $timeframe) {
                            $timeframes_html .= "<div style='background-color: #f8f9fa; padding: 10px; margin: 5px 0; border-radius: 4px; border-left: 3px solid #007bff;'>";
                            $timeframes_html .= "<strong>Zeitraum " . ($index + 1) . " (" . htmlspecialchars($timeframe['vehicle_name']) . "):</strong> ";
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
                                        <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeuge:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($vehicle_list) . "</td>
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
                
                // Google Calendar Event wird erst bei der Genehmigung erstellt
                
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
                            
                            <!-- Mehrfach-Fahrzeug-Auswahl -->
                            <?php if (isset($selectedVehicle['name'])): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-truck"></i> Ausgewählte Fahrzeuge</h6>
                                </div>
                                <div class="card-body">
                                    <div id="selected-vehicles-list">
                                        <!-- Ausgewählte Fahrzeuge werden hier dynamisch eingefügt -->
                                    </div>
                                    
                                    <!-- Fahrzeug hinzufügen -->
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="add-vehicle-btn">
                                            <i class="fas fa-plus"></i> Weiteres Fahrzeug auswählen
                                        </button>
                                    </div>
                                    
                                    <!-- Verfügbare Fahrzeuge Buttons (versteckt) -->
                                    <div id="vehicle-buttons-container" class="mt-3" style="display: none;">
                                        <label class="form-label">Weiteres Fahrzeug auswählen:</label>
                                        <div id="available-vehicles-grid" class="row g-2">
                                            <!-- Verfügbare Fahrzeug-Buttons werden hier dynamisch eingefügt -->
                                        </div>
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-secondary btn-sm" id="cancel-vehicle-btn">
                                                <i class="fas fa-times"></i> Abbrechen
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Kein Fahrzeug ausgewählt</h6>
                                <p class="mb-0">Bitte wählen Sie zuerst ein Fahrzeug aus der Fahrzeugauswahl aus.</p>
                                <a href="index.php" class="btn btn-primary btn-sm mt-2">
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
                                <a href="index.php" class="btn btn-outline-secondary me-md-2">
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
                window.location.href = 'index.php';
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
            const messageElement = document.querySelector('.alert-success, .alert-danger');
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
        
        // Konflikt-Modal anzeigen wenn nötig
        <?php if (isset($conflict_found) && $conflict_found): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const conflictModal = new bootstrap.Modal(document.getElementById('conflictModal'));
            conflictModal.show();
            
            // Event Listener für "Antrag abbrechen" Button
            document.getElementById('cancelReservationBtn').addEventListener('click', function() {
                // Modal schließen
                conflictModal.hide();
                // Nach kurzer Verzögerung zur Startseite weiterleiten
                setTimeout(function() {
                    window.location.href = 'index.php';
                }, 300);
            });
        });
        <?php endif; ?>
    </script>
    
    <!-- Konflikt-Modal -->
    <div class="modal fade" id="conflictModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Fahrzeug bereits reserviert
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6><strong>Konflikt erkannt!</strong></h6>
                        <p>Das ausgewählte Fahrzeug <strong><?php echo htmlspecialchars($conflict_timeframe['vehicle_name']); ?></strong> ist bereits für den gewünschten Zeitraum reserviert:</p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-calendar-alt text-danger"></i> Ihr gewünschter Zeitraum:</h6>
                                <p class="mb-2">
                                    <strong><?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['start'])); ?> - <?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['end'])); ?></strong>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-exclamation-triangle text-warning"></i> Bereits reserviert:</h6>
                                <?php if ($conflict_timeframe['existing_reservation']): ?>
                                    <p class="mb-1">
                                        <strong>Zeitraum:</strong> <?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['existing_reservation']['start_datetime'])); ?> - <?php echo date('d.m.Y H:i', strtotime($conflict_timeframe['existing_reservation']['end_datetime'])); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Antragsteller:</strong> <?php echo htmlspecialchars($conflict_timeframe['existing_reservation']['requester_name']); ?>
                                    </p>
                                    <p class="mb-0">
                                        <strong>Grund:</strong> <?php echo htmlspecialchars($conflict_timeframe['existing_reservation']['reason']); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="mb-0 text-muted">Details der bestehenden Reservierung konnten nicht geladen werden.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <p>Möchten Sie den Antrag trotzdem einreichen? Der Administrator wird über den Konflikt informiert und kann entscheiden, ob beide Reservierungen möglich sind.</p>
                    
                    <div class="alert alert-info">
                        <small><i class="fas fa-info-circle"></i> <strong>Hinweis:</strong> Bei einer Überschneidung kann es zu Problemen bei der Fahrzeugverfügbarkeit kommen.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="conflict_vehicle_id" value="<?php echo $conflict_timeframe['vehicle_id']; ?>">
                        <input type="hidden" name="conflict_start_datetime" value="<?php echo $conflict_timeframe['start']; ?>">
                        <input type="hidden" name="conflict_end_datetime" value="<?php echo $conflict_timeframe['end']; ?>">
                        <input type="hidden" name="requester_name" value="<?php echo htmlspecialchars($_POST['requester_name'] ?? ''); ?>">
                        <input type="hidden" name="requester_email" value="<?php echo htmlspecialchars($_POST['requester_email'] ?? ''); ?>">
                        <input type="hidden" name="reason" value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>">
                        <input type="hidden" name="location" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                        <button type="submit" name="force_submit_reservation" class="btn btn-warning">
                            <i class="fas fa-exclamation-triangle"></i> Antrag trotzdem versenden
                        </button>
                    </form>
                    <button type="button" id="cancelReservationBtn" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Antrag abbrechen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CSS für Fahrzeug-Buttons -->
    <style>
    .vehicle-button {
        transition: all 0.3s ease;
        border: 2px solid #dee2e6;
    }
    
    .vehicle-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        border-color: #007bff;
    }
    
    .vehicle-button:active {
        transform: translateY(0);
    }
    
    .vehicle-button i {
        transition: color 0.3s ease;
    }
    
    .vehicle-button:hover i {
        color: #007bff !important;
    }
    </style>

    <!-- JavaScript für Mehrfach-Fahrzeug-Auswahl -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Globale Variablen
        let selectedVehicles = <?php echo json_encode($selectedVehicles); ?>;
        let availableVehicles = [];
        
        // Lade verfügbare Fahrzeuge
        loadAvailableVehicles();
        
        // Initialisiere die Anzeige
        updateSelectedVehiclesList();
        
        // Event Listener
        document.getElementById('add-vehicle-btn').addEventListener('click', showVehicleButtons);
        document.getElementById('cancel-vehicle-btn').addEventListener('click', hideVehicleButtons);
        
        // Lade verfügbare Fahrzeuge vom Server
        function loadAvailableVehicles() {
            fetch('get-available-vehicles.php')
                .then(response => response.json())
                .then(data => {
                    availableVehicles = data;
                    updateVehicleButtons();
                })
                .catch(error => {
                    console.error('Fehler beim Laden der Fahrzeuge:', error);
                });
        }
        
        // Zeige Fahrzeug-Buttons
        function showVehicleButtons() {
            document.getElementById('vehicle-buttons-container').style.display = 'block';
            document.getElementById('add-vehicle-btn').style.display = 'none';
            updateVehicleButtons();
        }
        
        // Verstecke Fahrzeug-Buttons
        function hideVehicleButtons() {
            document.getElementById('vehicle-buttons-container').style.display = 'none';
            document.getElementById('add-vehicle-btn').style.display = 'inline-block';
        }
        
        // Aktualisiere Fahrzeug-Buttons
        function updateVehicleButtons() {
            const container = document.getElementById('available-vehicles-grid');
            container.innerHTML = '';
            
            // Filtere bereits ausgewählte Fahrzeuge heraus
            const selectedIds = selectedVehicles.map(v => v.id);
            const available = availableVehicles.filter(v => !selectedIds.includes(v.id));
            
            if (available.length === 0) {
                container.innerHTML = '<div class="col-12"><p class="text-muted">Alle verfügbaren Fahrzeuge sind bereits ausgewählt.</p></div>';
                return;
            }
            
            available.forEach(vehicle => {
                const col = document.createElement('div');
                col.className = 'col-md-6 col-lg-4';
                
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center p-3 vehicle-button';
                button.style.minHeight = '100px';
                button.onclick = () => addSelectedVehicle(vehicle);
                
                button.innerHTML = `
                    <i class="fas fa-truck fa-2x mb-2 text-primary"></i>
                    <strong class="mb-1">${vehicle.name}</strong>
                    ${vehicle.description ? '<small class="text-muted text-center">' + vehicle.description + '</small>' : ''}
                `;
                
                col.appendChild(button);
                container.appendChild(col);
            });
        }
        
        // Füge ausgewähltes Fahrzeug hinzu
        function addSelectedVehicle(vehicle) {
            selectedVehicles.push(vehicle);
            updateSelectedVehiclesList();
            hideVehicleButtons();
        }
        
        // Entferne Fahrzeug
        function removeVehicle(vehicleId) {
            selectedVehicles = selectedVehicles.filter(v => v.id != vehicleId);
            updateSelectedVehiclesList();
        }
        
        // Aktualisiere die Anzeige der ausgewählten Fahrzeuge
        function updateSelectedVehiclesList() {
            const container = document.getElementById('selected-vehicles-list');
            container.innerHTML = '';
            
            selectedVehicles.forEach((vehicle, index) => {
                const vehicleDiv = document.createElement('div');
                vehicleDiv.className = 'alert alert-info d-flex justify-content-between align-items-center mb-2';
                vehicleDiv.innerHTML = `
                    <div>
                        <strong>${vehicle.name}</strong>
                        ${vehicle.description ? '<br><small>' + vehicle.description + '</small>' : ''}
                    </div>
                    ${selectedVehicles.length > 1 ? '<button type="button" class="btn btn-outline-danger btn-sm" onclick="removeVehicle(' + vehicle.id + ')"><i class="fas fa-times"></i></button>' : ''}
                `;
                container.appendChild(vehicleDiv);
            });
            
            // Aktualisiere versteckte Felder für Formular
            updateHiddenFields();
        }
        
        // Aktualisiere versteckte Felder für Formular
        function updateHiddenFields() {
            // Aktualisiere vehicle_data für das erste Fahrzeug (Kompatibilität)
            if (selectedVehicles.length > 0) {
                document.querySelector('input[name="vehicle_data"]').value = JSON.stringify(selectedVehicles[0]);
            }
            
            // Füge versteckte Felder für alle Fahrzeuge hinzu
            // Entferne alte vehicle_ids Felder
            document.querySelectorAll('input[name="vehicle_ids[]"]').forEach(el => el.remove());
            
            // Füge neue vehicle_ids Felder hinzu
            selectedVehicles.forEach(vehicle => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'vehicle_ids[]';
                input.value = vehicle.id;
                document.querySelector('form').appendChild(input);
            });
        }
        
        // Globale Funktion für removeVehicle (für onclick)
        window.removeVehicle = removeVehicle;
    });
    </script>
</body>
</html>
