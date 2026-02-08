<?php
/**
 * Divera 24/7 – Termin per API an Divera senden
 * Formular: Datum, Uhrzeit, Titel, Ort → POST an Divera API (Termin erstellen)
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/divera.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('divera-termin-senden.php'));
    exit;
}

$message = '';
$message_type = '';
$divera_ok = !empty($divera_config['access_key']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $divera_ok) {
    $datum = trim($_POST['datum'] ?? '');
    $uhrzeit = trim($_POST['uhrzeit'] ?? '');
    $uhrzeit_bis = trim($_POST['uhrzeit_bis'] ?? '');
    $titel = trim($_POST['titel'] ?? '');
    $ort = trim($_POST['ort'] ?? '');

    $fehler = [];
    if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
        $fehler[] = 'Bitte ein gültiges Datum angeben.';
    }
    if ($uhrzeit === '') {
        $fehler[] = 'Bitte eine Startzeit angeben.';
    }
    if ($titel === '') {
        $fehler[] = 'Bitte einen Titel angeben.';
    }

    if (empty($fehler)) {
        $start_dt = $datum . ' ' . ($uhrzeit ?: '00:00');
        $start_ts = strtotime($start_dt);
        if ($uhrzeit_bis !== '') {
            $end_dt = $datum . ' ' . $uhrzeit_bis;
            $end_ts = strtotime($end_dt);
            if ($end_ts <= $start_ts) {
                $fehler[] = 'Die Endzeit muss nach der Startzeit liegen.';
            }
        } else {
            $end_ts = $start_ts + 3600; // Standard: 1 Stunde
        }
    }

    if (empty($fehler)) {

        $access_key = trim((string) ($divera_config['access_key'] ?? ''));
        $base_url = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
        $url = $base_url . '/api/v2/events?accesskey=' . urlencode($access_key);
        // API-Layout laut Divera OpenAPI: Event-Objekt mit notification_type, title, ts_start, ts_end, address, text
        $body = [
            'Event' => [
                'notification_type' => 2, // 2 = Alle des Standortes
                'title'             => $titel,
                'ts_start'          => $start_ts,
                'ts_end'            => $end_ts,
                'address'           => $ort,
                'text'              => $ort !== '' ? 'Ort: ' . $ort : '',
            ],
        ];

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content'  => json_encode($body),
                'timeout'  => 15,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if ($code >= 200 && $code < 300) {
            $message = 'Der Termin wurde erfolgreich an Divera 24/7 übermittelt.';
            $message_type = 'success';
        } else {
            $msg = $data['data']['message'] ?? $data['message'] ?? $data['error'] ?? null;
            if (is_array($msg)) {
                $msg = implode(' ', $msg);
            }
            if ($msg === null && is_array($data['errors'] ?? null)) {
                $msg = implode(' ', $data['errors']);
            }
            if ($msg === null || $msg === '') {
                $msg = $code === 403
                    ? 'Accesskey fehlt oder ist ungültig. Verwenden Sie den Einheits-Accesskey (nicht den Benutzer-Key aus dem Debug-Tab). In Divera: Verwaltung → Konto / Vertragsdaten oder Ansteuerungen. Key ohne Leerzeichen eintragen und erneut speichern.'
                    : 'Unbekannter Fehler.';
            }
            $message = 'Divera hat den Termin abgelehnt (HTTP ' . $code . '): ' . htmlspecialchars($msg);
            $message_type = 'danger';
        }
    } else {
        $message = implode(' ', $fehler);
        $message_type = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termin an Divera 24/7 senden - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Startseite</a></li>
                    <li class="nav-item"><a class="nav-link" href="formulare.php"><i class="fas fa-file-alt"></i> Formulare</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-calendar-plus"></i> Termin an Divera 24/7 senden</h3>
                        <p class="text-muted mb-0 small">Termin (Datum, Zeit, Titel, Ort) werden per API an Divera übermittelt.</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!$divera_ok): ?>
                            <div class="alert alert-warning">
                                <strong>Divera ist noch nicht eingerichtet.</strong><br>
                                Bitte hinterlegen Sie den Access Key in den <strong>globalen Einstellungen</strong>:<br>
                                <a href="admin/settings-global.php">Einstellungen → Globale Einstellungen</a> → Karte „Divera 24/7“.<br>
                                Den Access Key erhalten Sie in Divera 24/7 unter Verwaltung → API-Verwaltung.
                            </div>
                            <a href="formulare.php" class="btn btn-secondary">Zurück zu Formulare</a>
                        <?php else: ?>
                            <?php if ($message !== ''): ?>
                                <div class="alert alert-<?php echo $message_type; ?>"><?php echo nl2br(htmlspecialchars($message)); ?></div>
                            <?php endif; ?>
                            <form method="post">
                                <div class="mb-3">
                                    <label for="datum" class="form-label">Datum</label>
                                    <input type="date" class="form-control" id="datum" name="datum" value="<?php echo htmlspecialchars($_POST['datum'] ?? date('Y-m-d')); ?>" required>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6 mb-3">
                                        <label for="uhrzeit" class="form-label">Uhrzeit von</label>
                                        <input type="time" class="form-control" id="uhrzeit" name="uhrzeit" value="<?php echo htmlspecialchars($_POST['uhrzeit'] ?? '19:00'); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="uhrzeit_bis" class="form-label">Uhrzeit bis</label>
                                        <input type="time" class="form-control" id="uhrzeit_bis" name="uhrzeit_bis" value="<?php echo htmlspecialchars($_POST['uhrzeit_bis'] ?? '20:00'); ?>" placeholder="Optional">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="titel" class="form-label">Titel</label>
                                    <input type="text" class="form-control" id="titel" name="titel" placeholder="z. B. Übungsdienst, Einsatzübung" value="<?php echo htmlspecialchars($_POST['titel'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-4">
                                    <label for="ort" class="form-label">Ort / Adresse</label>
                                    <input type="text" class="form-control" id="ort" name="ort" placeholder="z. B. Gerätehaus, Musterstraße 1" value="<?php echo htmlspecialchars($_POST['ort'] ?? ''); ?>">
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> An Divera 24/7 senden</button>
                                    <a href="formulare.php" class="btn btn-secondary">Abbrechen</a>
                                </div>
                            </form>
                            <p class="text-muted small mt-3 mb-0">Ohne Angabe „Uhrzeit bis“ wird 1 Stunde Dauer angenommen. Bei der kostenlosen Divera-Version gelten Einschränkungen (z.&nbsp;B. ein Termin pro 5 Minuten).</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
