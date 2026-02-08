<?php
/**
 * Anwesenheitsliste – Schritt 2: Wrapper für Fehleranzeige (HTTP 200).
 * Logik und HTML liegen in anwesenheitsliste-eingaben-main.inc.php.
 */
ob_start();
try {
    require __DIR__ . '/anwesenheitsliste-eingaben-main.inc.php';
} catch (Throwable $e) {
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('HTTP/1.0 200 OK');
    }
    $msg = $e->getMessage();
    $file = $e->getFile();
    $line = $e->getLine();
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Fehler – Anwesenheitsliste</title></head>';
    echo '<body style="font-family:sans-serif;max-width:600px;margin:2rem auto;padding:1rem;">';
    echo '<h1>Fehler beim Laden der Anwesenheitsliste</h1>';
    echo '<p>Die Seite konnte nicht geladen werden.</p>';
    echo '<p><strong>Technische Details:</strong><br><code>' . htmlspecialchars($msg) . '</code></p>';
    echo '<p><small>Datei: ' . htmlspecialchars($file) . ' · Zeile: ' . (int)$line . '</small></p>';
    echo '<p><a href="anwesenheitsliste.php">Zurück zur Anwesenheitsliste</a> · <a href="index.php">Zur Startseite</a></p>';
    echo '</body></html>';
    exit;
}
if (ob_get_level()) ob_end_flush();
