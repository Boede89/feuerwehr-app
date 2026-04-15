<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !is_superadmin($_SESSION['user_id'])) {
    header('Location: ../login.php?error=superadmin_only');
    exit;
}

$base_upload_dir = realpath(__DIR__ . '/..');
if ($base_upload_dir === false) {
    die('Basisverzeichnis konnte nicht ermittelt werden.');
}
$objektplan_dir = $base_upload_dir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'objektplaene';
if (!is_dir($objektplan_dir)) {
    @mkdir($objektplan_dir, 0775, true);
}

$message = '';
$error = '';
$active_max_file_uploads = (int)(ini_get('max_file_uploads') ?: 20);
$active_upload_max_filesize = (string)(ini_get('upload_max_filesize') ?: 'unbekannt');
$active_post_max_size = (string)(ini_get('post_max_size') ?: 'unbekannt');

$parse_ini_size = static function ($size) {
    $size = trim((string)$size);
    if ($size === '') return 0;
    $unit = mb_strtolower(substr($size, -1), 'UTF-8');
    $value = (float)$size;
    switch ($unit) {
        case 'g': return (int)($value * 1024 * 1024 * 1024);
        case 'm': return (int)($value * 1024 * 1024);
        case 'k': return (int)($value * 1024);
        default: return (int)$value;
    }
};

$sanitize_path_part = static function ($value) {
    $value = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string)$value);
    $parts = explode(DIRECTORY_SEPARATOR, $value);
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $part = preg_replace('/[^a-zA-Z0-9._\- äöüÄÖÜß]/u', '_', $part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return implode(DIRECTORY_SEPARATOR, $out);
};

