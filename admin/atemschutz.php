<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist und Atemschutz-Berechtigung hat
if (!isset($_SESSION['user_id']) || !$_SESSION['can_atemschutz']) {
    header('Location: ../login.php');
    exit;
}

// Funktionen für Atemschutzgeräteträger
function calculateAge($birthdate) {
    $birth = new DateTime($birthdate);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

function calculateBisDate($amDate, $type, $age = null) {
    $am = new DateTime($amDate);
    
    switch ($type) {
        case 'strecke':
        case 'uebung':
            return $am->add(new DateInterval('P1Y'))->format('Y-m-d');
        case 'g263':
            if ($age >= 50) {
                return $am->add(new DateInterval('P1Y'))->format('Y-m-d');
            } else {
                return $am->add(new DateInterval('P3Y'))->format('Y-m-d');
            }
        default:
            return null;
    }
}

// AJAX-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_traeger':
                    $vorname = trim($_POST['vorname']);
                    $nachname = trim($_POST['nachname']);
                    $email = trim($_POST['email']) ?: null;
                    $geburtsdatum = $_POST['geburtsdatum'];
                    $alter = calculateAge($geburtsdatum);
                    $strecke_am = $_POST['strecke_am'] ?: null;
                    $g263_am = $_POST['g263_am'] ?: null;
                    $uebung_am = $_POST['uebung_am'] ?: null;
                    
                    // Berechne Bis-Daten
                    $strecke_bis = $strecke_am ? calculateBisDate($strecke_am, 'strecke') : null;
                    $g263_bis = $g263_am ? calculateBisDate($g263_am, 'g263', $alter) : null;
                    $uebung_bis = $uebung_am ? calculateBisDate($uebung_am, 'uebung') : null;
                    
                    $stmt = $db->prepare("
                        INSERT INTO atemschutz_traeger 
                        (vorname, nachname, email, geburtsdatum, alter_jahre, 
                         strecke_am, strecke_bis, g263_am, g263_bis, uebung_am, uebung_bis) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $vorname, $nachname, $email, $geburtsdatum, $alter,
                        $strecke_am, $strecke_bis, $g263_am, $g263_bis, $uebung_am, $uebung_bis
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Atemschutzgeräteträger erfolgreich hinzugefügt!']);
                    break;
                    
                case 'update_traeger':
                    $id = $_POST['id'];
                    $vorname = trim($_POST['vorname']);
                    $nachname = trim($_POST['nachname']);
                    $email = trim($_POST['email']) ?: null;
                    $geburtsdatum = $_POST['geburtsdatum'];
                    $alter = calculateAge($geburtsdatum);
                    $strecke_am = $_POST['strecke_am'] ?: null;
                    $g263_am = $_POST['g263_am'] ?: null;
                    $uebung_am = $_POST['uebung_am'] ?: null;
                    
                    // Berechne Bis-Daten
                    $strecke_bis = $strecke_am ? calculateBisDate($strecke_am, 'strecke') : null;
                    $g263_bis = $g263_am ? calculateBisDate($g263_am, 'g263', $alter) : null;
                    $uebung_bis = $uebung_am ? calculateBisDate($uebung_am, 'uebung') : null;
                    
                    $stmt = $db->prepare("
                        UPDATE atemschutz_traeger 
                        SET vorname = ?, nachname = ?, email = ?, geburtsdatum = ?, alter_jahre = ?,
                            strecke_am = ?, strecke_bis = ?, g263_am = ?, g263_bis = ?, 
                            uebung_am = ?, uebung_bis = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $vorname, $nachname, $email, $geburtsdatum, $alter,
                        $strecke_am, $strecke_bis, $g263_am, $g263_bis, $uebung_am, $uebung_bis, $id
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Atemschutzgeräteträger erfolgreich aktualisiert!']);
                    break;
                    
                case 'delete_traeger':
                    $id = $_POST['id'];
                    $stmt = $db->prepare("DELETE FROM atemschutz_traeger WHERE id = ?");
                    $stmt->execute([$id]);
                    echo json_encode(['success' => true, 'message' => 'Atemschutzgeräteträger erfolgreich gelöscht!']);
                    break;
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
    }
    exit;
}

// Lade alle Atemschutzgeräteträger
$stmt = $db->prepare("SELECT * FROM atemschutz_traeger ORDER BY nachname, vorname");
$stmt->execute();
$traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atemschutztauglichkeits-Überwachung</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-expired { background-color: #f8d7da; color: #721c24; }
        .status-warning { background-color: #fff3cd; color: #856404; }
        .status-ok { background-color: #d1edff; color: #0c5460; }
        .status-unknown { background-color: #e2e3e5; color: #383d41; }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reservations.php">
                                <i class="fas fa-calendar-alt"></i> Reservierungen
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="users.php">
                                <i class="fas fa-users"></i> Benutzer
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="vehicles.php">
                                <i class="fas fa-truck"></i> Fahrzeuge
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="atemschutz.php">
                                <i class="fas fa-lungs"></i> Atemschutz
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="settings.php">
                                <i class="fas fa-cog"></i> Einstellungen
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php">
                                <i class="fas fa-sign-out-alt"></i> Abmelden
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-lungs text-primary"></i> Atemschutztauglichkeits-Überwachung
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTraegerModal">
                            <i class="fas fa-plus"></i> Neuer Geräteträger
                        </button>
                    </div>
                </div>

                <!-- Status Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-danger">
                                    <i class="fas fa-exclamation-triangle"></i> Abgelaufen
                                </h5>
                                <h3 class="text-danger" id="expiredCount">0</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-warning">
                                    <i class="fas fa-clock"></i> Läuft bald ab
                                </h5>
                                <h3 class="text-warning" id="warningCount">0</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-success">
                                    <i class="fas fa-check-circle"></i> Gültig
                                </h5>
                                <h3 class="text-success" id="validCount">0</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-info">
                                    <i class="fas fa-users"></i> Gesamt
                                </h5>
                                <h3 class="text-info" id="totalCount">0</h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Traeger Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> Atemschutzgeräteträger
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Alter</th>
                                        <th>E-Mail</th>
                                        <th>Strecke</th>
                                        <th>G26.3</th>
                                        <th>Übung/Einsatz</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody id="traegerTableBody">
                                    <?php foreach ($traeger as $t): ?>
                                    <tr data-id="<?= $t['id'] ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($t['vorname'] . ' ' . $t['nachname']) ?></strong>
                                        </td>
                                        <td><?= $t['alter_jahre'] ?> Jahre</td>
                                        <td><?= $t['email'] ? htmlspecialchars($t['email']) : '-' ?></td>
                                        <td>
                                            <?php if ($t['strecke_am']): ?>
                                                <div class="status-badge <?= getStatusClass($t['strecke_bis']) ?>">
                                                    <?= date('d.m.Y', strtotime($t['strecke_am'])) ?> - <?= date('d.m.Y', strtotime($t['strecke_bis'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Nicht angegeben</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($t['g263_am']): ?>
                                                <div class="status-badge <?= getStatusClass($t['g263_bis']) ?>">
                                                    <?= date('d.m.Y', strtotime($t['g263_am'])) ?> - <?= date('d.m.Y', strtotime($t['g263_bis'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Nicht angegeben</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($t['uebung_am']): ?>
                                                <div class="status-badge <?= getStatusClass($t['uebung_bis']) ?>">
                                                    <?= date('d.m.Y', strtotime($t['uebung_am'])) ?> - <?= date('d.m.Y', strtotime($t['uebung_bis'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Nicht angegeben</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="editTraeger(<?= $t['id'] ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTraeger(<?= $t['id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal fade" id="addTraegerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Neuer Atemschutzgeräteträger</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="traegerForm">
                        <input type="hidden" id="traegerId" name="id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="vorname" class="form-label">Vorname *</label>
                                    <input type="text" class="form-control" id="vorname" name="vorname" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nachname" class="form-label">Nachname *</label>
                                    <input type="text" class="form-control" id="nachname" name="nachname" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">E-Mail</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="geburtsdatum" class="form-label">Geburtsdatum *</label>
                                    <input type="date" class="form-control" id="geburtsdatum" name="geburtsdatum" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="strecke_am" class="form-label">Strecke - Am Datum</label>
                                    <input type="date" class="form-control" id="strecke_am" name="strecke_am">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="g263_am" class="form-label">G26.3 - Am Datum</label>
                                    <input type="date" class="form-control" id="g263_am" name="g263_am">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="uebung_am" class="form-label">Übung/Einsatz - Am Datum</label>
                                    <input type="date" class="form-control" id="uebung_am" name="uebung_am">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-primary" onclick="saveTraeger()">Speichern</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Status-Klassen berechnen
        function getStatusClass(bisDate) {
            if (!bisDate) return 'status-unknown';
            
            const today = new Date();
            const bis = new Date(bisDate);
            const diffTime = bis - today;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays < 0) return 'status-expired';
            if (diffDays <= 30) return 'status-warning';
            return 'status-ok';
        }

        // Status-Badges hinzufügen
        document.addEventListener('DOMContentLoaded', function() {
            const badges = document.querySelectorAll('.status-badge');
            badges.forEach(badge => {
                const text = badge.textContent;
                const bisDate = text.split(' - ')[1];
                if (bisDate) {
                    badge.className = 'status-badge ' + getStatusClass(bisDate);
                }
            });
            
            updateStatusCounts();
        });

        // Status-Zähler aktualisieren
        function updateStatusCounts() {
            const rows = document.querySelectorAll('#traegerTableBody tr');
            let expired = 0, warning = 0, valid = 0;
            
            rows.forEach(row => {
                const badges = row.querySelectorAll('.status-badge');
                let hasExpired = false, hasWarning = false, hasValid = false;
                
                badges.forEach(badge => {
                    if (badge.classList.contains('status-expired')) hasExpired = true;
                    else if (badge.classList.contains('status-warning')) hasWarning = true;
                    else if (badge.classList.contains('status-ok')) hasValid = true;
                });
                
                if (hasExpired) expired++;
                else if (hasWarning) warning++;
                else if (hasValid) valid++;
            });
            
            document.getElementById('expiredCount').textContent = expired;
            document.getElementById('warningCount').textContent = warning;
            document.getElementById('validCount').textContent = valid;
            document.getElementById('totalCount').textContent = rows.length;
        }

        // Traeger bearbeiten
        function editTraeger(id) {
            // Hier würde die Logik zum Laden der Daten stehen
            document.getElementById('modalTitle').textContent = 'Atemschutzgeräteträger bearbeiten';
            document.getElementById('traegerId').value = id;
            // Modal öffnen
            new bootstrap.Modal(document.getElementById('addTraegerModal')).show();
        }

        // Traeger löschen
        function deleteTraeger(id) {
            if (confirm('Möchten Sie diesen Atemschutzgeräteträger wirklich löschen?')) {
                // AJAX-Löschung implementieren
                console.log('Lösche Traeger ID:', id);
            }
        }

        // Traeger speichern
        function saveTraeger() {
            const form = document.getElementById('traegerForm');
            const formData = new FormData(form);
            
            const action = document.getElementById('traegerId').value ? 'update_traeger' : 'add_traeger';
            formData.append('action', action);
            
            fetch('atemschutz.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Fehler: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ein Fehler ist aufgetreten!');
            });
        }
    </script>
</body>
</html>

<?php
// Hilfsfunktion für Status-Klassen
function getStatusClass($bisDate) {
    if (!$bisDate) return 'status-unknown';
    
    $today = new DateTime();
    $bis = new DateTime($bisDate);
    $diff = $today->diff($bis);
    $diffDays = $diff->days;
    
    if ($bis < $today) return 'status-expired';
    if ($diffDays <= 30) return 'status-warning';
    return 'status-ok';
}
?>
