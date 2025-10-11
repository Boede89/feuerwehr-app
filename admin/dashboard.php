<?php
/**
 * Dashboard - Einfache Version
 */

// Starte Session
    session_start();

// Einfache Datenbankverbindung
$host = 'feuerwehr_mysql';
$dbname = 'feuerwehr_app';
$username = 'root';

// Versuche verschiedene Passwörter
$passwords = ['root', 'feuerwehr123', '', 'password', 'admin'];
$db = null;

foreach ($passwords as $password) {
    try {
        $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        break; // Erfolgreich verbunden
    } catch (PDOException $e) {
        // Versuche nächstes Passwort
        continue;
    }
}

if (!$db) {
    die("Datenbankverbindung fehlgeschlagen. Bitte prüfen Sie die Anmeldedaten in docker-compose.yml");
}

// Einfache Berechtigungsprüfung
function hasAdminPermission($user_id = null) {
    global $db;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    
    if (!$user_id) {
        return false;
    }
    
    try {
        $stmt = $db->prepare("SELECT role, is_admin, can_settings FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // Prüfe alte role-basierte Berechtigung
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // Prüfe neue permission-basierte Berechtigung
        if ($user['is_admin'] || $user['can_settings']) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking admin permission: " . $e->getMessage());
        return false;
    }
}

function has_permission($permission) {
    global $db;
    
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        return false;
    }
    
    try {
        $stmt = $db->prepare("SELECT can_$permission FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user && $user["can_$permission"];
                    } catch (Exception $e) {
        error_log("Error checking permission: " . $e->getMessage());
        return false;
    }
}

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Berechtigungen ermitteln
$canAtemschutz = has_permission('atemschutz') || hasAdminPermission();
$canReservations = has_permission('reservations') || hasAdminPermission();

// Verarbeite POST-Requests
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_atemschutz_traeger'])) {
        if (!has_permission('atemschutz') && !hasAdminPermission()) {
            $error = 'Keine Berechtigung.';
        } else {
            try {
                $id = (int)$_POST['id'];
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $birthdate = $_POST['birthdate'] ?? '';
                $strecke_am = $_POST['strecke_am'] ?? '';
                $g263_am = $_POST['g263_am'] ?? '';
                $uebung_am = $_POST['uebung_am'] ?? '';

                if (empty($first_name) || empty($last_name)) {
                    throw new Exception('Vor- und Nachname sind erforderlich.');
                }

                $stmt = $db->prepare("UPDATE atemschutz_traeger SET first_name=?, last_name=?, email=?, birthdate=?, strecke_am=?, g263_am=?, uebung_am=? WHERE id=?");
                $stmt->execute([$first_name, $last_name, $email, $birthdate, $strecke_am, $g263_am, $uebung_am, $id]);
                
                $message = 'Geräteträger erfolgreich aktualisiert.';
            } catch (Exception $e) {
                $error = 'Fehler: ' . $e->getMessage();
            }
        }
    }
}

// Lade Atemschutz-Daten
$atemschutz_items = [];
if ($canAtemschutz) {
    try {
        $stmt = $db->query("SELECT * FROM atemschutz_traeger ORDER BY last_name, first_name");
        $atemschutz_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = 'Fehler beim Laden der Atemschutz-Daten: ' . $e->getMessage();
    }
}

