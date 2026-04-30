<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/einheit-settings-helper.php';

$message = '';
$error = '';
$availability_warnings = [];
$availability_modal_data = null;
$selectedVehicle = null;
$selectedVehicles = []; // Array für mehrere Fahrzeuge
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
if ($einheit_id > 0) {
    $_SESSION['current_einheit_id'] = $einheit_id;
}
$einheit_id = $einheit_id > 0 ? $einheit_id : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';

// Eingeloggte Benutzer (inkl. Systembenutzer) brauchen Reservierungs-Berechtigung
if (is_logged_in() && !has_permission('reservations')) {
    header('Location: index.php' . $einheit_param);
    exit;
}

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
    $redirect_url = 'index.php';
    if (!empty($_GET['einheit_id'])) {
        $redirect_url .= '?einheit_id=' . (int)$_GET['einheit_id'];
    }
    echo '<script>console.log("❌ Kein Fahrzeug ausgewählt - Prüfe SessionStorage");</script>';
    $error = "Bitte wählen Sie zuerst ein Fahrzeug aus.";
    echo '<script>setTimeout(function() { window.location.href = ' . json_encode($redirect_url) . '; }, 3000);</script>';
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
                $res_einheit = (int)($_POST['einheit_id'] ?? $einheit_id);
                $availability_check = check_loeschfahrzeug_availability_warning([$vehicle_id], $start_datetime, $end_datetime, $res_einheit > 0 ? $res_einheit : null);
                if (!empty($availability_check['warning'])) {
                    $availability_warnings[] = "Warnung Löschfahrzeug-Verfügbarkeit: Insgesamt {$availability_check['total_count']}, nach Reservierung belegt {$availability_check['reserved_after_count']}, verbleibend {$availability_check['remaining_after']} (Mindestwert {$availability_check['min_available']}).";
                }
                try {
                    $db->exec("ALTER TABLE reservations ADD COLUMN einheit_id INT NULL");
                } catch (Exception $e) {}
                $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts, status, einheit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode([]), 'pending', $res_einheit > 0 ? $res_einheit : null]);
                
                echo '<script>console.log("✅ Konflikt-Reservierung erfolgreich gespeichert - Sende E-Mails");</script>';
                
                // E-Mail nur an explizit ausgewählte Benutzer der Einheit (settings-reservations Fahrzeug-Tab)
                $admin_emails = [];
                try {
                    if ($res_einheit <= 0) {
                        $stmt_v = $db->prepare("SELECT einheit_id FROM vehicles WHERE id = ?");
                        $stmt_v->execute([$vehicle_id]);
                        $res_einheit = (int)($stmt_v->fetchColumn() ?: 0);
                    }
                    if ($res_einheit > 0) {
                        $settings = load_settings_for_einheit($db, $res_einheit);
                        $ids_json = $settings['reservation_notification_user_ids'] ?? '';
                        if ($ids_json !== '') {
                            $ids = json_decode($ids_json, true);
                            if (is_array($ids) && !empty($ids)) {
                                $amern_id = function_exists('get_einheit_amern_id') ? get_einheit_amern_id($db) : 0;
                                $ph = implode(',', array_fill(0, count($ids), '?'));
                                $unit_filter = ($amern_id > 0 && $res_einheit === $amern_id)
                                    ? " AND (einheit_id = ? OR einheit_id IS NULL)"
                                    : " AND einheit_id = ?";
                                $stmt = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND is_active = 1 AND (COALESCE(is_system_user, 0) = 0) AND email IS NOT NULL AND email != ''" . $unit_filter);
                                $params = array_merge(array_map('intval', $ids), [$res_einheit]);
                                $stmt->execute($params);
                                $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            }
                        }
                    }
                    echo '<script>console.log("🔍 Admin-E-Mails gefunden:", ' . count($admin_emails) . ');</script>';
                } catch (Exception $e) {
                    echo '<script>console.log("❌ Fehler beim Laden der Admin-E-Mails:", ' . json_encode($e->getMessage()) . ');</script>';
                }
                
                if (!empty($admin_emails) && $res_einheit > 0) {
                    // Fahrzeug-Name für E-Mail laden
                    $stmt = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
                    $stmt->execute([$vehicle_id]);
                    $vehicle = $stmt->fetch();
                    $vehicle_name = $vehicle ? $vehicle['name'] : 'Unbekanntes Fahrzeug';
                    
                    $subject = "🔔 Neue Fahrzeugreservierung (mit Konflikt) - " . $vehicle_name;
                    
                    // Basis-URL für Links in E-Mails: bevorzugt aus Einstellungen 'app_url'
                    try {
                        $stmtApp = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_url'");
                        $stmtApp->execute();
                        $appUrl = $stmtApp->fetchColumn();
                        if (!$appUrl) {
                            $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                        }
                    } catch (Exception $e) {
                        $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                    }
                    
                    $manageUrl = $appUrl . '/admin/dashboard.php';

                    $message_content = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa; padding: 20px;'>
                        <div style='background-color: #ffc107; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
                            <h1 style='margin: 0; font-size: 24px;'>⚠️ Neue Reservierung mit Konflikt eingegangen</h1>
                        </div>
                        <div style='background-color: white; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <p style='font-size: 16px; color: #333; margin-bottom: 25px;'>Ein neuer Antrag für eine Fahrzeugreservierung ist eingegangen, der Überschneidungen mit bestehenden Reservierungen aufweist.</p>
                            
                            <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                                <h3 style='margin: 0 0 15px 0; color: #856404; font-size: 18px;'>⚠️ Konflikt-Hinweis</h3>
                                <p style='margin: 0; color: #856404;'>Diese Reservierung überschneidet sich mit bestehenden Reservierungen. Bitte prüfen Sie die Verfügbarkeit.</p>
                            </div>
                            
                            <div style='background-color: #e3f2fd; border-left: 4px solid #007bff; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                                <h3 style='margin: 0 0 15px 0; color: #007bff; font-size: 18px;'>📋 Antragsdetails</h3>
                                <table style='width: 100%; border-collapse: collapse;'>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeug:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($vehicle_name) . "</td>
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
                                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📍 Standort:</td>
                                        <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($location) . "</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; font-weight: bold; color: #555; vertical-align: top;'>📅 Zeitraum:</td>
                                        <td style='padding: 8px 0; color: #333;'>
                                            <div style='background-color: #f8f9fa; padding: 10px; margin: 5px 0; border-radius: 4px; border-left: 3px solid #ffc107;'>
                                                <strong>Zeitraum (" . htmlspecialchars($vehicle_name) . "):</strong> " . format_datetime($start_datetime) . " - " . format_datetime($end_datetime) . "
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                                <p style='margin: 0; color: #856404; font-size: 14px;'>
                                    <strong>⏰ Wichtig:</strong> Diese Reservierung hat Konflikte mit bestehenden Reservierungen. Bitte prüfen Sie die Verfügbarkeit und entscheiden Sie über die Genehmigung.
                                </p>
                            </div>
                            
                            <div style='text-align: center; margin: 25px 0;'>
                                <a href='" . $manageUrl . "' 
                                   style='background-color: #ffc107; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>
                                    ⚠️ Antrag mit Konflikt bearbeiten
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
                        $email_sent = send_email_for_einheit($admin_email, $subject, $message_content, $res_einheit, true);
                        if ($email_sent) {
                            echo '<script>console.log("✅ Konflikt-E-Mail gesendet an:", ' . json_encode($admin_email) . ');</script>';
                        } else {
                            echo '<script>console.log("❌ Konflikt-E-Mail fehlgeschlagen an:", ' . json_encode($admin_email) . ');</script>';
                        }
                    }
                }
                
                $message = "Reservierung wurde trotz Konflikt erfolgreich eingereicht. Bitte beachten Sie, dass es Überschneidungen mit anderen Reservierungen geben kann.";
                if (!empty($availability_warnings)) {
                    $message .= ' ' . implode(' ', $availability_warnings);
                }
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

            // Fallback: Wenn keine vehicle_ids[] gesendet wurden, aus vehicle_data ableiten
            if (empty($vehicle_ids)) {
                $rawVehicleData = $_POST['vehicle_data'] ?? '';
                if (!empty($rawVehicleData)) {
                    $decoded = json_decode($rawVehicleData, true);
                    if (is_array($decoded) && isset($decoded['id'])) {
                        $vehicle_ids = [ (int)$decoded['id'] ];
                        echo '<script>console.log("✅ Fallback vehicle_ids aus vehicle_data gesetzt:", ' . json_encode($vehicle_ids) . ');</script>';
                    }
                }
            }
            
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
            $override_availability_warning = isset($_POST['override_availability_warning']) && $_POST['override_availability_warning'] === '1';
            
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

                $res_einheit = (int)($_POST['einheit_id'] ?? $einheit_id);
                $availability_check = check_loeschfahrzeug_availability_warning($vehicle_ids, $start_datetime, $end_datetime, $res_einheit > 0 ? $res_einheit : null);
                if (!empty($availability_check['warning'])) {
                    $availability_warnings[] = "Zeitraum " . ($index + 1) . ": Warnung Löschfahrzeug-Verfügbarkeit – insgesamt {$availability_check['total_count']}, danach belegt {$availability_check['reserved_after_count']}, verbleibend {$availability_check['remaining_after']} (Mindestwert {$availability_check['min_available']}).";
                    if (!$override_availability_warning) {
                        $overlap_hint = '';
                        if (!empty($availability_check['overlapping_reservations'][0])) {
                            $ov = $availability_check['overlapping_reservations'][0];
                            $overlap_hint = ' Bereits reserviert: ' . ($ov['vehicle_name'] ?? 'Fahrzeug') . ' von ' . ($ov['requester_name'] ?? 'Unbekannt') . (!empty($ov['reason']) ? ' (Grund: ' . $ov['reason'] . ')' : '') . '.';
                        }
                        $error = "Achtung: Das ausgewählte Löschfahrzeug ist das letzte verfügbare Fahrzeug in Zeitraum " . ($index + 1) . ". Bitte bestätigen Sie die Warnung im Dialog." . $overlap_hint;
                        $availability_modal_data = [
                            'index' => $index + 1,
                            'start' => $start_datetime,
                            'end' => $end_datetime,
                            'warning' => $availability_check,
                        ];
                        break;
                    }
                }
                
                // Debug: Validierung erfolgreich
                
                // Reservierung für alle ausgewählten Fahrzeuge speichern
                $res_einheit = (int)($_POST['einheit_id'] ?? $einheit_id);
                foreach ($vehicle_ids as $vehicle_id) {
                    try {
                        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts, status, einheit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode([]), 'pending', $res_einheit > 0 ? $res_einheit : null]);
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
            
            if ($error === '' && $success_count > 0) {
                echo '<script>console.log("✅ Reservierungen erfolgreich gespeichert - Sende E-Mails");</script>';
                
                // E-Mail nur an explizit ausgewählte Benutzer der Einheit (settings-reservations Fahrzeug-Tab)
                $admin_emails = [];
                try {
                    $res_einheit = (int)($_POST['einheit_id'] ?? $einheit_id);
                    if ($res_einheit <= 0 && !empty($vehicle_ids)) {
                        $stmt_v = $db->prepare("SELECT einheit_id FROM vehicles WHERE id = ?");
                        $stmt_v->execute([$vehicle_ids[0]]);
                        $res_einheit = (int)($stmt_v->fetchColumn() ?: 0);
                    }
                    if ($res_einheit > 0) {
                        $settings = load_settings_for_einheit($db, $res_einheit);
                        $ids_json = $settings['reservation_notification_user_ids'] ?? '';
                        if ($ids_json !== '') {
                            $ids = json_decode($ids_json, true);
                            if (is_array($ids) && !empty($ids)) {
                                $amern_id = function_exists('get_einheit_amern_id') ? get_einheit_amern_id($db) : 0;
                                $ph = implode(',', array_fill(0, count($ids), '?'));
                                $unit_filter = ($amern_id > 0 && $res_einheit === $amern_id)
                                    ? " AND (einheit_id = ? OR einheit_id IS NULL)"
                                    : " AND einheit_id = ?";
                                $stmt = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND is_active = 1 AND (COALESCE(is_system_user, 0) = 0) AND email IS NOT NULL AND email != ''" . $unit_filter);
                                $params = array_merge(array_map('intval', $ids), [$res_einheit]);
                                $stmt->execute($params);
                                $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            }
                        }
                    }
                    echo '<script>console.log("🔍 Admin-E-Mails gefunden:", ' . count($admin_emails) . ');</script>';
                } catch (Exception $e) {
                    echo '<script>console.log("❌ Fehler beim Laden der Admin-E-Mails:", ' . json_encode($e->getMessage()) . ');</script>';
                }
                
                if (!empty($admin_emails) && $res_einheit > 0) {
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
                    
                    // Basis-URL für Links in E-Mails: bevorzugt aus Einstellungen 'app_url'
                    try {
                        $stmtApp = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_url'");
                        $stmtApp->execute();
                        $appUrlValue = trim((string)$stmtApp->fetchColumn());
                    } catch (Exception $e) {
                        $appUrlValue = '';
                    }
                    $baseUrl = rtrim($appUrlValue !== '' ? $appUrlValue : ('http://' . $_SERVER['HTTP_HOST']), '/');
                    $manageUrl = $baseUrl . '/admin/dashboard.php';

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
                                <a href='" . htmlspecialchars($manageUrl, ENT_QUOTES, 'UTF-8') . "' 
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
                        $email_sent = send_email_for_einheit($admin_email, $subject, $message_content, $res_einheit, true);
                        if ($email_sent) {
                            echo '<script>console.log("✅ E-Mail gesendet an:", ' . json_encode($admin_email) . ');</script>';
                        } else {
                            echo '<script>console.log("❌ E-Mail fehlgeschlagen an:", ' . json_encode($admin_email) . ');</script>';
                        }
                    }
                }
                
                // Google Calendar Event wird erst bei der Genehmigung erstellt
                
                if (empty($errors)) {
                    $message = "Alle $success_count Reservierungen wurden erfolgreich eingereicht. Sie erhalten eine E-Mail, sobald über Ihre Anträge entschieden wurde.";
                    if (!empty($availability_warnings)) {
                        $message .= ' ' . implode(' ', $availability_warnings);
                    }
                    echo '<script>console.log("✅ Erfolgreiche Reservierung - Weiterleitung zur Startseite");</script>';
                    // Weiterleitung zur Startseite nach 3 Sekunden
                    $redirect_to_home = true;
                } else {
                    $message = "$success_count Reservierungen wurden erfolgreich eingereicht. " . implode(' ', $errors);
                    if (!empty($availability_warnings)) {
                        $message .= ' ' . implode(' ', $availability_warnings);
                    }
                    echo '<script>console.log("⚠️ Teilweise erfolgreiche Reservierung mit Fehlern");</script>';
                }
            } elseif ($error === '') {
                $error = "Keine Reservierungen konnten gespeichert werden. " . implode(' ', $errors);
                echo '<script>console.log("❌ Keine Reservierungen gespeichert");</script>';
            }
        }
        }
    }
}

