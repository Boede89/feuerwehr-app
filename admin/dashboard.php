<?php
/**
 * Dashboard - Reparierte Version
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

// Google Calendar Integration
require_once '../includes/google_calendar_service_account.php';
require_once '../includes/google_calendar.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Dashboard ist für alle eingeloggten Benutzer sichtbar; Inhalte werden per Berechtigung gesteuert
// WICHTIG: Berechtigungen einmal ermitteln und wiederverwenden (verhindert Inkonsistenzen)
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
                <?php echo get_admin_navigation(); ?>
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

        <?php if (!empty($pending_reservations)): ?>
        <!-- Ablehnungs-Modals für ausstehende Reservierungen -->
        <?php foreach ($pending_reservations as $reservation): ?>
            <div class="modal fade" id="rejectModal<?php echo $reservation['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Reservierung ablehnen</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="post" action="reservations.php">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="id" value="<?php echo $reservation['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Grund für Ablehnung:</label>
                                    <textarea class="form-control" name="rejection_reason" rows="3" required></textarea>
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
        <?php endforeach; ?>
        <?php endif; ?>
        
    </div>

    <!-- Modal: Atemschutz-Träger bearbeiten (vom Dashboard) -->
    <div class="modal fade" id="editTraegerDashModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-pen me-2"></i> Geräteträger bearbeiten</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="dashboard.php">
                    <input type="hidden" name="update_atemschutz_traeger" value="1">
                    <input type="hidden" name="id" id="dash_edit_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Vorname <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" id="dash_edit_first_name" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Nachname <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" id="dash_edit_last_name" required>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">E-Mail (optional)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" name="email" id="dash_edit_email" placeholder="name@beispiel.de">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">Geburtsdatum <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-cake-candles"></i></span>
                                    <input type="date" class="form-control" name="birthdate" id="dash_edit_birthdate" required>
                                </div>
                                <div class="form-text">Alter wird automatisch berechnet.</div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">Strecke am <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-road"></i></span>
                                    <input type="date" class="form-control" name="strecke_am" id="dash_edit_strecke_am" required>
                                </div>
                                <div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">G26.3 am <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-stethoscope me-2"></i></span>
                                    <input type="date" class="form-control" name="g263_am" id="dash_edit_g263_am" required>
                                </div>
                                <div class="form-text">Bis-Datum: unter 50 Jahre +3 Jahre, ab 50 +1 Jahr.</div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="form-label">Übung am <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-dumbbell me-2"></i></span>
                                    <input type="date" class="form-control" name="uebung_am" id="dash_edit_uebung_am" required>
                                </div>
                                <div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: E-Mail eintragen (schöne Variante statt Browser-Prompt) - immer verfügbar -->
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
        // Wenn ein Ablenhungs-Modal geöffnet wird, Konflikt prüfen
        document.querySelectorAll('.modal').forEach(function(modal) {
            modal.addEventListener('show.bs.modal', function() {
                try {
                    document.querySelectorAll('.dropdown-toggle').forEach(function(el){
                        try { bootstrap.Dropdown.getOrCreateInstance(el); } catch(_){}
                    });
                } catch(_) {}
            });
        });

        // Prefill für Atemschutz-Dashboard-Edit-Modal
        // Kein JS für Bearbeiten nötig – direkter Link zur Liste mit ?edit_id
    </script>

    <script>
        // Atemschutz-Benachrichtigungsfunktionen (immer verfügbar)
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
                    // schönes Modal öffnen (mit Bootstrap-Fallback)
                    const modalEl = document.getElementById('emailEntryModal');
                    if (!modalEl) { alert('Modal nicht verfügbar.'); return; }
                    const show = ()=>{ try { bootstrap.Modal.getOrCreateInstance(modalEl).show(); } catch(_) { alert('Modal kann nicht geöffnet werden.'); } };
                    document.getElementById('email_entry_id').value = String(id);
                    document.getElementById('email_entry_name').value = `${last_name || ''}, ${first_name || ''}`.trim();
                    document.getElementById('email_entry_email').value = '';
                    document.getElementById('email_entry_error').classList.add('d-none');
                    if (window.bootstrap && bootstrap.Modal) { show(); }
                    else {
                        const existing = document.querySelector('script[data-dyn="bs-bundle"]');
                        if (existing) existing.addEventListener('load', show);
                        else {
                            const s = document.createElement('script');
                            s.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
                            s.defer = true; s.async = true; s.setAttribute('data-dyn','bs-bundle');
                            s.addEventListener('load', show);
                            document.body.appendChild(s);
                        }
                    }
                    return;
                }
                // Mail aussenden
                const notifyRel = 'atemschutz-notify.php';
                let j = null;
                try { j = await fetch(notifyRel, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ ids:[id] }) }).then(r=>r.ok?r.json():null); } catch(_) { j = null; }
                if (j && j.success) { alert('E-Mail gesendet.'); } else { alert('Senden fehlgeschlagen.'); }
            } catch(e){ alert('Fehler: '+e.message); }
        }

        window.notifyAllAtemschutz = async function(){
            try {
                const res = await fetch('atemschutz-notify.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ all:true }) });
                const j = await res.json();
                if (j && j.success) { alert('E-Mails versendet: '+(j.sent||0)); } else { alert('Versand fehlgeschlagen.'); }
            } catch(e){ alert('Fehler: '+e.message); }
        }
    </script>
</body>
</html>