<?php
/**
 * Anwesenheitsliste – Personal: anwesende Mitglieder auswählen und Fahrzeug zuordnen.
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

// Mitglieder laden (alle aus members, sortiert)
$members = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle members evtl. in anderem Schema
}
// Fahrzeuge laden
$vehicles = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
}

// POST: Auswahl speichern und zurück
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['members'] = [];
    $draft['member_vehicle'] = [];
    if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
        foreach ($_POST['member_id'] as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $draft['members'][] = $mid;
                $vid = isset($_POST['vehicle'][$mid]) ? (int)$_POST['vehicle'][$mid] : 0;
                if ($vid > 0) {
                    $draft['member_vehicle'][$mid] = $vid;
                }
            }
        }
    }
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
    exit;
}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
$selected_ids = array_flip($draft['members']);
$member_vehicle = $draft['member_vehicle'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Personal - Feuerwehr App</title>
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
                    <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
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
                        <h3 class="mb-0"><i class="fas fa-users"></i> Personal – Anwesende auswählen</h3>
                        <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?></p>
                    </div>
                    <div class="card-body p-4">
                        <form method="post">
                            <p class="text-muted small">Wählen Sie die anwesenden Personen und optional das Fahrzeug, auf dem sie mitgefahren sind.</p>
                            <?php if (empty($members)): ?>
                                <p class="text-muted">Keine Mitglieder in der Datenbank. Bitte zuerst in der Mitgliederverwaltung anlegen.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Anwesend</th>
                                                <th>Name</th>
                                                <th>Fahrzeug (optional)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $m): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="member_id[]" value="<?php echo (int)$m['id']; ?>"
                                                           id="m<?php echo (int)$m['id']; ?>"
                                                           <?php echo isset($selected_ids[$m['id']]) ? 'checked' : ''; ?>>
                                                </td>
                                                <td>
                                                    <label for="m<?php echo (int)$m['id']; ?>" class="mb-0"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></label>
                                                </td>
                                                <td>
                                                    <select class="form-select form-select-sm" name="vehicle[<?php echo (int)$m['id']; ?>]">
                                                        <option value="">— kein Fahrzeug —</option>
                                                        <?php foreach ($vehicles as $v): ?>
                                                        <option value="<?php echo (int)$v['id']; ?>" <?php echo (isset($member_vehicle[$m['id']]) && (int)$member_vehicle[$m['id']] === (int)$v['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Übernehmen und zurück</button>
                                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                            </div>
                        </form>
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
</body>
</html>