$posted_timeframes = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ti = 0;
    while (isset($_POST["start_datetime_$ti"]) && isset($_POST["end_datetime_$ti"])) {
        $st = trim((string)($_POST["start_datetime_$ti"] ?? ''));
        $en = trim((string)($_POST["end_datetime_$ti"] ?? ''));
        if ($st !== '' && $en !== '') {
            $posted_timeframes[] = ['start' => $st, 'end' => $en];
        }
        $ti++;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (isset($redirect_to_home) && $redirect_to_home): ?>
    <meta http-equiv="refresh" content="3;url=<?php echo htmlspecialchars('index.php' . $einheit_param, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <title>Fahrzeug Reservierung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .save-processing-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(1px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            pointer-events: all;
        }
        .save-processing-overlay.active { display: flex; }
        .save-processing-box {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php else: ?>
                <?php include __DIR__ . '/includes/system-user-nav.inc.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
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
                        
                        <?php if ($error && !$availability_modal_data): ?>
                            <?php echo show_error($error); ?>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="reservationForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
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
                                <a href="vehicle-selection.php<?php echo $einheit_param; ?>" class="btn btn-primary btn-sm mt-2">
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
                                    <?php
                                    $render_timeframes = !empty($posted_timeframes) ? $posted_timeframes : [['start' => '', 'end' => '']];
                                    foreach ($render_timeframes as $tf_idx => $tf):
                                        $st_val = (string)($tf['start'] ?? '');
                                        $en_val = (string)($tf['end'] ?? '');
                                        $d_val = '';
                                        $st_time = '';
                                        $en_time = '';
                                        if ($st_val !== '' && strpos($st_val, 'T') !== false) {
                                            $parts = explode('T', $st_val, 2);
                                            $d_val = $parts[0];
                                            $st_time = substr($parts[1], 0, 5);
                                        }
                                        if ($en_val !== '' && strpos($en_val, 'T') !== false) {
                                            $parts = explode('T', $en_val, 2);
                                            $en_time = substr($parts[1], 0, 5);
                                        }
                                    ?>
                                    <div class="timeframe-row row mb-3 g-3">
                                        <div class="col-md-4">
                                            <label class="form-label" style="white-space: nowrap;">Datum <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control timeframe-date" value="<?php echo htmlspecialchars($d_val); ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label" style="white-space: nowrap;">Von (Uhrzeit) <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control timeframe-start-time" value="<?php echo htmlspecialchars($st_time); ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label" style="white-space: nowrap;">Bis (Uhrzeit) <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control timeframe-end-time" value="<?php echo htmlspecialchars($en_time); ?>" required>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-timeframe w-100" style="display: <?php echo count($render_timeframes) > 1 ? 'block' : 'none'; ?>;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <input type="datetime-local" class="start-datetime d-none" name="start_datetime_<?php echo (int)$tf_idx; ?>" value="<?php echo htmlspecialchars($st_val); ?>">
                                        <input type="datetime-local" class="end-datetime d-none" name="end_datetime_<?php echo (int)$tf_idx; ?>" value="<?php echo htmlspecialchars($en_val); ?>">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="index.php<?php echo $einheit_param; ?>" class="btn btn-outline-secondary me-md-2">
                                    <i class="fas fa-arrow-left"></i> Zurück zur Startseite
                                </a>
                                <?php if (isset($selectedVehicle['name'])): ?>
                                <button type="submit" name="submit_reservation" id="submitReservationBtn" class="btn btn-primary">
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
    <div id="saveProcessingOverlay" class="save-processing-overlay<?php echo (isset($redirect_to_home) && $redirect_to_home) ? ' active' : ''; ?>" aria-live="polite" aria-hidden="<?php echo (isset($redirect_to_home) && $redirect_to_home) ? 'false' : 'true'; ?>">
        <div class="save-processing-box text-center">
            <div class="spinner-border text-primary mb-2" role="status" aria-hidden="true"></div>
            <div class="small text-muted">Reservierung wird gespeichert und verarbeitet...</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sofortiges Bestätigungs-Modal + Submit-Sperre, um Doppel-Submits zu verhindern
        document.addEventListener('DOMContentLoaded', function() {
            // Hauptformular gezielt per ID holen (robuster gegen andere Formulare)
            const form = document.getElementById('reservationForm');
            const submitBtn = document.getElementById('submitReservationBtn');
            const processingOverlay = document.getElementById('saveProcessingOverlay');
            if (!form || !submitBtn) return;

            let alreadySubmitting = false;
            let availabilityWarningConfirmed = false;

            function showPendingModal() { /* Modal deaktiviert */ }

            function lockButton() {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Wird gesendet...';
                if (processingOverlay) {
                    processingOverlay.classList.add('active');
                    processingOverlay.setAttribute('aria-hidden', 'false');
                }
            }

            function collectTimeframes() {
                const rows = Array.from(document.querySelectorAll('.timeframe-row'));
                const timeframes = [];
                rows.forEach(function(row) {
                    const start = (row.querySelector('.start-datetime') || {}).value || '';
                    const end = (row.querySelector('.end-datetime') || {}).value || '';
                    if (start && end) timeframes.push({ start: start, end: end });
                });
                return timeframes;
            }

            function syncTimeframeRow(row) {
                if (!row) return;
                const dateInput = row.querySelector('.timeframe-date');
                const startTimeInput = row.querySelector('.timeframe-start-time');
                const endTimeInput = row.querySelector('.timeframe-end-time');
                const startHidden = row.querySelector('.start-datetime');
                const endHidden = row.querySelector('.end-datetime');
                if (!dateInput || !startTimeInput || !endTimeInput || !startHidden || !endHidden) return;
                const d = dateInput.value || '';
                const s = startTimeInput.value || '';
                const e = endTimeInput.value || '';
                startHidden.value = (d && s) ? (d + 'T' + s) : '';
                endHidden.value = (d && e) ? (d + 'T' + e) : '';
            }

            function syncAllTimeframes() {
                document.querySelectorAll('.timeframe-row').forEach(syncTimeframeRow);
            }

            function formatDateTime(v) {
                if (!v) return '-';
                const d = new Date(v);
                if (isNaN(d.getTime())) return v;
                return d.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
            }

            function buildAvailabilityWarningHtml(warnings) {
                let html = '';
                warnings.forEach(function(w, idx) {
                    const overlaps = Array.isArray(w.overlapping_reservations) ? w.overlapping_reservations : [];
                    const selectedNames = Array.isArray(w.selected_vehicle_names) ? w.selected_vehicle_names.filter(Boolean) : [];
                    const selectedLabel = selectedNames.length ? selectedNames.join(', ') : 'Das ausgewählte Fahrzeug';
                    const overlapVehicle = overlaps.length ? (overlaps[0].vehicle_name || 'ein anderes Löschfahrzeug') : 'ein anderes Löschfahrzeug';
                    let title = `Achtung: ${selectedLabel} ist das letzte Löschfahrzeug für diesen Zeitraum, da ${overlapVehicle} bereits reserviert wurde.`;
                    if (w.remaining_after <= 0) {
                        title = `Achtung: ${selectedLabel} ist das letzte Löschfahrzeug für diesen Zeitraum.`;
                    }
                    html += `<div class="alert alert-danger mb-3"><h6 class="mb-2">${title}</h6><div class="small mb-2"><strong>Zeitraum ${w.index || (idx + 1)}:</strong> ${formatDateTime(w.start)} - ${formatDateTime(w.end)}<br><strong>Verbleibend:</strong> ${w.remaining_after} (Mindestwert ${w.min_available})</div>`;
                    if (overlaps.length) {
                        html += '<div class="small"><strong>Bereits reserviert:</strong><ul class="mb-0 mt-1">';
                        overlaps.forEach(function(r) {
                            html += `<li><strong>${r.vehicle_name || 'Fahrzeug'}</strong> (${r.status === 'approved' ? 'genehmigt' : 'beantragt'}) von ${r.requester_name || 'Unbekannt'}${r.reason ? ' – Grund: ' + r.reason : ''}</li>`;
                        });
                        html += '</ul></div>';
                    }
                    html += '</div>';
                });
                return html;
            }

            function openAvailabilityWarningModal(warnings) {
                const body = document.getElementById('availabilityWarningContent');
                const btnConfirm = document.getElementById('btnConfirmAvailabilitySubmit');
                const btnCancel = document.getElementById('btnCancelAvailabilitySubmit');
                if (!body || !btnConfirm || !btnCancel) return false;
                body.innerHTML = buildAvailabilityWarningHtml(warnings);
                const modalEl = document.getElementById('availabilityWarningModal');
                if (!modalEl) return false;
                const modal = new bootstrap.Modal(modalEl);
                btnConfirm.onclick = function() {
                    availabilityWarningConfirmed = true;
                    let ov = form.querySelector('input[name="override_availability_warning"]');
                    if (!ov) {
                        ov = document.createElement('input');
                        ov.type = 'hidden';
                        ov.name = 'override_availability_warning';
                        form.appendChild(ov);
                    }
                    ov.value = '1';
                    modal.hide();
                    submitBtn.click();
                };
                btnCancel.onclick = function() {
                    availabilityWarningConfirmed = false;
                    const ov = form.querySelector('input[name="override_availability_warning"]');
                    if (ov) ov.value = '';
                };
                modal.show();
                return true;
            }

            function ensureHiddenSubmitData() {
                // Stelle sicher, dass mindestens ein vehicle_ids[] Feld vorhanden ist
                try {
                    const hasVehicleIds = form.querySelectorAll('input[name="vehicle_ids[]"]').length > 0;
                    if (!hasVehicleIds) {
                        const raw = document.querySelector('input[name="vehicle_data"]').value || '';
                        if (raw) {
                            const obj = JSON.parse(raw);
                            if (obj && obj.id) {
                                const hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = 'vehicle_ids[]';
                                hidden.value = String(obj.id);
                                form.appendChild(hidden);
                            }
                        }
                    }
                } catch (err) { console.warn('Warnung: vehicle_ids Ergänzung fehlgeschlagen', err); }

                // Sicherstellen, dass der Server die Aktion erkennt (submit_reservation)
                try {
                    let submitHidden = form.querySelector('input[name="submit_reservation"]');
                    if (!submitHidden) {
                        submitHidden = document.createElement('input');
                        submitHidden.type = 'hidden';
                        submitHidden.name = 'submit_reservation';
                        submitHidden.value = '1';
                        form.appendChild(submitHidden);
                    }
                } catch (_) {}
            }

            submitBtn.addEventListener('click', function(e) {
                console.log('🟦 Submit-Button geklickt');
                if (alreadySubmitting) {
                    console.log('🟨 Bereits im Submit-Vorgang, verhindere Doppel-Submit');
                    e.preventDefault();
                    return false;
                }
                if (!form.checkValidity()) {
                    console.log('🟥 HTML5-Validierung fehlgeschlagen');
                    e.preventDefault();
                    form.reportValidity && form.reportValidity();
                    return false;
                }
                e.preventDefault();
                syncAllTimeframes();
                if (!form.checkValidity()) {
                    form.reportValidity && form.reportValidity();
                    return false;
                }
                ensureHiddenSubmitData();

                if (availabilityWarningConfirmed) {
                    availabilityWarningConfirmed = false;
                }

                // Erst UI, dann absenden
                alreadySubmitting = true;
                lockButton();
                // Nur Button-Feedback, direkt absenden
                form.submit();
            });

            // Zusätzlich: falls das Formular aus anderen Gründen submitted wird (Enter-Taste), UI auch sperren
            form.addEventListener('submit', function(ev){
                console.log('🟦 Form submit Event');
                if (alreadySubmitting) return;
                syncAllTimeframes();
                if (!form.checkValidity()) {
                    console.log('🟥 HTML5-Validierung im submit-Event fehlgeschlagen');
                    ev.preventDefault();
                    form.reportValidity && form.reportValidity();
                    return;
                }
                ensureHiddenSubmitData();

                alreadySubmitting = true;
                lockButton();
                // Nur Button-Feedback
            });
        });
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
                    
                    // Fahrzeug-Info anzeigen (nur falls Element existiert)
                    const vehicleInfo = document.querySelector('.alert-info p');
                    if (vehicleInfo) {
                        vehicleInfo.innerHTML = `
                            <strong>${vehicleData.name}</strong><br>
                            <small>${vehicleData.description}</small>
                        `;
                        console.log('✅ Fahrzeug-Info angezeigt');
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
                window.location.href = <?php echo json_encode('index.php' . $einheit_param); ?>;
            }
        });
        
        let timeframeCount = document.querySelectorAll('.timeframe-row').length;
        
        // Weitere Zeit hinzufügen
        document.getElementById('add-timeframe').addEventListener('click', function() {
            const timeframesDiv = document.getElementById('timeframes');
            const newTimeframe = document.createElement('div');
            newTimeframe.className = 'timeframe-row row mb-3';
            newTimeframe.innerHTML = `
                <div class="col-md-4">
                    <label class="form-label" style="white-space: nowrap;">Datum <span class="text-danger">*</span></label>
                    <input type="date" class="form-control timeframe-date" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="white-space: nowrap;">Von (Uhrzeit) <span class="text-danger">*</span></label>
                    <input type="time" class="form-control timeframe-start-time" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="white-space: nowrap;">Bis (Uhrzeit) <span class="text-danger">*</span></label>
                    <input type="time" class="form-control timeframe-end-time" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-timeframe w-100">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <input type="datetime-local" class="start-datetime d-none" name="start_datetime_${timeframeCount}">
                <input type="datetime-local" class="end-datetime d-none" name="end_datetime_${timeframeCount}">
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
            const dateInput = timeframeRow.querySelector('.timeframe-date');
            const startTimeInput = timeframeRow.querySelector('.timeframe-start-time');
            const endTimeInput = timeframeRow.querySelector('.timeframe-end-time');
            if (!dateInput || !startTimeInput || !endTimeInput) return;

            function syncAndValidate() {
                syncTimeframeRow(timeframeRow);
                if (dateInput.value && startTimeInput.value && endTimeInput.value && endTimeInput.value <= startTimeInput.value) {
                    endTimeInput.value = '';
                    syncTimeframeRow(timeframeRow);
                    alert('Das Enddatum muss nach dem Startdatum liegen.');
                }
            }
            dateInput.addEventListener('change', syncAndValidate);
            startTimeInput.addEventListener('change', syncAndValidate);
            endTimeInput.addEventListener('change', syncAndValidate);
            
            // Mindestdatum setzen
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            const minDate = now.toISOString().slice(0, 10);
            dateInput.min = minDate;
        }
        
        // Initiale Validierung für vorhandene Zeiträume
        document.querySelectorAll('.timeframe-row').forEach(function(row){ setupTimeframeValidation(row); syncTimeframeRow(row); });
        
        // Automatische Weiterleitung zur Startseite nach erfolgreicher Reservierung
        <?php if (isset($redirect_to_home) && $redirect_to_home): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var processingOverlay = document.getElementById('saveProcessingOverlay');
            if (processingOverlay) {
                processingOverlay.classList.add('active');
                processingOverlay.setAttribute('aria-hidden', 'false');
            }
            setTimeout(function() {
                window.location.href = <?php echo json_encode('index.php' . $einheit_param); ?>;
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

        <?php if (!empty($availability_modal_data)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var modalData = <?php echo json_encode($availability_modal_data, JSON_UNESCAPED_UNICODE); ?>;
            var warn = modalData.warning || {};
            var overlaps = Array.isArray(warn.overlapping_reservations) ? warn.overlapping_reservations : [];
            var selectedNames = Array.isArray(warn.selected_vehicle_names) ? warn.selected_vehicle_names.filter(Boolean) : [];
            var selectedLabel = selectedNames.length ? selectedNames.join(', ') : 'Das ausgewählte Fahrzeug';
            var overlapVehicle = overlaps.length ? (overlaps[0].vehicle_name || 'ein anderes Löschfahrzeug') : 'ein anderes Löschfahrzeug';
            var title = 'Achtung: ' + selectedLabel + ' ist das letzte Löschfahrzeug für diesen Zeitraum, da ' + overlapVehicle + ' bereits reserviert wurde.';
            if ((warn.remaining_after || 0) <= 0) {
                title = 'Achtung: ' + selectedLabel + ' ist das letzte Löschfahrzeug für diesen Zeitraum.';
            }
            function formatDateTimeDe(v) {
                if (!v) return '-';
                var d = new Date(v);
                if (isNaN(d.getTime())) return v;
                return d.toLocaleString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            }

            var html = '<div class="alert alert-danger mb-3"><h6 class="mb-2">' + title + '</h6></div>';
            html += '<div class="border rounded p-2 mb-2 bg-light small">';
            html += '<div><strong>Zeitraum ' + (modalData.index || 1) + '</strong></div>';
            html += '<div class="mt-1"><i class="fas fa-calendar-alt me-1"></i>' + formatDateTimeDe(modalData.start) + ' <span class="mx-1">bis</span> ' + formatDateTimeDe(modalData.end) + '</div>';
            html += '</div>';
            html += '<div class="small mb-2"><strong>Verbleibend:</strong> ' + (warn.remaining_after ?? '-') + ' (Mindestwert ' + (warn.min_available ?? '-') + ')</div>';
            if (overlaps.length) {
                html += '<div class="small"><strong>Bereits reserviert:</strong><ul class="mb-0 mt-1">';
                overlaps.forEach(function(r) {
                    html += '<li><strong>' + (r.vehicle_name || 'Fahrzeug') + '</strong> (' + (r.status === 'approved' ? 'genehmigt' : 'beantragt') + ') von ' + (r.requester_name || 'Unbekannt') + (r.reason ? ' - Grund: ' + r.reason : '') + '</li>';
                });
                html += '</ul></div>';
            }
            html += '</div>';

            var body = document.getElementById('availabilityWarningContent');
            var modalEl = document.getElementById('availabilityWarningModal');
            var btnConfirm = document.getElementById('btnConfirmAvailabilitySubmit');
            var btnCancel = document.getElementById('btnCancelAvailabilitySubmit');
            var form = document.getElementById('reservationForm');
            if (!body || !modalEl || !btnConfirm || !btnCancel || !form) return;
            body.innerHTML = html;
            var modal = new bootstrap.Modal(modalEl);
            btnConfirm.onclick = function() {
                var ov = form.querySelector('input[name="override_availability_warning"]');
                if (!ov) {
                    ov = document.createElement('input');
                    ov.type = 'hidden';
                    ov.name = 'override_availability_warning';
                    form.appendChild(ov);
                }
                ov.value = '1';
                var sr = form.querySelector('input[name="submit_reservation"]');
                if (!sr) {
                    sr = document.createElement('input');
                    sr.type = 'hidden';
                    sr.name = 'submit_reservation';
                    sr.value = '1';
                    form.appendChild(sr);
                }
                var submitBtn = document.getElementById('submitReservationBtn');
                var processingOverlay = document.getElementById('saveProcessingOverlay');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Wird gesendet...';
                }
                if (processingOverlay) {
                    processingOverlay.classList.add('active');
                    processingOverlay.setAttribute('aria-hidden', 'false');
                }
                try {
                    document.querySelectorAll('.timeframe-row').forEach(function(row){
                        var d = row.querySelector('.timeframe-date');
                        var s = row.querySelector('.timeframe-start-time');
                        var e = row.querySelector('.timeframe-end-time');
                        var sh = row.querySelector('.start-datetime');
                        var eh = row.querySelector('.end-datetime');
                        if (d && s && sh) sh.value = (d.value && s.value) ? (d.value + 'T' + s.value) : '';
                        if (d && e && eh) eh.value = (d.value && e.value) ? (d.value + 'T' + e.value) : '';
                    });
                } catch (_) {}
                modal.hide();
                form.submit();
            };
            btnCancel.onclick = function() {
                modal.hide();
            };
            modal.show();
        });
        <?php endif; ?>
    </script>
    
    <!-- Pending-Modal entfernt: Button zeigt Status an -->

    <div class="modal fade" id="availabilityWarningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Warnung zur Löschfahrzeug-Verfügbarkeit</h5>
                </div>
                <div class="modal-body" id="availabilityWarningContent"></div>
                <div class="modal-footer">
                    <button type="button" id="btnCancelAvailabilitySubmit" class="btn btn-secondary"><i class="fas fa-times me-1"></i>Abbrechen</button>
                    <button type="button" id="btnConfirmAvailabilitySubmit" class="btn btn-danger"><i class="fas fa-exclamation-triangle me-1"></i>Trotzdem beantragen</button>
                </div>
            </div>
        </div>
    </div>

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
                        <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
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
        // Prüfe, ob die UI-Elemente für die Mehrfach-Fahrzeug-Auswahl vorhanden sind
        const selectedVehiclesListEl = document.getElementById('selected-vehicles-list');
        const addVehicleBtnEl = document.getElementById('add-vehicle-btn');
        const cancelVehicleBtnEl = document.getElementById('cancel-vehicle-btn');
        const availableVehiclesGridEl = document.getElementById('available-vehicles-grid');

        // Falls diese Elemente (bei fehlender Fahrzeugauswahl) nicht existieren, keine Initialisierung ausführen
        if (!selectedVehiclesListEl || !addVehicleBtnEl || !cancelVehicleBtnEl || !availableVehiclesGridEl) {
            return;
        }

        // Globale Variablen
        let selectedVehicles = <?php echo json_encode($selectedVehicles); ?>;
        let availableVehicles = [];

        // Lade verfügbare Fahrzeuge
        loadAvailableVehicles();

        // Initialisiere die Anzeige
        updateSelectedVehiclesList();

        // Event Listener
        addVehicleBtnEl.addEventListener('click', showVehicleButtons);
        cancelVehicleBtnEl.addEventListener('click', hideVehicleButtons);
        
        // Lade verfügbare Fahrzeuge vom Server (einheitsspezifisch)
        function loadAvailableVehicles() {
            const einheitParam = <?php echo json_encode($einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''); ?>;
            fetch('get-available-vehicles.php' + einheitParam)
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
            if (!container) return;
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
            if (!container) return;
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
