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
                                    <option value="einsatz">Einsatz</option>
                                    <option value="uebung">Übung</option>
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
                                <span id="selectedCount" class="badge bg-secondary ms-2">0 ausgewählt</span>
                            </label>
                            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                <div id="traegerList">
                                    <div class="text-center text-muted">
                                        <i class="fas fa-spinner fa-spin"></i> Lade Geräteträger...
                                    </div>
                                </div>
                            </div>
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
    
    <style>
        .traeger-card {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .traeger-card:hover .card {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .traeger-card .card {
            border: 2px solid #e9ecef;
            transition: all 0.2s ease;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .traeger-card .card.border-primary {
            border-color: #0d6efd !important;
            background-color: #0d6efd !important;
            color: white !important;
        }
        
        .traeger-card .card.border-light {
            border-color: #e9ecef !important;
            background-color: white !important;
            color: #212529 !important;
        }
        
        .traeger-card .card-title {
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        /* Mobile Optimierung */
        @media (max-width: 768px) {
            .traeger-card .card {
                min-height: 50px;
                padding: 0.75rem;
            }
            
            .traeger-card .card-title {
                font-size: 0.9rem;
            }
        }
        
        /* Grid Layout für bessere mobile Darstellung */
        #traegerList {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 0.5rem;
        }
        
        @media (max-width: 576px) {
            #traegerList {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 0.25rem;
            }
        }
    </style>
    
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
        
        // Globale Variable für ausgewählte Geräteträger
        window.selectedTraeger = new Set();
        
        // Geräteträger umschalten
        function toggleTraeger(traegerId) {
            const card = document.querySelector(`[data-traeger-id="${traegerId}"]`);
            const cardBody = card.querySelector('.card');
            
            if (window.selectedTraeger.has(traegerId)) {
                // Geräteträger abwählen
                window.selectedTraeger.delete(traegerId);
                cardBody.classList.remove('border-primary', 'bg-primary', 'text-white');
                cardBody.classList.add('border-light');
            } else {
                // Geräteträger auswählen
                window.selectedTraeger.add(traegerId);
                cardBody.classList.remove('border-light');
                cardBody.classList.add('border-primary', 'bg-primary', 'text-white');
            }
            
            // Zähler aktualisieren
            updateSelectedCount();
        }
        
        // Zähler für ausgewählte Geräteträger aktualisieren
        function updateSelectedCount() {
            const count = window.selectedTraeger.size;
            const countElement = document.getElementById('selectedCount');
            countElement.textContent = `${count} ausgewählt`;
            
            if (count > 0) {
                countElement.classList.remove('bg-secondary');
                countElement.classList.add('bg-primary');
            } else {
                countElement.classList.remove('bg-primary');
                countElement.classList.add('bg-secondary');
            }
        }
        
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
                                <div class="traeger-card mb-2" data-traeger-id="${traeger.id}" onclick="toggleTraeger(${traeger.id})">
                                    <div class="card h-100">
                                        <div class="card-body p-3 text-center">
                                            <h6 class="card-title mb-0">${traeger.first_name} ${traeger.last_name}</h6>
                                        </div>
                                    </div>
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
            
            // Füge ausgewählte Geräteträger hinzu
            window.selectedTraeger.forEach(traegerId => {
                formData.append('traeger[]', traegerId);
            });
            
            // Prüfe ob Eintragstyp ausgewählt wurde
            const entryType = document.getElementById('entryType').value;
            if (!entryType) {
                alert('Bitte wählen Sie einen Eintragstyp aus.');
                return;
            }
            
            // Prüfe ob mindestens ein Geräteträger ausgewählt wurde
            if (window.selectedTraeger.size === 0) {
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
                    // Auswahl zurücksetzen
                    window.selectedTraeger.clear();
                    // Karten zurücksetzen
                    document.querySelectorAll('.traeger-card .card').forEach(card => {
                        card.classList.remove('border-primary', 'bg-primary', 'text-white');
                        card.classList.add('border-light');
                    });
                    // Zähler zurücksetzen
                    updateSelectedCount();
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
