<?php
// Dashboard mit berechtigungsbasierten Bereichen und anpassbarer Ansicht
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

// Dashboard-Einstellungen laden
$dashboard_prefs = [];
try {
    $stmt = $db->prepare("SELECT preference_key, preference_value FROM user_dashboard_preferences WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prefs as $pref) {
        $dashboard_prefs[$pref['preference_key']] = $pref['preference_value'];
    }
} catch (Exception $e) {
    // Fallback zu Standard-Einstellungen
    $dashboard_prefs = [
        'dashboard_layout' => 'vertical',
        'show_reservations' => '1',
        'show_atemschutz' => '1',
        'show_settings' => '1',
        'reservations_limit' => '10',
        'atemschutz_limit' => '10'
    ];
}

// Einstellungen speichern (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_preferences') {
    $pref_key = $_POST['preference_key'] ?? '';
    $pref_value = $_POST['preference_value'] ?? '';
    
    if ($pref_key && in_array($pref_key, ['show_reservations', 'show_atemschutz', 'show_settings', 'reservations_limit', 'atemschutz_limit'])) {
        try {
            $stmt = $db->prepare("
                INSERT INTO user_dashboard_preferences (user_id, preference_key, preference_value) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value)
            ");
            $stmt->execute([$user_id, $pref_key, $pref_value]);
            echo json_encode(['success' => true]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }
    }
}

// Berechtigungen prüfen
$can_reservations = has_permission('reservations');
$can_atemschutz = has_permission('atemschutz');
$can_settings = has_permission('settings');

