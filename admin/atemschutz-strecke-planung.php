<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';

// Tabellen erstellen falls nicht vorhanden
try {
    $db->exec("CREATE TABLE IF NOT EXISTS strecke_termine (
        id INT AUTO_INCREMENT PRIMARY KEY,
        termin_datum DATE NOT NULL,
        termin_zeit TIME DEFAULT '09:00:00',
        ort VARCHAR(255) DEFAULT '',
        max_teilnehmer INT NOT NULL DEFAULT 10,
        bemerkung TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT,
        INDEX idx_datum (termin_datum)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $db->exec("CREATE TABLE IF NOT EXISTS strecke_zuordnungen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        termin_id INT NOT NULL,
        traeger_id INT NOT NULL,
        status ENUM('geplant', 'bestaetigt', 'abgesagt', 'absolviert') DEFAULT 'geplant',
        benachrichtigt_am DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_zuordnung (termin_id, traeger_id),
        FOREIGN KEY (termin_id) REFERENCES strecke_termine(id) ON DELETE CASCADE,
        FOREIGN KEY (traeger_id) REFERENCES atemschutz_traeger(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("Tabellenerstellung Fehler: " . $e->getMessage());
}

// Alle Termine laden
$termine = [];
try {
    $stmt = $db->prepare("
        SELECT t.*, 
               COUNT(z.id) as aktuelle_teilnehmer,
               GROUP_CONCAT(z.traeger_id) as traeger_ids
        FROM strecke_termine t
        LEFT JOIN strecke_zuordnungen z ON t.id = z.termin_id
        WHERE t.termin_datum >= CURDATE() - INTERVAL 30 DAY
        GROUP BY t.id
        ORDER BY t.termin_datum ASC, t.termin_zeit ASC
    ");
    $stmt->execute();
    $termine = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Fehler beim Laden der Termine: " . $e->getMessage();
}

// Alle aktiven Geräteträger laden
$traeger = [];
try {
    $stmt = $db->prepare("
        SELECT at.*, 
               DATE_ADD(at.strecke_am, INTERVAL 1 YEAR) as strecke_bis,
               DATEDIFF(DATE_ADD(at.strecke_am, INTERVAL 1 YEAR), CURDATE()) as tage_bis_ablauf,
               sz.termin_id as zugeordneter_termin
        FROM atemschutz_traeger at
        LEFT JOIN strecke_zuordnungen sz ON at.id = sz.traeger_id
        WHERE at.status = 'Aktiv'
        ORDER BY tage_bis_ablauf ASC, at.last_name ASC
    ");
    $stmt->execute();
    $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Fehler beim Laden der Geräteträger: " . $e->getMessage();
}

// Warnschwelle laden
$warnDays = 90;
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false && is_numeric($val)) { $warnDays = (int)$val; }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Übungsstrecke - Terminplanung</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .termin-card {
            border: 2px solid #dee2e6;
            border-radius: 12px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .termin-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .termin-card.dragover {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .termin-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px 10px 0 0;
        }
        .termin-header.voll {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }
        .termin-header.vergangen {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        .teilnehmer-liste {
            min-height: 100px;
            padding: 15px;
            background: #f8f9fa;
        }
        .traeger-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            margin: 4px;
            border-radius: 20px;
            background: white;
            border: 1px solid #dee2e6;
            cursor: grab;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        .traeger-badge:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
        .traeger-badge.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }
        .traeger-badge .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .traeger-badge .status-dot.rot { background: #e74c3c; }
        .traeger-badge .status-dot.gelb { background: #f39c12; }
        .traeger-badge .status-dot.gruen { background: #27ae60; }
        .traeger-badge .remove-btn {
            margin-left: 8px;
            color: #dc3545;
            cursor: pointer;
        }
        .traeger-badge .notify-btn {
            margin-left: 5px;
            color: #0d6efd;
            cursor: pointer;
        }
        .traeger-badge .notify-btn.notified {
            color: #27ae60;
        }
        .nicht-zugeordnet {
            background: #fff3cd;
            border: 2px dashed #ffc107;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .nicht-zugeordnet-header {
            background: #ffc107;
            color: #212529;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .plaetze-info {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.9);
        }
        .btn-group-termin {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }
        .modal-lg-custom {
            max-width: 600px;
        }
        .ablauf-warnung {
            font-size: 0.75rem;
            color: #dc3545;
        }
        .ablauf-ok {
            font-size: 0.75rem;
            color: #198754;
        }
    </style>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">
                <i class="fas fa-calendar-check"></i> Übungsstrecke - Terminplanung
            </h1>
            <div class="btn-group">
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#terminModal">
                    <i class="fas fa-plus"></i> Neuer Termin
                </button>
                <button class="btn btn-primary" onclick="autoZuordnung()">
                    <i class="fas fa-magic"></i> Auto-Zuordnung
                </button>
                <button class="btn btn-info" onclick="alleInformieren()">
                    <i class="fas fa-envelope"></i> Alle informieren
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Nicht zugeordnete Geräteträger -->
        <div class="nicht-zugeordnet" id="nicht-zugeordnet">
            <div class="nicht-zugeordnet-header">
                <i class="fas fa-users"></i> <strong>Nicht zugeordnete Geräteträger</strong>
                <span class="badge bg-dark ms-2" id="nicht-zugeordnet-count">0</span>
            </div>
            <div class="teilnehmer-liste" id="pool-traeger">
                <?php 
                $nichtZugeordnet = 0;
                foreach ($traeger as $t): 
                    if (empty($t['zugeordneter_termin'])):
                        $nichtZugeordnet++;
                        $statusClass = 'gruen';
                        if ($t['tage_bis_ablauf'] !== null) {
                            if ($t['tage_bis_ablauf'] < 0) $statusClass = 'rot';
                            elseif ($t['tage_bis_ablauf'] <= $warnDays) $statusClass = 'gelb';
                        }
                ?>
                    <span class="traeger-badge" 
                          draggable="true" 
                          data-traeger-id="<?php echo $t['id']; ?>"
                          data-name="<?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>"
                          data-email="<?php echo htmlspecialchars($t['email'] ?? ''); ?>"
                          data-ablauf="<?php echo $t['tage_bis_ablauf']; ?>">
                        <span class="status-dot <?php echo $statusClass; ?>"></span>
                        <?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>
                        <?php if ($t['tage_bis_ablauf'] !== null): ?>
                            <small class="ms-2 <?php echo $t['tage_bis_ablauf'] < 0 ? 'ablauf-warnung' : ($t['tage_bis_ablauf'] <= $warnDays ? 'ablauf-warnung' : 'ablauf-ok'); ?>">
                                (<?php echo $t['tage_bis_ablauf'] < 0 ? 'abgelaufen' : $t['tage_bis_ablauf'] . ' Tage'; ?>)
                            </small>
                        <?php endif; ?>
                    </span>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
        </div>
        <script>document.getElementById('nicht-zugeordnet-count').textContent = '<?php echo $nichtZugeordnet; ?>';</script>

        <!-- Termine -->
        <div class="row" id="termine-container">
            <?php foreach ($termine as $termin): 
                $istVoll = $termin['aktuelle_teilnehmer'] >= $termin['max_teilnehmer'];
                $istVergangen = strtotime($termin['termin_datum']) < strtotime('today');
                $headerClass = $istVergangen ? 'vergangen' : ($istVoll ? 'voll' : '');
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="termin-card" data-termin-id="<?php echo $termin['id']; ?>">
                    <div class="termin-header <?php echo $headerClass; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-1">
                                    <i class="fas fa-calendar-day"></i>
                                    <?php echo date('d.m.Y', strtotime($termin['termin_datum'])); ?>
                                </h5>
                                <div>
                                    <i class="fas fa-clock"></i> 
                                    <?php echo date('H:i', strtotime($termin['termin_zeit'])); ?> Uhr
                                </div>
                                <?php if ($termin['ort']): ?>
                                    <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($termin['ort']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <div class="plaetze-info">
                                    <i class="fas fa-users"></i>
                                    <?php echo $termin['aktuelle_teilnehmer']; ?>/<?php echo $termin['max_teilnehmer']; ?>
                                </div>
                                <div class="btn-group-termin">
                                    <button class="btn btn-sm btn-light" onclick="editTermin(<?php echo $termin['id']; ?>)" title="Bearbeiten">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-light" onclick="terminInformieren(<?php echo $termin['id']; ?>)" title="Teilnehmer informieren">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteTermin(<?php echo $termin['id']; ?>)" title="Löschen">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="teilnehmer-liste termin-drop-zone" data-termin-id="<?php echo $termin['id']; ?>" data-max="<?php echo $termin['max_teilnehmer']; ?>">
                        <?php 
                        // Zugeordnete Geräteträger für diesen Termin laden
                        $traegerIds = $termin['traeger_ids'] ? explode(',', $termin['traeger_ids']) : [];
                        foreach ($traeger as $t):
                            if (in_array($t['id'], $traegerIds)):
                                $statusClass = 'gruen';
                                if ($t['tage_bis_ablauf'] !== null) {
                                    if ($t['tage_bis_ablauf'] < 0) $statusClass = 'rot';
                                    elseif ($t['tage_bis_ablauf'] <= $warnDays) $statusClass = 'gelb';
                                }
                        ?>
                            <span class="traeger-badge" 
                                  draggable="true" 
                                  data-traeger-id="<?php echo $t['id']; ?>"
                                  data-name="<?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>"
                                  data-email="<?php echo htmlspecialchars($t['email'] ?? ''); ?>"
                                  data-ablauf="<?php echo $t['tage_bis_ablauf']; ?>">
                                <span class="status-dot <?php echo $statusClass; ?>"></span>
                                <?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>
                                <i class="fas fa-times remove-btn" onclick="removeZuordnung(<?php echo $t['id']; ?>, <?php echo $termin['id']; ?>)" title="Entfernen"></i>
                                <i class="fas fa-envelope notify-btn" onclick="einzelnInformieren(<?php echo $t['id']; ?>, <?php echo $termin['id']; ?>)" title="Benachrichtigen"></i>
                            </span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                        <?php if (empty($traegerIds)): ?>
                            <div class="text-muted text-center py-3">
                                <i class="fas fa-user-plus"></i> Geräteträger hierher ziehen
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($termine)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <h5>Noch keine Termine vorhanden</h5>
                    <p>Erstellen Sie neue Termine für die Übungsstrecke.</p>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#terminModal">
                        <i class="fas fa-plus"></i> Ersten Termin erstellen
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Termin Modal -->
    <div class="modal fade" id="terminModal" tabindex="-1">
        <div class="modal-dialog modal-lg-custom">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> Neuer Termin</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="terminForm">
                    <div class="modal-body">
                        <input type="hidden" name="termin_id" id="termin_id" value="">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Datum *</label>
                                <input type="date" class="form-control" name="termin_datum" id="termin_datum" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Uhrzeit *</label>
                                <input type="time" class="form-control" name="termin_zeit" id="termin_zeit" value="09:00" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ort</label>
                            <input type="text" class="form-control" name="ort" id="ort" placeholder="z.B. Feuerwehrgerätehaus Amern">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Maximale Teilnehmerzahl *</label>
                            <input type="number" class="form-control" name="max_teilnehmer" id="max_teilnehmer" min="1" max="50" value="10" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Bemerkung</label>
                            <textarea class="form-control" name="bemerkung" id="bemerkung" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Speichern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Info Modal -->
    <div class="modal fade" id="infoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="infoModalTitle">Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="infoModalBody">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Drag & Drop Funktionalität
        let draggedElement = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            initDragDrop();
        });
        
        function initDragDrop() {
            // Draggable Elemente
            document.querySelectorAll('.traeger-badge').forEach(badge => {
                badge.addEventListener('dragstart', handleDragStart);
                badge.addEventListener('dragend', handleDragEnd);
            });
            
            // Drop-Zonen (Termine)
            document.querySelectorAll('.termin-drop-zone').forEach(zone => {
                zone.addEventListener('dragover', handleDragOver);
                zone.addEventListener('dragleave', handleDragLeave);
                zone.addEventListener('drop', handleDrop);
            });
            
            // Pool als Drop-Zone
            const pool = document.getElementById('pool-traeger');
            pool.addEventListener('dragover', handleDragOver);
            pool.addEventListener('dragleave', handleDragLeave);
            pool.addEventListener('drop', handleDropToPool);
        }
        
        function handleDragStart(e) {
            draggedElement = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.traegerId);
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
            document.querySelectorAll('.termin-card').forEach(card => {
                card.classList.remove('dragover');
            });
        }
        
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.closest('.termin-card')?.classList.add('dragover');
        }
        
        function handleDragLeave(e) {
            this.closest('.termin-card')?.classList.remove('dragover');
        }
        
        function handleDrop(e) {
            e.preventDefault();
            const terminId = this.dataset.terminId;
            const traegerId = e.dataTransfer.getData('text/plain');
            const maxTeilnehmer = parseInt(this.dataset.max);
            const aktuelleTeilnehmer = this.querySelectorAll('.traeger-badge').length;
            
            if (aktuelleTeilnehmer >= maxTeilnehmer) {
                showInfo('Termin voll', 'Dieser Termin hat bereits die maximale Teilnehmerzahl erreicht.');
                return;
            }
            
            // Zuordnung speichern
            zuordnungSpeichern(traegerId, terminId);
        }
        
        function handleDropToPool(e) {
            e.preventDefault();
            const traegerId = e.dataTransfer.getData('text/plain');
            
            // Finde den aktuellen Termin des Geräteträgers
            const badge = document.querySelector(`.traeger-badge[data-traeger-id="${traegerId}"]`);
            const currentTermin = badge?.closest('.termin-drop-zone')?.dataset.terminId;
            
            if (currentTermin) {
                removeZuordnung(traegerId, currentTermin);
            }
        }
        
        function zuordnungSpeichern(traegerId, terminId) {
            fetch('../api/strecke-zuordnung.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'zuordnen', traeger_id: traegerId, termin_id: terminId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showInfo('Fehler', data.message || 'Zuordnung fehlgeschlagen');
                }
            })
            .catch(err => {
                showInfo('Fehler', 'Netzwerkfehler: ' + err.message);
            });
        }
        
        function removeZuordnung(traegerId, terminId) {
            if (!confirm('Zuordnung wirklich entfernen?')) return;
            
            fetch('../api/strecke-zuordnung.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'entfernen', traeger_id: traegerId, termin_id: terminId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showInfo('Fehler', data.message || 'Entfernen fehlgeschlagen');
                }
            });
        }
        
        // Termin-Funktionen
        document.getElementById('terminForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());
            data.action = data.termin_id ? 'update' : 'create';
            
            fetch('../api/strecke-termine.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    location.reload();
                } else {
                    showInfo('Fehler', result.message || 'Speichern fehlgeschlagen');
                }
            });
        });
        
        function editTermin(terminId) {
            fetch('../api/strecke-termine.php?id=' + terminId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('termin_id').value = data.termin.id;
                    document.getElementById('termin_datum').value = data.termin.termin_datum;
                    document.getElementById('termin_zeit').value = data.termin.termin_zeit;
                    document.getElementById('ort').value = data.termin.ort || '';
                    document.getElementById('max_teilnehmer').value = data.termin.max_teilnehmer;
                    document.getElementById('bemerkung').value = data.termin.bemerkung || '';
                    
                    document.querySelector('#terminModal .modal-title').innerHTML = '<i class="fas fa-edit"></i> Termin bearbeiten';
                    new bootstrap.Modal(document.getElementById('terminModal')).show();
                }
            });
        }
        
        function deleteTermin(terminId) {
            if (!confirm('Termin wirklich löschen? Alle Zuordnungen werden ebenfalls gelöscht.')) return;
            
            fetch('../api/strecke-termine.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', termin_id: terminId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showInfo('Fehler', data.message || 'Löschen fehlgeschlagen');
                }
            });
        }
        
        // Auto-Zuordnung
        function autoZuordnung() {
            if (!confirm('Automatische Zuordnung starten? Geräteträger werden nach Ablaufdatum priorisiert den Terminen zugeordnet.')) return;
            
            fetch('../api/strecke-zuordnung.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'auto_zuordnung' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showInfo('Erfolg', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showInfo('Fehler', data.message || 'Auto-Zuordnung fehlgeschlagen');
                }
            });
        }
        
        // E-Mail Funktionen
        function alleInformieren() {
            if (!confirm('Alle zugeordneten Geräteträger per E-Mail über ihren Termin informieren?')) return;
            
            fetch('../api/strecke-email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'alle_informieren' })
            })
            .then(r => r.json())
            .then(data => {
                showInfo(data.success ? 'Erfolg' : 'Fehler', data.message);
            });
        }
        
        function terminInformieren(terminId) {
            if (!confirm('Alle Teilnehmer dieses Termins per E-Mail informieren?')) return;
            
            fetch('../api/strecke-email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'termin_informieren', termin_id: terminId })
            })
            .then(r => r.json())
            .then(data => {
                showInfo(data.success ? 'Erfolg' : 'Fehler', data.message);
            });
        }
        
        function einzelnInformieren(traegerId, terminId) {
            if (!confirm('Geräteträger per E-Mail über den Termin informieren?')) return;
            
            fetch('../api/strecke-email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'einzeln_informieren', traeger_id: traegerId, termin_id: terminId })
            })
            .then(r => r.json())
            .then(data => {
                showInfo(data.success ? 'Erfolg' : 'Fehler', data.message);
            });
        }
        
        function showInfo(title, message) {
            document.getElementById('infoModalTitle').textContent = title;
            document.getElementById('infoModalBody').textContent = message;
            new bootstrap.Modal(document.getElementById('infoModal')).show();
        }
        
        // Modal zurücksetzen beim Schließen
        document.getElementById('terminModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('terminForm').reset();
            document.getElementById('termin_id').value = '';
            document.querySelector('#terminModal .modal-title').innerHTML = '<i class="fas fa-calendar-plus"></i> Neuer Termin';
        });
    </script>
</body>
</html>

