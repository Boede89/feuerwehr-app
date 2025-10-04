<?php
/**
 * Admin Reservations Minimal - Funktionsfähige Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/admin/reservations-minimal.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Einfache Authentifizierung (ohne can_approve_reservations)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

// Status ändern
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
    try {
        if ($action == 'approve') {
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $reservation_id]);
            
            $message = "Reservierung erfolgreich genehmigt.";
            
            // Google Calendar Event erstellen
            try {
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    // Prüfe ob Google Calendar Funktion verfügbar ist
                    if (function_exists('create_google_calendar_event')) {
                        error_log("Google Calendar: Versuche Event für Reservierung #$reservation_id zu erstellen");
                        
                        $event_id = create_google_calendar_event(
                            $reservation['vehicle_name'],
                            $reservation['reason'],
                            $reservation['start_datetime'],
                            $reservation['end_datetime'],
                            $reservation['id']
                        );
                        
                        if ($event_id) {
                            error_log("Google Calendar: Event erfolgreich erstellt - ID: $event_id");
                        } else {
                            error_log("Google Calendar: Event konnte nicht erstellt werden");
                        }
                    } else {
                        error_log('Google Calendar: Funktion create_google_calendar_event nicht verfügbar');
                    }
                } else {
                    error_log("Google Calendar: Reservierung #$reservation_id nicht gefunden");
                }
            } catch (Exception $e) {
                // Google Calendar Fehler loggen
                error_log('Google Calendar Fehler: ' . $e->getMessage());
                error_log('Google Calendar Stack Trace: ' . $e->getTraceAsString());
            }
            
            // E-Mail an Antragsteller senden
            try {
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    $subject = "Fahrzeugreservierung genehmigt - " . $reservation['vehicle_name'];
                    $message_content = "
                    <h2>Fahrzeugreservierung genehmigt</h2>
                    <p>Ihre Fahrzeugreservierung wurde genehmigt.</p>
                    <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                    <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                    <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                    <p>Vielen Dank für Ihre Reservierung!</p>
                    ";
                    
                    send_email($reservation['requester_email'], $subject, $message_content);
                }
            } catch (Exception $e) {
                // E-Mail Fehler ignorieren
                error_log('E-Mail Fehler: ' . $e->getMessage());
            }
            
        } elseif ($action == 'reject') {
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            
            $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$rejection_reason, $_SESSION['user_id'], $reservation_id]);
            
            $message = "Reservierung erfolgreich abgelehnt.";
            
            // E-Mail an Antragsteller senden
            try {
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$reservation_id]);
                $reservation = $stmt->fetch();
                
                if ($reservation) {
                    $subject = "Fahrzeugreservierung abgelehnt - " . $reservation['vehicle_name'];
                    $message_content = "
                    <h2>Fahrzeugreservierung abgelehnt</h2>
                    <p>Ihre Fahrzeugreservierung wurde leider abgelehnt.</p>
                    <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                    <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                    <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                    <p><strong>Ablehnungsgrund:</strong> " . htmlspecialchars($rejection_reason) . "</p>
                    ";
                    
                    send_email($reservation['requester_email'], $subject, $message_content);
                }
            } catch (Exception $e) {
                // E-Mail Fehler ignorieren
                error_log('E-Mail Fehler: ' . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        $error = "Fehler: " . $e->getMessage();
    }
}

// Reservierungen laden
try {
    $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.created_at DESC");
    $reservations = $stmt->fetchAll();
} catch (Exception $e) {
    $reservations = [];
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservierungen verwalten - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1>Reservierungen verwalten</h1>
                
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Alle Reservierungen</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reservations)): ?>
                            <p class="text-muted">Keine Reservierungen gefunden.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
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
                                                <td><?= htmlspecialchars($reservation['id']) ?></td>
                                                <td><?= htmlspecialchars($reservation['vehicle_name']) ?></td>
                                                <td><?= htmlspecialchars($reservation['requester_name']) ?></td>
                                                <td><?= htmlspecialchars($reservation['requester_email']) ?></td>
                                                <td><?= htmlspecialchars($reservation['reason']) ?></td>
                                                <td><?= htmlspecialchars($reservation['start_datetime']) ?></td>
                                                <td><?= htmlspecialchars($reservation['end_datetime']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $reservation['status'] == 'approved' ? 'success' : ($reservation['status'] == 'rejected' ? 'danger' : 'warning') ?>">
                                                        <?= htmlspecialchars($reservation['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($reservation['status'] == 'pending'): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="approve">
                                                            <input type="hidden" name="reservation_id" value="<?= $reservation['id'] ?>">
                                                            <button type="submit" class="btn btn-success btn-sm">Genehmigen</button>
                                                        </form>
                                                        <button type="button" class="btn btn-danger btn-sm" onclick="rejectReservation(<?= $reservation['id'] ?>)">Ablehnen</button>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mt-3">
                    <a href="../index.php" class="btn btn-secondary">Zurück zur Startseite</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Ablehnungs-Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reservierung ablehnen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="rejectForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="reservation_id" id="rejectReservationId">
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Ablehnungsgrund:</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        </div>
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
            document.getElementById('rejectReservationId').value = reservationId;
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }
    </script>
</body>
</html>

<?php
// Output Buffering beenden
ob_end_flush();
?>
