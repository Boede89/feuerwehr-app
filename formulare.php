<?php
/**
 * Formulare-Übersicht: Liste aller aktiven Formulare zum Ausfüllen.
 * Nur für eingeloggte Benutzer.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('formulare.php'));
    exit;
}
if (!has_form_fill_permission()) {
    header('Location: index.php?error=no_forms_access');
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id <= 0 && isset($_SESSION['user_id']) && (function_exists('is_superadmin') && !is_superadmin())) {
    $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
}
if ($einheit_id > 0) {
    $_SESSION['current_einheit_id'] = $einheit_id;
}
$einheit = null;
if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';

// Tabellen existieren ggf. noch nicht (werden im Formularcenter angelegt)
$forms = [];
try {
    $stmt = $db->query("SELECT id, title, description FROM app_forms WHERE is_active = 1 ORDER BY title");
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle app_forms existiert evtl. noch nicht
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulare - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .feature-icon { height: 80px; display: flex; align-items: center; justify-content: center; }
        .feature-icon i { font-size: 3rem; }
        .feature-card .card-body { display: flex; flex-direction: column; }
        .feature-card .card-text { flex-grow: 1; }
        .clickable-card { transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer; }
        .clickable-card:hover { transform: translateY(-5px); box-shadow: 0 8px 16px rgba(0,0,0,0.15) !important; }
        .clickable-card a { color: inherit; text-decoration: none; }
        .clickable-card:hover .card-title { color: #0d6efd; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php else: ?>
                <?php include __DIR__ . '/includes/system-user-nav.inc.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <?php if (isset($_GET['message']) && $_GET['message'] === 'maengelbericht_erfolg'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>Mängelbericht wurde erfolgreich gespeichert.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if (isset($_GET['message']) && $_GET['message'] === 'geraetewartmitteilung_erfolg'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>Gerätewartmitteilung wurde erfolgreich gespeichert.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-file-alt"></i> Formulare<?php if ($einheit): ?> <span class="text-muted">(<?php echo htmlspecialchars($einheit['name']); ?>)</span><?php endif; ?></h3>
                        <p class="text-muted mb-0">Wählen Sie ein Formular aus, das Sie ausfüllen möchten</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <!-- Anwesenheitsliste (fixer Eintrag) -->
                            <div class="col-md-6 col-lg-4">
                                <a href="anwesenheitsliste.php<?php echo $einheit_param; ?>" class="text-decoration-none">
                                    <div class="card h-100 shadow-sm feature-card clickable-card">
                                        <div class="card-body text-center p-4 d-flex flex-column">
                                            <div class="feature-icon mb-3">
                                                <i class="fas fa-clipboard-list text-primary"></i>
                                            </div>
                                            <h5 class="card-title">Anwesenheitsliste</h5>
                                            <p class="card-text text-muted small">Anwesenheit bei Diensten und Einsätzen erfassen. Vorschlag aus dem Dienstplan für den Tag.</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <!-- Mängelbericht (Platzhalter) -->
                            <div class="col-md-6 col-lg-4">
                                <a href="formular-maengelbericht.php<?php echo $einheit_param; ?>" class="text-decoration-none">
                                    <div class="card h-100 shadow-sm feature-card clickable-card">
                                        <div class="card-body text-center p-4 d-flex flex-column">
                                            <div class="feature-icon mb-3">
                                                <i class="fas fa-exclamation-triangle text-warning"></i>
                                            </div>
                                            <h5 class="card-title">Mängelbericht</h5>
                                            <p class="card-text text-muted small">Mängel und Schäden erfassen und melden.</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <!-- Gerätewartmitteilung (Platzhalter) -->
                            <div class="col-md-6 col-lg-4">
                                <a href="formular-geraetewartmitteilung.php<?php echo $einheit_param; ?>" class="text-decoration-none">
                                    <div class="card h-100 shadow-sm feature-card clickable-card">
                                        <div class="card-body text-center p-4 d-flex flex-column">
                                            <div class="feature-icon mb-3">
                                                <i class="fas fa-wrench text-info"></i>
                                            </div>
                                            <h5 class="card-title">Gerätewartmitteilung</h5>
                                            <p class="card-text text-muted small">Eingesetzte Fahrzeuge und Geräte bei Einsatz oder Übung erfassen.</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <?php if (!empty($forms)): ?>
                                <?php foreach ($forms as $form): ?>
                                <div class="col-md-6 col-lg-4">
                                    <a href="formulare-ausfuellen.php?id=<?php echo (int)$form['id']; ?><?php echo $einheit_param ? '&' . ltrim($einheit_param, '?') : ''; ?>" class="text-decoration-none">
                                        <div class="card h-100 shadow-sm feature-card clickable-card">
                                            <div class="card-body text-center p-4 d-flex flex-column">
                                                <div class="feature-icon mb-3">
                                                    <i class="fas fa-file-alt text-primary"></i>
                                                </div>
                                                <h5 class="card-title"><?php echo htmlspecialchars($form['title']); ?></h5>
                                                <?php if (!empty($form['description'])): ?>
                                                    <p class="card-text text-muted small"><?php echo htmlspecialchars($form['description']); ?></p>
                                                <?php else: ?>
                                                    <p class="card-text text-muted small">Formular ausfüllen</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($forms)): ?>
                            <p class="text-muted mt-3 mb-0 small">Weitere Formulare können im Formularcenter angelegt werden.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 3.1&nbsp;&nbsp;Alle Rechte vorbehalten</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/admin/includes/print-toast.inc.php'; ?>
    <script>
    var FORMULARE_EINHEIT_ID = <?php echo $einheit_id > 0 ? (int)$einheit_id : 0; ?>;
    (function() {
        var m = /[?&]print_maengelbericht=(\d+)/.exec(window.location.search);
        if (m && m[1]) {
            var id = m[1];
            var printWindow = window.open('', '_blank', 'noopener,width=800,height=600');
            if (printWindow) { try { printWindow.document.write('<html><head><title>Lade PDF...</title></head><body style="font-family:sans-serif;padding:2em;">PDF wird geladen...</body></html>'); } catch (e) {} }
            var url = 'api/print-maengelbericht.php?id=' + id + (FORMULARE_EINHEIT_ID > 0 ? '&einheit_id=' + FORMULARE_EINHEIT_ID : '');
            fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.open_pdf && data.pdf_base64) {
                        try {
                            var binary = atob(data.pdf_base64);
                            var bytes = new Uint8Array(binary.length);
                            for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                            var blob = new Blob([bytes], { type: 'application/pdf' });
                            var blobUrl = URL.createObjectURL(blob);
                            if (printWindow && !printWindow.closed) {
                                printWindow.location.href = blobUrl;
                                printWindow.onload = function() { try { printWindow.print(); } catch (e) {} setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 10000); };
                                setTimeout(function() { try { printWindow.print(); } catch (e) {} }, 1500);
                            } else {
                                var w = window.open(blobUrl, '_blank', 'noopener');
                                if (w) {
                                    w.onload = function() { try { w.print(); } catch (e) {} setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 10000); };
                                    setTimeout(function() { try { w.print(); } catch (e) {} }, 1500);
                                } else {
                                    var iframe = document.createElement('iframe');
                                    iframe.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;border:none;z-index:99999;background:#fff';
                                    iframe.src = blobUrl;
                                    document.body.appendChild(iframe);
                                    var btnBar = document.createElement('div');
                                    btnBar.style.cssText = 'position:fixed;top:10px;right:10px;z-index:100000;display:flex;gap:8px';
                                    var newWinBtn = document.createElement('button');
                                    newWinBtn.textContent = 'In neuem Fenster öffnen';
                                    newWinBtn.className = 'btn btn-outline-primary';
                                    newWinBtn.onclick = function() { var w = window.open(blobUrl, '_blank', 'noopener,width=900,height=700'); if (w) w.onload = function() { try { w.print(); } catch (e) {} }; };
                                    var closeBtn = document.createElement('button');
                                    closeBtn.textContent = 'Schließen';
                                    closeBtn.className = 'btn btn-primary';
                                    closeBtn.onclick = function() { iframe.remove(); btnBar.remove(); URL.revokeObjectURL(blobUrl); };
                                    btnBar.appendChild(newWinBtn);
                                    btnBar.appendChild(closeBtn);
                                    document.body.appendChild(btnBar);
                                    iframe.onload = function() { setTimeout(function() { try { iframe.contentWindow.print(); } catch (e) {} }, 500); };
                                }
                            }
                            if (typeof showPrintToast === 'function') showPrintToast('PDF wurde geöffnet. Der Druckdialog sollte sich öffnen – sonst Strg+P drücken.', true);
                            else alert('PDF wurde geöffnet. Der Druckdialog sollte sich öffnen – sonst Strg+P drücken.');
                        } catch (e) {
                            if (printWindow) try { printWindow.close(); } catch (x) {}
                            if (typeof showPrintToast === 'function') showPrintToast('PDF konnte nicht geöffnet werden.', false);
                            else alert('PDF konnte nicht geöffnet werden.');
                        }
                    } else {
                        if (printWindow) try { printWindow.close(); } catch (x) {}
                        if (typeof showPrintToast === 'function') showPrintToast(data.success ? 'Druckauftrag wurde gesendet.' : ('Fehler: ' + (data.message || '')), data.success);
                        else alert(data.success ? 'Druckauftrag wurde gesendet.' : 'Fehler: ' + (data.message || ''));
                    }
                })
                .catch(function() {
                    if (typeof showPrintToast === 'function') showPrintToast('Verbindungsfehler beim Drucken.', false);
                    else alert('Druck fehlgeschlagen.');
                })
                .finally(function() {
                    var q = window.location.search.replace(/[?&]print_maengelbericht=\d+/g, '').replace(/^&/, '?').replace(/&$/, '');
                    if (q === '?') q = '';
                    history.replaceState(null, '', window.location.pathname + q);
                });
        }
    })();
    </script>
</body>
</html>
