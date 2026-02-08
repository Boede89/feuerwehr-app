<?php
/**
 * Anwesenheitsliste – Schritt 2: Weitere Daten eingeben und speichern.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : '';
$auswahl = isset($_GET['auswahl']) ? trim($_GET['auswahl']) : '';

if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    header('Location: anwesenheitsliste.php?error=datum');
    exit;
}

$dienstplan_id = null;
$typ = 'dienst';
$titel_anzeige = '';
$is_einsatz = ($auswahl === 'einsatz');

if ($is_einsatz) {
    $typ = 'einsatz';
    $titel_anzeige = 'Sonstige Anwesenheit (Einsatz, etc.)';
} else {
    $dienstplan_id = (int)$auswahl;
    if ($dienstplan_id <= 0) {
        header('Location: anwesenheitsliste.php?error=auswahl');
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT id, datum, bezeichnung, typ FROM dienstplan WHERE id = ?");
        $stmt->execute([$dienstplan_id]);
        $dienst = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dienst) {
            header('Location: anwesenheitsliste.php?error=auswahl');
            exit;
        }
        $typ_label = get_dienstplan_typ_label($dienst['typ'] ?? 'uebungsdienst');
        $titel_anzeige = $dienst['bezeichnung'] . ' (' . $typ_label . ' · ' . date('d.m.Y', strtotime($dienst['datum'])) . ')';
    } catch (Exception $e) {
        header('Location: anwesenheitsliste.php?error=auswahl');
        exit;
    }
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bemerkung = trim($_POST['bemerkung'] ?? '');
    $datum_post = trim($_POST['datum'] ?? '');
    $auswahl_post = trim($_POST['auswahl'] ?? '');
    $typ_sonstige = trim($_POST['typ_sonstige'] ?? '');
    $typ_sonstige_freitext = trim($_POST['typ_sonstige_freitext'] ?? '');
    if ($datum_post === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_post)) {
        $error = 'Ungültiges Datum.';
    } else {
        $dp_id = ($auswahl_post === 'einsatz') ? null : (int)$auswahl_post;
        $typ_save = ($auswahl_post === 'einsatz') ? 'einsatz' : 'dienst';
        if ($auswahl_post === 'einsatz') {
            $typen = get_dienstplan_typen();
            if ($typ_sonstige === '__custom__') {
                $bezeichnung_save = $typ_sonstige_freitext !== '' ? $typ_sonstige_freitext : 'Sonstiges';
            } else {
                $typen = get_dienstplan_typen_auswahl();
                $bezeichnung_save = $typen[$typ_sonstige] ?? 'Einsatz';
            }
        } else {
            $bezeichnung_save = null;
        }
        try {
            try {
                $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN bemerkung TEXT NULL");
            } catch (Exception $e) {
                // Spalte existiert bereits
            }
            $stmt = $db->prepare("
                INSERT INTO anwesenheitslisten (datum, dienstplan_id, typ, bezeichnung, user_id, bemerkung)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$datum_post, $dp_id, $typ_save, $bezeichnung_save, $_SESSION['user_id'], $bemerkung !== '' ? $bemerkung : null]);
            header('Location: anwesenheitsliste.php?message=erfolg');
            exit;
        } catch (Exception $e) {
            $error = 'Speichern fehlgeschlagen. Bitte versuchen Sie es erneut.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – weitere Daten - Feuerwehr App</title>
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
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
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
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste – weitere Daten</h3>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <div class="alert alert-light border mb-4">
                            <strong>Gewählt:</strong><br>
                            <span class="text-muted"><?php echo date('d.m.Y', strtotime($datum)); ?></span> — <?php echo htmlspecialchars($titel_anzeige); ?>
                        </div>

                        <form method="post">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($datum); ?>">
                            <input type="hidden" name="auswahl" value="<?php echo $is_einsatz ? 'einsatz' : (int)$dienstplan_id; ?>">
                            <?php if ($is_einsatz): ?>
                            <div class="mb-4">
                                <label for="typ_sonstige" class="form-label">Typ</label>
                                <select class="form-select" id="typ_sonstige" name="typ_sonstige">
                                    <?php foreach (get_dienstplan_typen_auswahl() as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === 'einsatz' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__">— Anderer Typ (Freitext) —</option>
                                </select>
                                <div class="mt-2" id="typ_sonstige_freitext_wrap" style="display: none;">
                                    <input type="text" class="form-control" id="typ_sonstige_freitext" name="typ_sonstige_freitext" placeholder="Typ eingeben (z. B. Lehrgang, Versammlung)">
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="mb-4">
                                <label for="bemerkung" class="form-label">Bemerkung (optional)</label>
                                <textarea class="form-control" id="bemerkung" name="bemerkung" rows="3" placeholder="z. B. kurze Anmerkung zur Anwesenheitsliste"></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Anwesenheitsliste speichern
                                </button>
                                <a href="anwesenheitsliste.php" class="btn btn-secondary">Zurück</a>
                            </div>
                        </form>
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
    <?php if ($is_einsatz): ?>
    <script>
        document.getElementById('typ_sonstige').addEventListener('change', function() {
            var wrap = document.getElementById('typ_sonstige_freitext_wrap');
            wrap.style.display = this.value === '__custom__' ? 'block' : 'none';
        });
    </script>
    <?php endif; ?>
</body>
</html>
