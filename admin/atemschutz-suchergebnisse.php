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
        .export-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .export-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .export-option.selected {
            border-color: #198754;
            background-color: #f8fff9;
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
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportModal">
                                <i class="fas fa-download me-2"></i>Ergebnisse exportieren
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="exportModalLabel">
                        <i class="fas fa-download me-2"></i>Ergebnisse exportieren
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 text-center export-option" data-format="pdf">
                                <div class="card-body">
                                    <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                    <h6 class="card-title">PDF-Liste</h6>
                                    <p class="card-text small text-muted">Erstellt eine formatierte PDF-Datei mit der PA-Träger-Liste</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 text-center export-option" data-format="excel">
                                <div class="card-body">
                                    <i class="fas fa-file-excel fa-3x text-success mb-3"></i>
                                    <h6 class="card-title">Excel-Export</h6>
                                    <p class="card-text small text-muted">Exportiert die Daten als Excel-Tabelle (.xlsx)</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 text-center export-option" data-format="email">
                                <div class="card-body">
                                    <i class="fas fa-envelope fa-3x text-primary mb-3"></i>
                                    <h6 class="card-title">E-Mail-Versand</h6>
                                    <p class="card-text small text-muted">Sendet die Liste per E-Mail an Empfänger</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- E-Mail-Einstellungen (versteckt) -->
                    <div id="emailSettings" class="mt-3" style="display: none;">
                        <hr>
                        <h6><i class="fas fa-envelope me-2"></i>E-Mail-Einstellungen</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="emailRecipients" class="form-label">Empfänger</label>
                                <input type="email" class="form-control" id="emailRecipients" 
                                       value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" 
                                       placeholder="email@example.com" multiple>
                                <div class="form-text">Mehrere E-Mail-Adressen mit Komma trennen</div>
                            </div>
                            <div class="col-md-6">
                                <label for="emailSender" class="form-label">Absender</label>
                                <select class="form-select" id="emailSender">
                                    <option value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name'] . ' (' . ($_SESSION['email'] ?? 'Keine E-Mail') . ')'); ?>
                                    </option>
                                    <option value="custom">Manuell eingeben...</option>
                                </select>
                                <input type="email" class="form-control mt-2" id="customSender" 
                                       placeholder="absender@example.com" style="display: none;">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label for="emailSubject" class="form-label">Betreff</label>
                                <input type="text" class="form-control" id="emailSubject" 
                                       value="PA-Träger Liste - Übung <?php echo formatDate($searchParams['uebungsDatum'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Anhang</label>
                                <div class="form-control-plaintext">
                                    <i class="fas fa-file-pdf text-danger me-2"></i>
                                    <span class="text-muted">PA-Träger-Liste.pdf (wird automatisch angehängt)</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label for="emailMessage" class="form-label">Nachricht</label>
                            <textarea class="form-control" id="emailMessage" rows="3" placeholder="Optionale Nachricht...">Hallo,

anbei die Liste der PA-Träger für die geplante Übung am <?php echo formatDate($searchParams['uebungsDatum'] ?? ''); ?>.

Die Liste enthält <?php echo count($searchResults); ?> PA-Träger und ist nach dem Übungszertifikat sortiert (älteste zuerst).

Mit freundlichen Grüßen
<?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Abbrechen
                    </button>
                    <button type="button" class="btn btn-success" id="confirmExport" disabled>
                        <i class="fas fa-download me-2"></i>Export starten
                    </button>
                </div>
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
        let selectedFormat = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Export-Optionen auswählbar machen
            document.querySelectorAll('.export-option').forEach(option => {
                option.addEventListener('click', function() {
                    // Alle Optionen deselektieren
                    document.querySelectorAll('.export-option').forEach(opt => opt.classList.remove('selected'));
                    
                    // Diese Option auswählen
                    this.classList.add('selected');
                    selectedFormat = this.dataset.format;
                    
                    // Export-Button aktivieren
                    document.getElementById('confirmExport').disabled = false;
                    
                    // E-Mail-Einstellungen anzeigen/verstecken
                    const emailSettings = document.getElementById('emailSettings');
                    if (selectedFormat === 'email') {
                        emailSettings.style.display = 'block';
                    } else {
                        emailSettings.style.display = 'none';
                    }
                });
            });
            
            // Absender-Auswahl
            document.getElementById('emailSender').addEventListener('change', function() {
                const customSender = document.getElementById('customSender');
                if (this.value === 'custom') {
                    customSender.style.display = 'block';
                    customSender.required = true;
                } else {
                    customSender.style.display = 'none';
                    customSender.required = false;
                }
            });
            
            // Export bestätigen
            document.getElementById('confirmExport').addEventListener('click', function() {
                if (!selectedFormat) return;
                
                const button = this;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Export läuft...';
                button.disabled = true;
                
                if (selectedFormat === 'email') {
                    exportViaEmail();
                } else {
                    exportToFile(selectedFormat);
                }
                
                // Button nach 3 Sekunden zurücksetzen
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 3000);
            });
        });
        
        function exportToFile(format) {
            const searchData = {
                format: format,
                results: <?php echo json_encode($searchResults); ?>,
                params: <?php echo json_encode($searchParams); ?>
            };
            
            fetch('../api/export-pa-traeger.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(searchData)
            })
            .then(response => {
                if (response.ok) {
                    return response.blob();
                }
                throw new Error('Export fehlgeschlagen');
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `pa-traeger-liste-${new Date().toISOString().split('T')[0]}.${format === 'pdf' ? 'pdf' : 'xlsx'}`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                // Modal schließen
                bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
            })
            .catch(error => {
                console.error('Export-Fehler:', error);
                alert('Fehler beim Export: ' + error.message);
            });
        }
        
        function exportViaEmail() {
            const recipients = document.getElementById('emailRecipients').value;
            const subject = document.getElementById('emailSubject').value;
            const message = document.getElementById('emailMessage').value;
            const senderSelect = document.getElementById('emailSender');
            const customSender = document.getElementById('customSender');
            
            if (!recipients) {
                alert('Bitte geben Sie mindestens eine E-Mail-Adresse ein.');
                return;
            }
            
            let senderEmail = senderSelect.value;
            if (senderSelect.value === 'custom') {
                senderEmail = customSender.value;
                if (!senderEmail) {
                    alert('Bitte geben Sie eine gültige Absender-E-Mail-Adresse ein.');
                    return;
                }
            }
            
            const emailData = {
                recipients: recipients.split(',').map(email => email.trim()),
                sender: senderEmail,
                subject: subject,
                message: message,
                results: <?php echo json_encode($searchResults); ?>,
                params: <?php echo json_encode($searchParams); ?>
            };
            
            fetch('../api/email-pa-traeger.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(emailData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('E-Mail wurde erfolgreich versendet!');
                    bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
                } else {
                    alert('Fehler beim E-Mail-Versand: ' + (data.error || 'Unbekannter Fehler'));
                }
            })
            .catch(error => {
                console.error('E-Mail-Fehler:', error);
                alert('Fehler beim E-Mail-Versand: ' + error.message);
            });
        }
    </script>
</body>
</html>
