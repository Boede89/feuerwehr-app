<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';

$settings = [];
$divera_debug_payloads = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_access_key', 'divera_api_base_url', 'divera_debug_payloads')");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
        if ($row['setting_key'] === 'divera_debug_payloads' && $row['setting_value'] !== '') {
            $dec = json_decode($row['setting_value'], true);
            $divera_debug_payloads = is_array($dec) ? $dec : [];
        }
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $divera_access_key = trim($_POST['divera_access_key'] ?? '');
            if ($divera_access_key === '') {
                $divera_access_key = trim((string) ($settings['divera_access_key'] ?? ''));
            }
            $divera_api_base_url = trim($_POST['divera_api_base_url'] ?? '') ?: 'https://app.divera247.com';

            foreach (['divera_access_key' => $divera_access_key, 'divera_api_base_url' => $divera_api_base_url] as $k => $v) {
                $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                $stmt->execute([$k, $v]);
            }
            $message = 'Divera-Einstellungen gespeichert.';
            $settings['divera_access_key'] = $divera_access_key;
            $settings['divera_api_base_url'] = $divera_api_base_url;
        } catch (Exception $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Divera 24/7 Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <?php echo get_admin_navigation(); ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-calendar-plus"></i> Divera 24/7 Einstellungen</h1>
        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <ul class="nav nav-tabs mb-3" id="diveraTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="verbindung-tab" data-bs-toggle="tab" data-bs-target="#verbindung" type="button">Verbindung</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="debug-tab" data-bs-toggle="tab" data-bs-target="#debug" type="button">Debug</button>
        </li>
    </ul>

    <div class="tab-content" id="diveraTabContent">
        <div class="tab-pane fade show active" id="verbindung" role="tabpanel">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="fas fa-cog"></i> Verbindung</div>
                <div class="card-body">
                    <p class="text-muted small">Diese Einstellungen werden für „Termin an Divera 24/7“ (Formulare) und für die automatische Übermittlung genehmigter Fahrzeugreservierungen an Divera verwendet. Weitere Optionen können später hier ergänzt werden.</p>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Access Key (Einheits-Key)</label>
                            <input class="form-control" type="password" name="divera_access_key" value="" placeholder="Leer lassen zum Beibehalten" autocomplete="off">
                            <small class="text-muted"><?php echo !empty($settings['divera_access_key']) ? 'Key ist hinterlegt. Neuen Key eintragen zum Überschreiben.' : 'In Divera 24/7: Verwaltung → Konto (Kontakt- und Vertragsdaten).'; ?></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">API-Basis-URL</label>
                            <input class="form-control" type="url" name="divera_api_base_url" value="<?php echo htmlspecialchars($settings['divera_api_base_url'] ?? 'https://app.divera247.com'); ?>" placeholder="https://app.divera247.com">
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
        </div>
        <div class="tab-pane fade" id="debug" role="tabpanel">
            <div class="card">
                <div class="card-header"><i class="fas fa-bug"></i> Letzte 5 API-Anfragen an Divera</div>
                <div class="card-body">
                    <p class="text-muted small">POST (Erstellen) und DELETE (Löschen) – JSON-Bodies und Lösch-Requests (ohne Access Key).</p>
                    <?php if (empty($divera_debug_payloads)): ?>
                        <p class="text-muted">Noch keine Übermittlungen protokolliert.</p>
                    <?php else: ?>
                        <?php foreach ($divera_debug_payloads as $i => $entry): ?>
                            <?php 
                            $entry_type = $entry['type'] ?? 'post';
                            $is_delete = $entry_type === 'delete';
                            $is_response = $entry_type === 'response';
                            $is_skip = $entry_type === 'delete_skip';
                            $badge = $is_delete ? 'DELETE' : ($is_response ? 'RESPONSE' : ($is_skip ? 'DELETE ÜBERSPRUNGEN' : 'POST'));
                            $badge_class = $is_delete ? 'danger' : ($is_response ? 'warning' : ($is_skip ? 'secondary' : (($entry['source'] ?? '') === 'form' ? 'info' : 'primary')));
                            ?>
                            <div class="card mb-3">
                                <div class="card-header py-2 d-flex align-items-center">
                                    <strong>#<?php echo $i + 1; ?></strong>
                                    <span class="ms-2"><?php echo htmlspecialchars($entry['timestamp'] ?? ''); ?></span>
                                    <span class="badge ms-2 bg-<?php echo $badge_class; ?>"><?php echo $badge; ?></span>
                                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($entry['source'] ?? 'unknown'); ?></span>
                                    <?php if ($is_response && !empty($entry['context'])): ?>
                                        <span class="badge bg-dark ms-1"><?php echo htmlspecialchars($entry['context']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body p-2">
                                    <?php if ($is_skip): ?>
                                        <p class="mb-1"><strong>Reservierungs-ID:</strong> <?php echo (int)($entry['payload']['reservation_id'] ?? 0); ?></p>
                                        <p class="mb-1"><strong>Grund:</strong> <?php echo htmlspecialchars($entry['payload']['reason'] ?? ''); ?> (event_id_null = keine Divera-Event-ID gespeichert/gefunden; key_empty = kein Access Key)</p>
                                        <pre class="mb-0 small" style="max-height: 150px; overflow: auto;"><?php echo htmlspecialchars(json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                    <?php elseif ($is_delete): ?>
                                        <p class="mb-1"><strong>Event-ID:</strong> <?php echo (int)($entry['payload']['event_id'] ?? 0); ?></p>
                                        <p class="mb-1"><strong>URL-Pfad:</strong> <code><?php echo htmlspecialchars($entry['payload']['url_path'] ?? ''); ?></code></p>
                                        <pre class="mb-0 small" style="max-height: 150px; overflow: auto;"><?php echo htmlspecialchars(json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                    <?php elseif ($is_response): ?>
                                        <p class="mb-1 text-muted small">Divera-API-Antwort (zur Ermittlung der Event-ID):</p>
                                        <pre class="mb-0 small" style="max-height: 300px; overflow: auto;"><?php echo htmlspecialchars($entry['payload']['raw_response'] ?? ''); ?></pre>
                                    <?php else: ?>
                                        <pre class="mb-0 small" style="max-height: 300px; overflow: auto;"><?php echo htmlspecialchars(json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
