<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Auto Calendar Check Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>🧪 Dashboard Auto Calendar Check Test</h1>
        
        <h2>1. Test: Automatische Kalender-Prüfung</h2>
        <p>Simuliert das Dashboard mit automatischer Kalender-Prüfung:</p>
        
        <!-- Test Details Button -->
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#testDetailsModal">
            <i class="fas fa-info-circle"></i> Details anzeigen (Auto Check)
        </button>
        
        <h2>2. Debug Info</h2>
        <div id="debug-info"></div>
    </div>

    <!-- Test Details Modal -->
    <div class="modal fade" id="testDetailsModal" tabindex="-1" 
         data-vehicle-name="MTF"
         data-start-datetime="2025-01-15 10:00:00"
         data-end-datetime="2025-01-15 12:00:00">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle"></i> Test Reservierungsdetails #123
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-truck text-primary"></i> Fahrzeug</h6>
                            <p>MTF</p>
                            
                            <h6><i class="fas fa-user text-info"></i> Antragsteller</h6>
                            <p>
                                <strong>Max Mustermann</strong><br>
                                <small class="text-muted">max.mustermann@example.com</small>
                            </p>
                            
                            <h6><i class="fas fa-calendar-alt text-success"></i> Zeitraum</h6>
                            <p>
                                <strong>Von:</strong> 15.01.2025 10:00<br>
                                <strong>Bis:</strong> 15.01.2025 12:00
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-clipboard-list text-warning"></i> Grund</h6>
                            <p>Übung: Brandbekämpfung</p>
                            
                            <h6><i class="fas fa-map-marker-alt text-info"></i> Ort</h6>
                            <p>Feuerwehrhaus</p>
                            
                            <h6><i class="fas fa-info-circle text-secondary"></i> Status</h6>
                            <p>
                                <span class="badge bg-warning">
                                    <i class="fas fa-clock"></i> Ausstehend
                                </span>
                            </p>
                            
                            <h6><i class="fas fa-clock text-muted"></i> Erstellt</h6>
                            <p><small class="text-muted">05.10.2025 11:45</small></p>
                            
                            <h6><i class="fas fa-calendar-check text-info"></i> Kalender-Prüfung</h6>
                            <div id="calendar-check-123">
                                <div class="text-center">
                                    <div class="spinner-border spinner-border-sm text-info" role="status" id="spinner-123">
                                        <span class="visually-hidden">Lädt...</span>
                                    </div>
                                    <p class="text-muted mt-2" id="loading-text-123">Prüfe Kalender-Konflikte...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success">
                        <i class="fas fa-check"></i> Genehmigen
                    </button>
                    <button type="button" class="btn btn-danger">
                        <i class="fas fa-times"></i> Ablehnen
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simuliere die checkCalendarConflicts Funktion
        function checkCalendarConflicts(reservationId, vehicleName, startDateTime, endDateTime) {
            const container = document.getElementById('calendar-check-' + reservationId);
            const spinner = document.getElementById('spinner-' + reservationId);
            const loadingText = document.getElementById('loading-text-' + reservationId);
            
            console.log('🔍 Kalender-Prüfung gestartet für Reservierung #' + reservationId);
            console.log('Fahrzeug:', vehicleName);
            console.log('Zeitraum:', startDateTime, '-', endDateTime);
            
            // Simuliere AJAX-Anfrage
            setTimeout(() => {
                // Verstecke Spinner
                if (spinner) spinner.style.display = 'none';
                if (loadingText) loadingText.style.display = 'none';
                
                // Simuliere Ergebnis (zufällig Konflikt oder kein Konflikt)
                const hasConflict = Math.random() > 0.5;
                
                if (hasConflict) {
                    container.innerHTML = '<div class="alert alert-warning mt-2"><strong>Warnung:</strong> Für dieses Fahrzeug existieren bereits Kalender-Einträge:<ul class="mb-0 mt-2"><li><strong>MTF - Übung</strong><br><small class="text-muted">15.01.2025 09:00 - 15.01.2025 11:00</small></li></ul></div>';
                } else {
                    container.innerHTML = '<div class="alert alert-success mt-2"><strong>Kein Konflikt:</strong> Der beantragte Zeitraum ist frei.</div>';
                }
                
                console.log('✅ Kalender-Prüfung abgeschlossen:', hasConflict ? 'Konflikt gefunden' : 'Kein Konflikt');
            }, 2000); // 2 Sekunden Simulation
        }
        
        // Automatische Kalender-Prüfung für alle Details-Modals
        document.addEventListener('DOMContentLoaded', function() {
            // Event Listener für alle Details-Buttons
            document.querySelectorAll('[data-bs-toggle="modal"][data-bs-target^="#testDetailsModal"]').forEach(function(button) {
                button.addEventListener('click', function() {
                    const targetModalId = this.getAttribute('data-bs-target');
                    const reservationId = targetModalId.replace('#testDetailsModal', '123');
                    
                    console.log('🔍 Details-Button geklickt, Modal ID:', targetModalId);
                    console.log('Reservierungs-ID:', reservationId);
                    
                    // Starte Kalender-Prüfung automatisch nach Modal-Öffnung
                    setTimeout(function() {
                        // Hole die Reservierungsdaten aus dem Modal
                        const modal = document.querySelector(targetModalId);
                        if (modal) {
                            const vehicleName = modal.getAttribute('data-vehicle-name') || '';
                            const startDateTime = modal.getAttribute('data-start-datetime') || '';
                            const endDateTime = modal.getAttribute('data-end-datetime') || '';
                            
                            console.log('📊 Modal-Daten:', { vehicleName, startDateTime, endDateTime });
                            
                            if (vehicleName && startDateTime && endDateTime) {
                                checkCalendarConflicts(reservationId, vehicleName, startDateTime, endDateTime);
                            } else {
                                console.error('❌ Modal-Daten unvollständig');
                            }
                        }
                    }, 500); // Kurze Verzögerung damit Modal vollständig geöffnet ist
                });
            });
            
            // Debug Info
            const debugInfo = document.getElementById('debug-info');
            debugInfo.innerHTML = `
                <p><strong>Bootstrap verfügbar:</strong> ${typeof bootstrap !== 'undefined' ? 'Ja' : 'Nein'}</p>
                <p><strong>Modal Elemente:</strong> ${document.querySelectorAll('.modal').length}</p>
                <p><strong>Details Button Elemente:</strong> ${document.querySelectorAll('[data-bs-toggle="modal"]').length}</p>
                <p><strong>Test Modal Element:</strong> ${document.getElementById('testDetailsModal') ? 'Gefunden' : 'Nicht gefunden'}</p>
                <p><strong>checkCalendarConflicts Funktion:</strong> ${typeof checkCalendarConflicts !== 'undefined' ? 'Verfügbar' : 'Nicht verfügbar'}</p>
            `;
        });
    </script>
</body>
</html>
