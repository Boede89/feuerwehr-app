<?php
/**
 * Web-Version: Debug-Script für alle Probleme
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Simuliere Admin-Session für Tests
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
}

$debug_results = [];

try {
    // 1. Datenbank-Schema prüfen
    $debug_results['database'] = [];
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    $has_calendar_conflicts = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'calendar_conflicts') {
            $has_calendar_conflicts = true;
            $debug_results['database']['calendar_conflicts'] = true;
            $debug_results['database']['type'] = $column['Type'];
        }
    }
    
    if (!$has_calendar_conflicts) {
        $debug_results['database']['calendar_conflicts'] = false;
    }
    
    // 2. Funktionen prüfen
    $debug_results['functions'] = [
        'check_calendar_conflicts' => function_exists('check_calendar_conflicts'),
        'create_google_calendar_event' => function_exists('create_google_calendar_event'),
        'validate_csrf_token' => function_exists('validate_csrf_token'),
        'has_admin_access' => function_exists('has_admin_access')
    ];
    
    // 3. Google Calendar Klassen prüfen
    $debug_results['classes'] = [
        'GoogleCalendarServiceAccount' => class_exists('GoogleCalendarServiceAccount'),
        'GoogleCalendar' => class_exists('GoogleCalendar')
    ];
    
    // 4. Google Calendar Einstellungen prüfen
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $debug_results['settings'] = $settings;
    
    // 5. Teste Kalender-Konfliktprüfung
    $test_vehicle = 'MTF';
    $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
    $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
    
    $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
    $debug_results['calendar_test'] = [
        'success' => is_array($conflicts),
        'conflicts_count' => is_array($conflicts) ? count($conflicts) : 0,
        'conflicts' => $conflicts
    ];
    
    // 6. Teste Genehmigungsprozess
    $debug_results['approval_test'] = [];
    
    // Prüfe ausstehende Reservierungen
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $pending_reservation = $stmt->fetch();
    
    if ($pending_reservation) {
        $debug_results['approval_test']['has_pending'] = true;
        $debug_results['approval_test']['reservation_id'] = $pending_reservation['id'];
        $debug_results['approval_test']['vehicle_name'] = $pending_reservation['vehicle_name'];
    } else {
        $debug_results['approval_test']['has_pending'] = false;
    }
    
} catch (Exception $e) {
    $debug_results['error'] = $e->getMessage();
    $debug_results['trace'] = $e->getTraceAsString();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1><i class="fas fa-bug"></i> Debug-Report</h1>
        <p>Zeitstempel: <?php echo date('d.m.Y H:i:s'); ?></p>
        
        <?php if (isset($debug_results['error'])): ?>
            <div class="alert alert-danger">
                <h4>❌ Fehler aufgetreten:</h4>
                <p><?php echo htmlspecialchars($debug_results['error']); ?></p>
                <pre><?php echo htmlspecialchars($debug_results['trace']); ?></pre>
            </div>
        <?php endif; ?>
        
        <!-- 1. Datenbank-Schema -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-database"></i> 1. Datenbank-Schema</h3>
            </div>
            <div class="card-body">
                <?php if ($debug_results['database']['calendar_conflicts']): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check"></i> <strong>calendar_conflicts Feld existiert</strong><br>
                        Typ: <?php echo $debug_results['database']['type']; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times"></i> <strong>calendar_conflicts Feld fehlt!</strong><br>
                        <a href="update-database-web.php" class="btn btn-primary mt-2">Datenbank-Update ausführen</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 2. Funktionen -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-cogs"></i> 2. Funktionen</h3>
            </div>
            <div class="card-body">
                <?php foreach ($debug_results['functions'] as $function => $available): ?>
                    <div class="row mb-2">
                        <div class="col-6">
                            <code><?php echo $function; ?></code>
                        </div>
                        <div class="col-6">
                            <?php if ($available): ?>
                                <span class="badge bg-success"><i class="fas fa-check"></i> Verfügbar</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times"></i> Nicht verfügbar</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- 3. Google Calendar Klassen -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-calendar"></i> 3. Google Calendar Klassen</h3>
            </div>
            <div class="card-body">
                <?php foreach ($debug_results['classes'] as $class => $available): ?>
                    <div class="row mb-2">
                        <div class="col-6">
                            <code><?php echo $class; ?></code>
                        </div>
                        <div class="col-6">
                            <?php if ($available): ?>
                                <span class="badge bg-success"><i class="fas fa-check"></i> Verfügbar</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times"></i> Nicht verfügbar</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- 4. Google Calendar Einstellungen -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-cog"></i> 4. Google Calendar Einstellungen</h3>
            </div>
            <div class="card-body">
                <?php foreach ($debug_results['settings'] as $key => $value): ?>
                    <div class="row mb-2">
                        <div class="col-4">
                            <code><?php echo $key; ?></code>
                        </div>
                        <div class="col-8">
                            <?php if ($key === 'google_calendar_service_account_json'): ?>
                                <?php if (empty($value)): ?>
                                    <span class="text-danger">Nicht konfiguriert</span>
                                <?php else: ?>
                                    <span class="text-success">Konfiguriert (<?php echo strlen($value); ?> Zeichen)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (empty($value)): ?>
                                    <span class="text-danger">Nicht konfiguriert</span>
                                <?php else: ?>
                                    <span class="text-success"><?php echo htmlspecialchars($value); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- 5. Kalender-Konfliktprüfung Test -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> 5. Kalender-Konfliktprüfung Test</h3>
            </div>
            <div class="card-body">
                <?php if ($debug_results['calendar_test']['success']): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check"></i> <strong>Konfliktprüfung erfolgreich</strong><br>
                        Gefundene Konflikte: <?php echo $debug_results['calendar_test']['conflicts_count']; ?>
                    </div>
                    
                    <?php if (!empty($debug_results['calendar_test']['conflicts'])): ?>
                        <h5>Gefundene Konflikte:</h5>
                        <ul>
                            <?php foreach ($debug_results['calendar_test']['conflicts'] as $conflict): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($conflict['title']); ?></strong><br>
                                    <small class="text-muted">
                                        <?php echo date('d.m.Y H:i', strtotime($conflict['start'])); ?> - 
                                        <?php echo date('d.m.Y H:i', strtotime($conflict['end'])); ?>
                                    </small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times"></i> <strong>Konfliktprüfung fehlgeschlagen</strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 6. Genehmigungsprozess Test -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-check-circle"></i> 6. Genehmigungsprozess Test</h3>
            </div>
            <div class="card-body">
                <?php if ($debug_results['approval_test']['has_pending']): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Ausstehende Reservierung gefunden</strong><br>
                        ID: <?php echo $debug_results['approval_test']['reservation_id']; ?><br>
                        Fahrzeug: <?php echo htmlspecialchars($debug_results['approval_test']['vehicle_name']); ?>
                    </div>
                    <p><a href="admin/dashboard.php" class="btn btn-primary">Zum Dashboard (Teste Genehmigung)</a></p>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Keine ausstehenden Reservierungen gefunden</strong><br>
                        Erstelle zuerst eine Reservierung über <a href="reservation.php">reservation.php</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center">
            <a href="admin/dashboard.php" class="btn btn-primary">Zum Dashboard</a>
            <a href="admin/reservations.php" class="btn btn-secondary">Zu den Reservierungen</a>
        </div>
    </div>
</body>
</html>
