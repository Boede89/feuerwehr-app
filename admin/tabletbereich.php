<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !is_superadmin($_SESSION['user_id'])) {
    header('Location: ../login.php?error=superadmin_only');
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id > 0) {
    $_SESSION['current_einheit_id'] = $einheit_id;
}

require_once __DIR__ . '/../config/divera.php';

$message = '';
$error = '';
$alarms = [];
$selected_alarm_id = isset($_GET['alarm_id']) ? (int)$_GET['alarm_id'] : 0;
$selected_alarm = null;
$selected_alarm_detail = null;
$selected_alarm_reach = null;
$raw_alarm_detail = null;
$raw_alarm_reach = null;

$api_base = rtrim(trim((string)($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
$divera_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string)($divera_config['access_key'] ?? '')));

if ($divera_key === '') {
    $error = 'Kein Divera-Access-Key für diese Einheit hinterlegt.';
} else {
    $alarms_error = null;
    $alarms = fetch_divera_alarms($divera_key, $api_base, $alarms_error);
    if ($alarms_error) {
        $error = $alarms_error;
    }

    if ($selected_alarm_id <= 0 && !empty($alarms)) {
        $selected_alarm_id = (int)$alarms[0]['id'];
    }

    foreach ($alarms as $alarm) {
        if ((int)$alarm['id'] === $selected_alarm_id) {
            $selected_alarm = $alarm;
            break;
        }
    }

    if ($selected_alarm_id > 0) {
        $url_alarm = $api_base . '/api/v2/alarms/' . $selected_alarm_id . '?accesskey=' . urlencode($divera_key);
        $url_reach = $api_base . '/api/v2/alarms/reach/' . $selected_alarm_id . '?accesskey=' . urlencode($divera_key);
        $ctx = stream_context_create(['http' => ['timeout' => 18, 'ignore_errors' => true]]);

        $raw_alarm = @file_get_contents($url_alarm, false, $ctx);
        if (is_string($raw_alarm) && $raw_alarm !== '') {
            $raw_alarm_detail = json_decode($raw_alarm, true);
            if (is_array($raw_alarm_detail)) {
                $selected_alarm_detail = is_array($raw_alarm_detail['data'] ?? null) ? $raw_alarm_detail['data'] : $raw_alarm_detail;
            }
        }

        $raw_reach = @file_get_contents($url_reach, false, $ctx);
        if (is_string($raw_reach) && $raw_reach !== '') {
            $raw_alarm_reach = json_decode($raw_reach, true);
            if (is_array($raw_alarm_reach)) {
                $selected_alarm_reach = is_array($raw_alarm_reach['data'] ?? null) ? $raw_alarm_reach['data'] : $raw_alarm_reach;
            }
        }
    }
}

