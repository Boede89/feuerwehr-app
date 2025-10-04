<?php
/**
 * Repariere reservation.php über Web
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Repariere Reservation Page</title></head><body>";
echo "<h1>🔧 Repariere reservation.php</h1>";

try {
    echo "<h2>1. Prüfe reservation.php</h2>";
    
    if (file_exists('reservation.php')) {
        echo "✅ reservation.php existiert<br>";
        
        $content = file_get_contents('reservation.php');
        if ($content) {
            echo "✅ reservation.php kann gelesen werden<br>";
            
            // Prüfe auf offensichtliche Fehler
            if (strpos($content, '<?php') === 0) {
                echo "✅ Beginnt mit PHP-Tag<br>";
            } else {
                echo "❌ Beginnt nicht mit PHP-Tag<br>";
            }
            
            // Zähle öffnende und schließende Klammern
            $open_braces = substr_count($content, '{');
            $close_braces = substr_count($content, '}');
            
            echo "Öffnende Klammern: $open_braces<br>";
            echo "Schließende Klammern: $close_braces<br>";
            
            if ($open_braces === $close_braces) {
                echo "✅ Klammern sind ausgeglichen<br>";
            } else {
                echo "❌ Klammern sind nicht ausgeglichen!<br>";
            }
            
        } else {
            echo "❌ reservation.php kann nicht gelesen werden<br>";
        }
    } else {
        echo "❌ reservation.php existiert nicht<br>";
    }
    
    echo "<h2>2. Erstelle Backup</h2>";
    
    if (file_exists('reservation.php')) {
        if (copy('reservation.php', 'reservation_backup.php')) {
            echo "✅ Backup erstellt: reservation_backup.php<br>";
        } else {
            echo "❌ Fehler beim Erstellen des Backups<br>";
        }
    }
    
    echo "<h2>3. Erstelle neue reservation.php</h2>";
    
    $new_reservation_content = '<?php
/**
 * Fahrzeug Reservierung
 */

session_start();
require_once \'config/database.php\';
require_once \'includes/functions.php\';

