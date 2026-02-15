<?php
/**
 * Gerätewartmitteilung anzeigen und bearbeiten.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('forms')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: formularcenter.php?tab=submissions');
    exit;
}

$gwm = null;
try {
    $stmt = $db->prepare("
        SELECT g.*, COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM geraetewartmitteilungen g
        LEFT JOIN users u ON u.id = g.user_id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $gwm = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $gwm = null;
}
if (!$gwm) {
    header('Location: formularcenter.php?tab=submissions&filter_formular=geraetewartmitteilung&error=not_found');
    exit;
}

$fahrzeuge = [];
try {
    $stmt = $db->prepare("
        SELECT gf.*, v.name AS vehicle_name,
               m1.first_name AS masch_first, m1.last_name AS masch_last,
               m2.first_name AS einh_first, m2.last_name AS einh_last
        FROM geraetewartmitteilung_fahrzeuge gf
        LEFT JOIN vehicles v ON v.id = gf.vehicle_id
        LEFT JOIN members m1 ON m1.id = gf.maschinist_member_id
        LEFT JOIN members m2 ON m2.id = gf.einheitsfuehrer_member_id
        WHERE gf.geraetewartmitteilung_id = ?
        ORDER BY v.name
    ");
    $stmt->execute([$id]);
    $fahrzeuge = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$einsatzleiter_display = '';
if (!empty($gwm['einsatzleiter_member_id'])) {
    try {
        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
        $stmt->execute([$gwm['einsatzleiter_member_id']]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) $einsatzleiter_display = trim($m['last_name'] . ', ' . $m['first_name']);
    } catch (Exception $e) {}
}
if ($einsatzleiter_display === '' && !empty($gwm['einsatzleiter_freitext'])) {
    $einsatzleiter_display = $gwm['einsatzleiter_freitext'];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerätewartmitteilung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="formularcenter.php?tab=submissions&filter_formular=geraetewartmitteilung"><i class="fas fa-arrow-left"></i> Zurück zum Formularcenter</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h1 class="h3 mb-0"><i class="fas fa-wrench text-info"></i> Gerätewartmitteilung</h1>
    <p class="text-muted small"><?php echo date('d.m.Y', strtotime($gwm['datum'])); ?> – <?php echo $gwm['typ'] === 'einsatz' ? 'Einsatz' : 'Übung'; ?> – <?php echo htmlspecialchars($gwm['einsatz_uebungsart']); ?></p>

    <div class="card mb-4">
        <div class="card-body">
            <table class="table table-sm">
                <tr><th class="text-muted" style="width:180px">Typ</th><td><?php echo $gwm['typ'] === 'einsatz' ? 'Einsatz' : 'Übung'; ?></td></tr>
                <tr><th class="text-muted">Einsatz-/Übungsart</th><td><?php echo htmlspecialchars($gwm['einsatz_uebungsart']); ?></td></tr>
                <tr><th class="text-muted">Datum</th><td><?php echo date('d.m.Y', strtotime($gwm['datum'])); ?></td></tr>
                <tr><th class="text-muted">Einsatzbereitschaft</th><td><?php echo $gwm['einsatzbereitschaft'] === 'hergestellt' ? 'Hergestellt' : 'Nicht hergestellt'; ?></td></tr>
                <tr><th class="text-muted">Einsatzleiter</th><td><?php echo htmlspecialchars($einsatzleiter_display ?: '-'); ?></td></tr>
                <tr><th class="text-muted">Eingereicht von</th><td><?php echo htmlspecialchars(trim($gwm['user_first_name'] . ' ' . $gwm['user_last_name']) ?: 'Unbekannt'); ?></td></tr>
                <tr><th class="text-muted">Eingereicht am</th><td><?php echo format_datetime_berlin($gwm['created_at']); ?></td></tr>
                <?php if (!empty($gwm['mangel_beschreibung'])): ?>
                <tr><th class="text-muted">Mangel beschreiben</th><td><?php echo nl2br(htmlspecialchars($gwm['mangel_beschreibung'])); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Eingesetzte Fahrzeuge</strong></div>
        <div class="card-body">
            <?php foreach ($fahrzeuge as $f):
                $masch = trim(($f['masch_last'] ?? '') . ', ' . ($f['masch_first'] ?? ''));
                $einh = trim(($f['einh_last'] ?? '') . ', ' . ($f['einh_first'] ?? ''));
                $equipment_used = json_decode($f['equipment_used'] ?? '[]', true) ?: [];
                $defective = json_decode($f['defective_equipment'] ?? '[]', true) ?: [];
            ?>
            <div class="border rounded p-3 mb-3">
                <h6 class="mb-2"><?php echo htmlspecialchars($f['vehicle_name'] ?? 'Fahrzeug'); ?></h6>
                <p class="mb-1 small"><strong>Maschinist:</strong> <?php echo htmlspecialchars($masch ?: '-'); ?> | <strong>Einheitsführer:</strong> <?php echo htmlspecialchars($einh ?: '-'); ?></p>
                <?php if (!empty($equipment_used)): ?>
                <?php
                    $eq_names = [];
                    foreach ($equipment_used as $eqid) {
                        $st = $db->prepare("SELECT name FROM vehicle_equipment WHERE id = ?");
                        $st->execute([$eqid]);
                        $n = $st->fetchColumn();
                        if ($n) $eq_names[] = $n;
                    }
                ?>
                <p class="mb-1 small"><strong>Eingesetzte Geräte:</strong> <?php echo htmlspecialchars(implode(', ', $eq_names)); ?></p>
                <?php endif; ?>
                <?php if (!empty($defective) || !empty($f['defective_freitext']) || !empty($f['defective_mangel'])): ?>
                <p class="mb-0 small text-warning"><strong>Defekte Geräte:</strong>
                    <?php
                    $def_names = [];
                    foreach ($defective as $eqid) {
                        $st = $db->prepare("SELECT name FROM vehicle_equipment WHERE id = ?");
                        $st->execute([$eqid]);
                        $n = $st->fetchColumn();
                        if ($n) $def_names[] = $n;
                    }
                    if (!empty($f['defective_freitext'])) $def_names[] = $f['defective_freitext'];
                    echo htmlspecialchars(implode(', ', $def_names) ?: '-');
                    if (!empty($f['defective_mangel'])) echo ' – ' . htmlspecialchars($f['defective_mangel']);
                    ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="formularcenter.php?tab=submissions&filter_formular=geraetewartmitteilung" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
        <a href="../api/geraetewartmitteilung-pdf.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-success" download><i class="fas fa-file-pdf"></i> PDF</a>
        <button type="button" class="btn btn-outline-secondary" title="Drucken" onclick="druckenGwm(<?php echo (int)$id; ?>, this)"><i class="fas fa-print"></i> Drucken</button>
    </div>
</div>
<script>
function druckenGwm(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Drucken...'; }
    fetch('../api/print-geraetewartmitteilung.php?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) alert('Druckauftrag wurde an den Drucker gesendet.');
            else alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
        })
        .catch(function() { alert('Fehler beim Senden des Druckauftrags.'); })
        .finally(function() { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-print"></i> Drucken'; } });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