// Lade Reservierungs-Daten
$pending_reservations = [];
if ($canReservations) {
    try {
        $stmt = $db->query("SELECT * FROM reservations WHERE status = 'pending' ORDER BY created_at DESC");
        $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = 'Fehler beim Laden der Reservierungen: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire me-2"></i>Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../logout.php">Abmelden</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
                
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
            </div>
        </div>

        <?php if ($canAtemschutz): ?>
        <!-- Atemschutz Abschnitt -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-lungs"></i> Atemschutz</h6>
                        <a href="atemschutz.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-cog me-1"></i> Verwalten
                        </a>
                    </div>
                    <div class="card-body">
                        <?php
                        // Berechne Status für jeden Geräteträger
                        $now = new DateTime();
                        $items = [];
                        foreach ($atemschutz_items as $r) {
                            $age = $r['birthdate'] ? (int)$now->diff(new DateTime($r['birthdate']))->y : 0;
                            $streckeBis = !empty($r['strecke_am']) ? (new DateTime($r['strecke_am']))->modify('+1 year') : null;
                            $g263Bis = null; if (!empty($r['g263_am'])) { $g = new DateTime($r['g263_am']); $g->modify(($age < 50 ? '+3 year' : '+1 year')); $g263Bis = $g; }
                            $uebungBis = !empty($r['uebung_am']) ? (new DateTime($r['uebung_am']))->modify('+1 year') : null;
                            $streckeDiff = $streckeBis ? (int)$now->diff($streckeBis)->format('%r%a') : null;
                            $g263Diff = $g263Bis ? (int)$now->diff($g263Bis)->format('%r%a') : null;
                            $uebungDiff = $uebungBis ? (int)$now->diff($uebungBis)->format('%r%a') : null;

                            $status = 'ok';
                            if ($streckeDiff !== null && $streckeDiff < 0) $status = 'abgelaufen';
                            else if ($g263Diff !== null && $g263Diff < 0) $status = 'abgelaufen';
                            else if ($uebungDiff !== null && $uebungDiff < 0) $status = 'abgelaufen';
                            else if ($streckeDiff !== null && $streckeDiff < 30) $status = 'warnung';
                            else if ($g263Diff !== null && $g263Diff < 30) $status = 'warnung';
                            else if ($uebungDiff !== null && $uebungDiff < 30) $status = 'warnung';
                            
                            $items[] = array_merge($r, [
                                    'status' => $status,
                                'strecke_diff' => $streckeDiff,
                                'g263_diff' => $g263Diff,
                                'uebung_diff' => $uebungDiff
                            ]);
                        }
                        ?>

                        <?php if (empty($items)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-info-circle fa-2x mb-2"></i>
                                <p>Keine Geräteträger vorhanden.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>E-Mail</th>
                                            <th>Status</th>
                                            <th>Letzte Benachrichtigung</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $it): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($it['first_name'] . ' ' . $it['last_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($it['email'] ?: '-'); ?>
                                                </td>
                                                <td>
                                                    <?php if ($it['status']==='abgelaufen'): ?>
                                                        <span class="badge bg-danger">Abgelaufen</span>
                                                    <?php elseif ($it['status']==='warnung'): ?>
                                                        <span class="badge bg-warning text-dark">Warnung</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">OK</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($it['last_notified_at'] ? date('d.m.Y', strtotime($it['last_notified_at'])) : '-'); ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="notifyAtemschutz(<?php echo $it['id']; ?>)">
                                                        <i class="fas fa-envelope me-1"></i> Benachrichtigen
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($canReservations): ?>
        <div class="row">
            <!-- Fahrzeugreservierungen -->
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-car me-2"></i> Ausstehende Reservierungen</h6>
                        <a href="reservations.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-list me-1"></i> Alle anzeigen
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p>Keine ausstehenden Reservierungen.</p>
                            </div>
                        <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Fahrzeug</th>
                                            <th>Benutzer</th>
                                            <th>Von</th>
                                            <th>Bis</th>
                                            <th>Zweck</th>
                                            <th>Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_reservations as $reservation): ?>
                                                <tr>
                                                <td><?php echo htmlspecialchars($reservation['vehicle_name'] ?? 'Unbekannt'); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['user_name'] ?? 'Unbekannt'); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($reservation['start_time'])); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($reservation['end_time'])); ?></td>
                                                <td><?php echo htmlspecialchars($reservation['purpose'] ?? '-'); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-success me-1" onclick="approveReservation(<?php echo $reservation['id']; ?>)">
                                                        <i class="fas fa-check me-1"></i> Genehmigen
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="rejectReservation(<?php echo $reservation['id']; ?>)">
                                                        <i class="fas fa-times me-1"></i> Ablehnen
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
    </div>

    <!-- Modal: E-Mail eintragen -->
    <div class="modal fade" id="emailEntryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="fas fa-envelope me-2"></i> E-Mail eintragen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="emailEntryForm">
                    <input type="hidden" id="email_entry_id">
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label">Geräteträger</label>
                            <input type="text" class="form-control" id="email_entry_name" disabled>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">E-Mail Adresse</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-at"></i></span>
                                <input type="email" class="form-control" id="email_entry_email" placeholder="name@beispiel.de" required>
                            </div>
                            <div class="form-text">Diese Adresse wird gespeichert und für Benachrichtigungen verwendet.</div>
                        </div>
                        <div class="alert alert-danger d-none" id="email_entry_error"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-secondary">Speichern & Benachrichtigen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Atemschutz-Benachrichtigungsfunktionen
        window.notifyAtemschutz = async function(id){
            try {
                const relUrl = 'atemschutz-get.php?id='+encodeURIComponent(id);
                let data = null;
                try { data = await fetch(relUrl).then(r=>r.ok?r.json():null); } catch(_) { data = null; }
                if (!data || data.success !== true) {
                    const err = data && data.error ? String(data.error) : 'unbekannt';
                    alert('Konnte Geräteträger nicht laden ('+err+').');
                    return;
                }
                let { email, first_name, last_name } = data.data || {};
                if (!email) {
                    // Modal öffnen
                    const modalEl = document.getElementById('emailEntryModal');
                    if (!modalEl) { alert('Modal nicht verfügbar.'); return; }
                    document.getElementById('email_entry_id').value = String(id);
                    document.getElementById('email_entry_name').value = `${last_name || ''}, ${first_name || ''}`.trim();
                    document.getElementById('email_entry_email').value = '';
                    document.getElementById('email_entry_error').classList.add('d-none');
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    return;
                }
                // Mail aussenden
                const notifyRel = 'atemschutz-notify.php';
                let j = null;
                try { j = await fetch(notifyRel, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ ids:[id] }) }).then(r=>r.ok?r.json():null); } catch(_) { j = null; }
                if (j && j.success) { alert('E-Mail gesendet.'); } else { alert('Senden fehlgeschlagen.'); }
            } catch(e){ alert('Fehler: '+e.message); }
        }
    </script>
</body>
</html>