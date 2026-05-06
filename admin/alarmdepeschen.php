<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

function ad_ensure_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS alarmdepesche_inbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL DEFAULT 0,
            message_uid VARCHAR(191) NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            sender VARCHAR(255) NOT NULL DEFAULT '',
            received_at_utc DATETIME NOT NULL,
            filename_original VARCHAR(255) NOT NULL,
            storage_path VARCHAR(512) NOT NULL,
            sha256 VARCHAR(64) NULL,
            file_size_bytes BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_uid (message_uid),
            KEY idx_einheit_received (einheit_id, received_at_utc),
            KEY idx_received (received_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$message = '';
$error = '';

try {
    ad_ensure_table($db);
} catch (Throwable $e) {
    $error = 'Tabelle konnte nicht vorbereitet werden.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungueltiger Sicherheitstoken.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("SELECT storage_path FROM alarmdepesche_inbox WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $path = (string)($row['storage_path'] ?? '');
                    $del = $db->prepare("DELETE FROM alarmdepesche_inbox WHERE id = ?");
                    $del->execute([$id]);
                    if ($path !== '' && is_file($path)) {
                        @unlink($path);
                    }
                    $message = 'Depesche wurde geloescht.';
                }
            } catch (Throwable $e) {
                $error = 'Loeschen fehlgeschlagen.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_import') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungueltiger Sicherheitstoken.';
    } else {
        $einheitIdForImport = (int)($_POST['einheit_id'] ?? 0);
        if ($einheitIdForImport <= 0) {
            $error = 'Bitte eine gueltige Einheit waehlen, bevor der Import gestartet wird.';
        } else {
            $scriptPath = realpath(__DIR__ . '/../tools/import-alarmdepeschen-imap.py');
            if (!$scriptPath || !is_file($scriptPath)) {
                $error = 'Import-Script nicht gefunden.';
            } else {
                $pythonCandidates = ['/usr/bin/python3', 'python3'];
                $pythonCandidates = array_values(array_unique(array_filter($pythonCandidates, static function ($value) {
                    return trim((string)$value) !== '';
                })));

                $output = [];
                $exitCode = 1;
                $allErrors = [];
                foreach ($pythonCandidates as $pythonCmd) {
                    $candidateOutput = [];
                    $candidateExit = 1;
                    $cmd = escapeshellarg($pythonCmd)
                        . ' '
                        . escapeshellarg($scriptPath)
                        . ' --einheit-id=' . $einheitIdForImport;
                    @exec($cmd . ' 2>&1', $candidateOutput, $candidateExit);
                    $output = $candidateOutput;
                    $exitCode = $candidateExit;
                    if ($candidateExit !== 0) {
                        $allErrors[] = trim(implode(' ', $candidateOutput));
                    }
                    if ($candidateExit === 0) {
                        break;
                    }
                }

                if ($exitCode === 0) {
                    $summary = trim(implode(' ', $output));
                    $message = $summary !== ''
                        ? ('Import gestartet: ' . $summary)
                        : 'Import erfolgreich ausgefuehrt.';
                } else {
                    $details = trim(implode(' ', $output));
                    if ($details === '' && !empty($allErrors)) {
                        $details = trim(implode(' | ', array_filter($allErrors)));
                    }
                    if (stripos($details, 'python: not found') !== false || stripos($details, 'python3: not found') !== false) {
                        $error = 'Import fehlgeschlagen: Python fehlt im Web-Container. '
                            . 'Bitte Container neu bauen/starten: '
                            . '`docker compose build web && docker compose up -d web`';
                    } elseif (stripos($details, 'pymysql') !== false) {
                        $error = 'Import fehlgeschlagen: PyMySQL fehlt im Web-Container. '
                            . 'Bitte Container neu bauen/starten: '
                            . '`docker compose build web && docker compose up -d web`';
                    } else {
                        $error = 'Import fehlgeschlagen.' . ($details !== '' ? (' Details: ' . $details) : '');
                    }
                }
            }
        }
    }
}

$einheitId = function_exists('get_current_einheit_id') ? (int)(get_current_einheit_id() ?? 0) : 0;
$filterEinheit = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : $einheitId;
$limit = 200;
$rows = [];
try {
    if ($filterEinheit > 0) {
        $stmt = $db->prepare("
            SELECT id, einheit_id, subject, sender, received_at_utc, filename_original, file_size_bytes, created_at
            FROM alarmdepesche_inbox
            WHERE einheit_id = ? OR einheit_id = 0
            ORDER BY received_at_utc DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$filterEinheit]);
    } else {
        $stmt = $db->query("
            SELECT id, einheit_id, subject, sender, received_at_utc, filename_original, file_size_bytes, created_at
            FROM alarmdepesche_inbox
            ORDER BY received_at_utc DESC, id DESC
            LIMIT {$limit}
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Depeschen konnten nicht geladen werden.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alarmdepeschen</title>
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
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-file-pdf text-danger"></i> Alarmdepeschen</h1>
        <div class="d-flex gap-2">
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="refresh_import">
                <input type="hidden" name="einheit_id" value="<?php echo (int)$filterEinheit; ?>">
                <button type="submit" class="btn btn-primary" <?php echo $filterEinheit > 0 ? '' : 'disabled'; ?> title="<?php echo $filterEinheit > 0 ? 'Neue Alarmdepeschen aus dem IMAP-Postfach abrufen' : 'Bitte eine Einheit auswaehlen'; ?>">
                    <i class="fas fa-arrows-rotate me-1"></i> Aktualisieren
                </button>
            </form>
            <a href="settings-einsatzapp.php<?php echo $filterEinheit > 0 ? '?id=' . $filterEinheit : ''; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-gear me-1"></i> Einsatzapp Einstellungen
            </a>
        </div>
    </div>

    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-1"></i> Letzte Importierte Depeschen (max. <?php echo (int)$limit; ?>)</span>
            <span class="badge bg-secondary"><?php echo count($rows); ?> Eintraege</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="p-3 text-muted">Keine Depeschen vorhanden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Zeit (UTC)</th>
                                <th>Datei</th>
                                <th>Betreff</th>
                                <th>Absender</th>
                                <th>Groesse</th>
                                <th>Einheit</th>
                                <th class="text-end">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['received_at_utc']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['filename_original']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['subject']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['sender']); ?></td>
                                    <td>
                                        <?php
                                            $bytes = (int)($r['file_size_bytes'] ?? 0);
                                            echo $bytes > 0 ? htmlspecialchars(number_format($bytes / 1024, 1, ',', '.') . ' KB') : '-';
                                        ?>
                                    </td>
                                    <td><?php echo (int)($r['einheit_id'] ?? 0); ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" target="_blank" href="alarmdepesche-view.php?id=<?php echo (int)$r['id']; ?>">
                                            <i class="fas fa-eye"></i> Ansehen
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Depesche wirklich loeschen?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Loeschen
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

