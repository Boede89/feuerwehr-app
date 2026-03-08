<?php
/**
 * Übersicht aller Debug-/Test-Skripte – nur für Superadmin.
 */
require_once __DIR__ . '/../includes/debug-auth.php';

// Alle Debug- und Test-Skripte sammeln (Root + admin/) – relative URLs
$scripts = [];
$root = dirname(__DIR__);

foreach (array_merge(glob($root . '/debug-*.php') ?: [], glob($root . '/test-*.php') ?: []) as $path) {
    $name = basename($path);
    if ($name === 'debug-auth.php') continue;
    $scripts[] = ['name' => $name, 'url' => '../' . $name, 'group' => 'Root'];
}

foreach (array_merge(glob($root . '/admin/debug-*.php') ?: [], glob($root . '/admin/test-*.php') ?: []) as $path) {
    $name = basename($path);
    $scripts[] = ['name' => $name, 'url' => $name, 'group' => 'Admin'];
}

usort($scripts, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Lesbare Bezeichnungen: Was wird mit dem Skript getestet?
$labels = [
    'debug-500.php' => 'PHP-Fehler / 500-Debug',
    'debug-admin.php' => 'Admin-Login prüfen',
    'debug-admin-reservations.php' => 'Admin-Reservierungen anzeigen',
    'debug-admin-reservations-exact.php' => 'Admin-Reservierungen (exakt)',
    'debug-ajax-endpoint.php' => 'AJAX-Endpoint (Kalender-Konflikte)',
    'debug-app-approval.php' => 'App-Genehmigung (echt)',
    'debug-app-google-calendar.php' => 'App + Google Calendar',
    'debug-app-session-timing.php' => 'App-Session-Timing',
    'debug-approval-form.php' => 'Genehmigungs-Formular',
    'debug-approval-process.php' => 'Genehmigungs-Prozess',
    'debug-approval-vs-button.php' => 'Genehmigung vs. Button',
    'debug-atemschutz-liste.php' => 'Atemschutz-Liste',
    'debug-atemschutz-notifications.php' => 'Atemschutz-Benachrichtigungen',
    'debug-atemschutz-warnings.php' => 'Atemschutz-Warnungen',
    'debug-calendar-approval.php' => 'Kalender bei Genehmigung',
    'debug-calendar-conflicts.php' => 'Kalender-Konflikte',
    'debug-calendar-events-database.php' => 'Kalender-Events in DB',
    'debug-create-google-calendar-event.php' => 'Google-Event erstellen',
    'debug-current-delete.php' => 'Aktuelles Löschen',
    'debug-dashboard-calendar-check.php' => 'Dashboard Kalender-Check',
    'debug-dashboard-environment.php' => 'Dashboard-Umgebung',
    'debug-dashboard-error.php' => 'Dashboard-Fehler',
    'debug-dashboard-full.php' => 'Dashboard (vollständig)',
    'debug-dashboard-google-calendar.php' => 'Dashboard + Google Calendar',
    'debug-dashboard-html.php' => 'Dashboard-HTML',
    'debug-dashboard-modals.php' => 'Dashboard-Modals',
    'debug-dashboard-reservations.php' => 'Dashboard-Reservierungen',
    'debug-dashboard-simple.php' => 'Dashboard (einfach)',
    'debug-dashboard-with-data.php' => 'Dashboard mit Daten',
    'debug-database.php' => 'Datenbank-Verbindung',
    'debug-database-connection.php' => 'Datenbank-Verbindungsprobleme',
    'debug-database-schema.php' => 'Datenbank-Schema',
    'debug-datetime-format.php' => 'Datums-/Zeitformat',
    'debug-datetime-validation.php' => 'Datums-/Zeit-Validierung',
    'debug-delete-detailed.php' => 'Löschen (detailliert)',
    'debug-delete-failure.php' => 'Lösch-Fehler',
    'debug-delete-mystery.php' => 'Lösch-Mysterium',
    'debug-email-data.php' => 'E-Mail-Datenstruktur',
    'debug-email-delivery.php' => 'E-Mail-Zustellung',
    'debug-email-headers.php' => 'E-Mail-Header',
    'debug-email-simple.php' => 'E-Mail (einfach)',
    'debug-email-system.php' => 'E-Mail-System (SMTP)',
    'debug-email-templates.php' => 'E-Mail-Vorlagen',
    'debug-error-handling.php' => 'Fehlerbehandlung',
    'debug-error-logs.php' => 'Error-Logs',
    'debug-event-ids.php' => 'Event-IDs',
    'debug-feedback.php' => 'Feedback-System',
    'debug-feedback-list.php' => 'Feedback-Liste',
    'debug-function-return.php' => 'Funktion-Rückgabe (Google Calendar)',
    'debug-function-step-by-step.php' => 'Funktion Schritt-für-Schritt',
    'debug-function-version.php' => 'Funktion-Version / Cache',
    'debug-gmail-smtp.php' => 'Gmail SMTP',
    'debug-google-calendar-approval.php' => 'Google Calendar bei Genehmigung',
    'debug-google-calendar-browser.php' => 'Google Calendar (Browser)',
    'debug-google-calendar-delete.php' => 'Google Calendar löschen',
    'debug-google-calendar-delete-detailed.php' => 'Google Calendar löschen (detailliert)',
    'debug-google-calendar-detailed.php' => 'Google Calendar (detailliert)',
    'debug-google-calendar-error.php' => 'Google Calendar Fehler',
    'debug-google-calendar-fixed.php' => 'Google Calendar (Fix)',
    'debug-google-calendar-live.php' => 'Google Calendar (Live)',
    'debug-google-calendar-logs.php' => 'Google Calendar Logs',
    'debug-google-calendar-settings.php' => 'Google Calendar Einstellungen',
    'debug-google-calendar-visibility.php' => 'Google Calendar Sichtbarkeit',
    'debug-http-500.php' => 'HTTP 500 Fehler',
    'debug-live-app-approval.php' => 'Live App-Genehmigung',
    'debug-live-approval.php' => 'Live-Genehmigung',
    'debug-logs.php' => 'Logs',
    'debug-manual-button.php' => 'Manueller Button',
    'debug-new-function.php' => 'Neue Funktion (create_or_update)',
    'debug-notifications.php' => 'Benachrichtigungen',
    'debug-password-save.php' => 'Passwort speichern',
    'debug-path-issue.php' => 'Pfad-Probleme',
    'debug-real-app-approval.php' => 'Echte App-Genehmigung',
    'debug-real-app-vs-script.php' => 'App vs. Skript',
    'debug-real-approval.php' => 'Echte Genehmigung',
    'debug-real-reservation-approval.php' => 'Echte Reservierungs-Genehmigung',
    'debug-real-reservation-approval-live.php' => 'Echte Reservierungs-Genehmigung (Live)',
    'debug-redirect-issue.php' => 'Weiterleitungs-Problem',
    'debug-reservation-approval.php' => 'Reservierungs-Genehmigung',
    'debug-reservation-approval-method.php' => 'Reservierungs-Genehmigungs-Methode',
    'debug-reservation-console.php' => 'Reservierung (Browser-Console)',
    'debug-reservations-delete.php' => 'Reservierungen löschen',
    'debug-second-vehicle-logic.php' => 'Zweites Fahrzeug (Logik)',
    'debug-settings-page.php' => 'Einstellungs-Seite',
    'debug-simple.php' => 'Einfacher Debug',
    'debug-smtp-detailed.php' => 'SMTP (detailliert)',
    'debug-title-update.php' => 'Titel-Update',
    'debug-vehicles-display.php' => 'Fahrzeuge anzeigen',
    'debug-web.php' => 'Web-Debug',
    'debug-with-console.php' => 'Mit Console-Logging',
    'debug-db.php' => 'Datenbank (Docker)',
    'debug-divera.php' => 'Divera-Konfiguration',
    'debug-room-reservations.php' => 'Raumreservierungen',
    'test-admin-email.php' => 'Admin-E-Mail senden',
    'test-approve-direct.php' => 'Direkte Genehmigung',
    'test-approve-final.php' => 'Genehmigung (Final)',
    'test-atemschutz-table.php' => 'Atemschutz-Tabelle',
    'test-atemschutz-workflow.php' => 'Atemschutz-Workflow',
    'test-beautiful-emails.php' => 'E-Mail-Layout (schön)',
    'test-calendar-approval.php' => 'Kalender-Genehmigung',
    'test-calendar-conflicts.php' => 'Kalender-Konflikte',
    'test-cancelled-events-ignored.php' => 'Abgesagte Events ignorieren',
    'test-create-google-event.php' => 'Google-Event erstellen',
    'test-dashboard.php' => 'Dashboard',
    'test-dashboard-auto-calendar-check.php' => 'Dashboard Auto-Kalender-Check',
    'test-dashboard-display.php' => 'Dashboard-Anzeige',
    'test-dashboard-includes.php' => 'Dashboard-Includes',
    'test-dashboard-live.php' => 'Dashboard (Live)',
    'test-dashboard-modals-simple.php' => 'Dashboard-Modals (einfach)',
    'test-dashboard-parameters.php' => 'Dashboard-Parameter',
    'test-dashboard-syntax.php' => 'Dashboard-Syntax',
    'test-dashboard-variable-scope.php' => 'Dashboard Variablen-Scope',
    'test-dashboard-fix.php' => 'Dashboard-Fix',
    'test-database-conflict-check.php' => 'Datenbank-Konflikt-Check',
    'test-delete-comparison.php' => 'Lösch-Vergleich',
    'test-delete-fix.php' => 'Lösch-Fix',
    'test-delete-reservation.php' => 'Reservierung löschen',
    'test-direct-delete.php' => 'Direktes Löschen',
    'test-email-delivery.php' => 'E-Mail-Zustellung',
    'test-email-export.php' => 'E-Mail-Export (PA-Träger)',
    'test-email-headers.php' => 'E-Mail-Header',
    'test-external-smtp.php' => 'Externes SMTP',
    'test-final-delete.php' => 'Löschen (Final)',
    'test-final-email.php' => 'E-Mail (Final)',
    'test-force-delete.php' => 'Erzwungenes Löschen',
    'test-force-submit-redirect.php' => 'Force-Submit Weiterleitung',
    'test-functions-loading.php' => 'Funktionen laden',
    'test-functions.php' => 'Funktionen',
    'test-fixed-functions.php' => 'Funktionen (Fix)',
    'test-gmail-delivery.php' => 'Gmail-Zustellung',
    'test-gmail-smtp.php' => 'Gmail SMTP',
    'test-global-session-fix.php' => 'Session-Fix (global)',
    'test-google-calendar-api-direct.php' => 'Google Calendar API (direkt)',
    'test-google-calendar-complete.php' => 'Google Calendar (komplett)',
    'test-google-calendar-dashboard.php' => 'Google Calendar Dashboard',
    'test-google-calendar-debug.php' => 'Google Calendar Debug',
    'test-google-calendar-delete.php' => 'Google Calendar löschen',
    'test-google-calendar-delete-fixed.php' => 'Google Calendar löschen (Fix)',
    'test-google-calendar-direct.php' => 'Google Calendar (direkt)',
    'test-google-calendar-fixed.php' => 'Google Calendar (Fix)',
    'test-google-calendar-integration.php' => 'Google Calendar Integration',
    'test-google-calendar-service-account.php' => 'Google Calendar Service Account',
    'test-google-calendar-simple.php' => 'Google Calendar (einfach)',
    'test-improvements.php' => 'Verbesserungen',
    'test-json-save.php' => 'JSON speichern',
    'test-location-fix.php' => 'Ort-Fix',
    'test-logging-detailed.php' => 'Logging (detailliert)',
    'test-message-display.php' => 'Nachricht-Anzeige',
    'test-new-json.php' => 'Neues JSON',
    'test-new-reservation-approval.php' => 'Neue Reservierungs-Genehmigung',
    'test-real-force-submit.php' => 'Force-Submit (echt)',
    'test-redirect-functionality.php' => 'Weiterleitungs-Funktion',
    'test-reservation-api.php' => 'Reservierungs-API',
    'test-reservation-approval.php' => 'Reservierungs-Genehmigung',
    'test-reservation-approval-direct.php' => 'Reservierungs-Genehmigung (direkt)',
    'test-reservation-approval-fix.php' => 'Reservierungs-Genehmigung (Fix)',
    'test-reservation-approval-fixed.php' => 'Reservierungs-Genehmigung (Fix)',
    'test-reservation-approval-simple.php' => 'Reservierungs-Genehmigung (einfach)',
    'test-reservation-conflict.php' => 'Reservierungs-Konflikt',
    'test-reservation-emails.php' => 'Reservierungs-E-Mails',
    'test-reservation-fix.php' => 'Reservierungs-Fix',
    'test-reservation-google-calendar.php' => 'Reservierung + Google Calendar',
    'test-reservations-delete.php' => 'Reservierungen löschen',
    'test-rfc5322-headers.php' => 'RFC5322 E-Mail-Header',
    'test-send-email.php' => 'E-Mail senden',
    'test-session-fix.php' => 'Session-Fix',
    'test-simple-modal.php' => 'Einfaches Modal',
    'test-simple-return.php' => 'Einfache Rückgabe',
    'test-simple.php' => 'Einfacher Test',
    'test-access-token-fix.php' => 'Access Token Fix (Google)',
    'test-admin-reservations-direct.php' => 'Admin-Reservierungen (direkt)',
    'test-advanced-delete.php' => 'Erweitertes Löschen',
    'test-member-courses.php' => 'Mitglieder-Kurse',
    'test-uebung-planen.php' => 'Übung planen',
    'test-vehicle-insert.php' => 'Fahrzeug einfügen',
    'test-vehicle-name-fix.php' => 'Fahrzeugname-Fix',
    'test-wkhtmltopdf.php' => 'wkhtmltopdf (PDF)',
    'test-your-email.php' => 'E-Mail an Sie senden',
    'test.php' => 'Allgemeiner Test',
];

function get_script_label($name, $labels) {
    if (isset($labels[$name])) return $labels[$name];
    $base = preg_replace('/^(debug|test)-/', '', pathinfo($name, PATHINFO_FILENAME));
    return ucwords(str_replace(['-', '_'], ' ', $base));
}

// Labels zuweisen
foreach ($scripts as &$s) {
    $s['label'] = get_script_label($s['name'], $labels);
}
unset($s);

// Kategorien für bessere Übersicht
$categories = [
    'E-Mail' => ['debug-email-', 'debug-gmail-', 'debug-smtp-', 'test-email', 'test-gmail', 'test-send-email', 'test-external-smtp', 'test-rfc5322', 'test-beautiful-emails'],
    'Google Calendar' => ['debug-google-calendar-', 'debug-calendar-', 'debug-event-', 'test-google-calendar', 'test-calendar'],
    'Reservierung' => ['debug-reservation', 'debug-reservations-', 'debug-approval', 'debug-create-google', 'test-reservation', 'test-approve'],
    'Dashboard' => ['debug-dashboard-', 'test-dashboard'],
    'Datenbank' => ['debug-database', 'debug-admin.php', 'test-database'],
    'Divera & Admin' => ['debug-divera', 'debug-db', 'debug-room-', 'test-admin', 'test-member'],
    'Sonstige' => []
];

function get_category($name, $categories) {
    foreach ($categories as $cat => $prefixes) {
        if ($cat === 'Sonstige') continue;
        foreach ($prefixes as $p) {
            if (stripos($name, $p) === 0) return $cat;
        }
    }
    return 'Sonstige';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug-Skripte – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h1 class="h3 mb-4"><i class="fas fa-bug text-warning"></i> Debug- & Test-Skripte</h1>
        <p class="text-muted mb-4">Übersicht aller Debug-Skripte. Nur für Superadmins zugänglich.</p>

        <div class="mb-3">
            <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
        </div>

        <?php
        $by_cat = [];
        foreach ($scripts as $s) {
            $cat = get_category($s['name'], $categories);
            $by_cat[$cat][] = $s;
        }
        foreach ($categories as $cat => $_) {
            if (empty($by_cat[$cat])) continue;
            ?>
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-folder-open text-warning"></i> <?php echo htmlspecialchars($cat); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-2">
                        <?php foreach ($by_cat[$cat] as $s): ?>
                        <div class="col">
                            <a href="<?php echo htmlspecialchars($s['url']); ?>" target="_blank" class="btn btn-outline-warning btn-sm w-100 text-start d-flex align-items-center justify-content-between" title="<?php echo htmlspecialchars($s['name']); ?>">
                                <span><i class="fas fa-external-link-alt me-1"></i> <?php echo htmlspecialchars($s['label']); ?></span>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($s['group']); ?></span>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
