<?php
/**
 * Debug-Einstellungen: Fahrzeugzuordnungen, Divera 24/7 und weitere Debug-Ansichten.
 * Nur für Superadmins / Admins mit Einstellungsrechten.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$valid_tabs = ['fahrzeugzuordnungen', 'divera'];
$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'fahrzeugzuordnungen';
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'fahrzeugzuordnungen';

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id <= 0) {
    header('Location: settings.php');
    exit;
}

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$von = isset($_GET['von']) ? trim($_GET['von']) : $jahr . '-01-01';
$bis = isset($_GET['bis']) ? trim($_GET['bis']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = $jahr . '-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) $bis = date('Y-m-d');

// Ausgewählte Einheit prüfen
$einheit_name = '';
try {
    $stmt = $db->prepare("SELECT id, name FROM einheiten WHERE id = ? AND is_active = 1");
    $stmt->execute([$einheit_id]);
    $einheit_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$einheit_row) {
        header('Location: settings.php');
        exit;
    }
    $einheit_name = $einheit_row['name'];
} catch (Exception $e) {
    header('Location: settings.php');
    exit;
}

$einheit_where_a = " AND (a.einheit_id = " . $einheit_id . " OR a.einheit_id IS NULL)";

$debug_data = [];
$vehicles = [];
$members = [];

try {
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name");
    $stmt->execute([$einheit_id]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {}

try {
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
    $stmt->execute([$einheit_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {}

$init_member = function($mid) use (&$debug_data) {
    if (!isset($debug_data[$mid])) {
        $debug_data[$mid] = [
            'auf_fahrzeug' => [],
            'maschinist' => [],
            'einheitsfuehrer' => []
        ];
    }
};

try {
    $stmt = $db->prepare("SELECT am.member_id, am.vehicle_id, a.datum FROM anwesenheitsliste_mitglieder am JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id WHERE a.datum BETWEEN ? AND ? AND am.vehicle_id IS NOT NULL AND am.vehicle_id > 0" . $einheit_where_a);
    $stmt->execute([$von, $bis]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$r['member_id'];
        $vid = (int)$r['vehicle_id'];
        $init_member($mid);
        $debug_data[$mid]['auf_fahrzeug'][$vid] = ($debug_data[$mid]['auf_fahrzeug'][$vid] ?? 0) + 1;
    }
    
    $stmt = $db->prepare("SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id FROM anwesenheitsliste_fahrzeuge af JOIN anwesenheitslisten a ON a.id = af.anwesenheitsliste_id WHERE a.datum BETWEEN ? AND ?" . $einheit_where_a);
    $stmt->execute([$von, $bis]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vid = (int)$r['vehicle_id'];
        if (!empty($r['maschinist_member_id'])) {
            $mid = (int)$r['maschinist_member_id'];
            $init_member($mid);
            $debug_data[$mid]['maschinist'][$vid] = ($debug_data[$mid]['maschinist'][$vid] ?? 0) + 1;
        }
        if (!empty($r['einheitsfuehrer_member_id'])) {
            $mid = (int)$r['einheitsfuehrer_member_id'];
            $init_member($mid);
            $debug_data[$mid]['einheitsfuehrer'][$vid] = ($debug_data[$mid]['einheitsfuehrer'][$vid] ?? 0) + 1;
        }
    }
} catch (Exception $e) {}

$member_map = [];
foreach ($members as $m) $member_map[(int)$m['id']] = $m;
$debug_mids = array_keys($debug_data);
$debug_missing = array_filter($debug_mids, fn($mid) => (int)$mid > 0 && !isset($member_map[(int)$mid]));
if (!empty($debug_missing)) {
    try {
        $ph = implode(',', array_fill(0, count($debug_missing), '?'));
        $st = $db->prepare("SELECT id, first_name, last_name FROM members WHERE id IN ($ph)");
        $st->execute(array_values($debug_missing));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $member_map[(int)$row['id']] = ['last_name' => $row['last_name'] ?? '', 'first_name' => $row['first_name'] ?? ''];
        }
    } catch (Exception $ex) {}
}

// Divera 24/7 Debug (nur bei Tab divera)
$divera_debug_payloads = [];
$divera_api_debug = null;
if ($active_tab === 'divera') {
    $settings = load_settings_for_einheit($db, $einheit_id);
    try {
        $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key = 'divera_debug_payloads' LIMIT 1");
        $stmt->execute([$einheit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['setting_value'] !== '') {
            $dec = json_decode($row['setting_value'], true);
            $divera_debug_payloads = is_array($dec) ? $dec : [];
        }
    } catch (Exception $e) {}
    $divera_key = trim((string) ($settings['divera_access_key'] ?? ''));
    $api_base = rtrim(trim((string) ($settings['divera_api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
    $divera_api_debug = [
        'has_key' => $divera_key !== '',
        'api_base' => $api_base,
        'alarms' => null,
        'events' => null,
    ];
    if ($divera_key !== '') {
        $ctx = stream_context_create(['http' => ['timeout' => 15]]);
        $url_alarms = $api_base . '/api/v2/alarms/list?accesskey=' . urlencode($divera_key) . '&closed=0';
        $raw_alarms = @file_get_contents($url_alarms, false, $ctx);
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
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug – Feuerwehr App</title>
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
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h1 class="h3 mb-0"><i class="fas fa-bug text-warning"></i> Debug – <?php echo htmlspecialchars($einheit_name); ?></h1>
        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'fahrzeugzuordnungen' ? 'active' : ''; ?>" href="?tab=fahrzeugzuordnungen&einheit_id=<?php echo (int)$einheit_id; ?>&von=<?php echo urlencode($von); ?>&bis=<?php echo urlencode($bis); ?>">
                <i class="fas fa-truck"></i> Fahrzeugzuordnungen
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'divera' ? 'active' : ''; ?>" href="?tab=divera&einheit_id=<?php echo (int)$einheit_id; ?>">
                <i class="fas fa-calendar-plus"></i> Divera 24/7
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'fahrzeugzuordnungen'): ?>
    <div class="card mb-4">
        <div class="card-header">Zeitraum</div>
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="fahrzeugzuordnungen">
                <input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>">
                <div class="col-auto">
                    <label class="form-label">Von</label>
                    <input type="date" name="von" class="form-control" value="<?php echo htmlspecialchars($von); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Bis</label>
                    <input type="date" name="bis" class="form-control" value="<?php echo htmlspecialchars($bis); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Anwenden</button>
                </div>
            </form>
        </div>
    </div>

    <p class="text-muted small mb-3"><i class="fas fa-info-circle"></i> Person × Fahrzeug (pro Fahrzeug: Besatzung | Maschinist | Einheitsführer). Nur Anwesenheitslisten, keine Gerätewartmitteilungen. Zeitraum: <?php echo htmlspecialchars($von); ?> – <?php echo htmlspecialchars($bis); ?></p>

    <div class="card mb-4">
        <div class="card-header bg-light"><strong><?php echo htmlspecialchars($einheit_name); ?></strong></div>
        <div class="card-body p-0">
            <?php if (empty($debug_data)): ?>
            <p class="text-muted p-3 mb-0">Keine Daten im gefilterten Zeitraum.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Person</th>
                            <?php foreach ($vehicles as $v): ?>
                            <th colspan="3" class="text-center border-start bg-light" title="<?php echo htmlspecialchars($v['name']); ?>"><?php echo htmlspecialchars($v['name']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th></th>
                            <?php foreach ($vehicles as $v): ?>
                            <th class="text-center border-start" style="min-width: 40px;">Besatzung</th>
                            <th class="text-center bg-info bg-opacity-25" style="min-width: 40px;">Masch.</th>
                            <th class="text-center bg-success bg-opacity-25" style="min-width: 40px;">EF</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debug_data as $mid => $d):
                            $m = $member_map[(int)$mid] ?? null;
                            $name = $m ? trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? '')) : 'Unbekannt (ID ' . (int)$mid . ')';
                            if ($name === '' && $m) $name = 'ID ' . (int)$mid;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($name); ?></td>
                            <?php foreach ($vehicles as $v):
                                $vid = (int)$v['id'];
                                $besatzung = $d['auf_fahrzeug'][$vid] ?? 0;
                                $masch = $d['maschinist'][$vid] ?? 0;
                                $ef = $d['einheitsfuehrer'][$vid] ?? 0;
                            ?>
                            <td class="text-center border-start"><?php echo $besatzung > 0 ? $besatzung : '–'; ?></td>
                            <td class="text-center bg-info bg-opacity-10"><?php echo $masch > 0 ? $masch : '–'; ?></td>
                            <td class="text-center bg-success bg-opacity-10"><?php echo $ef > 0 ? $ef : '–'; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_tab === 'divera'): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="fas fa-calendar-plus"></i> Divera 24/7 – <?php echo htmlspecialchars($einheit_name); ?></span>
            <?php if (function_exists('is_superadmin') && is_superadmin($_SESSION['user_id'] ?? 0)): ?>
            <div class="d-flex gap-2">
                <a href="debug-divera.php?einheit_id=<?php echo (int)$einheit_id; ?>" class="btn btn-primary btn-sm"><i class="fas fa-cog me-1"></i> Divera-Konfiguration prüfen</a>
                <a href="cleanup-divera-global.php" class="btn btn-primary btn-sm"><i class="fas fa-broom me-1"></i> Globale Keys bereinigen</a>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="divera-api-debug-tab" data-bs-toggle="tab" data-bs-target="#divera-api-debug" type="button">API Debug</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="divera-debug-tab" data-bs-toggle="tab" data-bs-target="#divera-debug" type="button">Letzte API-Anfragen</button>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="divera-api-debug" role="tabpanel">
                    <?php if (!$divera_api_debug || !$divera_api_debug['has_key']): ?>
                    <div class="alert alert-warning">
                        <strong>Kein Divera Access Key konfiguriert.</strong> Bitte in den <a href="settings-global.php?einheit_id=<?php echo (int)$einheit_id; ?>&tab=divera">Einheitseinstellungen (Divera)</a> einen Access Key hinterlegen.
                    </div>
                    <?php else: ?>
                    <p class="text-muted small mb-3">API-Basis: <code><?php echo htmlspecialchars($divera_api_debug['api_base']); ?></code></p>
                    <h6 class="mt-3">1. Alarms API (aktive Einsätze für Anwesenheitsliste)</h6>
                    <p class="small text-muted">Anfrage-URL (list):</p>
                    <pre class="bg-light p-2 rounded small overflow-auto"><?php echo htmlspecialchars(($divera_api_debug['alarms'] ?? [])['url'] ?? ''); ?></pre>
                    <p class="small text-muted mt-2">Antwort (<?php echo (($divera_api_debug['alarms'] ?? [])['raw'] ?? false) === false ? 'Fehler' : strlen(($divera_api_debug['alarms'] ?? [])['raw']) . ' Zeichen'; ?>):</p>
                    <pre class="bg-dark text-light p-2 rounded small overflow-auto" style="max-height: 200px;"><?php
                    $raw = ($divera_api_debug['alarms'] ?? [])['raw'] ?? false;
                    if ($raw === false) echo 'Fehler: Konnte keine Verbindung herstellen.';
                    else { $pretty = json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); echo htmlspecialchars($pretty ?: $raw); }
                    ?></pre>
                    <?php
                    $al = $divera_api_debug['alarms'] ?? [];
                    $alarms_ok = is_array($al['parsed'] ?? null) && !empty($al['parsed']['success']);
                    $alarms_direct_ok = is_array($al['parsed_direct'] ?? null) && !empty($al['parsed_direct']['success']);
                    if ($alarms_ok || $alarms_direct_ok):
                        $use = $alarms_ok ? $al['parsed'] : $al['parsed_direct'];
                        $count = count($use['data'] ?? []);
                    ?><p class="mt-2"><span class="badge bg-success">Erfolgreich</span> <?php echo $count; ?> offene Alarmierung(en)</p>
                    <?php else: ?><p class="mt-2"><span class="badge bg-danger">Fehler</span> <?php
                        $p = $al['parsed'] ?? $al['parsed_direct'] ?? [];
                        echo htmlspecialchars($p['message'] ?? $p['error'] ?? 'API gab success: false zurück oder keine Verbindung.');
                    ?></p><?php endif; ?>
                    <h6 class="mt-4">2. Events API (Termine für Dienstplan-Import)</h6>
                    <p class="small text-muted">Anfrage-URL:</p>
                    <pre class="bg-light p-2 rounded small overflow-auto"><?php echo htmlspecialchars(($divera_api_debug['events'] ?? [])['url'] ?? ''); ?></pre>
                    <p class="small text-muted mt-2">Antwort (<?php echo (($divera_api_debug['events'] ?? [])['raw'] ?? false) === false ? 'Fehler' : strlen(($divera_api_debug['events'] ?? [])['raw']) . ' Zeichen'; ?>):</p>
                    <pre class="bg-dark text-light p-2 rounded small overflow-auto" style="max-height: 200px;"><?php
                    $raw = ($divera_api_debug['events'] ?? [])['raw'] ?? false;
                    if ($raw === false) echo 'Fehler: Konnte keine Verbindung herstellen.';
                    else { $pretty = json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); echo htmlspecialchars($pretty ?: $raw); }
                    ?></pre>
                    <?php $ev_parsed = ($divera_api_debug['events'] ?? [])['parsed'] ?? null; if (is_array($ev_parsed) && isset($ev_parsed['success'])): ?>
                        <p class="mt-2"><?php if ($ev_parsed['success']): ?>
                            <span class="badge bg-success">Erfolgreich</span>
                            <?php $ev = $ev_parsed['data'] ?? []; $items = $ev['items'] ?? $ev; echo count(is_array($items) ? $items : []); ?> Termin(e)
                        <?php else: ?>
                            <span class="badge bg-danger">Fehler</span> <?php echo htmlspecialchars($ev_parsed['message'] ?? $ev_parsed['error'] ?? ''); ?>
                        <?php endif; ?></p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="tab-pane fade" id="divera-debug" role="tabpanel">
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
                                    <p class="mb-1"><strong>Grund:</strong> <?php echo htmlspecialchars($entry['payload']['reason'] ?? ''); ?></p>
                                    <pre class="mb-0 small" style="max-height: 150px; overflow: auto;"><?php echo htmlspecialchars(json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                <?php elseif ($is_delete): ?>
                                    <p class="mb-1"><strong>Event-ID:</strong> <?php echo (int)($entry['payload']['event_id'] ?? 0); ?></p>
                                    <p class="mb-1"><strong>URL-Pfad:</strong> <code><?php echo htmlspecialchars($entry['payload']['url_path'] ?? ''); ?></code></p>
                                    <pre class="mb-0 small" style="max-height: 150px; overflow: auto;"><?php echo htmlspecialchars(json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                <?php elseif ($is_response): ?>
                                    <p class="mb-1 text-muted small">Divera-API-Antwort:</p>
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
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
