<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="admin/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">
                                <i class="fas fa-sign-out-alt"></i> Abmelden
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">
                                <i class="fas fa-sign-in-alt"></i> Anmelden
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <?php
                    // App Name aus den Einstellungen (nur hier auf der Startseite anzeigen)
                    $appDisplayName = 'Feuerwehr App';
                    try {
                        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_name'");
                        $stmt->execute();
                        $val = trim((string)$stmt->fetchColumn());
                        if ($val !== '') {
                            $appDisplayName = $val;
                        }
                    } catch (Exception $e) { /* Fallback beibehalten */ }
                ?>
                <div class="text-center mb-5">
                    <h1 class="display-4 text-primary">
                        <i class="fas fa-fire"></i> <?php echo htmlspecialchars($appDisplayName); ?>
                    </h1>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm feature-card">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon mb-3">
                                    <i class="fas fa-truck text-primary"></i>
                                </div>
                                <h5 class="card-title">Fahrzeug Reservierung</h5>
                                <p class="card-text">Reservieren Sie Feuerwehrfahrzeuge für Ihre Einsätze und Veranstaltungen.</p>
                                <a href="vehicle-selection.php" class="btn btn-primary btn-lg">
                                    <i class="fas fa-calendar-plus"></i> Fahrzeug reservieren
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm feature-card">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon mb-3">
                                    <i class="fas fa-mask text-info"></i>
                                </div>
                                <h5 class="card-title">Atemschutzeintrag erstellen</h5>
                                <p class="card-text">Erstellen Sie einen neuen Atemschutzeintrag für Einsatz/Übung, Atemschutzstrecke oder G26.3.</p>
                                <button class="btn btn-outline-info btn-lg" data-bs-toggle="modal" data-bs-target="#atemschutzModal">
                                    <i class="fas fa-plus"></i> Eintrag erstellen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Atemschutzeintrag Modal -->
    <div class="modal fade" id="atemschutzModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-mask me-2"></i>Atemschutzeintrag erstellen
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="atemschutzForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="entryType" class="form-label">
                                    <i class="fas fa-list me-1"></i>Eintragstyp
                                </label>
                                <select class="form-select" id="entryType" name="entry_type" required>
                                    <option value="">Bitte wählen...</option>
                                    <option value="einsatz_uebung">Einsatz/Übung</option>
                                    <option value="atemschutzstrecke">Atemschutzstrecke</option>
                                    <option value="g263">G26.3</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="entryDate" class="form-label">
                                    <i class="fas fa-calendar me-1"></i>Datum
                                </label>
                                <input type="date" class="form-control" id="entryDate" name="entry_date" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-users me-1"></i>Atemschutzgeräteträger
                            </label>
                            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                <div id="traegerList">
                                    <div class="text-center text-muted">
                                        <i class="fas fa-spinner fa-spin"></i> Lade Geräteträger...
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reason" class="form-label">
                                <i class="fas fa-clipboard-list me-1"></i>Grund
                            </label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" placeholder="Grund für den Atemschutzeintrag eingeben..." required></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-info" id="submitAtemschutzBtn">
                        <i class="fas fa-paper-plane me-1"></i>Absenden
                    </button>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 1.0&nbsp;&nbsp;Alle Rechte vorbehalten</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Heutiges Datum setzen
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('entryDate').value = today;
            
            // Geräteträger laden
            loadTraeger();
        });
        
        // Modal Event Listener
        document.getElementById('atemschutzModal').addEventListener('show.bs.modal', function() {
            loadTraeger();
        });
        
        // Geräteträger laden
        function loadTraeger() {
            const traegerList = document.getElementById('traegerList');
            traegerList.innerHTML = '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Lade Geräteträger...</div>';
            
            fetch('api/get-atemschutz-traeger.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '';
                        data.traeger.forEach(traeger => {
                            html += `
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="${traeger.id}" id="traeger_${traeger.id}" name="traeger[]">
                                    <label class="form-check-label" for="traeger_${traeger.id}">
                                        <strong>${traeger.first_name} ${traeger.last_name}</strong>
                                        <small class="text-muted d-block">${traeger.status}</small>
                                    </label>
                                </div>
                            `;
                        });
                        traegerList.innerHTML = html;
                    } else {
                        traegerList.innerHTML = '<div class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> Fehler beim Laden der Geräteträger</div>';
                    }
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    traegerList.innerHTML = '<div class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> Fehler beim Laden der Geräteträger</div>';
                });
        }
        
        // Formular absenden
        document.getElementById('submitAtemschutzBtn').addEventListener('click', function() {
            const form = document.getElementById('atemschutzForm');
            const formData = new FormData(form);
            
            // Prüfe ob mindestens ein Geräteträger ausgewählt wurde
            const selectedTraeger = document.querySelectorAll('input[name="traeger[]"]:checked');
            if (selectedTraeger.length === 0) {
                alert('Bitte wählen Sie mindestens einen Atemschutzgeräteträger aus.');
                return;
            }
            
            // Button deaktivieren
            const submitBtn = document.getElementById('submitAtemschutzBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Wird gesendet...';
            
            fetch('api/create-atemschutz-entry.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Atemschutzeintrag erfolgreich erstellt! Der Antrag wurde zur Genehmigung eingereicht.');
                    // Modal schließen
                    const modal = bootstrap.Modal.getInstance(document.getElementById('atemschutzModal'));
                    modal.hide();
                    // Formular zurücksetzen
                    form.reset();
                    document.getElementById('entryDate').value = new Date().toISOString().split('T')[0];
                } else {
                    alert('Fehler: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                alert('Fehler beim Erstellen des Atemschutzeintrags.');
            })
            .finally(() => {
                // Button wieder aktivieren
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    </script>
</body>
</html>
