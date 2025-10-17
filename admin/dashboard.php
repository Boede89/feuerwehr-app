<?php
// Dashboard mit berechtigungsbasierten Bereichen
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session starten bevor alles andere
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datenbankverbindung direkt hier
$host = "mysql";
$dbname = "feuerwehr_app";
$username = "feuerwehr_user";
$password = "feuerwehr_password";

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Datenbankfehler: " . $e->getMessage());
}

// Functions laden für Berechtigungsprüfungen
require_once '../includes/functions.php';

// Login-Prüfung
if (!isset($_SESSION["user_id"])) {
    echo '<script>window.location.href = "../login.php";</script>';
    exit();
}

// Benutzer laden
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    echo '<script>window.location.href = "../login.php";</script>';
    exit();
}

// Berechtigungen prüfen
$can_reservations = has_permission('reservations');
$can_atemschutz = has_permission('atemschutz');
$can_users = has_permission('users');
$can_settings = has_permission('settings');
$can_vehicles = has_permission('vehicles');

// Reservierungen laden (nur wenn berechtigt)
$pending_reservations = [];
if ($can_reservations) {
    try {
        $stmt = $db->prepare("
            SELECT r.*, v.name as vehicle_name, v.type as vehicle_type 
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            WHERE r.status = 'pending' 
            ORDER BY r.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fehler ignorieren, wird in Debug angezeigt
    }
}

// Atemschutz-Warnungen laden (nur wenn berechtigt)
$atemschutz_warnings = [];
if ($can_atemschutz) {
    try {
        // Sicherstellen, dass Tabelle existiert
        $db->exec("
            CREATE TABLE IF NOT EXISTS atemschutz_traeger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NULL,
                birthdate DATE NOT NULL,
                strecke_am DATE NOT NULL,
                g263_am DATE NOT NULL,
                uebung_am DATE NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'Aktiv',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Warnschwelle laden (Standard: 90 Tage)
        $warn_days = 90;
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
        $stmt->execute();
        $setting = $stmt->fetch();
        if ($setting && is_numeric($setting['setting_value'])) {
            $warn_days = (int)$setting['setting_value'];
        }
        
        $warn_date = date('Y-m-d', strtotime("+{$warn_days} days"));
        
        $stmt = $db->prepare("
            SELECT *, 
                CASE 
                    WHEN strecke_am <= CURDATE() THEN 'Abgelaufen'
                    WHEN strecke_am <= ? THEN 'Warnung'
                    ELSE 'OK'
                END as strecke_status,
                CASE 
                    WHEN g263_am <= CURDATE() THEN 'Abgelaufen'
                    WHEN g263_am <= ? THEN 'Warnung'
                    ELSE 'OK'
                END as g263_status,
                CASE 
                    WHEN uebung_am <= CURDATE() THEN 'Abgelaufen'
                    WHEN uebung_am <= ? THEN 'Warnung'
                    ELSE 'OK'
                END as uebung_status
            FROM atemschutz_traeger 
            WHERE status = 'Aktiv' 
            AND (strecke_am <= ? OR g263_am <= ? OR uebung_am <= ?)
            ORDER BY 
                CASE WHEN strecke_am <= CURDATE() THEN 1 ELSE 2 END,
                CASE WHEN g263_am <= CURDATE() THEN 1 ELSE 2 END,
                CASE WHEN uebung_am <= CURDATE() THEN 1 ELSE 2 END,
                strecke_am ASC, g263_am ASC, uebung_am ASC
            LIMIT 10
        ");
        $stmt->execute([$warn_date, $warn_date, $warn_date, $warn_date, $warn_date, $warn_date]);
        $atemschutz_warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fehler ignorieren, wird in Debug angezeigt
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .status-expired {
            background-color: #dc3545;
            color: white;
        }
        .status-warning {
            background-color: #ffc107;
            color: #000;
        }
        .status-ok {
            background-color: #28a745;
            color: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">Hallo, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</span>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                    <small class="text-muted">Willkommen, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</small>
                </h1>
            </div>
        </div>

        <div class="row">
            <!-- Reservierungen Bereich -->
            <?php if ($can_reservations): ?>
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar"></i> Offene Reservierungen
                        </h5>
                        <span class="badge bg-light text-dark"><?php echo count($pending_reservations); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p>Keine offenen Reservierungen</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($pending_reservations as $reservation): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start">
                                    <div class="ms-2 me-auto">
                                        <div class="fw-bold"><?php echo htmlspecialchars($reservation['vehicle_name']); ?></div>
                                        <small class="text-muted">
                                            <?php echo date('d.m.Y H:i', strtotime($reservation['start_datetime'])); ?> - 
                                            <?php echo date('d.m.Y H:i', strtotime($reservation['end_datetime'])); ?>
                                        </small>
                                        <div class="small text-muted mt-1">
                                            <?php echo htmlspecialchars($reservation['requester_name']); ?> - 
                                            <?php echo htmlspecialchars(substr($reservation['reason'], 0, 50)); ?><?php echo strlen($reservation['reason']) > 50 ? '...' : ''; ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-warning text-dark">Ausstehend</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="reservations.php" class="btn btn-primary w-100">
                            <i class="fas fa-calendar-alt"></i> Alle Reservierungen verwalten
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Atemschutz Bereich -->
            <?php if ($can_atemschutz): ?>
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-mask"></i> Atemschutz-Warnungen
                        </h5>
                        <span class="badge bg-light text-dark"><?php echo count($atemschutz_warnings); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($atemschutz_warnings)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p>Alle Geräteträger sind aktuell</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($atemschutz_warnings as $traeger): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']); ?></div>
                                            <div class="small text-muted">
                                                <div>Strecke: <span class="badge status-badge status-<?php echo $traeger['strecke_status'] === 'Abgelaufen' ? 'expired' : ($traeger['strecke_status'] === 'Warnung' ? 'warning' : 'ok'); ?>"><?php echo $traeger['strecke_status']; ?></span> (<?php echo date('d.m.Y', strtotime($traeger['strecke_am'])); ?>)</div>
                                                <div>G26.3: <span class="badge status-badge status-<?php echo $traeger['g263_status'] === 'Abgelaufen' ? 'expired' : ($traeger['g263_status'] === 'Warnung' ? 'warning' : 'ok'); ?>"><?php echo $traeger['g263_status']; ?></span> (<?php echo date('d.m.Y', strtotime($traeger['g263_am'])); ?>)</div>
                                                <div>Übung: <span class="badge status-badge status-<?php echo $traeger['uebung_status'] === 'Abgelaufen' ? 'expired' : ($traeger['uebung_status'] === 'Warnung' ? 'warning' : 'ok'); ?>"><?php echo $traeger['uebung_status']; ?></span> (<?php echo date('d.m.Y', strtotime($traeger['uebung_am'])); ?>)</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="atemschutz.php" class="btn btn-danger w-100">
                            <i class="fas fa-mask"></i> Atemschutz verwalten
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Weitere Bereiche -->
        <div class="row">
            <div class="col-12">
                <h4 class="mt-4 mb-3">Verfügbare Bereiche</h4>
                <div class="row">
                    <?php if ($can_reservations): ?>
                    <div class="col-md-3 mb-2">
                        <a href="reservations.php" class="btn btn-outline-primary w-100">
                            <i class="fas fa-calendar"></i> Reservierungen
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($can_atemschutz): ?>
                    <div class="col-md-3 mb-2">
                        <a href="atemschutz.php" class="btn btn-outline-danger w-100">
                            <i class="fas fa-mask"></i> Atemschutz
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($can_users): ?>
                    <div class="col-md-3 mb-2">
                        <a href="users.php" class="btn btn-outline-success w-100">
                            <i class="fas fa-users"></i> Benutzer
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($can_vehicles): ?>
                    <div class="col-md-3 mb-2">
                        <a href="vehicles.php" class="btn btn-outline-info w-100">
                            <i class="fas fa-truck"></i> Fahrzeuge
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($can_settings): ?>
                    <div class="col-md-3 mb-2">
                        <a href="settings.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-cog"></i> Einstellungen
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Debug Information (nur für Admins) -->
        <?php if (has_permission('admin')): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-bug"></i> Debug Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Session Information:</h6>
                                <p><strong>Session ID:</strong> <?php echo session_id(); ?></p>
                                <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                                <p><strong>Datenbankverbindung:</strong> <?php echo isset($db) && $db ? 'Erfolgreich' : 'Fehler'; ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6>Berechtigungen:</h6>
                                <ul class="list-unstyled">
                                    <li><i class="fas fa-<?php echo $can_reservations ? 'check text-success' : 'times text-danger'; ?>"></i> Reservierungen</li>
                                    <li><i class="fas fa-<?php echo $can_atemschutz ? 'check text-success' : 'times text-danger'; ?>"></i> Atemschutz</li>
                                    <li><i class="fas fa-<?php echo $can_users ? 'check text-success' : 'times text-danger'; ?>"></i> Benutzer</li>
                                    <li><i class="fas fa-<?php echo $can_vehicles ? 'check text-success' : 'times text-danger'; ?>"></i> Fahrzeuge</li>
                                    <li><i class="fas fa-<?php echo $can_settings ? 'check text-success' : 'times text-danger'; ?>"></i> Einstellungen</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>