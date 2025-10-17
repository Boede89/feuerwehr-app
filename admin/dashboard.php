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
$can_settings = has_permission('settings');

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
        // Fehler ignorieren
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
        // Fehler ignorieren
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
        .bis-badge { 
            padding: .25rem .5rem; 
            border-radius: .375rem; 
            display: inline-block; 
        }
        .bis-warn { 
            background-color: #fff3cd; 
            color: #664d03; 
        }
        .bis-expired { 
            background-color: #dc3545; 
            color: #fff; 
        }
        .warning-item {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .warning-header {
            display: flex;
            justify-content-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        .warning-name {
            font-weight: 600;
            color: #495057;
            margin: 0;
        }
        .warning-reasons {
            margin-top: 0.5rem;
        }
        .warning-reason {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 0.375rem;
        }
        .reason-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }
        .reason-details {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .reason-date {
            font-size: 0.875rem;
            color: #495057;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
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
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">
                <i class="fas fa-tachometer-alt"></i> Dashboard
                <small class="text-muted">Willkommen, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</small>
            </h1>
        </div>

        <!-- Navigation Buttons -->
        <div class="row g-2 mb-4">
            <?php if ($can_reservations): ?>
            <div class="col-12 col-md-4">
                <a href="reservations.php" class="btn btn-primary w-100">
                    <i class="fas fa-calendar"></i> Reservierungen
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($can_atemschutz): ?>
            <div class="col-12 col-md-4">
                <a href="atemschutz.php" class="btn btn-outline-danger w-100">
                    <i class="fas fa-mask"></i> Atemschutz
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($can_settings): ?>
            <div class="col-12 col-md-4">
                <a href="settings.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-cog"></i> Einstellungen
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Reservierungen Bereich -->
        <?php if ($can_reservations): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-calendar"></i> Offene Reservierungen (<?php echo count($pending_reservations); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine offenen Reservierungen</h5>
                                <p class="text-muted">Alle Reservierungen wurden bearbeitet.</p>
                            </div>
                        <?php else: ?>
                            <!-- Mobile-optimierte Karten-Ansicht -->
                            <div class="d-md-none">
                                <?php foreach ($pending_reservations as $reservation): ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="fas fa-truck text-primary"></i>
                                                    <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                                </h6>
                                                <span class="badge bg-warning text-dark">
                                                    Ausstehend
                                                </span>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-calendar-alt text-success"></i>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <i class="fas fa-user text-info"></i>
                                                <span><?php echo htmlspecialchars($reservation['requester_name']); ?></span>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <i class="fas fa-clipboard-list text-warning"></i>
                                                <span><?php echo htmlspecialchars(substr($reservation['reason'], 0, 80)); ?><?php echo strlen($reservation['reason']) > 80 ? '...' : ''; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Desktop-Tabellen-Ansicht -->
                            <div class="d-none d-md-block">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                        <tr>
                                            <th>Fahrzeug</th>
                                            <th>Antragsteller</th>
                                            <th>Datum/Zeit</th>
                                            <th>Grund</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($pending_reservations as $reservation): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-truck text-primary"></i>
                                                <?php echo htmlspecialchars($reservation['vehicle_name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($reservation['requester_name']); ?></td>
                                            <td>
                                                <strong><?php echo date('d.m.Y', strtotime($reservation['start_datetime'])); ?></strong><br>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                    <?php echo date('H:i', strtotime($reservation['end_datetime'])); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars(substr($reservation['reason'], 0, 50)); ?><?php echo strlen($reservation['reason']) > 50 ? '...' : ''; ?></td>
                                            <td><span class="badge bg-warning text-dark">Ausstehend</span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="reservations.php" class="btn btn-primary">
                            <i class="fas fa-calendar-alt"></i> Alle Reservierungen verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Atemschutz Bereich -->
        <?php if ($can_atemschutz): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-danger">
                            <i class="fas fa-mask"></i> Atemschutz-Warnungen (<?php echo count($atemschutz_warnings); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($atemschutz_warnings)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Alle Geräteträger sind aktuell</h5>
                                <p class="text-muted">Keine Warnungen oder abgelaufenen Zertifikate.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($atemschutz_warnings as $traeger): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="warning-item">
                                        <div class="warning-header">
                                            <h6 class="warning-name"><?php echo htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']); ?></h6>
                                        </div>
                                        <div class="warning-reasons">
                                            <?php if ($traeger['strecke_status'] !== 'OK'): ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">Strecke</span>
                                                <div class="reason-details">
                                                    <?php
                                                    // Berechne das Ablaufdatum (1 Jahr nach Durchführung)
                                                    $streckeAm = new DateTime($traeger['strecke_am']);
                                                    $streckeBis = clone $streckeAm;
                                                    $streckeBis->add(new DateInterval('P1Y')); // 1 Jahr hinzufügen
                                                    
                                                    $now = new DateTime('today');
                                                    $diff = (int)$now->diff($streckeBis)->format('%r%a');
                                                    $cls = '';
                                                    if ($diff < 0) { 
                                                        $cls = 'bis-expired'; 
                                                    } elseif ($diff <= $warn_days) { 
                                                        $cls = 'bis-warn'; 
                                                    }
                                                    ?>
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo date('d.m.Y', strtotime($traeger['strecke_am'])); ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($traeger['g263_status'] !== 'OK'): ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">G26.3</span>
                                                <div class="reason-details">
                                                    <?php
                                                    // Berechne das Ablaufdatum (3 Jahre für unter 50, 1 Jahr für über 50)
                                                    $g263Am = new DateTime($traeger['g263_am']);
                                                    $birthdate = new DateTime($traeger['birthdate']);
                                                    $age = $birthdate->diff(new DateTime())->y;
                                                    
                                                    $g263Bis = clone $g263Am;
                                                    if ($age < 50) {
                                                        $g263Bis->add(new DateInterval('P3Y')); // 3 Jahre für unter 50
                                                    } else {
                                                        $g263Bis->add(new DateInterval('P1Y')); // 1 Jahr für über 50
                                                    }
                                                    
                                                    $now = new DateTime('today');
                                                    $diff = (int)$now->diff($g263Bis)->format('%r%a');
                                                    $cls = '';
                                                    if ($diff < 0) { 
                                                        $cls = 'bis-expired'; 
                                                    } elseif ($diff <= $warn_days) { 
                                                        $cls = 'bis-warn'; 
                                                    }
                                                    ?>
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo date('d.m.Y', strtotime($traeger['g263_am'])); ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($traeger['uebung_status'] !== 'OK'): ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">Übung/Einsatz</span>
                                                <div class="reason-details">
                                                    <?php
                                                    // Berechne das Ablaufdatum (1 Jahr nach Durchführung)
                                                    $uebungAm = new DateTime($traeger['uebung_am']);
                                                    $uebungBis = clone $uebungAm;
                                                    $uebungBis->add(new DateInterval('P1Y')); // 1 Jahr hinzufügen
                                                    
                                                    $now = new DateTime('today');
                                                    $diff = (int)$now->diff($uebungBis)->format('%r%a');
                                                    $cls = '';
                                                    if ($diff < 0) { 
                                                        $cls = 'bis-expired'; 
                                                    } elseif ($diff <= $warn_days) { 
                                                        $cls = 'bis-warn'; 
                                                    }
                                                    ?>
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo date('d.m.Y', strtotime($traeger['uebung_am'])); ?></span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="atemschutz.php" class="btn btn-danger">
                            <i class="fas fa-mask"></i> Atemschutz verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>