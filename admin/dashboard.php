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
                    <div class="card-footer d-flex justify-content-between">
                        <a href="reservations.php" class="btn btn-primary">
                            <i class="fas fa-calendar-alt"></i> Alle Reservierungen verwalten
                        </a>
                        <a href="settings-vehicle-reservations.php" class="btn btn-outline-secondary">
                            <i class="fas fa-cog"></i> Einstellungen
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
    </script>
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
</body>
</html>