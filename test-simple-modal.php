<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modal Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>🧪 Einfacher Modal Test</h1>
        
        <h2>1. Bootstrap CSS Test</h2>
        <div class="alert alert-success">
            ✅ Bootstrap CSS ist geladen (diese grüne Box beweist es)
        </div>
        
        <h2>2. Modal Button Test</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#testModal">
            <i class="fas fa-info-circle"></i> Modal öffnen
        </button>
        
        <h2>3. JavaScript Test</h2>
        <div id="js-test">JavaScript wird geladen...</div>
        
        <h2>4. Console Log Test</h2>
        <button type="button" class="btn btn-secondary" onclick="testConsole()">Console Test</button>
        
        <h2>5. Bootstrap Modal Test</h2>
        <button type="button" class="btn btn-warning" onclick="testBootstrapModal()">Bootstrap Modal Test</button>
    </div>

    <!-- Test Modal -->
    <div class="modal fade" id="testModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Test Modal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Dieses Modal funktioniert!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // JavaScript Test
        document.getElementById('js-test').innerHTML = '✅ JavaScript ist geladen';
        
        // Console Test
        function testConsole() {
            console.log('Console Test erfolgreich!');
            alert('Console Test - siehe Browser-Konsole');
        }
        
        // Bootstrap Modal Test
        function testBootstrapModal() {
            if (typeof bootstrap !== 'undefined') {
                console.log('Bootstrap ist verfügbar');
                var modal = new bootstrap.Modal(document.getElementById('testModal'));
                modal.show();
            } else {
                console.log('Bootstrap ist NICHT verfügbar');
                alert('Bootstrap ist nicht geladen!');
            }
        }
        
        // Debug: Alle Bootstrap-Komponenten prüfen
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM geladen');
            console.log('Bootstrap verfügbar:', typeof bootstrap !== 'undefined');
            console.log('Modal Element:', document.getElementById('testModal'));
            console.log('Button Element:', document.querySelector('[data-bs-toggle="modal"]'));
        });
    </script>
</body>
</html>