// Reservierungen laden (nur wenn berechtigt und aktiviert)
$pending_reservations = [];
if ($can_reservations && ($dashboard_prefs['show_reservations'] ?? '1') === '1') {
    try {
        $limit = (int)($dashboard_prefs['reservations_limit'] ?? 10);
        $stmt = $db->prepare("
            SELECT r.*, v.name as vehicle_name, v.type as vehicle_type 
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            WHERE r.status = 'pending' 
            ORDER BY r.created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fehler ignorieren
    }
}

// Atemschutz-Warnungen laden (nur wenn berechtigt und aktiviert)
$atemschutz_warnings = [];
if ($can_atemschutz && ($dashboard_prefs['show_atemschutz'] ?? '1') === '1') {
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
        $limit = (int)($dashboard_prefs['atemschutz_limit'] ?? 10);
        
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
            LIMIT ?
        ");
        $stmt->execute([$warn_date, $warn_date, $warn_date, $warn_date, $warn_date, $warn_date, $limit]);
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
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
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
        .atemschutz-card {
            border-left: 4px solid #dc3545;
        }
        .reservations-card {
            border-left: 4px solid #0d6efd;
        }
        .preference-toggle {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .preference-toggle:hover {
            transform: scale(1.05);
        }
        .dashboard-section {
            margin-bottom: 2rem;
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
            border-left: 3px solid #dc3545;
        }
        .warning-reason.warning {
            border-left-color: #ffc107;
        }
        .warning-reason.ok {
            border-left-color: #28a745;
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
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h1 class="mb-0">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                        <small class="text-muted">Willkommen, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</small>
                    </h1>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="dashboardSettings" data-bs-toggle="dropdown">
                            <i class="fas fa-cog"></i> Einstellungen
                        </button>
                        <ul class="dropdown-menu">
                            <li><h6 class="dropdown-header">Bereiche anzeigen</h6></li>
                            <?php if ($can_reservations): ?>
                            <li>
                                <label class="dropdown-item preference-toggle">
                                    <input type="checkbox" class="form-check-input me-2" id="show_reservations" 
                                           <?php echo ($dashboard_prefs['show_reservations'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    Reservierungen
                                </label>
                            </li>
                            <?php endif; ?>
                            <?php if ($can_atemschutz): ?>
                            <li>
                                <label class="dropdown-item preference-toggle">
                                    <input type="checkbox" class="form-check-input me-2" id="show_atemschutz" 
                                           <?php echo ($dashboard_prefs['show_atemschutz'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    Atemschutz
                                </label>
                            </li>
                            <?php endif; ?>
                            <?php if ($can_settings): ?>
                            <li>
                                <label class="dropdown-item preference-toggle">
                                    <input type="checkbox" class="form-check-input me-2" id="show_settings" 
                                           <?php echo ($dashboard_prefs['show_settings'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    Einstellungen
                                </label>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($can_reservations): ?>
                    <a href="reservations.php" class="btn btn-primary">
                        <i class="fas fa-calendar"></i> Reservierungen
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($can_atemschutz): ?>
                    <a href="atemschutz.php" class="btn btn-danger">
                        <i class="fas fa-mask"></i> Atemschutz
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($can_settings): ?>
                    <a href="settings.php" class="btn btn-secondary">
                        <i class="fas fa-cog"></i> Einstellungen
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Reservierungen Bereich -->
        <?php if ($can_reservations && ($dashboard_prefs['show_reservations'] ?? '1') === '1'): ?>
        <div class="row dashboard-section">
            <div class="col-12">
                <div class="card reservations-card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar"></i> Offene Reservierungen
                        </h5>
                        <span class="badge bg-light text-dark"><?php echo count($pending_reservations); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                                <h5>Keine offenen Reservierungen</h5>
                                <p>Alle Reservierungen wurden bearbeitet.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($pending_reservations as $reservation): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title text-primary"><?php echo htmlspecialchars($reservation['vehicle_name']); ?></h6>
                                            <p class="card-text small text-muted mb-2">
                                                <i class="fas fa-clock"></i> 
                                                <?php echo date('d.m.Y H:i', strtotime($reservation['start_datetime'])); ?> - 
                                                <?php echo date('d.m.Y H:i', strtotime($reservation['end_datetime'])); ?>
                                            </p>
                                            <p class="card-text small">
                                                <strong>Antragsteller:</strong> <?php echo htmlspecialchars($reservation['requester_name']); ?><br>
                                                <strong>Grund:</strong> <?php echo htmlspecialchars(substr($reservation['reason'], 0, 80)); ?><?php echo strlen($reservation['reason']) > 80 ? '...' : ''; ?>
                                            </p>
                                        </div>
                                        <div class="card-footer bg-warning text-dark text-center">
                                            <i class="fas fa-exclamation-triangle"></i> Ausstehend
                                        </div>
                                    </div>
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
        </div>
        <?php endif; ?>

        <!-- Atemschutz Bereich -->
        <?php if ($can_atemschutz && ($dashboard_prefs['show_atemschutz'] ?? '1') === '1'): ?>
        <div class="row dashboard-section">
            <div class="col-12">
                <div class="card atemschutz-card">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-mask"></i> Atemschutz-Warnungen
                        </h5>
                        <span class="badge bg-light text-dark"><?php echo count($atemschutz_warnings); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($atemschutz_warnings)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                                <h5>Alle Geräteträger sind aktuell</h5>
                                <p>Keine Warnungen oder abgelaufenen Zertifikate.</p>
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
                                            <div class="warning-reason <?php echo $traeger['strecke_status'] === 'Abgelaufen' ? '' : 'warning'; ?>">
                                                <span class="reason-label">Strecke</span>
                                                <div class="reason-details">
                                                    <span class="reason-date"><?php echo date('d.m.Y', strtotime($traeger['strecke_am'])); ?></span>
                                                    <span class="badge status-badge status-<?php echo $traeger['strecke_status'] === 'Abgelaufen' ? 'expired' : 'warning'; ?>">
                                                        <?php echo $traeger['strecke_status']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($traeger['g263_status'] !== 'OK'): ?>
                                            <div class="warning-reason <?php echo $traeger['g263_status'] === 'Abgelaufen' ? '' : 'warning'; ?>">
                                                <span class="reason-label">G26.3</span>
                                                <div class="reason-details">
                                                    <span class="reason-date"><?php echo date('d.m.Y', strtotime($traeger['g263_am'])); ?></span>
                                                    <span class="badge status-badge status-<?php echo $traeger['g263_status'] === 'Abgelaufen' ? 'expired' : 'warning'; ?>">
                                                        <?php echo $traeger['g263_status']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($traeger['uebung_status'] !== 'OK'): ?>
                                            <div class="warning-reason <?php echo $traeger['uebung_status'] === 'Abgelaufen' ? '' : 'warning'; ?>">
                                                <span class="reason-label">Übung/Einsatz</span>
                                                <div class="reason-details">
                                                    <span class="reason-date"><?php echo date('d.m.Y', strtotime($traeger['uebung_am'])); ?></span>
                                                    <span class="badge status-badge status-<?php echo $traeger['uebung_status'] === 'Abgelaufen' ? 'expired' : 'warning'; ?>">
                                                        <?php echo $traeger['uebung_status']; ?>
                                                    </span>
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
                        <a href="atemschutz.php" class="btn btn-danger w-100">
                            <i class="fas fa-mask"></i> Atemschutz verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dashboard-Einstellungen speichern
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.preference-toggle input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const preferenceKey = this.id;
                    const preferenceValue = this.checked ? '1' : '0';
                    
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=save_preferences&preference_key=${preferenceKey}&preference_value=${preferenceValue}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Seite neu laden um Änderungen anzuzeigen
                            location.reload();
                        } else {
                            console.error('Fehler beim Speichern der Einstellungen:', data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Fehler:', error);
                    });
                });
            });
        });
    </script>
</body>
</html>