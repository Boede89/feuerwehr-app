<?php
/**
 * Einfacher Test für die process-reservation.php API
 * Öffnen Sie diese Seite im Browser, klicken Sie auf "Test starten".
 */
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    die('Bitte zuerst einloggen, dann diese Seite aufrufen.');
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>API-Test</title></head>
<body>
<h1>Process-Reservation API Test</h1>
<button onclick="runTest()">Test starten</button>
<div id="result" style="margin-top:20px;"></div>
<p><a href="dashboard.php">← Zurück zum Dashboard</a></p>
<script>
function runTest() {
    const result = document.getElementById('result');
    result.innerHTML = '<p>Teste...</p>';
    fetch('process-reservation.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'test', reservation_id: 0})
    })
    .then(r => Promise.all([r.ok, r.status, r.text()]))
    .then(([ok, status, text]) => {
        let html = '<p><strong>HTTP:</strong> ' + status + (ok ? ' OK' : ' Fehler') + '</p>';
        html += '<p><strong>Roh-Antwort:</strong></p><pre style="background:#f0f0f0;padding:10px;max-height:300px;overflow:auto;">' + escapeHtml(text.substring(0, 1500)) + '</pre>';
        try {
            const data = JSON.parse(text);
            html += '<p><strong>Geparst:</strong></p><pre>' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>';
            html += '<p style="color:' + (data.success ? 'green' : 'red') + ';">success=' + data.success + '</p>';
        } catch (e) {
            html += '<p style="color:red;">JSON-Parse-Fehler: ' + escapeHtml(e.message) + '</p>';
        }
        result.innerHTML = html;
    })
    .catch(err => {
        result.innerHTML = '<p style="color:red;">Fehler: ' + escapeHtml(err.message) + '</p>';
    });
}
function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
</body>
</html>
