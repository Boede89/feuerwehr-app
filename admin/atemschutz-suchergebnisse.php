<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Berechtigung prüfen
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

// Suchergebnisse aus Session laden (wurden von der Suche gesetzt)
$searchResults = $_SESSION['pa_traeger_search_results'] ?? [];
$searchParams = $_SESSION['pa_traeger_search_params'] ?? [];

// Session-Daten löschen
unset($_SESSION['pa_traeger_search_results']);
unset($_SESSION['pa_traeger_search_params']);

if (empty($searchResults)) {
    header('Location: atemschutz-uebung-planen.php');
    exit;
}

// Status-Badge-Klassen
function getStatusClass($status) {
    switch ($status) {
        case 'Tauglich': return 'bg-success';
        case 'Warnung': return 'bg-warning text-dark';
        case 'Abgelaufen': return 'bg-danger';
        case 'Übung abgelaufen': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

// Datum formatieren
function formatDate($date) {
    return date('d.m.Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PA-Träger Suchergebnisse - Atemschutz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%94%A5%3C/text%3E%3C/svg%3E">
    <style>
        .status-badge {
            font-size: 0.8rem;
            padding: 4px 8px;
        }
        .certificate-date {
            font-weight: 600;
        }
        .search-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire me-2"></i>Feuerwehr App
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i>
                    <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                </span>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex align-items-center mb-4">
                    <a href="atemschutz-uebung-planen.php" class="btn btn-outline-secondary me-3">
                        <i class="fas fa-arrow-left me-1"></i>Zurück zur Suche
                    </a>
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-search text-primary me-2"></i>PA-Träger Suchergebnisse
                        </h2>
                        <p class="text-muted mb-0">Gefundene Geräteträger für Ihre Übung</p>
                    </div>
                </div>

                <!-- Suchzusammenfassung -->
                <div class="search-summary">
                    <div class="row">
                        <div class="col-md-3">
                            <strong><i class="fas fa-calendar me-1"></i>Übungsdatum:</strong><br>
                            <span class="text-primary"><?php echo formatDate($searchParams['uebungsDatum'] ?? ''); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong><i class="fas fa-users me-1"></i>Anzahl:</strong><br>
                            <span class="text-primary">
                                <?php 
                                $anzahl = $searchParams['anzahlPaTraeger'] ?? 'alle';
                                echo $anzahl === 'alle' ? 'Alle verfügbaren' : $anzahl . ' PA-Träger';
                                ?>
                            </span>
                        </div>
                        <div class="col-md-3">
                            <strong><i class="fas fa-filter me-1"></i>Status:</strong><br>
                            <span class="text-primary"><?php echo implode(', ', $searchParams['statusFilter'] ?? []); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong><i class="fas fa-list me-1"></i>Gefunden:</strong><br>
                            <span class="text-success fw-bold"><?php echo count($searchResults); ?> PA-Träger</span>
                        </div>
                    </div>
                </div>

                <!-- Suchergebnisse -->
                <?php if (empty($searchResults)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Keine PA-Träger gefunden</h5>
                        <p class="text-muted">Versuchen Sie andere Suchkriterien oder erweitern Sie den Status-Filter.</p>
                        <a href="atemschutz-uebung-planen.php" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Neue Suche
                        </a>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>Gefundene PA-Träger
                                <span class="badge bg-primary ms-2"><?php echo count($searchResults); ?></span>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 60px;">#</th>
                                            <th>Name</th>
                                            <th>Status</th>
                                            <th>Strecke</th>
                                            <th>G26.3</th>
                                            <th>Übung/Einsatz</th>
                                            <th>E-Mail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($searchResults as $index => $traeger): ?>
                                        <tr>
                                            <td class="fw-bold text-muted"><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($traeger['name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo getStatusClass($traeger['status']); ?> status-badge">
                                                    <?php echo htmlspecialchars($traeger['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="certificate-date"><?php echo formatDate($traeger['strecke_am']); ?></span>
                                            </td>
                                            <td>
                                                <span class="certificate-date"><?php echo formatDate($traeger['g263_am']); ?></span>
                                            </td>
                                            <td>
                                                <span class="certificate-date"><?php echo formatDate($traeger['uebung_am']); ?></span>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar-alt me-1"></i>bis <?php echo formatDate($traeger['uebung_bis']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if (!empty($traeger['email'])): ?>
                                                    <a href="mailto:<?php echo htmlspecialchars($traeger['email']); ?>" class="text-decoration-none">
                                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($traeger['email']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Aktions-Buttons -->
                    <div class="row mt-4">
                        <div class="col-12 text-center">
                            <a href="atemschutz-uebung-planen.php" class="btn btn-primary me-2">
                                <i class="fas fa-search me-2"></i>Neue Suche
                            </a>
                            <button class="btn btn-success" onclick="exportResults()">
                                <i class="fas fa-download me-2"></i>Ergebnisse exportieren
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 2.1&nbsp;&nbsp;Alle Rechte vorbehalten</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportResults() {
            alert('Export-Funktion wird in Kürze implementiert!\n\nGeplante Formate:\n- PDF-Liste\n- Excel-Export\n- E-Mail-Versand');
        }
    </script>
</body>
</html>
