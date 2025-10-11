<?php
/**
 * Dashboard - Komplett neue saubere Version
 */

// Starte Session
session_start();

// Einfache Datenbankverbindung
$host = "feuerwehr_mysql";
$dbname = "feuerwehr_app";
$username = "feuerwehr_user";
$password = "feuerwehr_password";

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
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

function has_permission($permission_name, $user_id = null) {
    global $db;
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    
    if (!$user_id) {
        return false;
    }
    
    try {
        $stmt = $db->prepare("SELECT can_$permission_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result["can_$permission_name"] == 1) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking permission '$permission_name': " . $e->getMessage());
        return false;
    }
}

// Hole Benutzerinformationen
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Berechtigungen prüfen
$canAtemschutz = has_permission('atemschutz') || hasAdminPermission();
$canReservations = has_permission('reservations') || hasAdminPermission();
$canUsers = has_permission('users') || hasAdminPermission();
$canSettings = hasAdminPermission();

// Hole Atemschutz-Daten
$atemschutz_data = [];
if ($canAtemschutz) {
    try {
        $stmt = $db->query("SELECT * FROM atemschutz ORDER BY name");
        $atemschutz_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching atemschutz data: " . $e->getMessage());
    }
}

// Hole Reservierungs-Daten
$reservations_data = [];
if ($canReservations) {
    try {
        $stmt = $db->query("SELECT * FROM reservations ORDER BY start_date DESC LIMIT 10");
        $reservations_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching reservations data: " . $e->getMessage());
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
        .card {
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .atemschutz-item {
            border-left: 4px solid #dc3545;
        }
        .atemschutz-item.overdue {
            border-left-color: #ffc107;
        }
        .atemschutz-item.expired {
            border-left-color: #dc3545;
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
                <span class="navbar-text me-3">Hallo, <?php echo htmlspecialchars($user['name']); ?>!</span>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Atemschutz Bereich -->
            <?php if ($canAtemschutz): ?>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-mask"></i> Atemschutz</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($atemschutz_data)): ?>
                            <p class="text-muted">Keine Atemschutz-Daten verfügbar.</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($atemschutz_data as $item): ?>
                                    <?php
                                    $last_check = new DateTime($item['last_check']);
                                    $next_check = clone $last_check;
                                    $next_check->add(new DateInterval('P1Y')); // 1 Jahr
                                    $now = new DateTime();
                                    $days_until = $now->diff($next_check)->days;
                                    
                                    $item_class = 'atemschutz-item';
                                    if ($next_check < $now) {
                                        $item_class .= ' expired';
                                    } elseif ($days_until <= 30) {
                                        $item_class .= ' overdue';
                                    }
                                    ?>
                                    <div class="list-group-item <?php echo $item_class; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($item['name']); ?></h6>
                                            <small>
                                                <?php if ($next_check < $now): ?>
                                                    <span class="badge bg-danger">Abgelaufen</span>
                                                <?php elseif ($days_until <= 30): ?>
                                                    <span class="badge bg-warning">Bald fällig</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">OK</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <p class="mb-1">
                                            <small class="text-muted">
                                                Letzte Prüfung: <?php echo $last_check->format('d.m.Y'); ?><br>
                                                Nächste Prüfung: <?php echo $next_check->format('d.m.Y'); ?>
                                                <?php if ($next_check < $now): ?>
                                                    <br><strong class="text-danger">Überfällig um <?php echo $now->diff($next_check)->days; ?> Tage</strong>
                                                <?php elseif ($days_until <= 30): ?>
                                                    <br><strong class="text-warning">Fällig in <?php echo $days_until; ?> Tagen</strong>
                                                <?php endif; ?>
                                            </small>
                                        </p>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="notifyAtemschutz(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-bell"></i> Benachrichtigen
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-primary" onclick="notifyAllAtemschutz()">
                                    <i class="fas fa-bell"></i> Alle benachrichtigen
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reservierungen Bereich -->
            <?php if ($canReservations): ?>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-calendar"></i> Reservierungen</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reservations_data)): ?>
                            <p class="text-muted">Keine Reservierungen verfügbar.</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($reservations_data as $reservation): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($reservation['title']); ?></h6>
                                            <small class="text-muted">
                                                <?php echo date('d.m.Y', strtotime($reservation['start_date'])); ?>
                                            </small>
                                        </div>
                                        <p class="mb-1"><?php echo htmlspecialchars($reservation['description']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Navigation -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cog"></i> Navigation</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if ($canAtemschutz): ?>
                            <div class="col-md-3 mb-2">
                                <a href="atemschutz.php" class="btn btn-outline-danger w-100">
                                    <i class="fas fa-mask"></i> Atemschutz
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($canReservations): ?>
                            <div class="col-md-3 mb-2">
                                <a href="reservations.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-calendar"></i> Reservierungen
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($canUsers): ?>
                            <div class="col-md-3 mb-2">
                                <a href="users.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-users"></i> Benutzer
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($canSettings): ?>
                            <div class="col-md-3 mb-2">
                                <a href="settings.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-cog"></i> Einstellungen
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- E-Mail Modal -->
    <div class="modal fade" id="emailEntryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">E-Mail-Adresse eingeben</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="emailForm">
                        <div class="mb-3">
                            <label for="emailInput" class="form-label">E-Mail-Adresse:</label>
                            <input type="email" class="form-control" id="emailInput" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-primary" onclick="saveEmailAndNotify()">Speichern & Benachrichtigen</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentUserId = null;

        async function notifyAtemschutz(userId) {
            console.log('DEBUG: Versuche E-Mail-Modal zu öffnen für ID:', userId);
            
            // Prüfe ob der Benutzer eine E-Mail hat
            try {
                const response = await fetch('atemschutz-get.php');
                const data = await response.json();
                const user = data.find(u => u.id == userId);
                
                if (user && user.email) {
                    // Benutzer hat E-Mail, sende Benachrichtigung
                    await sendNotification(userId, user.email);
                } else {
                    // Benutzer hat keine E-Mail, öffne Modal
                    currentUserId = userId;
                    const modal = new bootstrap.Modal(document.getElementById('emailEntryModal'));
                    modal.show();
                }
            } catch (error) {
                console.error('Fehler beim Laden der Benutzerdaten:', error);
                alert('Fehler beim Laden der Benutzerdaten');
            }
        }

        async function notifyAllAtemschutz() {
            try {
                const response = await fetch('atemschutz-get.php');
                const data = await response.json();
                
                for (const user of data) {
                    if (user.email) {
                        await sendNotification(user.id, user.email);
                    }
                }
                
                alert('Benachrichtigungen wurden gesendet!');
            } catch (error) {
                console.error('Fehler beim Senden der Benachrichtigungen:', error);
                alert('Fehler beim Senden der Benachrichtigungen');
            }
        }

        async function sendNotification(userId, email) {
            try {
                const response = await fetch('atemschutz-notify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        email: email
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Benachrichtigung wurde gesendet!');
                } else {
                    alert('Fehler beim Senden der Benachrichtigung: ' + result.message);
                }
            } catch (error) {
                console.error('Fehler beim Senden der Benachrichtigung:', error);
                alert('Fehler beim Senden der Benachrichtigung');
            }
        }

        async function saveEmailAndNotify() {
            const email = document.getElementById('emailInput').value;
            
            if (!email) {
                alert('Bitte geben Sie eine E-Mail-Adresse ein');
                return;
            }
            
            try {
                // Speichere E-Mail-Adresse
                const saveResponse = await fetch('atemschutz-notify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: currentUserId,
                        email: email,
                        action: 'save_email'
                    })
                });
                
                const saveResult = await saveResponse.json();
                
                if (saveResult.success) {
                    // Sende Benachrichtigung
                    await sendNotification(currentUserId, email);
                    
                    // Schließe Modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('emailEntryModal'));
                    modal.hide();
                    
                    // Leere Eingabefeld
                    document.getElementById('emailInput').value = '';
                } else {
                    alert('Fehler beim Speichern der E-Mail-Adresse: ' + saveResult.message);
                }
            } catch (error) {
                console.error('Fehler beim Speichern der E-Mail-Adresse:', error);
                alert('Fehler beim Speichern der E-Mail-Adresse');
            }
        }
    </script>
</body>
</html>