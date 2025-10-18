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

// Sicherstellen, dass Berechtigungsspalten existieren
try {
    $db->exec("ALTER TABLE users ADD COLUMN can_reservations TINYINT(1) DEFAULT 1");
    $db->exec("ALTER TABLE users ADD COLUMN can_users TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_settings TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_vehicles TINYINT(1) DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN can_atemschutz TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalten existieren bereits, ignoriere Fehler
}

// Debug: Berechtigungen anzeigen
echo '<script>console.log("🔍 Dashboard Debug - Berechtigungen:");</script>';
echo '<script>console.log("can_reservations:", ' . json_encode($can_reservations) . ');</script>';
echo '<script>console.log("can_atemschutz:", ' . json_encode($can_atemschutz) . ');</script>';
echo '<script>console.log("can_settings:", ' . json_encode($can_settings) . ');</script>';
echo '<script>console.log("user_id:", ' . json_encode($_SESSION['user_id'] ?? 'nicht gesetzt') . ');</script>';

// Reservierungen laden (nur wenn berechtigt)
$pending_reservations = [];
if ($can_reservations) {
    try {
        $stmt = $db->prepare("
            SELECT r.*, v.name as vehicle_name
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            WHERE r.status = 'pending' 
            ORDER BY r.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<script>console.log("🔍 Reservierungen geladen:", ' . count($pending_reservations) . ');</script>';
        echo '<script>console.log("Reservierungen:", ' . json_encode($pending_reservations) . ');</script>';
    } catch (Exception $e) {
        echo '<script>console.log("❌ Fehler beim Laden der Reservierungen:", ' . json_encode($e->getMessage()) . ');</script>';
    }
}

// Atemschutzeintrag-Anträge laden (nur wenn berechtigt)
$atemschutz_entries = [];
if ($can_atemschutz) {
    try {
        // Stelle sicher, dass die Tabellen existieren
        $db->exec("
            CREATE TABLE IF NOT EXISTS atemschutz_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_type ENUM('einsatz', 'uebung', 'atemschutzstrecke', 'g263') NOT NULL,
                entry_date DATE NOT NULL,
                requester_id INT NOT NULL,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                rejection_reason TEXT NULL,
                approved_by INT NULL,
                approved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS atemschutz_entry_traeger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_id INT NOT NULL,
                traeger_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (entry_id) REFERENCES atemschutz_entries(id) ON DELETE CASCADE,
                FOREIGN KEY (traeger_id) REFERENCES atemschutz_traeger(id) ON DELETE CASCADE,
                UNIQUE KEY unique_entry_traeger (entry_id, traeger_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
                    // Lade offene Atemschutzeintrag-Anträge
                    $stmt = $db->prepare("
                        SELECT ae.*, 
                               COALESCE(u.first_name, 'Unbekannt') as first_name, 
                               COALESCE(u.last_name, '') as last_name,
                               GROUP_CONCAT(CONCAT(at.first_name, ' ', at.last_name) ORDER BY at.last_name, at.first_name SEPARATOR ', ') as traeger_names,
                               COUNT(aet.traeger_id) as traeger_count
                        FROM atemschutz_entries ae
                        LEFT JOIN users u ON ae.requester_id = u.id
                        LEFT JOIN atemschutz_entry_traeger aet ON ae.id = aet.entry_id
                        LEFT JOIN atemschutz_traeger at ON aet.traeger_id = at.id
                        WHERE ae.status = 'pending'
                        GROUP BY ae.id
                        ORDER BY ae.created_at DESC
                    ");
        $stmt->execute();
        $atemschutz_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Atemschutzeintrag-Anträge geladen: " . count($atemschutz_entries));
    } catch (Exception $e) {
        error_log("Fehler beim Laden der Atemschutzeintrag-Anträge: " . $e->getMessage());
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
        
        // Lade alle aktiven Geräteträger und filtere dann in PHP
        $stmt = $db->prepare("
            SELECT * FROM atemschutz_traeger 
            WHERE status = 'Aktiv'
            ORDER BY last_name ASC, first_name ASC
        ");
        $stmt->execute();
        $all_traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtere nur die wirklich auffälligen Geräteträger
        $atemschutz_warnings = [];
        foreach ($all_traeger as $traeger) {
            $has_warning = false;
            
            // Prüfe Strecke (1 Jahr Gültigkeit)
            $streckeAm = new DateTime($traeger['strecke_am']);
            $streckeBis = clone $streckeAm;
            $streckeBis->add(new DateInterval('P1Y'));
            $now = new DateTime('today');
            $diff = (int)$now->diff($streckeBis)->format('%r%a');
            if ($diff < 0 || $diff <= $warn_days) {
                $has_warning = true;
            }
            
            // Prüfe G26.3 (3 Jahre unter 50, 1 Jahr über 50)
            if (!$has_warning) {
                $g263Am = new DateTime($traeger['g263_am']);
                $birthdate = new DateTime($traeger['birthdate']);
                $age = $birthdate->diff(new DateTime())->y;
                
                $g263Bis = clone $g263Am;
                if ($age < 50) {
                    $g263Bis->add(new DateInterval('P3Y'));
                } else {
                    $g263Bis->add(new DateInterval('P1Y'));
                }
                
                $diff = (int)$now->diff($g263Bis)->format('%r%a');
                if ($diff < 0 || $diff <= $warn_days) {
                    $has_warning = true;
                }
            }
            
            // Prüfe Übung (1 Jahr Gültigkeit)
            if (!$has_warning) {
                $uebungAm = new DateTime($traeger['uebung_am']);
                $uebungBis = clone $uebungAm;
                $uebungBis->add(new DateInterval('P1Y'));
                
                $diff = (int)$now->diff($uebungBis)->format('%r%a');
                if ($diff < 0 || $diff <= $warn_days) {
                    $has_warning = true;
                }
            }
            
            // Nur auffällige Geräteträger hinzufügen
            if ($has_warning) {
                $atemschutz_warnings[] = $traeger;
            }
        }
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
    <!-- Details Modal für Reservierungen -->
    <div class="modal fade" id="reservationDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Reservierungsdetails
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-truck me-2"></i>Fahrzeug
                            </h6>
                            <p id="modalVehicleName" class="mb-3"></p>
                            
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-user me-2"></i>Antragsteller
                            </h6>
                            <p id="modalRequesterName" class="mb-3"></p>
                            <p id="modalRequesterEmail" class="text-muted mb-3"></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-calendar me-2"></i>Zeitraum
                            </h6>
                            <p id="modalDateTime" class="mb-3"></p>
                            
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-map-marker-alt me-2"></i>Standort
                            </h6>
                            <p id="modalLocation" class="mb-3"></p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-clipboard-list me-2"></i>Grund
                            </h6>
                            <p id="modalReason" class="mb-3"></p>
                            
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-info-circle me-2"></i>Status
                            </h6>
                            <p id="modalStatus" class="mb-3"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Schließen
                    </button>
                    <button type="button" class="btn btn-danger" id="rejectBtn" onclick="showRejectModal()">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                    <button type="button" class="btn btn-success" id="approveBtn" onclick="approveReservation()">
                        <i class="fas fa-check me-1"></i>Genehmigen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Ablehnungsgrund Modal -->
    <div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times-circle me-2"></i>Reservierung ablehnen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Hinweis:</strong> Bitte geben Sie einen Grund für die Ablehnung an. Dieser wird dem Antragsteller per E-Mail mitgeteilt.
                    </div>
                    
                    <div class="mb-3">
                        <label for="rejectReason" class="form-label">
                            <i class="fas fa-comment me-1"></i>Ablehnungsgrund
                        </label>
                        <textarea class="form-control" id="rejectReason" rows="4" placeholder="Grund für die Ablehnung eingeben...">Das gewünschte Fahrzeug ist zu diesem Zeitpunkt bereits reserviert.</textarea>
                        <div class="form-text">Der Grund wird dem Antragsteller per E-Mail übermittelt.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn" onclick="confirmReject()">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Konflikt-Warnung Modal -->
    <div class="modal fade" id="conflictWarningModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Konflikte gefunden
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warnung:</strong> Die Genehmigung dieser Reservierung würde zu Konflikten mit bestehenden Reservierungen führen.
                    </div>
                    
                    <p>Folgende Reservierungen würden <strong>storniert</strong> werden:</p>
                    
                    <div id="conflictList" class="mb-3">
                        <!-- Konflikte werden hier dynamisch eingefügt -->
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Hinweis:</strong> Die stornierten Antragsteller erhalten eine E-Mail-Benachrichtigung über die Stornierung.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-warning" id="confirmConflictResolutionBtn" onclick="confirmConflictResolution()">
                        <i class="fas fa-check me-1"></i>Bestätigen und Genehmigen
                    </button>
                </div>
            </div>
        </div>
    </div>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
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
                                            
                                            <div class="d-grid">
                                                <button class="btn btn-outline-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                                    <i class="fas fa-eye"></i> Details anzeigen
                                                </button>
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
                                            <th>Aktionen</th>
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
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                                    <i class="fas fa-eye"></i> Details
                                                </button>
                                            </td>
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
        
        <!-- Atemschutzeintrag-Anträge -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-info">
                            <i class="fas fa-clipboard-list"></i> Offene Atemschutzeinträge (<?php echo count($atemschutz_entries); ?>)
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($atemschutz_entries)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine offenen Anträge</h5>
                                <p class="text-muted">Alle Atemschutzeintrag-Anträge wurden bearbeitet.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($atemschutz_entries as $entry): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card border-info">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title text-dark mb-0">
                                                    <?php
                                                    $type_names = [
                                                        'einsatz' => 'Einsatz',
                                                        'uebung' => 'Übung',
                                                        'atemschutzstrecke' => 'Atemschutzstrecke',
                                                        'g263' => 'G26.3'
                                                    ];
                                                    echo $type_names[$entry['entry_type']] ?? $entry['entry_type'];
                                                    ?>
                                                </h6>
                                                <span class="badge bg-info">Antrag</span>
                                            </div>
                                            
                                            <p class="card-text mb-2">
                                                <strong>Datum:</strong><br>
                                                <?php echo date('d.m.Y', strtotime($entry['entry_date'])); ?>
                                            </p>
                                            
                                            <?php if ($entry['traeger_count'] == 1): ?>
                                            <p class="card-text mb-3">
                                                <strong>Geräteträger:</strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($entry['traeger_names'] ?? 'Keine'); ?></small>
                                            </p>
                                            <?php else: ?>
                                            <p class="card-text mb-3">
                                                <strong>Geräteträger:</strong><br>
                                                <small class="text-muted"><?php echo $entry['traeger_count']; ?> Geräteträger - siehe Details</small>
                                            </p>
                                            <?php endif; ?>
                                            
                                            <div class="d-grid gap-2">
                                                <?php if ($entry['traeger_count'] == 1): ?>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-success btn-sm" onclick="approveAtemschutzEntry(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-check me-1"></i>Genehmigen
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="showAtemschutzRejectModal(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-times me-1"></i>Ablehnen
                                                    </button>
                                                </div>
                                                <?php else: ?>
                                                <button class="btn btn-outline-info btn-sm" onclick="showAtemschutzEntryDetails(<?php echo $entry['id']; ?>)">
                                                    <i class="fas fa-eye me-1"></i>Details anzeigen
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Auffällige Geräteträger -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-danger">
                            <i class="fas fa-mask"></i> Auffällige Geräteträger (<?php echo count($atemschutz_warnings); ?>)
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
                                            <div class="email-buttons">
                                                <?php
                                                // Status für jeden Zertifikatstyp bestimmen
                                                $now = new DateTime('today');
                                                
                                                // Strecke Status
                                                $streckeAm = new DateTime($traeger['strecke_am']);
                                                $streckeBis = clone $streckeAm;
                                                $streckeBis->add(new DateInterval('P1Y'));
                                                $streckeDiff = (int)$now->diff($streckeBis)->format('%r%a');
                                                $streckeUrgency = ($streckeDiff < 0) ? 'abgelaufen' : 'warnung';
                                                $streckeClass = ($streckeDiff < 0) ? 'bis-expired' : 'bis-warn';
                                                
                                                // G26.3 Status
                                                $g263Am = new DateTime($traeger['g263_am']);
                                                $birthdate = new DateTime($traeger['birthdate']);
                                                $age = $birthdate->diff(new DateTime())->y;
                                                $g263Bis = clone $g263Am;
                                                if ($age < 50) {
                                                    $g263Bis->add(new DateInterval('P3Y'));
                                                } else {
                                                    $g263Bis->add(new DateInterval('P1Y'));
                                                }
                                                $g263Diff = (int)$now->diff($g263Bis)->format('%r%a');
                                                $g263Urgency = ($g263Diff < 0) ? 'abgelaufen' : 'warnung';
                                                $g263Class = ($g263Diff < 0) ? 'bis-expired' : 'bis-warn';
                                                
                                                // Übung Status
                                                $uebungAm = new DateTime($traeger['uebung_am']);
                                                $uebungBis = clone $uebungAm;
                                                $uebungBis->add(new DateInterval('P1Y'));
                                                $uebungDiff = (int)$now->diff($uebungBis)->format('%r%a');
                                                $uebungUrgency = ($uebungDiff < 0) ? 'abgelaufen' : 'warnung';
                                                $uebungClass = ($uebungDiff < 0) ? 'bis-expired' : 'bis-warn';
                                                
                                                // Bestimme den höchsten Prioritätsstatus (abgelaufen > warnung)
                                                $hasExpired = ($streckeDiff < 0 || $g263Diff < 0 || $uebungDiff < 0);
                                                $hasWarning = ($streckeDiff <= $warn_days || $g263Diff <= $warn_days || $uebungDiff <= $warn_days);
                                                
                                                $buttonClass = $hasExpired ? 'btn-danger' : 'btn-warning';
                                                $buttonText = $hasExpired ? 'E-Mail senden (Aufforderung)' : 'E-Mail senden (Erinnerung)';
                                                
                                                // Sammle alle problematischen Zertifikate
                                                $problematicCertificates = [];
                                                if ($streckeDiff < 0 || $streckeDiff <= $warn_days) {
                                                    $problematicCertificates[] = [
                                                        'type' => 'strecke',
                                                        'urgency' => $streckeUrgency,
                                                        'expiry_date' => $streckeBis->format('d.m.Y')
                                                    ];
                                                }
                                                if ($g263Diff < 0 || $g263Diff <= $warn_days) {
                                                    $problematicCertificates[] = [
                                                        'type' => 'g263',
                                                        'urgency' => $g263Urgency,
                                                        'expiry_date' => $g263Bis->format('d.m.Y')
                                                    ];
                                                }
                                                if ($uebungDiff < 0 || $uebungDiff <= $warn_days) {
                                                    $problematicCertificates[] = [
                                                        'type' => 'uebung',
                                                        'urgency' => $uebungUrgency,
                                                        'expiry_date' => $uebungBis->format('d.m.Y')
                                                    ];
                                                }
                                                ?>
                                                
                                                <button class="btn btn-sm <?php echo $buttonClass; ?> email-btn" 
                                                        data-traeger-id="<?php echo $traeger['id']; ?>"
                                                        data-traeger-name="<?php echo htmlspecialchars($traeger['first_name'] . ' ' . $traeger['last_name']); ?>"
                                                        data-email="<?php echo htmlspecialchars($traeger['email'] ?? ''); ?>"
                                                        data-certificates="<?php echo htmlspecialchars(json_encode($problematicCertificates)); ?>"
                                                        title="<?php echo $buttonText; ?>">
                                                    <i class="fas fa-envelope"></i> <?php echo $buttonText; ?>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="warning-reasons">
                                            <?php
                                            $now = new DateTime('today');
                                            
                                            // Strecke anzeigen
                                            $streckeAm = new DateTime($traeger['strecke_am']);
                                            $streckeBis = clone $streckeAm;
                                            $streckeBis->add(new DateInterval('P1Y'));
                                            $diff = (int)$now->diff($streckeBis)->format('%r%a');
                                            $cls = '';
                                            if ($diff < 0) {
                                                $cls = 'bis-expired';
                                            } elseif ($diff <= $warn_days) {
                                                $cls = 'bis-warn';
                                            }
                                            ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">Strecke</span>
                                                <div class="reason-details">
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo date('d.m.Y', strtotime($traeger['strecke_am'])); ?></span>
                    </div>
                                            </div>
                                            
                                            <?php
                                            // G26.3 anzeigen
                                            $g263Am = new DateTime($traeger['g263_am']);
                                            $birthdate = new DateTime($traeger['birthdate']);
                                            $age = $birthdate->diff(new DateTime())->y;
                                            
                                            $g263Bis = clone $g263Am;
                                            if ($age < 50) {
                                                $g263Bis->add(new DateInterval('P3Y'));
                                            } else {
                                                $g263Bis->add(new DateInterval('P1Y'));
                                            }
                                            
                                            $diff = (int)$now->diff($g263Bis)->format('%r%a');
                                            $cls = '';
                                            if ($diff < 0) {
                                                $cls = 'bis-expired';
                                            } elseif ($diff <= $warn_days) {
                                                $cls = 'bis-warn';
                                            }
                                            ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">G26.3</span>
                                                <div class="reason-details">
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo date('d.m.Y', strtotime($traeger['g263_am'])); ?></span>
                                                </div>
                                            </div>
                                            
                                            <?php
                                            // Übung anzeigen
                                            $uebungAm = new DateTime($traeger['uebung_am']);
                                            $uebungBis = clone $uebungAm;
                                            $uebungBis->add(new DateInterval('P1Y'));
                                            
                                            $diff = (int)$now->diff($uebungBis)->format('%r%a');
                                            $cls = '';
                                            if ($diff < 0) {
                                                $cls = 'bis-expired';
                                            } elseif ($diff <= $warn_days) {
                                                $cls = 'bis-warn';
                                            }
                                            ?>
                                            <div class="warning-reason">
                                                <span class="reason-label">Übung/Einsatz</span>
                                                <div class="reason-details">
                                                    <span class="bis-badge <?php echo $cls; ?>"><?php echo date('d.m.Y', strtotime($traeger['uebung_am'])); ?></span>
                                                </div>
                                            </div>
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

    <!-- Modal: E-Mail-Adresse eingeben -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope me-2"></i>E-Mail senden
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Geräteträger</label>
                        <input type="text" class="form-control" id="modalTraegerName" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="modalEmail" class="form-label">E-Mail-Adresse <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="modalEmail" placeholder="max.mustermann@feuerwehr.de" required>
                        <div class="form-text">Die E-Mail-Adresse wird für diesen Geräteträger gespeichert.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-Mail-Inhalt</label>
                        <div class="border rounded p-3 bg-light">
                            <div class="mb-2">
                                <label class="form-label small">Betreff <span class="text-muted">(editierbar)</span></label>
                                <input type="text" class="form-control form-control-sm" id="emailSubject" placeholder="E-Mail-Betreff wird hier geladen...">
                            </div>
                            <div>
                                <label class="form-label small">Nachricht <span class="text-muted">(editierbar)</span></label>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span></span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="resetEmailBtn">
                                        <i class="fas fa-undo"></i> Zurücksetzen
                                    </button>
                                </div>
                                <textarea class="form-control" id="emailBody" rows="8" placeholder="E-Mail-Inhalt wird hier geladen..."></textarea>
                                <div class="form-text">
                                    <strong>Verfügbare Platzhalter:</strong>
                                    <code>{first_name}</code> <code>{last_name}</code> <code>{expiry_date}</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-primary" id="sendEmailBtn">
                        <i class="fas fa-paper-plane"></i> E-Mail senden
                    </button>
                            </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentTraegerId = null;
        let currentCertificates = [];
        let originalSubject = '';
        let originalBody = '';
        
        document.addEventListener('DOMContentLoaded', function() {
            const emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
            const modalTraegerName = document.getElementById('modalTraegerName');
            const modalEmail = document.getElementById('modalEmail');
            const emailSubject = document.getElementById('emailSubject');
            const emailBody = document.getElementById('emailBody');
            const sendEmailBtn = document.getElementById('sendEmailBtn');
            const resetEmailBtn = document.getElementById('resetEmailBtn');
            
            // E-Mail-Buttons Event Listener
            document.querySelectorAll('.email-btn').forEach(button => {
                button.addEventListener('click', function() {
                    currentTraegerId = this.dataset.traegerId;
                    const traegerName = this.dataset.traegerName;
                    const email = this.dataset.email;
                    currentCertificates = JSON.parse(this.dataset.certificates);
                    
                    // Modal füllen
                    modalTraegerName.value = traegerName;
                    modalEmail.value = email;
                    
                    // E-Mail-Vorschau laden
                    loadEmailPreview();
                    
                    // Modal öffnen
                    emailModal.show();
                });
            });
            
            // E-Mail-Vorschau laden
            function loadEmailPreview() {
                if (currentCertificates.length === 0) {
                    emailSubject.value = '';
                    emailBody.value = 'Keine problematischen Zertifikate gefunden.';
                    return;
                }
                
                // Bestimme den höchsten Prioritätsstatus
                const hasExpired = currentCertificates.some(cert => cert.urgency === 'abgelaufen');
                const urgency = hasExpired ? 'abgelaufen' : 'warnung';
                
                // Lade E-Mail-Vorlagen
                fetch('get-email-preview.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `traeger_id=${currentTraegerId}&certificates=${encodeURIComponent(JSON.stringify(currentCertificates))}&urgency=${urgency}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        emailSubject.value = data.subject;
                        emailBody.value = data.body;
                        originalSubject = data.subject;
                        originalBody = data.body;
                    } else {
                        emailSubject.value = 'Fehler';
                        emailBody.value = 'Fehler beim Laden der E-Mail-Vorschau.';
                        originalSubject = '';
                        originalBody = '';
                    }
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    emailSubject.value = 'Fehler';
                    emailBody.value = 'Fehler beim Laden der E-Mail-Vorschau.';
                });
            }
            
            // Zurücksetzen-Button
            resetEmailBtn.addEventListener('click', function() {
                emailSubject.value = originalSubject;
                emailBody.value = originalBody;
            });
            
            // E-Mail senden
            sendEmailBtn.addEventListener('click', function() {
                const email = modalEmail.value.trim();
                const subject = emailSubject.value.trim();
                const body = emailBody.value.trim();
                
                if (!email) {
                    alert('Bitte geben Sie eine E-Mail-Adresse ein.');
                    return;
                }
                
                if (!subject.trim() || !body.trim()) {
                    alert('Bitte geben Sie einen Betreff und eine Nachricht ein.');
                    return;
                }
                
                // Button während des Versands deaktivieren
                const originalText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sende...';
                
                // AJAX-Anfrage
                fetch('send-atemschutz-email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `traeger_id=${currentTraegerId}&email=${encodeURIComponent(email)}&subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}&certificates=${encodeURIComponent(JSON.stringify(currentCertificates))}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Erfolg anzeigen
                        this.innerHTML = '<i class="fas fa-check"></i> Gesendet';
                        this.classList.remove('btn-primary');
                        this.classList.add('btn-success');
                        
                        // Modal nach 2 Sekunden schließen
                        setTimeout(() => {
                            emailModal.hide();
                            location.reload(); // Seite neu laden um E-Mail-Adresse zu aktualisieren
                        }, 2000);
                    } else {
                        // Fehler anzeigen
                        this.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Fehler';
                        this.classList.remove('btn-primary');
                        this.classList.add('btn-danger');
                        
                        // Nach 3 Sekunden zurücksetzen
                        setTimeout(() => {
                            this.disabled = false;
                            this.classList.remove('btn-danger');
                            this.classList.add('btn-primary');
                            this.innerHTML = originalText;
                        }, 3000);
                        
                        alert('Fehler: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    this.disabled = false;
                    this.innerHTML = originalText;
                    alert('Fehler beim Senden der E-Mail');
                });
            });
            
            // Modal zurücksetzen beim Schließen
            emailModal._element.addEventListener('hidden.bs.modal', function() {
                modalEmail.value = '';
                emailSubject.value = '';
                emailBody.value = '';
                originalSubject = '';
                originalBody = '';
                sendEmailBtn.disabled = false;
                sendEmailBtn.innerHTML = '<i class="fas fa-paper-plane"></i> E-Mail senden';
                sendEmailBtn.classList.remove('btn-success', 'btn-danger');
                sendEmailBtn.classList.add('btn-primary');
            });
        });
        
        // Reservierungsdetails anzeigen
        function showReservationDetails(reservation) {
            document.getElementById('modalVehicleName').textContent = reservation.vehicle_name;
            document.getElementById('modalRequesterName').textContent = reservation.requester_name;
            document.getElementById('modalRequesterEmail').textContent = reservation.requester_email;
            document.getElementById('modalDateTime').innerHTML = 
                '<strong>' + new Date(reservation.start_datetime).toLocaleDateString('de-DE') + '</strong><br>' +
                '<small class="text-muted">' + 
                new Date(reservation.start_datetime).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}) + 
                ' - ' + 
                new Date(reservation.end_datetime).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}) + 
                '</small>';
            document.getElementById('modalLocation').textContent = reservation.location || 'Nicht angegeben';
            document.getElementById('modalReason').textContent = reservation.reason;
            document.getElementById('modalStatus').innerHTML = '<span class="badge bg-warning text-dark">Ausstehend</span>';
            
            // Reservierungs-ID für Genehmigen/Ablehnen speichern
            window.currentReservationId = reservation.id;
            
            // Konfliktprüfung durchführen
            checkReservationConflicts(reservation.id);
            
            // Modal anzeigen
            const modal = new bootstrap.Modal(document.getElementById('reservationDetailsModal'));
            modal.show();
        }
        
        // Konfliktprüfung für Reservierung
        function checkReservationConflicts(reservationId) {
            fetch('check-reservation-conflicts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    reservation_id: reservationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayConflictInfo(data);
                } else {
                    console.error('Konfliktprüfung fehlgeschlagen:', data.message);
                }
            })
            .catch(error => {
                console.error('Fehler bei Konfliktprüfung:', error);
            });
        }
        
        // Konfliktinformationen anzeigen
        function displayConflictInfo(data) {
            const statusElement = document.getElementById('modalStatus');
            
            if (data.has_conflicts) {
                // Konflikte gefunden - zeige Warnung
                statusElement.innerHTML = `
                    <span class="badge bg-warning text-dark me-2">Ausstehend</span>
                    <span class="badge bg-danger">${data.conflict_count} Konflikt${data.conflict_count > 1 ? 'e' : ''}</span>
                `;
                
                // Konflikte-Details hinzufügen
                let conflictsHtml = '<div class="mt-3"><h6 class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Zeitüberschneidungen gefunden:</h6>';
                conflictsHtml += '<div class="alert alert-warning">';
                
                data.conflicts.forEach(conflict => {
                    conflictsHtml += `
                        <div class="mb-2 p-2 border-start border-3 border-warning">
                            <strong>${conflict.vehicle_name}</strong><br>
                            <small class="text-muted">
                                ${conflict.start_date} von ${conflict.start_time} bis ${conflict.end_time}<br>
                                Antragsteller: ${conflict.requester_name}<br>
                                Grund: ${conflict.reason}
                            </small>
                        </div>
                    `;
                });
                
                conflictsHtml += '</div></div>';
                
                // Konflikte nach dem Status-Element einfügen
                const existingConflicts = document.getElementById('conflictDetails');
                if (existingConflicts) {
                    existingConflicts.remove();
                }
                
                const conflictDiv = document.createElement('div');
                conflictDiv.id = 'conflictDetails';
                conflictDiv.innerHTML = conflictsHtml;
                statusElement.parentNode.insertAdjacentElement('afterend', conflictDiv);
                
            } else {
                // Keine Konflikte - zeige grünen Status
                statusElement.innerHTML = `
                    <span class="badge bg-warning text-dark me-2">Ausstehend</span>
                    <span class="badge bg-success">Kein Konflikt</span>
                `;
                
                // Entferne eventuell vorhandene Konflikt-Details
                const existingConflicts = document.getElementById('conflictDetails');
                if (existingConflicts) {
                    existingConflicts.remove();
                }
            }
        }
        
        // Reservierung genehmigen
        function approveReservation() {
            if (!window.currentReservationId) return;
            
            const approveBtn = document.getElementById('approveBtn');
            const originalText = approveBtn.innerHTML;
            
            approveBtn.disabled = true;
            approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Genehmige...';
            
            fetch('process-reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'approve',
                    reservation_id: window.currentReservationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    approveBtn.innerHTML = '<i class="fas fa-check me-1"></i>Genehmigt!';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-success');
                    
                    // Modal nach 2 Sekunden schließen und Seite neu laden
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else if (data.has_conflicts) {
                    // Konflikte gefunden - zeige Warnung
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    
                    showConflictWarning(data.conflicts);
                } else {
                    approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-danger');
                    
                    setTimeout(() => {
                        approveBtn.disabled = false;
                        approveBtn.innerHTML = originalText;
                        approveBtn.classList.remove('btn-danger');
                        approveBtn.classList.add('btn-success');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                approveBtn.classList.remove('btn-success');
                approveBtn.classList.add('btn-danger');
                
                setTimeout(() => {
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    approveBtn.classList.remove('btn-danger');
                    approveBtn.classList.add('btn-success');
                }, 3000);
            });
        }
        
        // Konflikt-Warnung anzeigen
        function showConflictWarning(conflicts) {
            const conflictList = document.getElementById('conflictList');
            let conflictsHtml = '';
            
            conflicts.forEach(conflict => {
                conflictsHtml += `
                    <div class="card mb-2 border-danger">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="card-title text-danger mb-2">
                                        <i class="fas fa-calendar-times me-2"></i>${conflict.vehicle_name}
                                    </h6>
                                    <p class="card-text mb-1">
                                        <strong>Antragsteller:</strong> ${conflict.requester_name}
                                    </p>
                                    <p class="card-text mb-1">
                                        <strong>Zeitraum:</strong> ${conflict.start_date} von ${conflict.start_time} bis ${conflict.end_time}
                                    </p>
                                    <p class="card-text mb-0">
                                        <strong>Grund:</strong> ${conflict.reason}
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <span class="badge bg-danger">Wird storniert</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            conflictList.innerHTML = conflictsHtml;
            
            // Speichere Konflikt-IDs für spätere Verarbeitung
            window.conflictIds = conflicts.map(c => c.id);
            
            // Zeige Modal
            const modal = new bootstrap.Modal(document.getElementById('conflictWarningModal'));
            modal.show();
        }
        
        // Konfliktlösung bestätigen
        function confirmConflictResolution() {
            if (!window.currentReservationId || !window.conflictIds) return;
            
            const confirmBtn = document.getElementById('confirmConflictResolutionBtn');
            const originalText = confirmBtn.innerHTML;
            
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Verarbeite...';
            
            fetch('process-reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'approve_with_conflict_resolution',
                    reservation_id: window.currentReservationId,
                    conflict_ids: window.conflictIds
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i>Erfolgreich!';
                    confirmBtn.classList.remove('btn-warning');
                    confirmBtn.classList.add('btn-success');
                    
                    // Alle Modals schließen und Seite neu laden
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    confirmBtn.classList.remove('btn-warning');
                    confirmBtn.classList.add('btn-danger');
                    
                    setTimeout(() => {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = originalText;
                        confirmBtn.classList.remove('btn-danger');
                        confirmBtn.classList.add('btn-warning');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                confirmBtn.classList.remove('btn-warning');
                confirmBtn.classList.add('btn-danger');
                
                setTimeout(() => {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.classList.remove('btn-danger');
                    confirmBtn.classList.add('btn-warning');
                }, 3000);
            });
        }
        
        // Ablehnungsmodal anzeigen
        function showRejectModal() {
            // Details Modal schließen
            const detailsModal = bootstrap.Modal.getInstance(document.getElementById('reservationDetailsModal'));
            if (detailsModal) {
                detailsModal.hide();
            }
            
            // Ablehnungsmodal anzeigen
            const rejectModal = new bootstrap.Modal(document.getElementById('rejectReasonModal'));
            rejectModal.show();
        }
        
        // Reservierung ablehnen (bestätigen)
        function confirmReject() {
            if (!window.currentReservationId) return;
            
            const reason = document.getElementById('rejectReason').value.trim();
            if (!reason) {
                alert('Bitte geben Sie einen Grund für die Ablehnung ein.');
                return;
            }
            
            const confirmBtn = document.getElementById('confirmRejectBtn');
            const originalText = confirmBtn.innerHTML;
            
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Lehne ab...';
            
            fetch('process-reservation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'reject',
                    reservation_id: window.currentReservationId,
                    reason: reason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i>Abgelehnt!';
                    confirmBtn.classList.remove('btn-danger');
                    confirmBtn.classList.add('btn-success');
                    
                    // Modal nach 2 Sekunden schließen und Seite neu laden
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    confirmBtn.classList.remove('btn-danger');
                    confirmBtn.classList.add('btn-warning');
                    
                    setTimeout(() => {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = originalText;
                        confirmBtn.classList.remove('btn-warning');
                        confirmBtn.classList.add('btn-danger');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                confirmBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                confirmBtn.classList.remove('btn-danger');
                confirmBtn.classList.add('btn-warning');
                
                setTimeout(() => {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.classList.remove('btn-warning');
                    confirmBtn.classList.add('btn-danger');
                }, 3000);
            });
        }
        
        // Atemschutzeintrag-Details anzeigen
        function showAtemschutzEntryDetails(entryId) {
            window.currentAtemschutzEntryId = entryId;
            
            const detailsDiv = document.getElementById('atemschutzEntryDetails');
            detailsDiv.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Lade Details...</div>';
            
            fetch('../api/get-atemschutz-entry-details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    entry_id: entryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const entry = data.entry;
                    const typeNames = {
                        'einsatz': 'Einsatz',
                        'uebung': 'Übung',
                        'atemschutzstrecke': 'Atemschutzstrecke',
                        'g263': 'G26.3'
                    };
                    
                    detailsDiv.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-info">Eintragstyp</h6>
                                <p>${typeNames[entry.entry_type] || entry.entry_type}</p>
                                
                                <h6 class="text-info">Datum</h6>
                                <p>${new Date(entry.entry_date).toLocaleDateString('de-DE')}</p>
                                
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-info">Geräteträger</h6>
                                <p><small>${entry.traeger_names || 'Keine'}</small></p>
                                
                                <h6 class="text-info">Eingereicht am</h6>
                                <p><small>${new Date(entry.created_at).toLocaleString('de-DE')}</small></p>
                            </div>
                        </div>
                    `;
                } else {
                    detailsDiv.innerHTML = '<div class="alert alert-danger">Fehler beim Laden der Details</div>';
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                detailsDiv.innerHTML = '<div class="alert alert-danger">Fehler beim Laden der Details</div>';
            });
            
            const modal = new bootstrap.Modal(document.getElementById('atemschutzEntryDetailsModal'));
            modal.show();
        }
        
        // Atemschutzeintrag genehmigen
        function approveAtemschutzEntry(entryId) {
            if (!entryId) {
                entryId = window.currentAtemschutzEntryId;
            }
            if (!entryId) return;
            
            const approveBtn = document.getElementById('approveAtemschutzBtn');
            const originalText = approveBtn.innerHTML;
            
            approveBtn.disabled = true;
            approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Genehmige...';
            
            fetch('../api/process-atemschutz-entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'approve',
                    entry_id: entryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    approveBtn.innerHTML = '<i class="fas fa-check me-1"></i>Genehmigt!';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-success');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    approveBtn.classList.remove('btn-success');
                    approveBtn.classList.add('btn-danger');
                    
                    setTimeout(() => {
                        approveBtn.disabled = false;
                        approveBtn.innerHTML = originalText;
                        approveBtn.classList.remove('btn-danger');
                        approveBtn.classList.add('btn-success');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                approveBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                approveBtn.classList.remove('btn-success');
                approveBtn.classList.add('btn-danger');
                
                setTimeout(() => {
                    approveBtn.disabled = false;
                    approveBtn.innerHTML = originalText;
                    approveBtn.classList.remove('btn-danger');
                    approveBtn.classList.add('btn-success');
                }, 3000);
            });
        }
        
        // Atemschutzeintrag-Ablehnung Modal anzeigen
        function showAtemschutzRejectModal(entryId) {
            window.currentAtemschutzRejectId = entryId;
            const modal = new bootstrap.Modal(document.getElementById('atemschutzRejectConfirmModal'));
            modal.show();
        }
        
        // Atemschutzeintrag ablehnen (Antrag wird gelöscht)
        function rejectAtemschutzEntry(entryId) {
            fetch('../api/process-atemschutz-entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'delete',
                    entry_id: entryId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Zeige Erfolgsmeldung
                    showAtemschutzSuccessMessage('Atemschutzeintrag erfolgreich gelöscht!');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showAtemschutzErrorMessage('Fehler beim Löschen', data.message);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                showAtemschutzErrorMessage('Netzwerkfehler', 'Es ist ein Fehler beim Löschen des Atemschutzeintrags aufgetreten.');
            });
        }
        
        // Atemschutzeintrag ablehnen bestätigen
        function confirmAtemschutzReject() {
            if (!window.currentAtemschutzEntryId) return;
            
            const reason = document.getElementById('atemschutzRejectReason').value.trim();
            if (!reason) {
                alert('Bitte geben Sie einen Ablehnungsgrund an.');
                return;
            }
            
            const rejectBtn = document.querySelector('#atemschutzRejectModal .btn-danger');
            const originalText = rejectBtn.innerHTML;
            
            rejectBtn.disabled = true;
            rejectBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Lehne ab...';
            
            fetch('../api/process-atemschutz-entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'reject',
                    entry_id: window.currentAtemschutzEntryId,
                    reason: reason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    rejectBtn.innerHTML = '<i class="fas fa-check me-1"></i>Abgelehnt!';
                    rejectBtn.classList.remove('btn-danger');
                    rejectBtn.classList.add('btn-success');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    rejectBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                    rejectBtn.classList.remove('btn-danger');
                    rejectBtn.classList.add('btn-warning');
                    
                    setTimeout(() => {
                        rejectBtn.disabled = false;
                        rejectBtn.innerHTML = originalText;
                        rejectBtn.classList.remove('btn-warning');
                        rejectBtn.classList.add('btn-danger');
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                rejectBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Fehler';
                rejectBtn.classList.remove('btn-danger');
                rejectBtn.classList.add('btn-warning');
                
                setTimeout(() => {
                    rejectBtn.disabled = false;
                    rejectBtn.innerHTML = originalText;
                    rejectBtn.classList.remove('btn-warning');
                    rejectBtn.classList.add('btn-danger');
                }, 3000);
            });
        }
        
        // Hilfsfunktionen für schöne Meldungen
        function showAtemschutzSuccessMessage(message) {
            // Erstelle temporäre Toast-Nachricht
            const toastHtml = `
                <div class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas fa-check-circle me-2"></i>${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.querySelector('.toast:last-child');
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            // Entferne das Element nach 5 Sekunden
            setTimeout(() => {
                if (toastElement && toastElement.parentNode) {
                    toastElement.parentNode.removeChild(toastElement);
                }
            }, 5000);
        }
        
        function showAtemschutzErrorMessage(title, message) {
            // Erstelle temporäre Toast-Nachricht
            const toastHtml = `
                <div class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas fa-exclamation-triangle me-2"></i><strong>${title}:</strong> ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.querySelector('.toast:last-child');
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            // Entferne das Element nach 8 Sekunden
            setTimeout(() => {
                if (toastElement && toastElement.parentNode) {
                    toastElement.parentNode.removeChild(toastElement);
                }
            }, 8000);
        }
        
        // Event-Listener für Atemschutz-Ablehnungs-Bestätigung
        document.addEventListener('DOMContentLoaded', function() {
            const confirmRejectBtn = document.getElementById('confirmAtemschutzReject');
            if (confirmRejectBtn) {
                confirmRejectBtn.addEventListener('click', function() {
                    if (window.currentAtemschutzRejectId) {
                        rejectAtemschutzEntry(window.currentAtemschutzRejectId);
                        // Modal schließen
                        const modal = bootstrap.Modal.getInstance(document.getElementById('atemschutzRejectConfirmModal'));
                        modal.hide();
                    }
                });
            }
        });
    </script>
    
    <!-- Atemschutzeintrag-Details Modal -->
    <div class="modal fade" id="atemschutzEntryDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-clipboard-list me-2"></i>Atemschutzeintrag-Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="atemschutzEntryDetails">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Lade Details...
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Schließen
                    </button>
                    <button type="button" class="btn btn-success" id="approveAtemschutzBtn" onclick="approveAtemschutzEntry(window.currentAtemschutzEntryId)">
                        <i class="fas fa-check me-1"></i>Genehmigen
                    </button>
                    <button type="button" class="btn btn-danger" id="rejectAtemschutzBtn" onclick="showAtemschutzRejectModal(window.currentAtemschutzEntryId)">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Atemschutzeintrag-Ablehnung Modal -->
    <div class="modal fade" id="atemschutzRejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times me-2"></i>Atemschutzeintrag ablehnen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Achtung:</strong> Diese Aktion kann nicht rückgängig gemacht werden!
                    </div>
                    
                    <p>Bitte geben Sie einen Grund für die Ablehnung an:</p>
                    
                    <div class="mb-3">
                        <label for="atemschutzRejectReason" class="form-label">Ablehnungsgrund</label>
                        <textarea class="form-control" id="atemschutzRejectReason" rows="4" placeholder="Grund für die Ablehnung eingeben...">Der Antrag entspricht nicht den Anforderungen.</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmAtemschutzReject()">
                        <i class="fas fa-times me-1"></i>Ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .email-buttons {
            margin-top: 8px;
            display: flex;
            justify-content: center;
        }
        .email-buttons .btn {
            font-size: 0.8rem;
            padding: 4px 12px;
            width: 100%;
        }
        .warning-header {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .warning-name {
            margin-bottom: 0;
        }
    </style>

    <!-- Atemschutz Ablehnungs-Bestätigungs-Modal -->
    <div class="modal fade" id="atemschutzRejectConfirmModal" tabindex="-1" aria-labelledby="atemschutzRejectConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title" id="atemschutzRejectConfirmModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Bestätigung erforderlich
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="fas fa-trash-alt text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <h6 class="text-warning mb-3">Atemschutzeintrag ablehnen</h6>
                    <p class="text-muted mb-0">Möchten Sie diesen Atemschutzeintrag wirklich ablehnen?<br><strong>Diese Aktion kann nicht rückgängig gemacht werden.</strong></p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-secondary px-4 me-2" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-warning px-4" id="confirmAtemschutzReject">
                        <i class="fas fa-trash-alt me-2"></i>Ja, ablehnen
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>