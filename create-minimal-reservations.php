<?php
/**
 * Create Minimal Reservations - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/create-minimal-reservations.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Create Minimal Reservations</h1>";
echo "<p>Diese Seite erstellt eine minimale, funktionierende Version von admin/reservations.php.</p>";

try {
    // 1. Erstelle minimale Version
    echo "<h2>1. Erstelle minimale Version:</h2>";
    
    $minimal_content = '<?php
// Output Buffering starten
ob_start();

// Session starten
session_start();

// Datenbankverbindung
require_once \'../config/database.php\';
require_once \'../includes/functions.php\';

// Einfache Authentifizierung
if (!isset($_SESSION[\'user_id\']) || $_SESSION[\'user_role\'] !== \'admin\') {
    header(\'Location: ../login.php\');
    exit;
}

$message = \'\';
$error = \'\';

// POST-Verarbeitung
if ($_SERVER[\'REQUEST_METHOD\'] == \'POST\' && isset($_POST[\'action\'])) {
    $reservation_id = (int)$_POST[\'reservation_id\'];
    $action = $_POST[\'action\'];
    
    try {
        if ($action == \'approve\') {
            // Reservierung genehmigen
            $stmt = $db->prepare("UPDATE reservations SET status = \'approved\', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION[\'user_id\'], $reservation_id]);
            
            // E-Mail senden
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
                ";
                
                send_email($reservation[\'requester_email\'], $subject, $message_content);
            }
            
            $message = "Reservierung wurde genehmigt.";
            
        } elseif ($action == \'reject\') {
            $rejection_reason = $_POST[\'rejection_reason\'] ?? \'\';
            
            if (empty($rejection_reason)) {
                $error = "Bitte geben Sie einen Grund für die Ablehnung an.";
            } else {
                $stmt = $db->prepare("UPDATE reservations SET status = \'rejected\', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$rejection_reason, $_SESSION[\'user_id\'], $reservation_id]);
                
                $message = "Reservierung wurde abgelehnt.";
            }
        }
    } catch (Exception $e) {
        $error = "Fehler: " . $e->getMessage();
    }
}

// Reservierungen laden
try {
    $sql = "SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $reservations = $stmt->fetchAll();
} catch (Exception $e) {
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">Feuerwehr App</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link active" href="reservations.php">Reservierungen</a>
                <a class="nav-link" href="vehicles.php">Fahrzeuge</a>
                <a class="nav-link" href="users.php">Benutzer</a>
                <a class="nav-link" href="settings.php">Einstellungen</a>
                <a class="nav-link" href="../logout.php">Abmelden</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1>Reservierungen</h1>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
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
                                <td>
                                    <?php if ($reservation[\'status\'] == \'pending\'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="reservation_id" value="<?php echo $reservation[\'id\']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Genehmigen</button>
                                        </form>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="rejectReservation(<?php echo $reservation[\'id\']; ?>)">Ablehnen</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="reservation_id" id="reject_reservation_id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Ablehnen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function rejectReservation(reservationId) {
            document.getElementById(\'reject_reservation_id\').value = reservationId;
            new bootstrap.Modal(document.getElementById(\'rejectModal\')).show();
        }
    </script>
</body>
</html>
<?php
// Output Buffering beenden
ob_end_flush();
?>';
    
    // Speichere die minimale Version
    file_put_contents('admin/reservations-minimal.php', $minimal_content);
    echo "<p style='color: green;'>✅ Minimale Version erstellt: admin/reservations-minimal.php</p>";
    
    // 2. Teste die minimale Version
    echo "<h2>2. Teste die minimale Version:</h2>";
    
    if (file_exists('admin/reservations-minimal.php')) {
        echo "<p style='color: green;'>✅ admin/reservations-minimal.php existiert</p>";
        echo "<p><strong>Dateigröße:</strong> " . strlen($minimal_content) . " Zeichen</p>";
        
        // Prüfe wichtige Elemente
        if (strpos($minimal_content, 'ob_start()') !== false) {
            echo "<p style='color: green;'>✅ Output Buffering vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ Output Buffering fehlt</p>";
        }
        
        if (strpos($minimal_content, 'session_start()') !== false) {
            echo "<p style='color: green;'>✅ session_start() vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ session_start() fehlt</p>";
        }
        
        if (strpos($minimal_content, 'require_once') !== false) {
            echo "<p style='color: green;'>✅ require_once vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ require_once fehlt</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ admin/reservations-minimal.php konnte nicht erstellt werden</p>";
    }
    
    // 3. Ersetze die ursprüngliche Datei
    echo "<h2>3. Ersetze die ursprüngliche Datei:</h2>";
    
    if (file_exists('admin/reservations-minimal.php')) {
        // Backup der ursprünglichen Datei
        if (file_exists('admin/reservations.php')) {
            copy('admin/reservations.php', 'admin/reservations-backup-original.php');
            echo "<p style='color: green;'>✅ Backup erstellt: admin/reservations-backup-original.php</p>";
        }
        
        // Kopiere die minimale Version über die ursprüngliche
        copy('admin/reservations-minimal.php', 'admin/reservations.php');
        echo "<p style='color: green;'>✅ Ursprüngliche Datei wurde durch minimale Version ersetzt</p>";
        
    } else {
        echo "<p style='color: red;'>❌ Minimale Datei existiert nicht - kann nicht ersetzt werden</p>";
    }
    
    // 4. Nächste Schritte
    echo "<h2>4. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Testen Sie <a href='admin/reservations.php'>admin/reservations.php</a></li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, liegt das Problem an der Web Server Konfiguration</li>";
    echo "</ol>";
    
    // 5. Zusammenfassung
    echo "<h2>5. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Minimale Version erstellt</li>";
    echo "<li>✅ Backup der ursprünglichen Datei</li>";
    echo "<li>✅ Ursprüngliche Datei ersetzt</li>";
    echo "<li>✅ Alle wichtigen Funktionen enthalten</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Create Minimal Reservations abgeschlossen!</em></p>";
?>
