<?php
/**
 * Divera-API Rohantworten: Alarmliste, Alarm-Detail, Reach (nur Superadmin).
 * Aufruf: admin/debug-divera-api.php?einheit_id=1&alarm_id=12345
 */
require_once __DIR__ . '/../includes/debug-auth.php';

$einheit_id = isset($_GET['einheit_id']) ? (int) $_GET['einheit_id'] : 1;
if ($einheit_id > 0) {
    $_SESSION['current_einheit_id'] = $einheit_id;
}
require_once __DIR__ . '/../config/divera.php';

$api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
$key = trim(preg_replace('/[\r\n\t\v]+/', '', (string) ($divera_config['access_key'] ?? '')));

$alarm_id = isset($_GET['alarm_id']) ? (int) $_GET['alarm_id'] : 0;

$alarms_decoded = null;
$alarm_decoded = null;
$reach_decoded = null;
$alarms_raw_err = '';
$alarm_raw_err = '';
$reach_raw_err = '';

$divera_http_get = static function (string $url) {
    $ctx = stream_context_create(['http' => ['timeout' => 18, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    return is_string($raw) ? $raw : '';
};

if ($key !== '') {
    $url_alarms = $api_base . '/api/v2/alarms?accesskey=' . urlencode($key);
    $raw_alarms = $divera_http_get($url_alarms);
    if ($raw_alarms === '') {
        $alarms_raw_err = 'Keine Antwort oder leer (Alarmliste).';
    } else {
        $alarms_decoded = json_decode($raw_alarms, true);
        if (!is_array($alarms_decoded)) {
            $alarms_raw_err = 'Alarmliste: kein gültiges JSON.';
        }
    }

    if ($alarm_id > 0) {
        $url_alarm = $api_base . '/api/v2/alarms/' . $alarm_id . '?accesskey=' . urlencode($key);
        $raw_alarm = $divera_http_get($url_alarm);
        if ($raw_alarm === '') {
            $alarm_raw_err = 'Keine Antwort oder leer (Alarm-Detail).';
        } else {
            $alarm_decoded = json_decode($raw_alarm, true);
            if (!is_array($alarm_decoded)) {
                $alarm_raw_err = 'Alarm-Detail: kein gültiges JSON.';
            }
        }

        $url_reach = $api_base . '/api/v2/alarms/reach/' . $alarm_id . '?accesskey=' . urlencode($key);
        $raw_reach = $divera_http_get($url_reach);
        if ($raw_reach === '') {
            $reach_raw_err = 'Keine Antwort oder leer (Reach).';
        } else {
            $reach_decoded = json_decode($raw_reach, true);
            if (!is_array($reach_decoded)) {
                $reach_raw_err = 'Reach: kein gültiges JSON.';
            }
        }
    }
}

$pretty = static function ($data) {
    if ($data === null) {
        return '(keine Daten)';
    }
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
};

$reach_inner = is_array($reach_decoded['data'] ?? null) ? $reach_decoded['data'] : null;
$alarm_inner = is_array($alarm_decoded['data'] ?? null) ? $alarm_decoded['data'] : null;

$hint_ucr_answered = is_array($alarm_inner) ? divera_alarm_ucr_answered_ids($alarm_inner, 0) : [];
$hint_reach_ucr = ($reach_inner !== null) ? divera_reach_confirmed_ucr_ids($reach_inner, 0) : [];

$alarm_items_for_links = [];
if (is_array($alarms_decoded)) {
    $block = $alarms_decoded['data'] ?? [];
    $items = $block['items'] ?? $block;
    if (is_array($items)) {
        foreach ($items as $id => $row) {
            if (is_array($row) && isset($row['id'])) {
                $alarm_items_for_links[] = [
                    'id' => (int) $row['id'],
                    'title' => trim((string) ($row['title'] ?? '')),
                ];
            } elseif (is_numeric($id) && is_array($row)) {
                $alarm_items_for_links[] = [
                    'id' => (int) $id,
                    'title' => trim((string) ($row['title'] ?? '')),
                ];
            }
        }
    }
}
usort($alarm_items_for_links, static fn ($a, $b) => $b['id'] <=> $a['id']);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Divera API-Antworten (Debug)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h1 class="h3"><i class="fas fa-bug text-warning"></i> Divera API – Rohdaten</h1>
    <p class="text-muted">Nur für Superadmins. Der Access-Key wird nicht angezeigt; es erscheinen nur JSON-Antworten der öffentlichen Endpunkte.</p>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-0 small">Einheit-ID</label>
                    <input type="number" class="form-control" name="einheit_id" value="<?php echo (int) $einheit_id; ?>" min="0" style="width:7rem;" title="Welche Einheit – steuert den Divera-Key">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-0 small">Alarm-ID (Einsatz)</label>
                    <input type="number" class="form-control" name="alarm_id" value="<?php echo $alarm_id > 0 ? (int) $alarm_id : ''; ?>" min="0" placeholder="z. B. 12345" style="width:10rem;">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Laden</button>
                </div>
            </form>
            <p class="small text-muted mb-0 mt-2">API-Basis: <code><?php echo htmlspecialchars($api_base); ?></code>
                · Key: <?php echo $key !== '' ? '<span class="text-success">hinterlegt</span>' : '<span class="text-danger">fehlt (Einheit prüfen)</span>'; ?></p>
        </div>
    </div>

    <?php if ($key === ''): ?>
        <div class="alert alert-warning">Kein Divera-Access-Key für diese Einheit (bzw. ohne Einheits-Kontext). In <a href="settings-global.php?einheit_id=<?php echo (int) max(1, $einheit_id); ?>&tab=divera">Einstellungen → Divera</a> hinterlegen oder <code>einheit_id</code> setzen.</div>
    <?php else: ?>

    <?php if ($alarm_items_for_links !== []): ?>
    <div class="card mb-3">
        <div class="card-header">Alarme in der Antwort (Kurzlinks)</div>
        <div class="card-body py-2">
            <p class="small text-muted mb-2">Zum schnellen Öffnen – neueste zuerst (max. 30 angezeigt).</p>
            <ul class="list-unstyled mb-0 small row">
                <?php foreach (array_slice($alarm_items_for_links, 0, 30) as $it): ?>
                <li class="col-12 col-md-6 col-lg-4 mb-1">
                    <a href="?einheit_id=<?php echo (int) $einheit_id; ?>&amp;alarm_id=<?php echo (int) $it['id']; ?>">ID <?php echo (int) $it['id']; ?> – <?php echo htmlspecialchars($it['title'] !== '' ? $it['title'] : '(ohne Titel)'); ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3 border-info">
        <div class="card-header bg-info text-white">Auswertung (wie die App)</div>
        <div class="card-body small">
            <p class="mb-1"><strong>ucr_answered → User-/UCR-IDs</strong> (für <code>members.divera_ucr_id</code>; verschachtelt: <strong>äußere Keys = Status-ID</strong>, <strong>innere Keys = User-ID/UCR-ID</strong> mit <code>ts</code>/<code>note</code>):</p>
            <pre class="bg-white border rounded p-2 mb-3"><?php echo htmlspecialchars($pretty($hint_ucr_answered)); ?></pre>
            <p class="mb-1"><strong>confirmed → UCR-IDs</strong> (Reach, ohne Status-Filter):</p>
            <pre class="bg-white border rounded p-2 mb-0"><?php echo htmlspecialchars($pretty($hint_reach_ucr)); ?></pre>
            <p class="text-muted mt-2 mb-0"><strong>Status-ID für die App-Einstellung:</strong> Bei <code>ucr_answered</code> die <strong>äußeren</strong> Schlüssel (z. B. <code>44986</code>). In die Mitgliederverwaltung gehört die <strong>innere</strong> Nummer (z. B. <code>251321</code>) ins Feld Divera UCR-ID, sofern das bei euch der Abgleich mit Divera ist.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><code>GET /api/v2/alarms</code></div>
        <div class="card-body">
            <?php if ($alarms_raw_err !== ''): ?>
                <p class="text-danger mb-0"><?php echo htmlspecialchars($alarms_raw_err); ?></p>
            <?php else: ?>
                <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height:420px;overflow:auto;"><?php echo htmlspecialchars($pretty($alarms_decoded)); ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alarm_id > 0): ?>
    <div class="card mb-3">
        <div class="card-header"><code>GET /api/v2/alarms/<?php echo (int) $alarm_id; ?></code></div>
        <div class="card-body">
            <?php if ($alarm_raw_err !== ''): ?>
                <p class="text-danger mb-0"><?php echo htmlspecialchars($alarm_raw_err); ?></p>
            <?php else: ?>
                <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height:520px;overflow:auto;"><?php echo htmlspecialchars($pretty($alarm_decoded)); ?></pre>
            <?php endif; ?>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-header"><code>GET /api/v2/alarms/reach/<?php echo (int) $alarm_id; ?></code></div>
        <div class="card-body">
            <?php if ($reach_raw_err !== ''): ?>
                <p class="text-danger mb-0"><?php echo htmlspecialchars($reach_raw_err); ?></p>
            <?php else: ?>
                <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height:520px;overflow:auto;"><?php echo htmlspecialchars($pretty($reach_decoded)); ?></pre>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <p class="text-muted small">Alarm-Detail und Reach erscheinen, sobald eine <strong>Alarm-ID</strong> eingetragen und geladen wurde.</p>
    <?php endif; ?>

    <?php endif; ?>

    <p class="mt-4 mb-0">
        <a href="debug-divera.php?einheit_id=<?php echo (int) max(1, $einheit_id); ?>" class="btn btn-outline-secondary btn-sm">← Divera-Konfiguration</a>
        <a href="settings-global.php?einheit_id=<?php echo (int) max(1, $einheit_id); ?>&tab=divera" class="btn btn-outline-secondary btn-sm">Einstellungen Divera</a>
    </p>
</div>
</body>
</html>
