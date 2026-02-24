<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/einheiten-setup.php';
require_once __DIR__ . '/includes/rooms-setup.php';

$message = '';
$error = '';

$einheit_id_url = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id_url > 0) {
    if (is_logged_in() && !is_system_user()) {
        if (function_exists('user_has_einheit_access') && user_has_einheit_access($_SESSION['user_id'], $einheit_id_url)) {
            $_SESSION['current_einheit_id'] = $einheit_id_url;
        }
    } else {
        try {
            $stmt = $db->prepare("SELECT id FROM einheiten WHERE id = ? AND is_active = 1");
            $stmt->execute([$einheit_id_url]);
            if ($stmt->fetch()) $_SESSION['current_einheit_id'] = $einheit_id_url;
        } catch (Exception $e) {}
    }
}

$rooms = [];
$einheit_filter = $einheit_id_url > 0 ? $einheit_id_url : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : null);
$einheit_param = $einheit_filter > 0 ? '?einheit_id=' . (int)$einheit_filter : '';
try {
    $sql = "SELECT * FROM rooms WHERE is_active = 1";
    $params = [];
    if ($einheit_filter > 0) {
        $sql .= " AND (einheit_id = ? OR einheit_id IS NULL)";
        $params[] = $einheit_filter;
    }
    $sql .= " ORDER BY sort_order ASC, name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Räume: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raum auswählen - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-door-open"></i> Raum auswählen
                        </h3>
                        <p class="text-muted mb-0">Wählen Sie den Raum aus, den Sie reservieren möchten</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <?php echo show_error($error); ?>
                        <?php endif; ?>
                        
                        <?php if (empty($rooms)): ?>
                            <div class="alert alert-warning text-center">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Keine Räume verfügbar</strong><br>
                                Es sind derzeit keine Räume zur Reservierung verfügbar. Bitte legen Sie Räume in den Einstellungen an.
                            </div>
                        <?php else: ?>
                            <div class="row justify-content-center g-4">
                                <?php foreach ($rooms as $room): ?>
                                    <div class="col-lg-4 col-md-6 col-sm-8 col-10">
                                        <div class="card h-100 room-card shadow-sm" onclick="selectRoom(<?php echo (int)$room['id']; ?>, <?php echo json_encode($room['name']); ?>, <?php echo json_encode($room['description'] ?? ''); ?>)">
                                            <div class="card-body text-center p-4">
                                                <div class="room-icon mb-3">
                                                    <i class="fas fa-door-open"></i>
                                                </div>
                                                <h5 class="card-title fw-bold"><?php echo htmlspecialchars($room['name']); ?></h5>
                                                <p class="card-text text-muted"><?php echo htmlspecialchars($room['description'] ?? ''); ?></p>
                                                <div class="room-action mt-3">
                                                    <span class="badge bg-primary">Auswählen</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center">
                        <a href="reservation-choice.php<?php echo $einheit_param; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const einheitId = <?php echo json_encode($einheit_filter > 0 ? (int)$einheit_filter : null); ?>;
        function selectRoom(roomId, roomName, description) {
            const roomData = { id: roomId, name: roomName, description: description || '' };
            sessionStorage.setItem('selectedRoom', JSON.stringify(roomData));
            const resUrl = 'room-reservation.php' + (einheitId ? '?einheit_id=' + einheitId : '');
            window.location.href = resUrl;
        }
        document.querySelectorAll('.room-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.15)';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            });
        });
    </script>
    <style>
        .room-card { cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent; border-radius: 15px; }
        .room-card:hover { border-color: #0d6efd; }
        .room-icon i { font-size: 3.5rem; padding: 1.2rem; border-radius: 50%; background: linear-gradient(135deg, #0d6efd, #6610f2); color: white; }
    </style>
</body>
</html>
