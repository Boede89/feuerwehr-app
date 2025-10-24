<?php
/**
 * Neues Dashboard mit kollabierbaren und sortierbaren Bereichen
 */

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datenbankverbindung
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

// Functions laden
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

// Dashboard-Daten laden
$pending_reservations = [];
if ($can_reservations) {
    $stmt = $db->query("SELECT * FROM reservations WHERE status = 'pending' ORDER BY created_at DESC LIMIT 10");
    $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$recent_atemschutz = [];
if ($can_atemschutz) {
    $stmt = $db->query("SELECT * FROM atemschutz_uebungen ORDER BY uebungsdatum DESC LIMIT 5");
    $recent_atemschutz = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$feedback_stats = [];
if ($can_settings) {
    $stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count 
        FROM feedback 
        GROUP BY status
    ");
    $feedback_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <link href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css" rel="stylesheet">
    <style>
        .dashboard-section {
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .dashboard-section-header {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease;
        }
        
        .dashboard-section-header:hover {
            background-color: #f8f9fa;
        }
        
        .dashboard-section-header.collapsed .collapse-icon {
            transform: rotate(-90deg);
        }
        
        .collapse-icon {
            transition: transform 0.3s ease;
            margin-right: 0.5rem;
        }
        
        .dashboard-section-body {
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .dashboard-section-body.collapsed {
            max-height: 0;
            padding: 0;
            margin: 0;
        }
        
        .dashboard-sections-container {
            position: relative;
        }
        
        .dashboard-section.sortable-ghost {
            opacity: 0.4;
        }
        
        .dashboard-section.sortable-chosen {
            cursor: move;
        }
        
        .dashboard-section.sortable-drag {
            transform: rotate(5deg);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .edit-mode .dashboard-section {
            border: 2px dashed #007bff;
            background-color: #f8f9ff;
        }
        
        .edit-mode .dashboard-section-header {
            background-color: #e3f2fd;
        }
        
        .edit-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .edit-controls .btn {
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Bearbeiten-Controls -->
    <div class="edit-controls">
        <button id="toggleEditMode" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-edit"></i> Bearbeiten
        </button>
        <button id="saveLayout" class="btn btn-success btn-sm" style="display: none;">
            <i class="fas fa-save"></i> Speichern
        </button>
        <button id="cancelEdit" class="btn btn-secondary btn-sm" style="display: none;">
            <i class="fas fa-times"></i> Abbrechen
        </button>
    </div>

    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">
                <i class="fas fa-tachometer-alt"></i> Dashboard
                <small class="text-muted">Willkommen, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</small>
            </h1>
        </div>

        <!-- Dashboard-Bereiche Container -->
        <div id="dashboardSections" class="dashboard-sections-container">
            
            <!-- Offene Reservierungen -->
            <?php if ($can_reservations): ?>
            <div class="dashboard-section" data-section-id="reservations">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="reservations">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-chevron-down collapse-icon"></i>
                            <i class="fas fa-calendar"></i> Offene Reservierungen (<?php echo count($pending_reservations); ?>)
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="reservations">
                        <?php if (empty($pending_reservations)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5 class="text-muted">Keine offenen Reservierungen</h5>
                                <p class="text-muted">Alle Reservierungen wurden bearbeitet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Fahrzeug</th>
                                            <th>Datum</th>
                                            <th>Zeit</th>
                                            <th>Status</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_reservations as $reservation): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($reservation['vehicle_name']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($reservation['reservation_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($reservation['start_time'] . ' - ' . $reservation['end_time']); ?></td>
                                            <td><span class="badge bg-warning">Ausstehend</span></td>
                                            <td>
                                                <a href="reservations.php?id=<?php echo $reservation['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye"></i> Ansehen
                                                </a>
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
            <?php endif; ?>

            <!-- Atemschutz-Übungen -->
            <?php if ($can_atemschutz): ?>
            <div class="dashboard-section" data-section-id="atemschutz">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="atemschutz">
                        <h6 class="m-0 font-weight-bold text-success">
                            <i class="fas fa-chevron-down collapse-icon"></i>
                            <i class="fas fa-mask"></i> Atemschutz-Übungen
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="atemschutz">
                        <?php if (empty($recent_atemschutz)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                                <h5 class="text-muted">Keine Atemschutz-Übungen</h5>
                                <p class="text-muted">Es wurden noch keine Übungen geplant.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($recent_atemschutz as $uebung): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($uebung['titel']); ?></h6>
                                        <small><?php echo date('d.m.Y', strtotime($uebung['uebungsdatum'])); ?></small>
                                    </div>
                                    <p class="mb-1"><?php echo htmlspecialchars($uebung['beschreibung']); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Feedback-Übersicht -->
            <?php if ($can_settings): ?>
            <div class="dashboard-section" data-section-id="feedback">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="feedback">
                        <h6 class="m-0 font-weight-bold text-info">
                            <i class="fas fa-chevron-down collapse-icon"></i>
                            <i class="fas fa-comments"></i> Feedback-Übersicht
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="feedback">
                        <div class="row">
                            <?php 
                            $statusLabels = [
                                'new' => 'Neu',
                                'in_progress' => 'In Bearbeitung',
                                'resolved' => 'Gelöst',
                                'closed' => 'Geschlossen'
                            ];
                            $statusColors = [
                                'new' => 'danger',
                                'in_progress' => 'warning',
                                'resolved' => 'success',
                                'closed' => 'secondary'
                            ];
                            ?>
                            <?php foreach ($feedback_stats as $stat): ?>
                            <div class="col-md-3 mb-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h5 class="card-title text-<?php echo $statusColors[$stat['status']] ?? 'secondary'; ?>">
                                            <?php echo $stat['count']; ?>
                                        </h5>
                                        <p class="card-text"><?php echo $statusLabels[$stat['status']] ?? $stat['status']; ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Letzte Aktivitäten -->
            <div class="dashboard-section" data-section-id="recent_activities">
                <div class="card shadow">
                    <div class="card-header dashboard-section-header" data-section="recent_activities">
                        <h6 class="m-0 font-weight-bold text-secondary">
                            <i class="fas fa-chevron-down collapse-icon"></i>
                            <i class="fas fa-history"></i> Letzte Aktivitäten
                        </h6>
                    </div>
                    <div class="card-body dashboard-section-body" data-section="recent_activities">
                        <div class="text-center py-5">
                            <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                            <h5 class="text-muted">Aktivitäten-Log</h5>
                            <p class="text-muted">Hier werden die letzten Aktivitäten angezeigt.</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        let sortable = null;
        let isEditMode = false;
        let originalSettings = null;

        // Dashboard-Einstellungen laden
        async function loadDashboardSettings() {
            try {
                const response = await fetch('api/load-dashboard-settings.php');
                const data = await response.json();
                
                if (data.success) {
                    applySettings(data.settings);
                    originalSettings = data.settings;
                }
            } catch (error) {
                console.error('Fehler beim Laden der Einstellungen:', error);
            }
        }

        // Einstellungen anwenden
        function applySettings(settings) {
            settings.forEach(setting => {
                const section = document.querySelector(`[data-section-id="${setting.id}"]`);
                if (section) {
                    const header = section.querySelector('.dashboard-section-header');
                    const body = section.querySelector('.dashboard-section-body');
                    
                    if (setting.collapsed) {
                        header.classList.add('collapsed');
                        body.classList.add('collapsed');
                    } else {
                        header.classList.remove('collapsed');
                        body.classList.remove('collapsed');
                    }
                }
            });
        }

        // Dashboard-Einstellungen speichern
        async function saveDashboardSettings() {
            const sections = Array.from(document.querySelectorAll('.dashboard-section'));
            const settings = sections.map((section, index) => ({
                id: section.dataset.sectionId,
                collapsed: section.querySelector('.dashboard-section-header').classList.contains('collapsed'),
                order: index
            }));

            try {
                const response = await fetch('api/save-dashboard-settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ sections: settings })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Dashboard-Layout gespeichert!');
                    originalSettings = settings;
                } else {
                    alert('Fehler beim Speichern: ' + data.message);
                }
            } catch (error) {
                console.error('Fehler beim Speichern:', error);
                alert('Fehler beim Speichern der Einstellungen');
            }
        }

        // Bearbeiten-Modus aktivieren
        function enableEditMode() {
            isEditMode = true;
            document.body.classList.add('edit-mode');
            document.getElementById('toggleEditMode').style.display = 'none';
            document.getElementById('saveLayout').style.display = 'inline-block';
            document.getElementById('cancelEdit').style.display = 'inline-block';

            // Sortable aktivieren
            const container = document.getElementById('dashboardSections');
            sortable = new Sortable(container, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag'
            });
        }

        // Bearbeiten-Modus deaktivieren
        function disableEditMode() {
            isEditMode = false;
            document.body.classList.remove('edit-mode');
            document.getElementById('toggleEditMode').style.display = 'inline-block';
            document.getElementById('saveLayout').style.display = 'none';
            document.getElementById('cancelEdit').style.display = 'none';

            // Sortable deaktivieren
            if (sortable) {
                sortable.destroy();
                sortable = null;
            }
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Einstellungen laden
            loadDashboardSettings();

            // Kollaps-Header
            document.querySelectorAll('.dashboard-section-header').forEach(header => {
                header.addEventListener('click', function() {
                    if (!isEditMode) {
                        const section = this.dataset.section;
                        const body = document.querySelector(`.dashboard-section-body[data-section="${section}"]`);
                        
                        this.classList.toggle('collapsed');
                        body.classList.toggle('collapsed');
                        
                        // Einstellungen speichern
                        saveDashboardSettings();
                    }
                });
            });

            // Bearbeiten-Buttons
            document.getElementById('toggleEditMode').addEventListener('click', enableEditMode);
            document.getElementById('saveLayout').addEventListener('click', function() {
                saveDashboardSettings();
                disableEditMode();
            });
            document.getElementById('cancelEdit').addEventListener('click', function() {
                if (originalSettings) {
                    applySettings(originalSettings);
                }
                disableEditMode();
            });
        });
    </script>
</body>
</html>
