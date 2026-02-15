<?php
/**
 * Anwesenheitsliste – Geräte: eingesetzte Geräte pro Fahrzeug auswählen.
 * Zeigt nur Fahrzeuge, die unter Fahrzeuge oder Personal ausgewählt wurden.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : '';
$auswahl = isset($_GET['auswahl']) ? trim($_GET['auswahl']) : '';
if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $auswahl === '') {
    header('Location: anwesenheitsliste.php?error=datum');
    exit;
}

$draft_key = 'anwesenheit_draft';
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}
$draft = &$_SESSION[$draft_key];

$vehicle_equipment_table_exists = false;
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS vehicle_equipment (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            KEY idx_vehicle (vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $vehicle_equipment_table_exists = true;
} catch (Exception $e) {
    error_log('vehicle_equipment: ' . $e->getMessage());
}

$selected_vehicle_ids = array_unique(array_merge(
    $draft['vehicles'] ?? [],
    array_values(array_filter($draft['member_vehicle'] ?? []))
));
$selected_vehicle_ids = array_filter(array_map('intval', $selected_vehicle_ids), function($v) { return $v > 0; });

$vehicles_with_equipment = [];
if ($vehicle_equipment_table_exists && !empty($selected_vehicle_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_vehicle_ids), '?'));
    try {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute(array_values($selected_vehicle_ids));
        $vehicles_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vehicles_raw as $v) {
            $vid = (int)$v['id'];
            $stmt2 = $db->prepare("SELECT id, name FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
            $stmt2->execute([$vid]);
            $equipment = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $vehicles_with_equipment[$vid] = [
                'name' => $v['name'],
                'equipment' => $equipment
            ];
        }
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['vehicle_equipment'] = [];
    foreach ($selected_vehicle_ids as $vid) {
        $selected = isset($_POST['equipment'][$vid]) && is_array($_POST['equipment'][$vid])
            ? array_map('intval', array_filter($_POST['equipment'][$vid], 'ctype_digit'))
            : [];
        if (!empty($selected)) {
            $draft['vehicle_equipment'][$vid] = $selected;
        }
    }
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}

$saved_selection = $draft['vehicle_equipment'] ?? [];
if (!is_array($saved_selection)) $saved_selection = [];

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Geräte - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Startseite</a></li>
                <li class="nav-item"><a class="nav-link" href="formulare.php"><i class="fas fa-file-alt"></i> Formulare</a></li>
                <?php if (!is_system_user()): ?>
                <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <?php if (!is_system_user()): ?>
                        <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-tools"></i> Geräte – eingesetzte Gerätschaften pro Fahrzeug</h3>
                    <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?> – Wählen Sie die eingesetzten Geräte pro Fahrzeug.</p>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($selected_vehicle_ids)): ?>
                        <p class="text-muted">Bitte wählen Sie zuerst unter <strong>Fahrzeuge</strong> oder <strong>Personal</strong> mindestens ein Fahrzeug aus.</p>
                        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
                    <?php else: ?>
                    <form method="post" id="geraeteForm">
                        <p class="text-muted small">Markieren Sie die Geräte, die bei diesem Einsatz/Dienst pro Fahrzeug eingesetzt wurden. Die Gerätelisten werden in den <a href="admin/vehicles.php">Fahrzeug-Einstellungen</a> pro Fahrzeug hinterlegt.</p>
                        <?php foreach ($vehicles_with_equipment as $vid => $data): ?>
                        <div class="card mb-3">
                            <div class="card-header py-2"><strong><?php echo htmlspecialchars($data['name']); ?></strong></div>
                            <div class="card-body py-3">
                                <?php if (empty($data['equipment'])): ?>
                                    <p class="text-muted small mb-0">Keine Geräte hinterlegt. <a href="admin/vehicles-geraete.php?vehicle_id=<?php echo (int)$vid; ?>">Geräte verwalten</a></p>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php foreach ($data['equipment'] as $eq):
                                            $checked = isset($saved_selection[$vid]) && in_array((int)$eq['id'], $saved_selection[$vid]);
                                        ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="equipment[<?php echo (int)$vid; ?>][]" value="<?php echo (int)$eq['id']; ?>" id="eq_<?php echo (int)$vid; ?>_<?php echo (int)$eq['id']; ?>"<?php echo $checked ? ' checked' : ''; ?>>
                                            <label class="form-check-label" for="eq_<?php echo (int)$vid; ?>_<?php echo (int)$eq['id']; ?>"><?php echo htmlspecialchars($eq['name']); ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Übernehmen und zurück</button>
                            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<footer class="bg-light mt-5 py-4">
    <div class="container text-center">
        <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.addEventListener('beforeunload', function() {
    var form = document.getElementById('geraeteForm');
    if (form) {
        var fd = new FormData(form);
        fd.append('form_type', 'geraete');
        navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
    } else {
        navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
    }
});
</script>
</body>
</html>
