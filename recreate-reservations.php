<?php
/**
 * Recreate Reservations - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/recreate-reservations.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Recreate Reservations</h1>";
echo "<p>Diese Seite erstellt eine neue, saubere Version von admin/reservations.php.</p>";

try {
    // 1. Backup der ursprünglichen Datei
    echo "<h2>1. Backup der ursprünglichen Datei:</h2>";
    
    if (file_exists('admin/reservations.php')) {
        $backup_content = file_get_contents('admin/reservations.php');
        file_put_contents('admin/reservations-backup.php', $backup_content);
        echo "<p style='color: green;'>✅ Backup erstellt: admin/reservations-backup.php</p>";
    } else {
        echo "<p style='color: red;'>❌ admin/reservations.php existiert nicht!</p>";
        exit;
    }
    
    // 2. Erstelle neue, saubere Version
    echo "<h2>2. Erstelle neue, saubere Version:</h2>";
    
    $new_content = '<?php
// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

session_start();
require_once \'../config/database.php\';
require_once \'../includes/functions.php\';

// Nur für eingeloggte Benutzer mit Genehmiger-Zugriff
if (!can_approve_reservations()) {
    redirect(\'../login.php\');
}

$message = \'\';
$error = \'\';

// Status ändern
if ($_SERVER[\'REQUEST_METHOD\'] == \'POST\' && isset($_POST[\'action\'])) {
    $reservation_id = (int)$_POST[\'reservation_id\'];
    $action = $_POST[\'action\'];
    
    if (!validate_csrf_token($_POST[\'csrf_token\'] ?? \'\')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        try {
            if ($action == \'approve\') {
                $stmt = $db->prepare("UPDATE reservations SET status = \'approved\', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION[\'user_id\'], $reservation_id]);
                
                // Google Calendar Event erstellen
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    $event_id = create_google_calendar_event(
                        $reservation[\'vehicle_name\'],
                        $reservation[\'reason\'],
                        $reservation[\'start_datetime\'],
                        $reservation[\'end_datetime\'],
                        $reservation_id
                    );
                }
                
                // E-Mail an Antragsteller senden
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    $subject = "Reservierung genehmigt - " . $reservation[\'vehicle_name\'];
                    $message_content = "
                    <h2>Reservierung genehmigt</h2>
                    <p>Ihre Reservierung wurde genehmigt.</p>
                    <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation[\'vehicle_name\']) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reservation[\'reason\']) . "</p>
                    <p><strong>Von:</strong> " . htmlspecialchars($reservation[\'start_datetime\']) . "</p>
                    <p><strong>Bis:</strong> " . htmlspecialchars($reservation[\'end_datetime\']) . "</p>
                    <p>Vielen Dank für Ihre Reservierung!</p>
                    ";
                    
                    send_email($reservation[\'requester_email\'], $subject, $message_content);
                }
                
                $message = "Reservierung wurde genehmigt.";
                
            } elseif ($action == \'reject\') {
                $rejection_reason = sanitize_input($_POST[\'rejection_reason\'] ?? \'\');
                
                if (empty($rejection_reason)) {
                    $error = "Bitte geben Sie einen Grund für die Ablehnung an.";
                } else {
                    $stmt = $db->prepare("UPDATE reservations SET status = \'rejected\', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$rejection_reason, $_SESSION[\'user_id\'], $reservation_id]);
                    
                    // E-Mail an Antragsteller senden
                    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                    $stmt->execute([$reservation_id]);
                    $reservation = $stmt->fetch();
                    
                    if ($reservation) {
                        $subject = "Reservierung abgelehnt - " . $reservation[\'vehicle_name\'];
                        $message_content = "
                        <h2>Reservierung abgelehnt</h2>
                        <p>Ihre Reservierung wurde leider abgelehnt.</p>
                        <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation[\'vehicle_name\']) . "</p>
                        <p><strong>Grund:</strong> " . htmlspecialchars($reservation[\'reason\']) . "</p>
                        <p><strong>Von:</strong> " . htmlspecialchars($reservation[\'start_datetime\']) . "</p>
                        <p><strong>Bis:</strong> " . htmlspecialchars($reservation[\'end_datetime\']) . "</p>
                        <p><strong>Ablehnungsgrund:</strong> " . htmlspecialchars($rejection_reason) . "</p>
                        <p>Bitte kontaktieren Sie uns für weitere Informationen.</p>
                        ";
                        
                        send_email($reservation[\'requester_email\'], $subject, $message_content);
                    }
                    
                    $message = "Reservierung wurde abgelehnt.";
                }
            } elseif ($action == \'delete\') {
                $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                $stmt->execute([$reservation_id]);
                $message = "Reservierung wurde gelöscht.";
            }
        } catch (Exception $e) {
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

// Filter und Suche
$status_filter = $_GET[\'status\'] ?? \'all\';
$search = $_GET[\'search\'] ?? \'\';

// Reservierungen laden
$where_conditions = [];
$params = [];

if ($status_filter != \'all\') {
    $where_conditions[] = "r.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(r.requester_name LIKE ? OR r.requester_email LIKE ? OR v.name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_sql = !empty($where_conditions) ? \'WHERE \' . implode(\' AND \', $where_conditions) : \'\';

try {
    $sql = "
        SELECT r.*, v.name as vehicle_name, 
               u.username as approved_by_username
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN users u ON r.approved_by = u.id
        $where_sql
        ORDER BY r.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
    $reservations = [];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservierungen - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a class="nav-link active" href="reservations.php">
                    <i class="fas fa-calendar-check"></i> Reservierungen
                </a>
                <a class="nav-link" href="vehicles.php">
                    <i class="fas fa-truck"></i> Fahrzeuge
                </a>
                <a class="nav-link" href="users.php">
                    <i class="fas fa-users"></i> Benutzer
                </a>
                <a class="nav-link" href="settings.php">
                    <i class="fas fa-cog"></i> Einstellungen
                </a>
                <a class="nav-link" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-calendar-check"></i> Reservierungen</h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter und Suche -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?php echo $status_filter == \'all\' ? \'selected\' : \'\'; ?>>Alle</option>
                                    <option value="pending" <?php echo $status_filter == \'pending\' ? \'selected\' : \'\'; ?>>Ausstehend</option>
                                    <option value="approved" <?php echo $status_filter == \'approved\' ? \'selected\' : \'\'; ?>>Genehmigt</option>
                                    <option value="rejected" <?php echo $status_filter == \'rejected\' ? \'selected\' : \'\'; ?>>Abgelehnt</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="search" class="form-label">Suche</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Name, E-Mail oder Fahrzeug">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i> Suchen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Reservierungen Tabelle -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Fahrzeug</th>
                                        <th>Antragsteller</th>
                                        <th>E-Mail</th>
                                        <th>Grund</th>
                                        <th>Von</th>
                                        <th>Bis</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reservations as $reservation): ?>
                                        <tr>
                                            <td><?php echo $reservation[\'id\']; ?></td>
                                            <td><?php echo htmlspecialchars($reservation[\'vehicle_name\']); ?></td>
                                            <td><?php echo htmlspecialchars($reservation[\'requester_name\']); ?></td>
                                            <td><?php echo htmlspecialchars($reservation[\'requester_email\']); ?></td>
                                            <td><?php echo htmlspecialchars($reservation[\'reason\']); ?></td>
                                            <td><?php echo date(\'d.m.Y H:i\', strtotime($reservation[\'start_datetime\'])); ?></td>
                                            <td><?php echo date(\'d.m.Y H:i\', strtotime($reservation[\'end_datetime\'])); ?></td>
                                            <td>
                                                <?php
                                                $status_class = \'\';
                                                $status_text = \'\';
                                                switch ($reservation[\'status\']) {
                                                    case \'pending\':
                                                        $status_class = \'warning\';
                                                        $status_text = \'Ausstehend\';
                                                        break;
                                                    case \'approved\':
                                                        $status_class = \'success\';
                                                        $status_text = \'Genehmigt\';
                                                        break;
                                                    case \'rejected\':
                                                        $status_class = \'danger\';
                                                        $status_text = \'Abgelehnt\';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                            </td>
                                            <td><?php echo date(\'d.m.Y H:i\', strtotime($reservation[\'created_at\'])); ?></td>
                                            <td>
                                                <?php if ($reservation[\'status\'] == \'pending\'): ?>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-success btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#approveModal" 
                                                                data-reservation-id="<?php echo $reservation[\'id\']; ?>">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#rejectModal" 
                                                                data-reservation-id="<?php echo $reservation[\'id\']; ?>">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                <?php elseif (in_array($reservation[\'status\'], [\'approved\', \'rejected\'])): ?>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-danger btn-sm" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#deleteModal" 
                                                                data-reservation-id="<?php echo $reservation[\'id\']; ?>"
                                                                data-reservation-info="<?php echo htmlspecialchars($reservation[\'vehicle_name\'] . \' - \' . $reservation[\'requester_name\']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
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

    <!-- Genehmigen Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Reservierung genehmigen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Sind Sie sicher, dass Sie diese Reservierung genehmigen möchten?</p>
                        <input type="hidden" name="reservation_id" id="approve_reservation_id">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-success">Genehmigen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Ablehnen Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Reservierung ablehnen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Ablehnungsgrund</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        </div>
                        <input type="hidden" name="reservation_id" id="reject_reservation_id">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Ablehnen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Löschen Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Reservierung löschen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Sind Sie sicher, dass Sie diese Reservierung löschen möchten?</p>
                        <p><strong>Reservierung:</strong> <span id="delete_reservation_info"></span></p>
                        <input type="hidden" name="reservation_id" id="delete_reservation_id">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Löschen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modal Event Listeners
        document.getElementById(\'approveModal\').addEventListener(\'show.bs.modal\', function (event) {
            const button = event.relatedTarget;
            const reservationId = button.getAttribute(\'data-reservation-id\');
            document.getElementById(\'approve_reservation_id\').value = reservationId;
        });

        document.getElementById(\'rejectModal\').addEventListener(\'show.bs.modal\', function (event) {
            const button = event.relatedTarget;
            const reservationId = button.getAttribute(\'data-reservation-id\');
            document.getElementById(\'reject_reservation_id\').value = reservationId;
        });

        document.getElementById(\'deleteModal\').addEventListener(\'show.bs.modal\', function (event) {
            const button = event.relatedTarget;
            const reservationId = button.getAttribute(\'data-reservation-id\');
            const reservationInfo = button.getAttribute(\'data-reservation-info\');
            document.getElementById(\'delete_reservation_id\').value = reservationId;
            document.getElementById(\'delete_reservation_info\').textContent = reservationInfo;
        });
    </script>
</body>
</html>
<?php
// Output Buffering beenden
ob_end_flush();
?>';
    
    // Speichere die neue Version
    file_put_contents('admin/reservations-new.php', $new_content);
    echo "<p style='color: green;'>✅ Neue Version erstellt: admin/reservations-new.php</p>";
    
    // 3. Teste die neue Version
    echo "<h2>3. Teste die neue Version:</h2>";
    
    if (file_exists('admin/reservations-new.php')) {
        echo "<p style='color: green;'>✅ admin/reservations-new.php existiert</p>";
        echo "<p><strong>Dateigröße:</strong> " . strlen($new_content) . " Zeichen</p>";
        
        // Prüfe wichtige Elemente
        if (strpos($new_content, 'ob_start()') !== false) {
            echo "<p style='color: green;'>✅ Output Buffering vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ Output Buffering fehlt</p>";
        }
        
        if (strpos($new_content, 'ob_end_flush()') !== false) {
            echo "<p style='color: green;'>✅ Output Buffering Ende vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ Output Buffering Ende fehlt</p>";
        }
        
        if (strpos($new_content, 'session_start()') !== false) {
            echo "<p style='color: green;'>✅ session_start() vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ session_start() fehlt</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ admin/reservations-new.php konnte nicht erstellt werden</p>";
    }
    
    // 4. Nächste Schritte
    echo "<h2>4. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Testen Sie <a href='admin/reservations-new.php'>admin/reservations-new.php</a></li>";
    echo "<li>Falls es funktioniert, ersetzen Sie die ursprüngliche Datei</li>";
    echo "<li>Falls es nicht funktioniert, liegt das Problem an der Web Server Konfiguration</li>";
    echo "</ol>";
    
    // 5. Ersetze die ursprüngliche Datei
    echo "<h2>5. Ersetze die ursprüngliche Datei:</h2>";
    
    if (file_exists('admin/reservations-new.php')) {
        // Kopiere die neue Version über die ursprüngliche
        copy('admin/reservations-new.php', 'admin/reservations.php');
        echo "<p style='color: green;'>✅ Ursprüngliche Datei wurde ersetzt</p>";
        echo "<p><strong>Hinweis:</strong> Backup ist verfügbar unter admin/reservations-backup.php</p>";
    } else {
        echo "<p style='color: red;'>❌ Neue Datei existiert nicht - kann nicht ersetzt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Recreate Reservations abgeschlossen!</em></p>";
?>
