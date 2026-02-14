<?php
/**
 * Formulare-Übersicht: Liste aller aktiven Formulare zum Ausfüllen.
 * Nur für eingeloggte Benutzer.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('formulare.php'));
    exit;
}

// Tabellen existieren ggf. noch nicht (werden im Formularcenter angelegt)
$forms = [];
try {
    $stmt = $db->query("SELECT id, title, description FROM app_forms WHERE is_active = 1 ORDER BY title");
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle app_forms existiert evtl. noch nicht
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulare - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .feature-icon { height: 80px; display: flex; align-items: center; justify-content: center; }
        .feature-icon i { font-size: 3rem; }
        .feature-card .card-body { display: flex; flex-direction: column; }
        .feature-card .card-text { flex-grow: 1; }
        .clickable-card { transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer; }
        .clickable-card:hover { transform: translateY(-5px); box-shadow: 0 8px 16px rgba(0,0,0,0.15) !important; }
        .clickable-card a { color: inherit; text-decoration: none; }
        .clickable-card:hover .card-title { color: #0d6efd; }
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
                    <?php if (is_logged_in()): ?>
                    <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="login.php"><i class="fas fa-sign-in-alt"></i> Anmelden</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-file-alt"></i> Formulare</h3>
                        <p class="text-muted mb-0">Wählen Sie ein Formular aus, das Sie ausfüllen möchten</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <!-- Anwesenheitsliste (fixer Eintrag) -->
                            <div class="col-md-6 col-lg-4">
                                <a href="anwesenheitsliste.php" class="text-decoration-none">
                                    <div class="card h-100 shadow-sm feature-card clickable-card">
                                        <div class="card-body text-center p-4 d-flex flex-column">
                                            <div class="feature-icon mb-3">
                                                <i class="fas fa-clipboard-list text-primary"></i>
                                            </div>
                                            <h5 class="card-title">Anwesenheitsliste</h5>
                                            <p class="card-text text-muted small">Anwesenheit bei Diensten und Einsätzen erfassen. Vorschlag aus dem Dienstplan für den Tag.</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <?php if (!empty($forms)): ?>
                                <?php foreach ($forms as $form): ?>
                                <div class="col-md-6 col-lg-4">
                                    <a href="formulare-ausfuellen.php?id=<?php echo (int)$form['id']; ?>" class="text-decoration-none">
                                        <div class="card h-100 shadow-sm feature-card clickable-card">
                                            <div class="card-body text-center p-4 d-flex flex-column">
                                                <div class="feature-icon mb-3">
                                                    <i class="fas fa-file-alt text-primary"></i>
                                                </div>
                                                <h5 class="card-title"><?php echo htmlspecialchars($form['title']); ?></h5>
                                                <?php if (!empty($form['description'])): ?>
                                                    <p class="card-text text-muted small"><?php echo htmlspecialchars($form['description']); ?></p>
                                                <?php else: ?>
                                                    <p class="card-text text-muted small">Formular ausfüllen</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($forms)): ?>
                            <p class="text-muted mt-3 mb-0 small">Weitere Formulare können im Formularcenter angelegt werden.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 2.5&nbsp;&nbsp;Alle Rechte vorbehalten</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
