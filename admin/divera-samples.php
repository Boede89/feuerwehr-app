<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

function ds_admin_ensure_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_divera_samples (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL DEFAULT 0,
            display_name VARCHAR(255) NOT NULL,
            keyword VARCHAR(255) NOT NULL DEFAULT '',
            incident_date VARCHAR(255) NOT NULL DEFAULT '',
            alarms_json LONGTEXT NOT NULL,
            alarm_detail_json LONGTEXT NOT NULL,
            reach_json LONGTEXT NOT NULL,
            events_json LONGTEXT NOT NULL,
            created_by_token VARCHAR(191) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_einheit_created (einheit_id, created_at),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$message = '';
$error = '';
try {
    ds_admin_ensure_table($db);
} catch (Throwable $e) {
    $error = 'Beispieldaten-Tabelle konnte nicht vorbereitet werden.';
}

$einheitIdCurrent = function_exists('get_current_einheit_id') ? (int)(get_current_einheit_id() ?? 0) : 0;
$filterEinheit = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : $einheitIdCurrent;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungueltiger Sicherheitstoken.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                if ($filterEinheit > 0) {
                    $stmt = $db->prepare("DELETE FROM mobile_divera_samples WHERE id = ? AND (einheit_id = ? OR einheit_id = 0)");
                    $stmt->execute([$id, $filterEinheit]);
                } else {
                    $stmt = $db->prepare("DELETE FROM mobile_divera_samples WHERE id = ?");
                    $stmt->execute([$id]);
                }
                if ($stmt->rowCount() > 0) {
                    $message = 'Beispieldaten wurden geloescht.';
                } else {
                    $error = 'Datensatz nicht gefunden oder keine Berechtigung fuer diese Einheit.';
                }
            } catch (Throwable $e) {
                $error = 'Loeschen fehlgeschlagen.';
            }
        }
    }
}

$rows = [];
try {
    if ($filterEinheit > 0) {
        $stmt = $db->prepare("
            SELECT id, einheit_id, display_name, keyword, incident_date, created_at
            FROM mobile_divera_samples
            WHERE einheit_id = ? OR einheit_id = 0
            ORDER BY created_at DESC, id DESC
            LIMIT 200
        ");
        $stmt->execute([$filterEinheit]);
    } else {
        $stmt = $db->query("
            SELECT id, einheit_id, display_name, keyword, incident_date, created_at
            FROM mobile_divera_samples
            ORDER BY created_at DESC, id DESC
            LIMIT 200
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Beispieldaten konnten nicht geladen werden.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Divera Beispieldaten</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="d-flex ms-auto align-items-center">
            <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-database text-primary"></i> Divera Beispieldaten</h1>
        <a href="settings-global.php<?php echo $filterEinheit > 0 ? '?einheit_id=' . $filterEinheit . '&tab=einsatzapp' : ''; ?>" class="btn btn-outline-secondary">
            <i class="fas fa-gear me-1"></i> EinsatzApp Einstellungen
        </a>
    </div>

    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-1"></i> Gespeicherte Datensaetze (max. 200)</span>
            <span class="badge bg-secondary"><?php echo count($rows); ?> Eintraege</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="p-3 text-muted">Keine Beispieldaten vorhanden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Stichwort</th>
                                <th>Einsatzdatum</th>
                                <th>Einheit</th>
                                <th>Gespeichert am</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['display_name']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['keyword']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['incident_date']); ?></td>
                                    <td><?php echo (int)($r['einheit_id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Diesen Beispieldatensatz wirklich loeschen?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Loeschen
                                            </button>
                                        </form>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

