<?php
/**
 * Anwesenheitsliste: Dienste/Einsatz/Manuell auswählen, Vorschlag aus Dienstplan für den Tag.
 * Nur für eingeloggte Benutzer.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Tabellen anlegen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS dienstplan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL,
            typ VARCHAR(50) DEFAULT 'dienst',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS anwesenheitslisten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            dienstplan_id INT NULL,
            typ ENUM('dienst','einsatz','manuell') NOT NULL DEFAULT 'dienst',
            bezeichnung VARCHAR(255) NULL,
            bemerkung TEXT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (dienstplan_id) REFERENCES dienstplan(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            KEY idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS anwesenheitsliste_mitglieder (
                id INT AUTO_INCREMENT PRIMARY KEY,
                anwesenheitsliste_id INT NOT NULL,
                member_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (anwesenheitsliste_id) REFERENCES anwesenheitslisten(id) ON DELETE CASCADE,
                UNIQUE KEY unique_list_member (anwesenheitsliste_id, member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e2) {
        // members-Tabelle kann fehlen oder andere DB-Struktur
        error_log('Anwesenheitsliste_mitglieder Tabelle: ' . $e2->getMessage());
    }
} catch (Exception $e) {
    error_log('Anwesenheitsliste Tabellen: ' . $e->getMessage());
}

$message = '';
$error = '';
if (isset($_GET['message']) && $_GET['message'] === 'erfolg') {
    $message = 'Anwesenheitsliste wurde angelegt.';
}
// Datum: GET (z. B. nach Schritt 2) oder heute
$datum = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['datum'])) {
    $datum = trim($_POST['datum']);
} elseif (!empty($_GET['datum'])) {
    $d = trim($_GET['datum']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $datum = $d;
    }
} else {
    $datum = date('Y-m-d');
}

// Dienste für gewähltes Datum laden (nur solche, für die noch KEINE Anwesenheitsliste existiert)
$dienste_fuer_tag = [];
$vorschlag = null;
try {
    $stmt = $db->prepare("
        SELECT d.id, d.bezeichnung, d.typ, d.datum
        FROM dienstplan d
        LEFT JOIN anwesenheitslisten a ON a.dienstplan_id = d.id
        WHERE d.datum = ? AND a.id IS NULL
        ORDER BY d.bezeichnung
    ");
    $stmt->execute([$datum]);
    $dienste_fuer_tag = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $vorschlag = $dienste_fuer_tag[0] ?? null;
} catch (Exception $e) {
    // Tabelle kann fehlen
}

// Alle Dienste ohne Anwesenheitsliste (für "Anderen Dienst auswählen") - nur bis heute, keine Zukunft
$andere_dienste = [];
try {
    $stmt = $db->prepare("
        SELECT d.id, d.datum, d.bezeichnung
        FROM dienstplan d
        LEFT JOIN anwesenheitslisten a ON a.dienstplan_id = d.id
        WHERE a.id IS NULL AND d.datum <= ?
        ORDER BY d.datum DESC, d.bezeichnung
    ");
    $stmt->execute([$datum]);
    $andere_dienste = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// Speichern erfolgt auf der nächsten Seite (anwesenheitsliste-eingaben.php)

// Letzte Anwesenheitslisten (Übersicht)
$letzte_listen = [];
try {
    $stmt = $db->query("
        SELECT a.*, d.bezeichnung AS dienst_bezeichnung
        FROM anwesenheitslisten a
        LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
        ORDER BY a.created_at DESC
        LIMIT 20
    ");
    $letzte_listen = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .anwesenheits-btn {
            min-height: 160px;
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .anwesenheits-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        .anwesenheits-btn .feature-icon {
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .anwesenheits-btn .feature-icon i {
            font-size: 2.25rem;
        }
        .anwesenheits-btn .card-title {
            font-size: 1.1rem;
            font-weight: 600;
        }
    </style>
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
                        <h3 class="mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste</h3>
                        <p class="text-muted mb-0 mt-1">Wählen Sie den Dienst oder die Art der Anwesenheitsliste für das gewünschte Datum.</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <label class="form-label">Anwesenheitsliste für heute</label>
                            <div class="row g-3">
                                <?php if ($vorschlag): ?>
                                    <div class="col-12 col-md-4">
                                        <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($datum); ?>&auswahl=<?php echo (int)$vorschlag['id']; ?>" class="btn btn-primary w-100 h-100 anwesenheits-btn text-decoration-none">
                                            <div class="feature-icon mb-2"><i class="fas fa-check"></i></div>
                                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($vorschlag['bezeichnung']); ?></h5>
                                            <p class="mb-0 small opacity-90"><?php echo htmlspecialchars(get_dienstplan_typ_label($vorschlag['typ'] ?? 'uebungsdienst')); ?> · <?php echo date('d.m.Y', strtotime($vorschlag['datum'])); ?></p>
                                            <small class="d-block mt-1 opacity-75">(Vorschlag für heute)</small>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12 col-md-4">
                                    <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($datum); ?>&auswahl=einsatz" class="btn btn-outline-danger w-100 h-100 anwesenheits-btn text-decoration-none">
                                        <div class="feature-icon mb-2"><i class="fas fa-exclamation-triangle"></i></div>
                                        <h5 class="card-title mb-0">Einsatz oder Manuelle Anwesenheit</h5>
                                    </a>
                                </div>
                                <div class="col-12 col-md-4">
                                    <button type="button" class="btn btn-outline-primary w-100 h-100 anwesenheits-btn" data-bs-toggle="modal" data-bs-target="#andereDiensteModal">
                                        <div class="feature-icon mb-2"><i class="fas fa-list"></i></div>
                                        <h5 class="card-title mb-0">Anderen Dienst auswählen</h5>
                                    </button>
                                </div>
                            </div>
                            <p class="text-muted small mt-2 mb-0">Wählen Sie eine Option – Sie werden zur Eingabe weitergeleitet.</p>
                        </div>

                        <a href="formulare.php" class="btn btn-link">Zurück zu Formulare</a>

                        <!-- Modal: Anderen Dienst auswählen -->
                        <div class="modal fade" id="andereDiensteModal" tabindex="-1">
                            <div class="modal-dialog modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Anderen Dienst auswählen</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="text-muted small">Nur Dienste, für die noch keine Anwesenheitsliste existiert.</p>
                                        <?php if (empty($andere_dienste)): ?>
                                            <p class="text-muted">Keine weiteren Dienste zur Auswahl.</p>
                                        <?php else: ?>
                                            <div class="list-group">
                                                <?php foreach ($andere_dienste as $d): ?>
                                                    <a href="anwesenheitsliste-eingaben.php?datum=<?php echo urlencode($d['datum']); ?>&auswahl=<?php echo (int)$d['id']; ?>" class="list-group-item list-group-item-action">
                                                        <strong><?php echo date('d.m.Y', strtotime($d['datum'])); ?></strong> — <?php echo htmlspecialchars($d['bezeichnung']); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($letzte_listen)): ?>
                        <hr class="my-4">
                        <h5 class="h6 text-muted">Zuletzt angelegte Anwesenheitslisten</h5>
                        <ul class="list-group list-group-flush">
                            <?php foreach (array_slice($letzte_listen, 0, 10) as $l): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>
                                    <?php echo date('d.m.Y', strtotime($l['datum'])); ?>
                                    —
                                    <?php
                                    if ($l['typ'] === 'einsatz') echo 'Einsatz';
                                    elseif ($l['typ'] === 'manuell') echo htmlspecialchars($l['bezeichnung'] ?: 'Manuelle Anwesenheit');
                                    else echo htmlspecialchars($l['dienst_bezeichnung'] ?? 'Dienst');
                                    ?>
                                </span>
                                <small class="text-muted"><?php echo date('d.m. H:i', strtotime($l['created_at'])); ?></small>
                            </li>
                            <?php endforeach; ?>
                        </ul>
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
