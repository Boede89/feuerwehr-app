<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einsatz-sync-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}
$einheitId = function_exists('get_current_einheit_id') ? (int)get_current_einheit_id() : 0;
$conn = (isset($db) && $db instanceof PDO) ? $db : null;
$incidents = [];
$incidentsError = null;
try {
    if (!$conn) {
        throw new RuntimeException('Keine gueltige Datenbankverbindung vorhanden.');
    }
    if (function_exists('einsatz_ensure_table')) {
        einsatz_ensure_table($conn);
    }
    $stmt = $conn->prepare("
        SELECT
            id,
            divera_alarm_id AS einsatznummer,
            title,
            address,
            latitude,
            longitude,
            is_active,
            is_sample,
            alarm_ts,
            created_at,
            last_synced_at
        FROM einsatz_data
        WHERE einheit_id = ?
        ORDER BY is_active DESC, last_synced_at DESC, id DESC
    ");
    $stmt->execute([$einheitId]);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $incidentsError = $e->getMessage();
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einsatzdaten</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        #einsatz-map { height: 70vh; min-height: 420px; border-radius: .5rem; }
        .leaflet-tooltip.vehicle-label {
            background: #0b2a4a;
            color: #fff;
            border: 0;
            box-shadow: 0 1px 6px rgba(0,0,0,.25);
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 10px;
        }
        .leaflet-tooltip.incident-label {
            background: #7f1d1d;
            color: #fff;
            border: 0;
            box-shadow: 0 1px 6px rgba(0,0,0,.25);
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 10px;
        }
    </style>
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
        <h1 class="h3 mb-0"><i class="fas fa-map-marked-alt text-danger"></i> Einsatzdaten</h1>
        <div class="text-muted small">Einheit-ID: <?php echo (int)$einheitId; ?></div>
    </div>
    <div class="card">
        <div class="card-body">
            <div id="einsatz-map"></div>
            <div id="einsatz-empty" class="alert alert-secondary d-none mb-0">
                Aktuell liegt kein aktiver Einsatz vor. Die Karte wird angezeigt, sobald eine Einsatzstelle verfuegbar ist.
            </div>
            <div class="mt-3" id="einsatz-meta">Lade Daten ...</div>
        </div>
    </div>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-table text-primary"></i> Einsaetze aus Einsatzdatenbank</strong>
            <span class="badge bg-secondary"><?php echo (int)count($incidents); ?> Eintraege</span>
        </div>
        <div class="card-body p-0">
            <?php if ($incidentsError): ?>
                <div class="alert alert-danger m-3 mb-0">
                    Fehler beim Laden der Einsatzdaten: <?php echo htmlspecialchars($incidentsError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php elseif (empty($incidents)): ?>
                <div class="alert alert-secondary m-3 mb-0">
                    Keine Einsaetze in der Einsatzdatenbank vorhanden.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Einsatznummer</th>
                            <th>Titel</th>
                            <th>Adresse</th>
                            <th>Status</th>
                            <th>Typ</th>
                            <th>Koordinaten</th>
                            <th>Alarmzeit</th>
                            <th>Letztes Update</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($incidents as $incident): ?>
                            <tr>
                                <td><?php echo (int)$incident['id']; ?></td>
                                <td><?php echo htmlspecialchars((string)($incident['einsatznummer'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($incident['title'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($incident['address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ((int)($incident['is_active'] ?? 0) === 1): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)($incident['is_sample'] ?? 0) === 1): ?>
                                        <span class="badge bg-warning text-dark">Beispiel</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Echt</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $lat = isset($incident['latitude']) ? (float)$incident['latitude'] : null;
                                    $lon = isset($incident['longitude']) ? (float)$incident['longitude'] : null;
                                    echo ($lat !== null && $lon !== null)
                                        ? htmlspecialchars(number_format($lat, 6, '.', '') . ', ' . number_format($lon, 6, '.', ''), ENT_QUOTES, 'UTF-8')
                                        : '-';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $alarmTs = isset($incident['alarm_ts']) ? (int)$incident['alarm_ts'] : 0;
                                    echo $alarmTs > 0
                                        ? htmlspecialchars(date('Y-m-d H:i:s', $alarmTs), ENT_QUOTES, 'UTF-8')
                                        : '-';
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)($incident['last_synced_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
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
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(() => {
    const map = L.map('einsatz-map').setView([51.1657, 10.4515], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    let incidentMarker = null;
    const vehicleMarkers = new Map();
    let initialFitDone = false;
    const metaEl = document.getElementById('einsatz-meta');
    const mapEl = document.getElementById('einsatz-map');
    const emptyEl = document.getElementById('einsatz-empty');
    const incidentIcon = L.divIcon({
        className: 'incident-marker-icon',
        html: '<div style="width:28px;height:28px;border-radius:50%;background:#dc2626;border:3px solid #fff;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 6px rgba(0,0,0,.35);font-size:15px;">🔥</div>',
        iconSize: [28, 28],
        iconAnchor: [14, 14]
    });
    const vehicleIcon = L.divIcon({
        className: 'vehicle-marker-icon',
        html: '<div style="width:28px;height:28px;border-radius:50%;background:#1d4ed8;border:3px solid #fff;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 6px rgba(0,0,0,.35);font-size:14px;">🚒</div>',
        iconSize: [28, 28],
        iconAnchor: [14, 14]
    });

    function upsertVehicleMarker(v) {
        const id = String(v.vehicle_id);
        const label = v.vehicle_name || ('Fahrzeug #' + id);
        let marker = vehicleMarkers.get(id);
        if (!marker) {
            marker = L.marker([v.latitude, v.longitude], { title: label, icon: vehicleIcon }).addTo(map);
            vehicleMarkers.set(id, marker);
            marker.bindTooltip(label, {
                permanent: true,
                direction: 'top',
                offset: [0, -16],
                className: 'vehicle-label'
            });
        } else {
            marker.setLatLng([v.latitude, v.longitude]);
            marker.setIcon(vehicleIcon);
            marker.setTooltipContent(label);
        }
        marker.bindPopup('<strong>' + label + '</strong><br>Letztes Update: ' + (v.updated_at || '-'));
        return marker;
    }

    async function refresh() {
        try {
            const res = await fetch('einsatzdaten-feed.php', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.success) throw new Error(json.message || 'Unbekannter Fehler');
            const data = json.data || {};

            const bounds = [];

            if (data.incident && Number.isFinite(data.incident.latitude) && Number.isFinite(data.incident.longitude)) {
                mapEl.classList.remove('d-none');
                emptyEl.classList.add('d-none');
                const pos = [data.incident.latitude, data.incident.longitude];
                if (!incidentMarker) {
                    incidentMarker = L.marker(pos, { title: data.incident.label || 'Einsatzstelle', icon: incidentIcon }).addTo(map);
                    incidentMarker.bindPopup('<strong>' + (data.incident.label || 'Einsatzstelle') + '</strong>');
                    incidentMarker.bindTooltip(data.incident.label || 'Einsatzstelle', {
                        permanent: true,
                        direction: 'top',
                        offset: [0, -16],
                        className: 'incident-label'
                    });
                } else {
                    incidentMarker.setLatLng(pos);
                    incidentMarker.setIcon(incidentIcon);
                    incidentMarker.setPopupContent('<strong>' + (data.incident.label || 'Einsatzstelle') + '</strong>');
                    incidentMarker.setTooltipContent(data.incident.label || 'Einsatzstelle');
                }
                bounds.push(pos);
            } else if (incidentMarker) {
                map.removeLayer(incidentMarker);
                incidentMarker = null;
            }

            const seen = new Set();
            (data.vehicles || []).forEach(v => {
                if (!Number.isFinite(v.latitude) || !Number.isFinite(v.longitude)) return;
                const marker = upsertVehicleMarker(v);
                bounds.push(marker.getLatLng());
                seen.add(String(v.vehicle_id));
            });

            for (const [key, marker] of vehicleMarkers.entries()) {
                if (!seen.has(key) || !data.incident) {
                    map.removeLayer(marker);
                    vehicleMarkers.delete(key);
                }
            }

            if (!data.incident) {
                mapEl.classList.add('d-none');
                emptyEl.classList.remove('d-none');
            }

            if (!initialFitDone && bounds.length > 0) {
                map.fitBounds(L.latLngBounds(bounds), { padding: [40, 40] });
                initialFitDone = true;
            }

            metaEl.innerHTML = '<span class="badge bg-primary">Fahrzeuge: ' + (data.vehicles || []).length + '</span>' +
                (data.incident ? ' <span class="badge bg-danger">Einsatzstelle aktiv</span>' : ' <span class="badge bg-secondary">Keine Einsatzstelle</span>') +
                ' <span class="text-muted ms-2">Letztes Polling: ' + new Date().toLocaleTimeString() + '</span>';
        } catch (err) {
            metaEl.innerHTML = '<span class="text-danger">Fehler beim Laden: ' + (err && err.message ? err.message : 'Unbekannt') + '</span>';
        }
    }

    refresh();
    setInterval(refresh, 5000);
})();
</script>
</body>
</html>
