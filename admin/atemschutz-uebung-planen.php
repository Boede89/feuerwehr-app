<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Berechtigung prüfen
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

// Heutiges Datum für Vorauswahl
$today = date('Y-m-d');

// Status-Optionen für Filter
$statusOptions = [
    'Tauglich' => 'Tauglich',
    'Warnung' => 'Warnung', 
    'Abgelaufen' => 'Abgelaufen',
    'Übung abgelaufen' => 'Übung abgelaufen'
];

// Anzahl-Optionen für PA-Träger
$anzahlOptions = [
    'alle' => 'Alle verfügbaren PA-Träger',
    '1' => '1 PA-Träger',
    '2' => '2 PA-Träger',
    '3' => '3 PA-Träger',
    '4' => '4 PA-Träger',
    '5' => '5 PA-Träger',
    '6' => '6 PA-Träger',
    '7' => '7 PA-Träger',
    '8' => '8 PA-Träger',
    '9' => '9 PA-Träger',
    '10' => '10 PA-Träger'
];

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Übung planen - Atemschutz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%94%A5%3C/text%3E%3C/svg%3E">
    <style>
        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #dc3545, #c82333);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .feature-icon:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }
        .feature-icon i {
            font-size: 2.5rem;
            color: white;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        .form-section h5 {
            color: #495057;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .btn-custom {
            padding: 12px 30px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .status-tauglich { background-color: #d4edda; color: #155724; }
        .status-warnung { background-color: #fff3cd; color: #856404; }
        .status-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .status-uebung-abgelaufen { background-color: #f8d7da; color: #721c24; }
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
                    <a href="atemschutz.php" class="btn btn-outline-secondary me-3">
                        <i class="fas fa-arrow-left me-1"></i>Zurück
                    </a>
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-calendar-plus text-primary me-2"></i>Übung planen
                        </h2>
                        <p class="text-muted mb-0">Planen Sie eine Atemschutz-Übung und finden Sie passende PA-Träger</p>
                    </div>
                </div>

                <form id="uebungPlanenForm">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="form-section">
                                <h5><i class="fas fa-calendar-alt me-2"></i>Übungsdaten</h5>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="uebungsDatum" class="form-label">
                                                <i class="fas fa-calendar me-1"></i>Datum der Übung
                                            </label>
                                            <input type="date" class="form-control" id="uebungsDatum" name="uebungsDatum" 
                                                   value="<?php echo $today; ?>" required>
                                            <div class="form-text">Wählen Sie das Datum für die geplante Übung</div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="anzahlPaTraeger" class="form-label">
                                                <i class="fas fa-users me-1"></i>Anzahl PA-Träger
                                            </label>
                                            <select class="form-select" id="anzahlPaTraeger" name="anzahlPaTraeger" required>
                                                <?php foreach ($anzahlOptions as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $value === 'alle' ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Wählen Sie die benötigte Anzahl an PA-Trägern</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5><i class="fas fa-filter me-2"></i>Status-Filter</h5>
                                
                                <div class="mb-3">
                                    <label class="form-label">PA-Träger mit folgendem Status anzeigen:</label>
                                    <div class="row">
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <div class="col-md-6 col-lg-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           id="status_<?php echo strtolower(str_replace(' ', '_', $value)); ?>" 
                                                           name="statusFilter[]" value="<?php echo $value; ?>"
                                                           <?php echo in_array($value, ['Tauglich', 'Warnung', 'Übung abgelaufen']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label d-flex align-items-center" 
                                                           for="status_<?php echo strtolower(str_replace(' ', '_', $value)); ?>">
                                                        <span class="status-badge status-<?php 
                                                            // Explizite CSS-Klassen-Zuordnung
                                                            $cssClasses = [
                                                                'Tauglich' => 'tauglich',
                                                                'Warnung' => 'warnung', 
                                                                'Abgelaufen' => 'abgelaufen',
                                                                'Übung abgelaufen' => 'uebung-abgelaufen'
                                                            ];
                                                            echo $cssClasses[$value] ?? 'tauglich';
                                                        ?> me-2">
                                                            <?php echo $label; ?>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Wählen Sie die Status aus, für die PA-Träger angezeigt werden sollen</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="feature-icon mb-3">
                                        <i class="fas fa-search"></i>
                                    </div>
                                    <h5 class="card-title">PA-Träger suchen</h5>
                                    <p class="card-text text-muted">Klicken Sie auf den Button, um passende PA-Träger für Ihre Übung zu finden.</p>
                                    <button type="submit" class="btn btn-primary btn-custom w-100">
                                        <i class="fas fa-search me-2"></i>PA-Träger suchen
                                    </button>
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm mt-3">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-info-circle me-2"></i>Hinweise
                                    </h6>
                                    <ul class="list-unstyled mb-0 small">
                                        <li class="mb-2">
                                            <i class="fas fa-check text-success me-2"></i>
                                            <strong>Tauglich:</strong> Alle Zertifikate gültig
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                            <strong>Warnung:</strong> Zertifikat läuft bald ab
                                        </li>
                                        <li class="mb-2">
                                            <i class="fas fa-times text-danger me-2"></i>
                                            <strong>Abgelaufen:</strong> Zertifikat ist abgelaufen
                                        </li>
                                        <li class="mb-0">
                                            <i class="fas fa-ban text-secondary me-2"></i>
                                            <strong>Übung abgelaufen:</strong> Übungszertifikat abgelaufen
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
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
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM geladen, initialisiere Übung planen...');
            
            // Heutiges Datum als Standard setzen, falls nicht bereits gesetzt
            const datumInput = document.getElementById('uebungsDatum');
            if (datumInput) {
                if (!datumInput.value) {
                    datumInput.value = new Date().toISOString().split('T')[0];
                }
                console.log('Datum-Input gefunden:', datumInput.value);
            } else {
                console.error('Datum-Input nicht gefunden!');
            }
            
            // Formular-Event-Listener hinzufügen
            const form = document.getElementById('uebungPlanenForm');
            console.log('Formular gefunden:', form);
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    console.log('Formular-Submit erkannt');
                    handleFormSubmit();
                });
                
                // Zusätzlicher Click-Event für den Button
                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        console.log('Button-Click erkannt');
                        handleFormSubmit();
                    });
                }
            } else {
                console.error('Formular nicht gefunden!');
            }
            
            function handleFormSubmit() {
                try {
                    console.log('Verarbeite Formular...');
                    
                    // Formular-Daten sammeln
                    const formData = new FormData(form);
                    const uebungsDatum = formData.get('uebungsDatum');
                    const anzahlPaTraeger = formData.get('anzahlPaTraeger');
                    const statusFilter = formData.getAll('statusFilter[]');
                    
                    console.log('Formular-Daten:', { uebungsDatum, anzahlPaTraeger, statusFilter });
                    
                    // Validierung
                    if (!uebungsDatum) {
                        alert('Bitte wählen Sie ein Übungsdatum aus.');
                        return;
                    }
                    
                    if (statusFilter.length === 0) {
                        alert('Bitte wählen Sie mindestens einen Status aus.');
                        return;
                    }
                    
                    // Daten für die Suche vorbereiten
                    const searchData = {
                        uebungsDatum: uebungsDatum,
                        anzahlPaTraeger: anzahlPaTraeger,
                        statusFilter: statusFilter
                    };
                    
                    console.log('Suche nach PA-Trägern:', searchData);
                    
                    // Hier wird später die Suchfunktion implementiert
                    alert('Die Suchfunktion wird in Kürze implementiert.\n\nGewählte Parameter:\n' + 
                          'Datum: ' + uebungsDatum + '\n' +
                          'Anzahl: ' + (anzahlPaTraeger === 'alle' ? 'Alle verfügbaren' : anzahlPaTraeger + ' PA-Träger') + '\n' +
                          'Status: ' + statusFilter.join(', '));
                } catch (error) {
                    console.error('Fehler beim Verarbeiten des Formulars:', error);
                    alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.');
                }
            }
        });
    </script>
</body>
</html>