$pretty_json = static function ($value) {
    if ($value === null) return 'Keine Daten';
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
};
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabletbereich - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .tablet-shell { max-width: 860px; margin: 0 auto; }
        .tablet-card { border-radius: 14px; border: 1px solid #e5e7eb; }
        .tablet-card .card-header { border-top-left-radius: 14px; border-top-right-radius: 14px; font-weight: 600; }
        .alarm-list-item { border-radius: 10px; margin-bottom: .5rem; border: 1px solid #e5e7eb; }
        .alarm-list-item.active { border-color: #0d6efd; background: #eef5ff; }
        .kv-label { color: #6c757d; font-size: .85rem; }
        .kv-value { font-weight: 600; word-break: break-word; }
        pre.tablet-pre {
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 44vh;
            overflow: auto;
            background: #111827;
            color: #f9fafb;
            border-radius: 10px;
            padding: .75rem;
            font-size: .78rem;
        }
        @media (max-width: 768px) {
            .container-fluid { padding-left: .75rem; padding-right: .75rem; }
            .btn, .form-select { min-height: 44px; }
            .h3 { font-size: 1.2rem; }
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-3 py-md-4">
        <div class="tablet-shell">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h1 class="h3 mb-0"><i class="fas fa-tablet-alt text-dark me-2"></i>Tabletbereich</h1>
                <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
            </div>

            <div class="card tablet-card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label mb-1">Einheit-ID</label>
                            <input type="number" class="form-control" name="einheit_id" value="<?php echo (int)$einheit_id; ?>" min="0">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label mb-1">Einsatz-ID (optional)</label>
                            <input type="number" class="form-control" name="alarm_id" value="<?php echo $selected_alarm_id > 0 ? (int)$selected_alarm_id : ''; ?>" min="0" placeholder="Automatisch = neuester Einsatz">
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-download me-1"></i>Divera-Daten laden</button>
                        </div>
                    </form>
                    <div class="small text-muted mt-2">API: <code><?php echo htmlspecialchars($api_base); ?></code></div>
                </div>
            </div>

            <?php if ($error): ?>
                <?php echo show_error($error); ?>
            <?php elseif ($message): ?>
                <?php echo show_success($message); ?>
            <?php endif; ?>

            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-bell me-2 text-primary"></i>Aktive Divera-Einsätze</div>
                <div class="card-body">
                    <?php if (empty($alarms)): ?>
                        <p class="text-muted mb-0">Keine aktiven Einsätze gefunden.</p>
                    <?php else: ?>
                        <?php foreach ($alarms as $alarm): ?>
                            <?php
                                $aid = (int)$alarm['id'];
                                $active = $aid === (int)$selected_alarm_id;
                                $link = 'tabletbereich.php?einheit_id=' . (int)$einheit_id . '&alarm_id=' . $aid;
                            ?>
                            <a class="d-block p-2 p-md-3 text-decoration-none alarm-list-item <?php echo $active ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($link); ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($alarm['title'] ?: ('Einsatz #' . $aid)); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($alarm['address'] ?? ''); ?></div>
                                    </div>
                                    <span class="badge bg-secondary">#<?php echo $aid; ?></span>
                                </div>
                                <?php if (!empty($alarm['date'])): ?>
                                    <div class="small text-muted mt-1"><?php echo date('d.m.Y H:i', (int)$alarm['date']); ?> Uhr</div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selected_alarm_id > 0): ?>
            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-info-circle me-2 text-success"></i>Einsatz-Übersicht</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="kv-label">Einsatz-ID</div>
                            <div class="kv-value"><?php echo (int)$selected_alarm_id; ?></div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="kv-label">Titel</div>
                            <div class="kv-value"><?php echo htmlspecialchars((string)($selected_alarm['title'] ?? $selected_alarm_detail['title'] ?? '')); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="kv-label">Adresse</div>
                            <div class="kv-value"><?php echo htmlspecialchars((string)($selected_alarm['address'] ?? $selected_alarm_detail['address'] ?? '')); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="kv-label">Text / Zusatzinfo</div>
                            <div class="kv-value"><?php echo nl2br(htmlspecialchars((string)($selected_alarm['text'] ?? $selected_alarm_detail['text'] ?? ''))); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-users me-2 text-warning"></i>Rückmeldungen (Reach)</div>
                <div class="card-body">
                    <?php
                        $received = is_array($selected_alarm_reach['received'] ?? null) ? count($selected_alarm_reach['received']) : 0;
                        $viewed = is_array($selected_alarm_reach['viewed'] ?? null) ? count($selected_alarm_reach['viewed']) : 0;
                        $confirmed = is_array($selected_alarm_reach['confirmed'] ?? null) ? count($selected_alarm_reach['confirmed']) : 0;
                    ?>
                    <div class="row g-2">
                        <div class="col-4"><div class="p-2 border rounded text-center"><div class="small text-muted">Empfangen</div><div class="h5 mb-0"><?php echo (int)$received; ?></div></div></div>
                        <div class="col-4"><div class="p-2 border rounded text-center"><div class="small text-muted">Gesehen</div><div class="h5 mb-0"><?php echo (int)$viewed; ?></div></div></div>
                        <div class="col-4"><div class="p-2 border rounded text-center"><div class="small text-muted">Bestätigt</div><div class="h5 mb-0"><?php echo (int)$confirmed; ?></div></div></div>
                    </div>
                </div>
            </div>

            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-code me-2 text-secondary"></i>Divera-Rohdaten (alles abrufbare)</div>
                <div class="card-body">
                    <h6 class="mb-2">Alarm-Detail (<code>/api/v2/alarms/<?php echo (int)$selected_alarm_id; ?></code>)</h6>
                    <pre class="tablet-pre"><?php echo htmlspecialchars($pretty_json($raw_alarm_detail)); ?></pre>
                    <h6 class="mb-2 mt-3">Reach (<code>/api/v2/alarms/reach/<?php echo (int)$selected_alarm_id; ?></code>)</h6>
                    <pre class="tablet-pre"><?php echo htmlspecialchars($pretty_json($raw_alarm_reach)); ?></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