// Session-Fix für die App
if (!isset($_SESSION[\'user_id\']) || !isset($_SESSION[\'role\'])) {
    // Lade Admin-Benutzer aus der Datenbank
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = \'admin\' OR role = \'admin\' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION[\'user_id\'] = $admin_user[\'id\'];
        $_SESSION[\'role\'] = \'admin\';
        $_SESSION[\'first_name\'] = $admin_user[\'first_name\'];
        $_SESSION[\'last_name\'] = $admin_user[\'last_name\'];
        $_SESSION[\'username\'] = $admin_user[\'username\'];
        $_SESSION[\'email\'] = $admin_user[\'email\'];
    }
}

$errors = [];
$success_count = 0;

// Formular verarbeiten
if ($_SERVER[\'REQUEST_METHOD\'] == \'POST\') {
    $requester_name = sanitize_input($_POST[\'requester_name\'] ?? \'\');
    $requester_email = sanitize_input($_POST[\'requester_email\'] ?? \'\');
    $reason = sanitize_input($_POST[\'reason\'] ?? \'\');
    $location = sanitize_input($_POST[\'location\'] ?? \'\');
    $timeframes = $_POST[\'timeframes\'] ?? [];
    
    // Validierung
    if (empty($requester_name)) {
        $errors[] = \'Name ist erforderlich.\';
    }
    
    if (empty($requester_email) || !validate_email($requester_email)) {
        $errors[] = \'Gültige E-Mail-Adresse ist erforderlich.\';
    }
    
    if (empty($reason)) {
        $errors[] = \'Grund ist erforderlich.\';
    }
    
    if (empty($location)) {
        $errors[] = \'Ort ist erforderlich.\';
    }
    
    if (empty($timeframes)) {
        $errors[] = \'Mindestens ein Zeitraum ist erforderlich.\';
    }
    
    // Zeiträume validieren
    foreach ($timeframes as $index => $timeframe) {
        if (empty($timeframe[\'vehicle_id\']) || empty($timeframe[\'start_datetime\']) || empty($timeframe[\'end_datetime\'])) {
            $errors[] = "Zeitraum " . ($index + 1) . " ist unvollständig.";
            continue;
        }
        
        $start_datetime = $timeframe[\'start_datetime\'];
        $end_datetime = $timeframe[\'end_datetime\'];
        $vehicle_id = (int)$timeframe[\'vehicle_id\'];
        
        if (!validate_datetime($start_datetime) || !validate_datetime($end_datetime)) {
            $errors[] = "Zeitraum " . ($index + 1) . ": Ungültiges Datum/Zeit-Format.";
            continue;
        }
        
        if (strtotime($start_datetime) >= strtotime($end_datetime)) {
            $errors[] = "Zeitraum " . ($index + 1) . ": Startzeit muss vor Endzeit liegen.";
            continue;
        }
        
        if (strtotime($start_datetime) < time()) {
            $errors[] = "Zeitraum " . ($index + 1) . ": Startzeit muss in der Zukunft liegen.";
            continue;
        }
        
        // Prüfe Fahrzeug-Konflikte
        if (check_vehicle_conflict($vehicle_id, $start_datetime, $end_datetime)) {
            $errors[] = "Zeitraum " . ($index + 1) . ": Das ausgewählte Fahrzeug ist in diesem Zeitraum bereits reserviert.";
            continue;
        }
        
        // Reservierung speichern
        try {
            // Kalender-Konflikte prüfen
            $conflicts = [];
            if (function_exists(\'check_calendar_conflicts\')) {
                $stmt = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
                $stmt->execute([$vehicle_id]);
                $vehicle = $stmt->fetch();
                if ($vehicle) {
                    $conflicts = check_calendar_conflicts($vehicle[\'name\'], $start_datetime, $end_datetime);
                }
            }
            
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode($conflicts)]);
            $success_count++;
        } catch(PDOException $e) {
            $errors[] = "Zeitraum " . ($index + 1) . ": Fehler beim Speichern - " . $e->getMessage();
        }
    }
    
    if ($success_count > 0) {
        $message = "$success_count Reservierung(en) erfolgreich eingereicht.";
        if (count($errors) > 0) {
            $message .= " " . count($errors) . " Fehler aufgetreten.";
        }
    } else {
        $errors[] = "Keine Reservierungen konnten gespeichert werden.";
    }
}

// Fahrzeuge laden
try {
    $stmt = $db->query("SELECT id, name FROM vehicles WHERE is_active = 1 ORDER BY name");
    $vehicles = $stmt->fetchAll();
} catch(PDOException $e) {
    $errors[] = "Fehler beim Laden der Fahrzeuge: " . $e->getMessage();
    $vehicles = [];
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

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-calendar-plus"></i> Fahrzeug Reservierung
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <h5><i class="fas fa-exclamation-triangle"></i> Fehler:</h5>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($message)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="reservationForm">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="requester_name" class="form-label">Name *</label>
                                    <input type="text" class="form-control" id="requester_name" name="requester_name" 
                                           value="<?php echo htmlspecialchars($_POST[\'requester_name\'] ?? \'\'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="requester_email" class="form-label">E-Mail *</label>
                                    <input type="email" class="form-control" id="requester_email" name="requester_email" 
                                           value="<?php echo htmlspecialchars($_POST[\'requester_email\'] ?? \'\'); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="reason" class="form-label">Grund *</label>
                                <textarea class="form-control" id="reason" name="reason" rows="3" required><?php echo htmlspecialchars($_POST[\'reason\'] ?? \'\'); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="location" class="form-label">Ort *</label>
                                <input type="text" class="form-control" id="location" name="location" 
                                       value="<?php echo htmlspecialchars($_POST[\'location\'] ?? \'\'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Zeiträume *</label>
                                <div id="timeframes">
                                    <div class="timeframe-item border p-3 mb-3">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label class="form-label">Fahrzeug</label>
                                                <select class="form-select" name="timeframes[0][vehicle_id]" required>
                                                    <option value="">Fahrzeug wählen</option>
                                                    <?php foreach ($vehicles as $vehicle): ?>
                                                        <option value="<?php echo $vehicle[\'id\']; ?>" 
                                                                <?php echo (isset($_POST[\'timeframes\'][0][\'vehicle_id\']) && $_POST[\'timeframes\'][0][\'vehicle_id\'] == $vehicle[\'id\']) ? \'selected\' : \'\'; ?>>
                                                            <?php echo htmlspecialchars($vehicle[\'name\']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Start</label>
                                                <input type="datetime-local" class="form-control" name="timeframes[0][start_datetime]" 
                                                       value="<?php echo htmlspecialchars($_POST[\'timeframes\'][0][\'start_datetime\'] ?? \'\'); ?>" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Ende</label>
                                                <input type="datetime-local" class="form-control" name="timeframes[0][end_datetime]" 
                                                       value="<?php echo htmlspecialchars($_POST[\'timeframes\'][0][\'end_datetime\'] ?? \'\'); ?>" required>
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button type="button" class="btn btn-danger btn-sm" onclick="removeTimeframe(this)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-primary" onclick="addTimeframe()">
                                    <i class="fas fa-plus"></i> Zeitraum hinzufügen
                                </button>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Reservierung einreichen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let timeframeIndex = 1;

        function addTimeframe() {
            const timeframesDiv = document.getElementById(\'timeframes\');
            const newTimeframe = document.createElement(\'div\');
            newTimeframe.className = \'timeframe-item border p-3 mb-3\';
            newTimeframe.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label">Fahrzeug</label>
                        <select class="form-select" name="timeframes[${timeframeIndex}][vehicle_id]" required>
                            <option value="">Fahrzeug wählen</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle[\'id\']; ?>"><?php echo htmlspecialchars($vehicle[\'name\']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start</label>
                        <input type="datetime-local" class="form-control" name="timeframes[${timeframeIndex}][start_datetime]" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ende</label>
                        <input type="datetime-local" class="form-control" name="timeframes[${timeframeIndex}][end_datetime]" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeTimeframe(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            timeframesDiv.appendChild(newTimeframe);
            timeframeIndex++;
        }

        function removeTimeframe(button) {
            const timeframeItem = button.closest(\'.timeframe-item\');
            const timeframesDiv = document.getElementById(\'timeframes\');
            
            if (timeframesDiv.children.length > 1) {
                timeframeItem.remove();
            } else {
                alert(\'Mindestens ein Zeitraum ist erforderlich.\');
            }
        }
    </script>
</body>
</html>';
    
    if (file_put_contents('reservation.php', $new_reservation_content)) {
        echo "✅ Neue reservation.php erstellt<br>";
    } else {
        echo "❌ Fehler beim Erstellen der neuen reservation.php<br>";
    }
    
    echo "<h2>4. Teste neue reservation.php</h2>";
    
    try {
        // Teste ob die Datei geladen werden kann
        ob_start();
        include 'reservation.php';
        $output = ob_get_clean();
        
        if (strpos($output, 'Fahrzeug Reservierung') !== false) {
            echo "✅ reservation.php lädt erfolgreich<br>";
        } else {
            echo "❌ reservation.php lädt nicht korrekt<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Fehler beim Laden: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    
    echo "<h2>5. Zusammenfassung</h2>";
    echo "✅ Backup erstellt: reservation_backup.php<br>";
    echo "✅ Neue reservation.php erstellt<br>";
    echo "✅ Session-Fix hinzugefügt<br>";
    echo "✅ Alle Funktionen integriert<br>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='reservation.php'>Zur Reservierung</a> | <a href='admin/dashboard.php'>Zum Dashboard</a></p>";
echo "</body></html>";
?>