$is_pdf_name = static function ($filename) {
    return mb_strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION), 'UTF-8') === 'pdf';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max_size = $parse_ini_size(ini_get('post_max_size'));
    $upload_max_size = $parse_ini_size(ini_get('upload_max_filesize'));
    if ($content_length > 0 && empty($_POST) && empty($_FILES)) {
        $error = 'Upload zu groß oder durch Server-Limits blockiert. Bitte kleinere Datenmengen hochladen oder die PHP-Limits erhöhen (post_max_size='
            . (ini_get('post_max_size') ?: 'unbekannt') . ', upload_max_filesize=' . (ini_get('upload_max_filesize') ?: 'unbekannt') . ').';
    } elseif ($post_max_size > 0 && $content_length > $post_max_size) {
        $error = 'Upload überschreitet post_max_size (' . (ini_get('post_max_size') ?: 'unbekannt') . ').';
    } elseif ($upload_max_size > 0 && isset($_FILES['objektplan_zip']['size']) && (int)$_FILES['objektplan_zip']['size'] > $upload_max_size) {
        $error = 'ZIP-Datei überschreitet upload_max_filesize (' . (ini_get('upload_max_filesize') ?: 'unbekannt') . ').';
    } elseif (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } elseif (isset($_POST['delete_all_files'])) {
        $deleted = 0;
        if (is_dir($objektplan_dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($objektplan_dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    if (mb_strtolower((string)$item->getExtension(), 'UTF-8') === 'pdf') {
                        if (@unlink($item->getPathname())) {
                            $deleted++;
                        }
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getPathname()); // leere Unterordner aufräumen
                }
            }
        }
        $message = $deleted > 0 ? "{$deleted} Objektplan-Datei(en) wurden gelöscht." : 'Es waren keine PDF-Objektpläne zum Löschen vorhanden.';
    } elseif (isset($_POST['delete_file'])) {
        $rel = (string)($_POST['delete_file'] ?? '');
        $rel = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
        $rel = ltrim($rel, DIRECTORY_SEPARATOR);
        $full = realpath($objektplan_dir . DIRECTORY_SEPARATOR . $rel);
        $base = realpath($objektplan_dir);
        if ($full && $base && strpos($full, $base) === 0 && is_file($full)) {
            if (@unlink($full)) {
                $message = 'Datei wurde gelöscht.';
            } else {
                $error = 'Datei konnte nicht gelöscht werden.';
            }
        } else {
            $error = 'Ungültiger Dateipfad.';
        }
    } elseif (isset($_POST['upload_folder'])) {
        $saved = 0;
        $skipped = 0;

        if (!isset($_FILES['objektplan_files'])) {
            $error = 'Keine Dateien übermittelt.';
        } else {
            $files = $_FILES['objektplan_files'];
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;

            for ($i = 0; $i < $count; $i++) {
                $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_OK) {
                    $skipped++;
                    continue;
                }

                $tmp = (string)($files['tmp_name'][$i] ?? '');
                $name = (string)($files['name'][$i] ?? '');
                $full_path = (string)($files['full_path'][$i] ?? $name);
                if ($tmp === '' || $name === '') {
                    $skipped++;
                    continue;
                }
                if (!$is_pdf_name($name)) {
                    $skipped++;
                    continue;
                }

                $safe_rel = $sanitize_path_part($full_path);
                if ($safe_rel === '') {
                    $safe_rel = $sanitize_path_part($name);
                }
                if ($safe_rel === '' || !$is_pdf_name($safe_rel)) {
                    $skipped++;
                    continue;
                }

                $target = $objektplan_dir . DIRECTORY_SEPARATOR . $safe_rel;
                $target_dir = dirname($target);
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0775, true);
                }

                if (@move_uploaded_file($tmp, $target)) {
                    $saved++;
                } else {
                    $skipped++;
                }
            }
            $message = "Upload abgeschlossen: {$saved} PDF gespeichert" . ($skipped > 0 ? ", {$skipped} übersprungen." : '.');
            if ($count > 0 && $active_max_file_uploads > 0 && $count >= $active_max_file_uploads) {
                $message .= " Hinweis: Es wurden genau {$count} Dateien im Request erkannt (aktuelles max_file_uploads-Limit: {$active_max_file_uploads}). Wenn im Ordner mehr Dateien waren, bitte Upload in Teilmengen durchführen oder Limit weiter erhöhen.";
            }
        }
    } elseif (isset($_POST['upload_zip'])) {
        $zip_err = (int)($_FILES['objektplan_zip']['error'] ?? UPLOAD_ERR_NO_FILE);
        $zip_tmp = (string)($_FILES['objektplan_zip']['tmp_name'] ?? '');
        $zip_name = (string)($_FILES['objektplan_zip']['name'] ?? '');

        if ($zip_err !== UPLOAD_ERR_OK || $zip_tmp === '' || $zip_name === '') {
            $error = 'Bitte eine ZIP-Datei auswählen.';
        } elseif (mb_strtolower(pathinfo($zip_name, PATHINFO_EXTENSION), 'UTF-8') !== 'zip') {
            $error = 'Nur ZIP-Dateien sind erlaubt.';
        } elseif (!class_exists('ZipArchive')) {
            $error = 'ZIP-Upload ist auf diesem Server nicht verfügbar (ZipArchive fehlt).';
        } else {
            $zip = new ZipArchive();
            if ($zip->open($zip_tmp) !== true) {
                $error = 'ZIP-Datei konnte nicht geöffnet werden.';
            } else {
                $saved = 0;
                $skipped = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = (string)$zip->getNameIndex($i);
                    if ($entry === '' || str_ends_with($entry, '/')) {
                        continue;
                    }
                    if (!$is_pdf_name($entry)) {
                        $skipped++;
                        continue;
                    }
                    $safe_rel = $sanitize_path_part($entry);
                    if ($safe_rel === '' || !$is_pdf_name($safe_rel)) {
                        $skipped++;
                        continue;
                    }
                    $target = $objektplan_dir . DIRECTORY_SEPARATOR . $safe_rel;
                    $target_dir = dirname($target);
                    if (!is_dir($target_dir)) {
                        @mkdir($target_dir, 0775, true);
                    }
                    $stream = $zip->getStream($entry);
                    if ($stream === false) {
                        $skipped++;
                        continue;
                    }
                    $out = @fopen($target, 'wb');
                    if ($out === false) {
                        fclose($stream);
                        $skipped++;
                        continue;
                    }
                    while (!feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false) break;
                        fwrite($out, $chunk);
                    }
                    fclose($stream);
                    fclose($out);
                    $saved++;
                }
                $zip->close();
                $message = "ZIP-Import abgeschlossen: {$saved} PDF gespeichert" . ($skipped > 0 ? ", {$skipped} übersprungen." : '.');
            }
        }
    }
}

