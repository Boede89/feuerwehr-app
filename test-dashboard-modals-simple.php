<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Modal Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>🧪 Dashboard Modal Test</h1>
        
        <h2>1. Einfacher Modal Test</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#testModal">
            <i class="fas fa-info-circle"></i> Modal öffnen
        </button>
        
        <h2>2. Kalender-Prüfung Test</h2>
        <div id="calendar-test">
            <button type="button" class="btn btn-outline-info btn-sm" onclick="testCalendarCheck()">
                <i class="fas fa-search"></i> Kalender-Konflikte prüfen
            </button>
        </div>
        
        <h2>3. Debug Info</h2>
        <div id="debug-info">Lade...</div>
    </div>

    <!-- Test Modal -->
    <div class="modal fade" id="testModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Test Modal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Dieses Modal funktioniert!</p>
                    <p><strong>Fahrzeug:</strong> MTF</p>
                    <p><strong>Antragsteller:</strong> Test User</p>
                    <p><strong>Start:</strong> 2025-01-15 10:00:00</p>
                    <p><strong>Ende:</strong> 2025-01-15 12:00:00</p>
                    
                    <h6><i class="fas fa-calendar-check text-info"></i> Kalender-Prüfung</h6>
                    <div id="calendar-check-test">
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="testCalendarCheckInModal()">
                            <i class="fas fa-search"></i> Kalender-Konflikte prüfen
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success">Genehmigen</button>
                    <button type="button" class="btn btn-danger">Ablehnen</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testCalendarCheck() {
            const container = document.getElementById('calendar-test');
            
            // Zeige Lade-Status
            container.innerHTML = '<button class="btn btn-outline-info btn-sm" disabled><i class="fas fa-spinner fa-spin"></i> Prüfe Kalender...</button>';
            
            // Simuliere AJAX-Anfrage
            setTimeout(() => {
                // Simuliere Erfolg ohne Konflikte
                container.innerHTML = '<div class="alert alert-success mt-2"><strong>Kein Konflikt:</strong> Der beantragte Zeitraum ist frei.</div>';
            }, 2000);
        }
        
        function testCalendarCheckInModal() {
            const container = document.getElementById('calendar-check-test');
            
            // Zeige Lade-Status
            container.innerHTML = '<button class="btn btn-outline-info btn-sm" disabled><i class="fas fa-spinner fa-spin"></i> Prüfe Kalender...</button>';
            
            // Simuliere AJAX-Anfrage
            setTimeout(() => {
                // Simuliere Konflikte
                container.innerHTML = '<div class="alert alert-warning mt-2"><strong>Warnung:</strong> Für dieses Fahrzeug existieren bereits Kalender-Einträge:<ul class="mb-0 mt-2"><li><strong>MTF - Übung</strong><br><small class="text-muted">15.01.2025 09:00 - 15.01.2025 11:00</small></li></ul></div>';
            }, 2000);
        }
        
        // Debug Info
        document.addEventListener('DOMContentLoaded', function() {
            const debugInfo = document.getElementById('debug-info');
            debugInfo.innerHTML = `
                <p><strong>Bootstrap verfügbar:</strong> ${typeof bootstrap !== 'undefined' ? 'Ja' : 'Nein'}</p>
                <p><strong>Modal Elemente:</strong> ${document.querySelectorAll('.modal').length}</p>
                <p><strong>Button Elemente:</strong> ${document.querySelectorAll('[data-bs-toggle="modal"]').length}</p>
                <p><strong>Test Modal Element:</strong> ${document.getElementById('testModal') ? 'Gefunden' : 'Nicht gefunden'}</p>
            `;
        });
    </script>
</body>
</html>
