<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-home"></i> Startseite
                        </a>
                    </li>
                    <?php if (is_logged_in()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="admin/dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
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
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">
                                <i class="fas fa-sign-in-alt"></i> Anmelden
                            </a>
                        </li>
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
                        <h3 class="mb-0">
                            <i class="fas fa-file-alt"></i> Formulare
                        </h3>
                        <p class="text-muted mb-0">Wählen Sie ein Formular aus, das Sie ausfüllen möchten</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <!-- Mängelbericht -->
                            <div class="col-md-6 col-lg-4">
                                <div class="card h-100 shadow-sm feature-card clickable-card" data-bs-toggle="modal" data-bs-target="#maengelberichtModal" style="cursor: pointer;">
                                    <div class="card-body text-center p-4 d-flex flex-column">
                                        <div class="feature-icon mb-3">
                                            <i class="fas fa-exclamation-triangle text-warning"></i>
                                        </div>
                                        <h5 class="card-title">Mängelbericht</h5>
                                        <p class="card-text">Erstellen Sie einen Mängelbericht für Fahrzeuge, Geräte oder Ausrüstung.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 2.2&nbsp;&nbsp;Alle Rechte vorbehalten</p>
        </div>
    </footer>

    <!-- Mängelbericht Modal -->
    <div class="modal fade" id="maengelberichtModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Mängelbericht
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="maengelberichtForm">
                        <div class="mb-3">
                            <label for="maengelberichtDate" class="form-label">
                                <i class="fas fa-calendar me-1"></i>Datum <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="maengelberichtDate" name="date" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-warning" id="submitMaengelberichtBtn">
                        <i class="fas fa-paper-plane me-1"></i>Absenden
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Heutiges Datum setzen beim Öffnen des Modals
        document.getElementById('maengelberichtModal').addEventListener('show.bs.modal', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('maengelberichtDate').value = today;
        });
        
        // Formular zurücksetzen beim Schließen
        document.getElementById('maengelberichtModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('maengelberichtForm').reset();
        });
    </script>
    <style>
        .feature-icon {
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .feature-icon i {
            font-size: 3rem;
        }
        
        .feature-card .card-body {
            display: flex;
            flex-direction: column;
        }
        
        .feature-card .card-text {
            flex-grow: 1;
        }
        
        .clickable-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }
        
        .clickable-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15) !important;
        }
        
        .clickable-card a {
            color: inherit;
            text-decoration: none;
        }
        
        .clickable-card:hover .card-title {
            color: #0d6efd;
        }
    </style>
</body>
</html>