$file_rows = [];
if (is_dir($objektplan_dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($objektplan_dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if (mb_strtolower((string)$file->getExtension(), 'UTF-8') !== 'pdf') continue;
        $full = $file->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($full, strlen($objektplan_dir))), '/');
        $file_rows[] = [
            'rel' => $rel,
            'url' => '../uploads/objektplaene/' . $rel,
            'size' => (int)$file->getSize(),
            'mtime' => (int)$file->getMTime(),
        ];
    }
}
usort($file_rows, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

$format_size = static function ($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
};
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Objektpläne - Feuerwehr App</title>
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
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="h3 mb-0"><i class="fas fa-folder-open text-danger me-2"></i>Objektpläne verwalten</h1>
            <a href="tabletbereich.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zum Tabletbereich</a>
        </div>

        <?php if ($message): ?><?php echo show_success($message); ?><?php endif; ?>
        <?php if ($error): ?><?php echo show_error($error); ?><?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-upload me-2"></i>Ordner-Upload (PDFs)</div>
                    <div class="card-body">
                        <p class="text-muted small">Hier können Sie einen kompletten Ordner mit Unterordnern hochladen. Nur PDF-Dateien werden übernommen.</p>
                        <p class="small text-muted mb-2">Aktive Limits: <code>max_file_uploads=<?php echo (int)$active_max_file_uploads; ?></code>, <code>upload_max_filesize=<?php echo htmlspecialchars($active_upload_max_filesize); ?></code>, <code>post_max_size=<?php echo htmlspecialchars($active_post_max_size); ?></code></p>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="upload_folder" value="1">
                            <div class="mb-3">
                                <label class="form-label">Ordner auswählen</label>
                                <input type="file" name="objektplan_files[]" id="objektplan_files_input" class="form-control" webkitdirectory directory multiple required>
                                <small id="objektplan_files_hint" class="text-muted"></small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Ordner hochladen</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-file-archive me-2"></i>ZIP-Upload</div>
                    <div class="card-body">
                        <p class="text-muted small">Alternative: Ein ZIP mit Ihren Objektplänen hochladen. Unterordner werden übernommen, nur PDF-Dateien werden importiert.</p>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="upload_zip" value="1">
                            <div class="mb-3">
                                <label class="form-label">ZIP-Datei auswählen</label>
                                <input type="file" name="objektplan_zip" class="form-control" accept=".zip" required>
                            </div>
                            <button type="submit" class="btn btn-outline-primary"><i class="fas fa-file-import me-1"></i>ZIP importieren</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-file-pdf me-2"></i>Vorhandene Objektpläne</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary"><?php echo count($file_rows); ?> Datei(en)</span>
                    <form method="POST" onsubmit="return confirm('Wirklich alle hinterlegten Objektpläne löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="delete_all_files" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-trash-alt"></i> Alle löschen
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($file_rows)): ?>
                    <p class="text-muted mb-0">Noch keine Objektpläne hochgeladen.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Datei</th>
                                    <th>Größe</th>
                                    <th>Geändert</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($file_rows as $row): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($row['rel']); ?></code></td>
                                        <td><?php echo htmlspecialchars($format_size($row['size'])); ?></td>
                                        <td><?php echo date('d.m.Y H:i', $row['mtime']); ?></td>
                                        <td class="d-flex gap-2">
                                            <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> Öffnen
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Datei wirklich löschen?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="delete_file" value="<?php echo htmlspecialchars($row['rel']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i> Löschen
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
    <script>
        (function () {
            var input = document.getElementById('objektplan_files_input');
            var hint = document.getElementById('objektplan_files_hint');
            var maxFiles = <?php echo (int)$active_max_file_uploads; ?>;
            if (!input || !hint) return;
            input.addEventListener('change', function () {
                var count = (input.files && input.files.length) ? input.files.length : 0;
                if (count <= 0) {
                    hint.textContent = '';
                    return;
                }
                if (maxFiles > 0 && count > maxFiles) {
                    hint.className = 'text-danger';
                    hint.textContent = 'Achtung: Gewählt ' + count + ' Dateien, erlaubt sind aktuell nur ' + maxFiles + ' pro Upload-Request.';
                } else {
                    hint.className = 'text-success';
                    hint.textContent = count + ' Datei(en) ausgewählt.';
                }
            });
        })();
    </script>
</body>
</html>
