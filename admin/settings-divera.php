<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/divera.php';
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
$divera_api_debug = null;
$divera_reservation_groups = [];
$legacy_ids_raw = '';
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_access_key', 'divera_api_base_url', 'divera_reservation_groups', 'divera_reservation_group_ids', 'divera_debug_payloads')");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
        if ($row['setting_key'] === 'divera_debug_payloads' && $row['setting_value'] !== '') {
            $dec = json_decode($row['setting_value'], true);
            $divera_debug_payloads = is_array($dec) ? $dec : [];
        }
        if ($row['setting_key'] === 'divera_reservation_groups' && $row['setting_value'] !== '') {
            $dec = json_decode($row['setting_value'], true);
            $divera_reservation_groups = is_array($dec) ? $dec : [];
        }
        if ($row['setting_key'] === 'divera_reservation_group_ids') {
            $legacy_ids_raw = trim((string)$row['setting_value']);
        }
    }
    if (empty($divera_reservation_groups) && !empty($legacy_ids_raw)) {
        $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', $legacy_ids_raw)));
        foreach ($ids as $id) {
            if ($id > 0) $divera_reservation_groups[] = ['id' => $id, 'name' => 'Gruppe ' . $id];
        }
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden: ' . $e->getMessage();
}

// Divera API Debug (Alarms + Events live test)
$divera_key = trim((string) ($settings['divera_access_key'] ?? $divera_config['access_key'] ?? ''));
if ($divera_key === '' && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $divera_key = trim((string) ($row['divera_access_key'] ?? ''));
    } catch (Exception $e) { /* ignore */ }
}
$api_base = rtrim(trim((string) ($settings['divera_api_base_url'] ?? $divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
$divera_api_debug = [
    'has_key' => $divera_key !== '',
    'api_base' => $api_base,
    'alarms' => null,
    'events' => null,
];
if ($divera_key !== '') {
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $url_alarms_list = $api_base . '/api/v2/alarms/list?accesskey=' . urlencode($divera_key) . '&closed=0';
    $raw_alarms = @file_get_contents($url_alarms_list, false, $ctx);
    $url_alarms_direct = $api_base . '/api/v2/alarms?accesskey=' . urlencode($divera_key);
    $raw_alarms_direct = @file_get_contents($url_alarms_direct, false, $ctx);
    $divera_api_debug['alarms'] = [
        'url' => $api_base . '/api/v2/alarms/list?accesskey=***&closed=0',
        'url_direct' => $api_base . '/api/v2/alarms?accesskey=***',
        'raw' => $raw_alarms,
        'raw_direct' => $raw_alarms_direct,
        'parsed' => is_string($raw_alarms) ? json_decode($raw_alarms, true) : null,
        'parsed_direct' => is_string($raw_alarms_direct) ? json_decode($raw_alarms_direct, true) : null,
    ];
    $url_events = $api_base . '/api/v2/events?accesskey=' . urlencode($divera_key);
    $raw_events = @file_get_contents($url_events, false, $ctx);
    $divera_api_debug['events'] = [
        'url' => $api_base . '/api/v2/events?accesskey=***',
        'raw' => $raw_events,
        'parsed' => is_string($raw_events) ? json_decode($raw_events, true) : null,
    ];
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

            // Empfänger-Gruppen: ID + Name pro Zeile
            $group_ids = $_POST['divera_group_id'] ?? [];
            $group_names = $_POST['divera_group_name'] ?? [];
            $groups = [];
            foreach ($group_ids as $i => $gid) {
                $gid = trim((string) $gid);
                $gidInt = $gid === '' ? 0 : (int) $gid;
                $gname = trim((string) ($group_names[$i] ?? ''));
                if ($gname !== '') {
                    $groups[] = ['id' => $gidInt, 'name' => $gname];
                } elseif ($gidInt > 0) {
                    $groups[] = ['id' => $gidInt, 'name' => 'Gruppe ' . $gidInt];
                }
            }
            $divera_reservation_groups_json = json_encode($groups);

            foreach (['divera_access_key' => $divera_access_key, 'divera_api_base_url' => $divera_api_base_url, 'divera_reservation_groups' => $divera_reservation_groups_json] as $k => $v) {
                $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                $stmt->execute([$k, $v]);
            }
            $message = 'Divera-Einstellungen gespeichert.';
            $settings['divera_access_key'] = $divera_access_key;
            $settings['divera_api_base_url'] = $divera_api_base_url;
            $divera_reservation_groups = $groups;
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
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
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
            <button class="nav-link" id="api-debug-tab" data-bs-toggle="tab" data-bs-target="#api-debug" type="button">API Debug (Anwesenheitsliste + Terminübergabe)</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="debug-tab" data-bs-toggle="tab" data-bs-target="#debug" type="button">Letzte API-Anfragen (Terminübergabe)</button>
        </li>
    </ul>

    <div class="tab-content" id="diveraTabContent">
        <div class="tab-pane fade show active" id="verbindung" role="tabpanel">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="fas fa-cog"></i> Verbindung</div>
                <div class="card-body">
                    <p class="text-muted small">Diese Einstellungen werden für die automatische Übermittlung genehmigter Fahrzeugreservierungen an Divera verwendet. Weitere Optionen können später hier ergänzt werden.</p>
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
                        <div class="mb-3">
                            <label class="form-label">Empfänger-Gruppen (Fahrzeugreservierungen)</label>
                            <p class="text-muted small">Definieren Sie Divera-Gruppen mit ID und Namen. ID leer = keine Gruppen-ID an Divera (alle des Standortes). Beim Genehmigen kann die Empfänger-Gruppe ausgewählt werden.</p>
                            <div id="diveraGroupsContainer">
                                <?php foreach ($divera_reservation_groups as $idx => $g): ?>
                                <div class="input-group mb-2 divera-group-row">
                                    <input type="number" class="form-control" name="divera_group_id[]" placeholder="ID (leer = keine)" value="<?php echo (int)($g['id'] ?? 0) > 0 ? (int)$g['id'] : ''; ?>" min="0">
                                    <input type="text" class="form-control" name="divera_group_name[]" placeholder="Name der Gruppe" value="<?php echo htmlspecialchars($g['name'] ?? ''); ?>">
                                    <button type="button" class="btn btn-outline-danger btn-remove-group" title="Gruppe entfernen"><i class="fas fa-trash"></i></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddGroup"><i class="fas fa-plus me-1"></i>Gruppe hinzufügen</button>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
        </div>
        <div class="tab-pane fade" id="api-debug" role="tabpanel">
            <div class="card">
                <div class="card-header"><i class="fas fa-bug"></i> Divera API Debug</div>
                <div class="card-body">
                    <?php if (!$divera_api_debug['has_key']): ?>
                        <div class="alert alert-warning">
                            <strong>Kein Divera Access Key konfiguriert.</strong> Bitte im Tab „Verbindung“ oder im <a href="profile.php">Profil</a> einen Divera Access Key hinterlegen.
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-3">API-Basis: <code><?php echo htmlspecialchars($divera_api_debug['api_base']); ?></code></p>

                        <h6 class="mt-4">1. Alarms API (aktive Einsätze für Anwesenheitsliste)</h6>
                        <p class="small text-muted">Anfrage-URL (list):</p>
                        <pre class="bg-light p-2 rounded small overflow-auto"><?php echo htmlspecialchars($divera_api_debug['alarms']['url']); ?></pre>
                        <p class="small text-muted">Alternative URL (alarms):</p>
                        <pre class="bg-light p-2 rounded small overflow-auto"><?php echo htmlspecialchars($divera_api_debug['alarms']['url_direct']); ?></pre>
                        <p class="small text-muted mt-2">Antwort list (<?php echo $divera_api_debug['alarms']['raw'] === false ? 'Fehler – keine Antwort' : strlen($divera_api_debug['alarms']['raw']) . ' Zeichen'; ?>):</p>
                        <pre class="bg-dark text-light p-2 rounded small overflow-auto" style="max-height: 300px;"><?php
                        if ($divera_api_debug['alarms']['raw'] === false) {
                            echo 'Fehler: Konnte keine Verbindung herstellen.';
                        } else {
                            $pretty = json_encode(json_decode($divera_api_debug['alarms']['raw']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            echo htmlspecialchars($pretty ?: $divera_api_debug['alarms']['raw']);
                        }
                        ?></pre>
                        <?php if ($divera_api_debug['alarms']['raw_direct'] !== false): ?>
                        <p class="small text-muted mt-2">Antwort alarms (<?php echo strlen($divera_api_debug['alarms']['raw_direct']); ?> Zeichen):</p>
                        <pre class="bg-dark text-light p-2 rounded small overflow-auto" style="max-height: 200px;"><?php
                        $pretty_d = json_encode(json_decode($divera_api_debug['alarms']['raw_direct']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        echo htmlspecialchars($pretty_d ?: $divera_api_debug['alarms']['raw_direct']);
                        ?></pre>
                        <?php endif; ?>
                        <?php
                        $alarms_ok = is_array($divera_api_debug['alarms']['parsed']) && !empty($divera_api_debug['alarms']['parsed']['success']);
                        $alarms_direct_ok = is_array($divera_api_debug['alarms']['parsed_direct'] ?? null) && !empty($divera_api_debug['alarms']['parsed_direct']['success']);
                        if ($alarms_ok || $alarms_direct_ok):
                            $use_parsed = $alarms_ok ? $divera_api_debug['alarms']['parsed'] : $divera_api_debug['alarms']['parsed_direct'];
                            $alarm_data = $use_parsed['data'] ?? [];
                            $count = is_array($alarm_data) ? count($alarm_data) : 0;
                        ?>
                            <p class="mt-2">
                                <span class="badge bg-success">Erfolgreich</span>
                                <span class="text-muted"><?php echo $count; ?> offene Alarmierung(en)</span>
                                <?php if ($alarms_ok && !$alarms_direct_ok): ?><span class="text-muted small">(list)</span><?php endif; ?>
                                <?php if ($alarms_direct_ok && !$alarms_ok): ?><span class="text-muted small">(alarms)</span><?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p class="mt-2">
                                <?php
                                $p = $divera_api_debug['alarms']['parsed'] ?? $divera_api_debug['alarms']['parsed_direct'] ?? [];
                                $err_msg = is_array($p) ? ($p['message'] ?? $p['error'] ?? $p['msg'] ?? '') : '';
                                if ($err_msg === '') {
                                    $err_msg = ($divera_api_debug['alarms']['raw'] === false && ($divera_api_debug['alarms']['raw_direct'] ?? true) === false)
                                        ? 'Keine Verbindung zu Divera möglich.'
                                        : 'API gab success: false zurück. Mögliche Ursachen: ungültiger Access Key, Alarms-API nicht in der Divera-Lizenz enthalten (kostenpflichtiges Modul), oder fehlende Berechtigung.';
                                }
                                ?>
                                <span class="badge bg-danger">Fehler</span>
                                <span class="text-danger"><?php echo htmlspecialchars($err_msg); ?></span>
                            </p>
                        <?php endif; ?>

                        <h6 class="mt-4">2. Events API (Termine für Dienstplan-Import)</h6>
                        <p class="small text-muted">Anfrage-URL:</p>
                        <pre class="bg-light p-2 rounded small overflow-auto"><?php echo htmlspecialchars($divera_api_debug['events']['url']); ?></pre>
                        <p class="small text-muted mt-2">Antwort (<?php echo $divera_api_debug['events']['raw'] === false ? 'Fehler – keine Antwort' : strlen($divera_api_debug['events']['raw']) . ' Zeichen'; ?>):</p>
                        <pre class="bg-dark text-light p-2 rounded small overflow-auto" style="max-height: 300px;"><?php
                        if ($divera_api_debug['events']['raw'] === false) {
                            echo 'Fehler: Konnte keine Verbindung herstellen.';
                        } else {
                            $pretty = json_encode(json_decode($divera_api_debug['events']['raw']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            echo htmlspecialchars($pretty ?: $divera_api_debug['events']['raw']);
                        }
                        ?></pre>
                        <?php if (is_array($divera_api_debug['events']['parsed']) && isset($divera_api_debug['events']['parsed']['success'])): ?>
                            <p class="mt-2">
                                <?php if ($divera_api_debug['events']['parsed']['success']): ?>
                                    <span class="badge bg-success">Erfolgreich</span>
                                    <?php
                                    $ev_data = $divera_api_debug['events']['parsed']['data'] ?? [];
                                    $items = $ev_data['items'] ?? $ev_data;
                                    $ev_count = is_array($items) ? count($items) : 0;
                                    ?>
                                    <span class="text-muted"><?php echo $ev_count; ?> Termin(e)</span>
                                <?php else:
                                    $err_msg = $divera_api_debug['events']['parsed']['message'] ?? $divera_api_debug['events']['parsed']['error'] ?? $divera_api_debug['events']['parsed']['msg'] ?? '';
                                    if ($err_msg === '') {
                                        $err_msg = 'API gab success: false zurück. Mögliche Ursachen: ungültiger Access Key oder fehlende Berechtigung.';
                                    }
                                ?>
                                    <span class="badge bg-danger">Fehler</span>
                                    <span class="text-danger"><?php echo htmlspecialchars($err_msg); ?></span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
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
                            $ctx = $entry['context'] ?? '';
                            $is_failed = ($ctx === 'create_failed');
                            $badge = $is_delete ? 'DELETE' : ($is_response ? ($is_failed ? 'RESPONSE (Fehler)' : 'RESPONSE') : ($is_skip ? 'DELETE ÜBERSPRUNGEN' : 'POST'));
                            $badge_class = $is_delete ? 'danger' : ($is_response ? ($is_failed ? 'danger' : 'warning') : ($is_skip ? 'secondary' : (($entry['source'] ?? '') === 'form' ? 'info' : 'primary')));
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('diveraGroupsContainer');
    const btnAdd = document.getElementById('btnAddGroup');
    const rowTpl = () => {
        const div = document.createElement('div');
        div.className = 'input-group mb-2 divera-group-row';
        div.innerHTML = '<input type="number" class="form-control" name="divera_group_id[]" placeholder="ID (leer = keine)" min="0">' +
            '<input type="text" class="form-control" name="divera_group_name[]" placeholder="Name der Gruppe">' +
            '<button type="button" class="btn btn-outline-danger btn-remove-group" title="Gruppe entfernen"><i class="fas fa-trash"></i></button>';
        return div;
    };
    btnAdd.addEventListener('click', function() {
        container.appendChild(rowTpl());
    });
    container.addEventListener('click', function(e) {
        if (e.target.closest('.btn-remove-group')) {
            e.target.closest('.divera-group-row').remove();
        }
    });
});
</script>
</body>
</html>
