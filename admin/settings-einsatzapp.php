<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$einheit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($einheit_id <= 0) {
    header('Location: settings-einheiten.php');
    exit;
}
if (!user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    header('Location: settings-einheiten.php?error=access_denied');
    exit;
}

$einheit = null;
$message = '';
$error = '';
$settings = [];
$einsatzapp_api_tokens = [];

try {
    $stmt = $db->prepare('SELECT id, name, kurzbeschreibung FROM einheiten WHERE id = ?');
    $stmt->execute([$einheit_id]);
    $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $einheit = null;
}
if (!$einheit) {
    header('Location: settings-einheiten.php?error=not_found');
    exit;
}

try {
    $settings = load_settings_for_einheit($db, $einheit_id);
    if (!empty($settings['einsatzapp_api_tokens'])) {
        $decTokens = json_decode($settings['einsatzapp_api_tokens'], true);
        if (is_array($decTokens)) {
            foreach ($decTokens as $row) {
                if (!is_array($row)) continue;
                $tok = trim((string)($row['token'] ?? ''));
                $lbl = trim((string)($row['label'] ?? ''));
                if ($tok === '') continue;
                $einsatzapp_api_tokens[] = ['label' => $lbl, 'token' => $tok];
            }
        }
    }
} catch (Throwable $e) {
    $error = 'Fehler beim Laden der Einsatzapp-Einstellungen: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_einsatzapp']) && !isset($_POST['test_imap'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungueltiger Sicherheitstoken.';
    } else {
        try {
            $api_labels = $_POST['einsatzapp_api_label'] ?? [];
            $api_tokens = $_POST['einsatzapp_api_token'] ?? [];
            $einsatzapp_tokens_save = [];
            foreach ($api_tokens as $i => $tokenRaw) {
                $token = trim((string)$tokenRaw);
                $label = trim((string)($api_labels[$i] ?? ''));
                if ($token === '') continue;
                $einsatzapp_tokens_save[] = ['label' => $label, 'token' => $token];
            }

            $imapPasswordInput = trim((string)($_POST['alarmdepesche_imap_password'] ?? ''));
            $imapPassword = $imapPasswordInput !== ''
                ? $imapPasswordInput
                : trim((string)($settings['alarmdepesche_imap_password'] ?? ''));

            $toSave = [
                'einsatzapp_api_tokens' => json_encode($einsatzapp_tokens_save, JSON_UNESCAPED_UNICODE),
                'alarmdepesche_imap_host' => trim((string)($_POST['alarmdepesche_imap_host'] ?? '')),
                'alarmdepesche_imap_port' => trim((string)($_POST['alarmdepesche_imap_port'] ?? '993')),
                'alarmdepesche_imap_user' => trim((string)($_POST['alarmdepesche_imap_user'] ?? '')),
                'alarmdepesche_imap_password' => $imapPassword,
                'alarmdepesche_imap_folder' => trim((string)($_POST['alarmdepesche_imap_folder'] ?? 'INBOX')),
                'alarmdepesche_imap_search_mode' => in_array(trim((string)($_POST['alarmdepesche_imap_search_mode'] ?? 'UNSEEN')), ['UNSEEN', 'ALL'], true)
                    ? trim((string)($_POST['alarmdepesche_imap_search_mode'] ?? 'UNSEEN'))
                    : 'UNSEEN',
                'alarmdepesche_subject_filter' => trim((string)($_POST['alarmdepesche_subject_filter'] ?? '')),
            ];
            save_settings_bulk_for_einheit($db, $einheit_id, $toSave);

            $settings = load_settings_for_einheit($db, $einheit_id);
            $einsatzapp_api_tokens = [];
            if (!empty($settings['einsatzapp_api_tokens'])) {
                $decTokens = json_decode($settings['einsatzapp_api_tokens'], true);
                if (is_array($decTokens)) {
                    foreach ($decTokens as $row) {
                        if (!is_array($row)) continue;
                        $tok = trim((string)($row['token'] ?? ''));
                        $lbl = trim((string)($row['label'] ?? ''));
                        if ($tok === '') continue;
                        $einsatzapp_api_tokens[] = ['label' => $lbl, 'token' => $tok];
                    }
                }
            }
            $message = 'Einsatzapp-Einstellungen gespeichert.';
        } catch (Throwable $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_imap'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungueltiger Sicherheitstoken.';
    } else {
        $host = trim((string)($_POST['alarmdepesche_imap_host'] ?? ($settings['alarmdepesche_imap_host'] ?? '')));
        $port = trim((string)($_POST['alarmdepesche_imap_port'] ?? ($settings['alarmdepesche_imap_port'] ?? '993')));
        $user = trim((string)($_POST['alarmdepesche_imap_user'] ?? ($settings['alarmdepesche_imap_user'] ?? '')));
        $folder = trim((string)($_POST['alarmdepesche_imap_folder'] ?? ($settings['alarmdepesche_imap_folder'] ?? 'INBOX')));
        $passwordInput = trim((string)($_POST['alarmdepesche_imap_password'] ?? ''));
        $password = $passwordInput !== '' ? $passwordInput : trim((string)($settings['alarmdepesche_imap_password'] ?? ''));

        if ($host === '' || $user === '' || $password === '') {
            $error = 'IMAP-Test nicht moeglich: Bitte Host, Benutzer und Passwort angeben.';
        } else {
            $scriptPath = realpath(__DIR__ . '/../tools/import-alarmdepeschen-imap.py');
            if (!$scriptPath || !is_file($scriptPath)) {
                $error = 'IMAP-Test nicht moeglich: Import-Script nicht gefunden.';
            } else {
                $pythonCandidates = ['/usr/bin/python3', 'python3'];
                $output = [];
                $exitCode = 1;
                foreach ($pythonCandidates as $pythonCmd) {
                    $candidateOutput = [];
                    $candidateExit = 1;
                    $cmd = escapeshellarg($pythonCmd)
                        . ' '
                        . escapeshellarg($scriptPath)
                        . ' --test-only'
                        . ' --einheit-id=' . (int)$einheit_id
                        . ' --host=' . escapeshellarg($host)
                        . ' --port=' . (int)$port
                        . ' --user=' . escapeshellarg($user)
                        . ' --password=' . escapeshellarg($password)
                        . ' --folder=' . escapeshellarg($folder);
                    @exec($cmd . ' 2>&1', $candidateOutput, $candidateExit);
                    $output = $candidateOutput;
                    $exitCode = $candidateExit;
                    if ($candidateExit === 0) {
                        break;
                    }
                }

                if ($exitCode === 0) {
                    $summary = trim(implode(' ', $output));
                    $message = $summary !== '' ? ('IMAP-Test erfolgreich: ' . $summary) : 'IMAP-Test erfolgreich.';
                } else {
                    $details = trim(implode(' ', $output));
                    if (stripos($details, 'AUTHENTICATIONFAILED') !== false || stripos($details, 'Invalid credentials') !== false) {
                        $error = 'IMAP-Test fehlgeschlagen: Login nicht moeglich (Benutzer/Passwort ungueltig).';
                    } else {
                        $error = 'IMAP-Test fehlgeschlagen.' . ($details !== '' ? (' Details: ' . $details) : '');
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einsatzapp – <?php echo htmlspecialchars($einheit['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="settings.php">Einstellungen</a></li>
                <li class="breadcrumb-item"><a href="settings-einheiten.php">Einheiten</a></li>
                <li class="breadcrumb-item"><a href="settings-einheit.php?id=<?php echo (int)$einheit_id; ?>"><?php echo htmlspecialchars($einheit['name']); ?></a></li>
                <li class="breadcrumb-item active">Einsatzapp</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0"><i class="fas fa-mobile-screen-button text-primary"></i> Einsatzapp – <?php echo htmlspecialchars($einheit['name']); ?></h1>
            <a href="settings-einheit.php?id=<?php echo (int)$einheit_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Zurück zu Einheitseinstellungen
            </a>
        </div>

        <?php if ($message) echo show_success($message); ?>
        <?php if ($error) echo show_error($error); ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="save_einsatzapp" value="1">

            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-key"></i> Einsatzapp API-Tokens</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Mehrere Tokens sind moeglich (z. B. pro Geraet oder Rolle).</p>
                    <div id="einsatzappTokenContainer">
                        <?php foreach ($einsatzapp_api_tokens as $tok): ?>
                        <div class="row g-2 align-items-center mb-2 einsatzapp-token-row">
                            <div class="col-md-4">
                                <input type="text" class="form-control" name="einsatzapp_api_label[]" placeholder="Bezeichnung / Kommentar" value="<?php echo htmlspecialchars($tok['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-7">
                                <input type="text" class="form-control" name="einsatzapp_api_token[]" placeholder="API-Token" value="<?php echo htmlspecialchars($tok['token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-1 d-grid">
                                <button type="button" class="btn btn-outline-danger btn-remove-einsatzapp-token" title="Token entfernen"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddEinsatzappToken"><i class="fas fa-plus me-1"></i>Token hinzufügen</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnGenerateEinsatzappToken"><i class="fas fa-key me-1"></i>Zufälligen Schlüssel erzeugen</button>
                    </div>
                    <small class="text-muted d-block mt-2">Tokens werden beim Speichern übernommen. Leere Zeilen werden ignoriert.</small>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-file-pdf"></i> Alarmdepesche (IMAP Postfach)</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Diese Zugangsdaten werden vom Import-Script verwendet, um Fax-PDFs aus dem Postfach abzurufen.</p>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">IMAP Host</label>
                            <input type="text" class="form-control" name="alarmdepesche_imap_host" placeholder="z.B. imap.example.com" value="<?php echo htmlspecialchars($settings['alarmdepesche_imap_host'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Port</label>
                            <input type="number" class="form-control" name="alarmdepesche_imap_port" placeholder="993" value="<?php echo htmlspecialchars($settings['alarmdepesche_imap_port'] ?? '993', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IMAP Ordner</label>
                            <input type="text" class="form-control" name="alarmdepesche_imap_folder" placeholder="INBOX" value="<?php echo htmlspecialchars($settings['alarmdepesche_imap_folder'] ?? 'INBOX', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">IMAP Benutzer</label>
                            <input type="text" class="form-control" name="alarmdepesche_imap_user" placeholder="z.B. fax@feuerwehr.de" value="<?php echo htmlspecialchars($settings['alarmdepesche_imap_user'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IMAP Passwort</label>
                            <input type="password" class="form-control" name="alarmdepesche_imap_password" placeholder="Leer lassen = bisheriges Passwort behalten">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label class="form-label">Suchmodus</label>
                            <?php $imap_search_mode = strtoupper(trim((string)($settings['alarmdepesche_imap_search_mode'] ?? 'UNSEEN'))); ?>
                            <select class="form-select" name="alarmdepesche_imap_search_mode">
                                <option value="UNSEEN" <?php echo $imap_search_mode === 'UNSEEN' ? 'selected' : ''; ?>>Nur ungelesene Mails (UNSEEN)</option>
                                <option value="ALL" <?php echo $imap_search_mode === 'ALL' ? 'selected' : ''; ?>>Alle Mails (ALL)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Betreff-Filter (optional)</label>
                            <input type="text" class="form-control" name="alarmdepesche_subject_filter" placeholder="z.B. Alarmdepesche" value="<?php echo htmlspecialchars($settings['alarmdepesche_subject_filter'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">Hinweis: Das Passwort wird nur überschrieben, wenn hier ein neuer Wert eingetragen wird.</small>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Einsatzapp-Einstellungen speichern</button>
                <button type="submit" class="btn btn-outline-success" name="test_imap" value="1"><i class="fas fa-plug me-1"></i>IMAP Verbindung testen</button>
                <a class="btn btn-outline-danger" href="alarmdepeschen.php?einheit_id=<?php echo (int)$einheit_id; ?>"><i class="fas fa-file-pdf me-1"></i>Alarmdepeschen verwalten</a>
                <a class="btn btn-outline-primary" href="divera-samples.php?einheit_id=<?php echo (int)$einheit_id; ?>"><i class="fas fa-database me-1"></i>Divera Beispieldaten verwalten</a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        var einsatzappContainer = document.getElementById('einsatzappTokenContainer');
        var btnAdd = document.getElementById('btnAddEinsatzappToken');
        var btnGenerate = document.getElementById('btnGenerateEinsatzappToken');

        function randomToken(len) {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            var out = '';
            for (var i = 0; i < len; i++) out += chars.charAt(Math.floor(Math.random() * chars.length));
            return out;
        }

        function createTokenRow(label, token) {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2 einsatzapp-token-row';
            row.innerHTML =
                '<div class="col-md-4"><input type="text" class="form-control" name="einsatzapp_api_label[]" placeholder="Bezeichnung / Kommentar"></div>' +
                '<div class="col-md-7"><input type="text" class="form-control" name="einsatzapp_api_token[]" placeholder="API-Token"></div>' +
                '<div class="col-md-1 d-grid"><button type="button" class="btn btn-outline-danger btn-remove-einsatzapp-token" title="Token entfernen"><i class="fas fa-trash"></i></button></div>';
            row.querySelector('input[name="einsatzapp_api_label[]"]').value = label || '';
            row.querySelector('input[name="einsatzapp_api_token[]"]').value = token || '';
            return row;
        }

        if (einsatzappContainer && einsatzappContainer.children.length === 0) {
            einsatzappContainer.appendChild(createTokenRow('', ''));
        }
        if (btnAdd) {
            btnAdd.addEventListener('click', function () {
                einsatzappContainer.appendChild(createTokenRow('', ''));
            });
        }
        if (btnGenerate) {
            btnGenerate.addEventListener('click', function () {
                einsatzappContainer.appendChild(createTokenRow('Neuer Token', randomToken(40)));
            });
        }
        if (einsatzappContainer) {
            einsatzappContainer.addEventListener('click', function (e) {
                if (e.target.closest('.btn-remove-einsatzapp-token')) {
                    var row = e.target.closest('.einsatzapp-token-row');
                    if (row) row.remove();
                }
            });
        }
    })();
    </script>
</body>
</html>

