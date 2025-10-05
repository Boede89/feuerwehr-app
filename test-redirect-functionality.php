<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weiterleitung Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>🧪 Weiterleitung Test</h1>
        
        <h2>1. Test: Antrag trotzdem versenden</h2>
        <p>Simuliert den "Antrag trotzdem versenden" Button:</p>
        <button type="button" class="btn btn-warning" onclick="testForceSubmit()">
            <i class="fas fa-exclamation-triangle"></i> Antrag trotzdem versenden
        </button>
        <div id="force-submit-result" class="mt-2"></div>
        
        <h2>2. Test: Antrag abbrechen</h2>
        <p>Simuliert den "Antrag abbrechen" Button:</p>
        <button type="button" class="btn btn-secondary" onclick="testCancel()">
            <i class="fas fa-times"></i> Antrag abbrechen
        </button>
        <div id="cancel-result" class="mt-2"></div>
        
        <h2>3. Test: Modal mit Weiterleitung</h2>
        <p>Testet das komplette Modal mit beiden Buttons:</p>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#testModal">
            <i class="fas fa-info-circle"></i> Modal testen
        </button>
        
        <h2>4. Debug Info</h2>
        <div id="debug-info"></div>
    </div>

    <!-- Test Modal -->
    <div class="modal fade" id="testModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Test: Fahrzeug bereits reserviert
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6><strong>Konflikt erkannt!</strong></h6>
                        <p>Das ausgewählte Fahrzeug <strong>MTF</strong> ist bereits für den gewünschten Zeitraum reserviert:</p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-calendar-alt text-danger"></i> Ihr gewünschter Zeitraum:</h6>
                                <p class="mb-2">
                                    <strong>15.01.2025 10:00 - 15.01.2025 12:00</strong>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-exclamation-triangle text-warning"></i> Bereits reserviert:</h6>
                                <p class="mb-1">
                                    <strong>Zeitraum:</strong> 15.01.2025 10:00 - 15.01.2025 12:00
                                </p>
                                <p class="mb-1">
                                    <strong>Antragsteller:</strong> Max Mustermann
                                </p>
                                <p class="mb-0">
                                    <strong>Grund:</strong> Übung: Brandbekämpfung
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <p>Möchten Sie den Antrag trotzdem einreichen? Der Administrator wird über den Konflikt informiert und kann entscheiden, ob beide Reservierungen möglich sind.</p>
                    
                    <div class="alert alert-info">
                        <small><i class="fas fa-info-circle"></i> <strong>Hinweis:</strong> Bei einer Überschneidung kann es zu Problemen bei der Fahrzeugverfügbarkeit kommen.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="testForceSubmitFromModal()">
                        <i class="fas fa-exclamation-triangle"></i> Antrag trotzdem versenden
                    </button>
                    <button type="button" id="testCancelBtn" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Antrag abbrechen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testForceSubmit() {
            const resultDiv = document.getElementById('force-submit-result');
            resultDiv.innerHTML = '<div class="alert alert-info">Teste "Antrag trotzdem versenden"...</div>';
            
            setTimeout(() => {
                resultDiv.innerHTML = '<div class="alert alert-success">✅ Weiterleitung zur Startseite würde erfolgen: <a href="index.php">index.php</a></div>';
            }, 1000);
        }
        
        function testCancel() {
            const resultDiv = document.getElementById('cancel-result');
            resultDiv.innerHTML = '<div class="alert alert-info">Teste "Antrag abbrechen"...</div>';
            
            setTimeout(() => {
                resultDiv.innerHTML = '<div class="alert alert-success">✅ Weiterleitung zur Startseite würde erfolgen: <a href="index.php">index.php</a></div>';
            }, 1000);
        }
        
        function testForceSubmitFromModal() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('testModal'));
            modal.hide();
            
            setTimeout(() => {
                alert('✅ "Antrag trotzdem versenden" - Weiterleitung zur Startseite würde erfolgen');
                window.location.href = 'index.php';
            }, 300);
        }
        
        // Event Listener für "Antrag abbrechen" Button im Modal
        document.getElementById('testCancelBtn').addEventListener('click', function() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('testModal'));
            modal.hide();
            
            setTimeout(() => {
                alert('✅ "Antrag abbrechen" - Weiterleitung zur Startseite würde erfolgen');
                window.location.href = 'index.php';
            }, 300);
        });
        
        // Debug Info
        document.addEventListener('DOMContentLoaded', function() {
            const debugInfo = document.getElementById('debug-info');
            debugInfo.innerHTML = `
                <p><strong>Bootstrap verfügbar:</strong> ${typeof bootstrap !== 'undefined' ? 'Ja' : 'Nein'}</p>
                <p><strong>Modal Elemente:</strong> ${document.querySelectorAll('.modal').length}</p>
                <p><strong>Test Modal Element:</strong> ${document.getElementById('testModal') ? 'Gefunden' : 'Nicht gefunden'}</p>
                <p><strong>Cancel Button Element:</strong> ${document.getElementById('testCancelBtn') ? 'Gefunden' : 'Nicht gefunden'}</p>
            `;
        });
    </script>
</body>
</html>
