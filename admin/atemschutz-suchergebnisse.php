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

// SMTP-Einstellungen für E-Mail-Auswahl laden
$smtpFromEmail = '';
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_from_email' LIMIT 1");
    $stmt->execute();
    $setting = $stmt->fetch();
    if ($setting) {
        $smtpFromEmail = $setting['setting_value'];
    }
} catch (Exception $e) {
    // Fehler ignorieren
}

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
        
        .construction-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            pointer-events: none;
        }
        
        .construction-text {
            color: #ffc107;
            font-weight: bold;
            font-size: 16px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.8);
            transform: rotate(-15deg);
            background: rgba(255, 193, 7, 0.2);
            padding: 8px 16px;
            border: 2px solid #ffc107;
            border-radius: 4px;
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
                                    <div class="card h-100 text-center export-option" data-format="pdf" style="position: relative;">
                                        <div class="card-body">
                                            <i class="fas fa-download fa-3x text-danger mb-3"></i>
                                            <h6 class="card-title">PDF-Herunterladen</h6>
                                            <p class="card-text small text-muted">Lädt eine PDF-Datei mit der PA-Träger-Liste herunter</p>
                                        </div>
                                        <div class="construction-overlay">
                                            <div class="construction-text">Under Construction</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100 text-center export-option" data-format="print">
                                        <div class="card-body">
                                            <i class="fas fa-print fa-3x text-success mb-3"></i>
                                            <h6 class="card-title">Drucken</h6>
                                            <p class="card-text small text-muted">Öffnet die Liste direkt im Druckdialog</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100 text-center export-option" data-format="email" style="position: relative;">
                                        <div class="card-body">
                                            <i class="fas fa-envelope fa-3x text-primary mb-3"></i>
                                            <h6 class="card-title">E-Mail-Versand</h6>
                                            <p class="card-text small text-muted">Sendet die Liste per E-Mail an Empfänger</p>
                                        </div>
                                        <div class="construction-overlay">
                                            <div class="construction-text">Under Construction</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    
                    <!-- E-Mail-Einstellungen (versteckt) -->
                    <div id="emailSettings" class="mt-3" style="display: none;">
                        <hr>
                        <h6><i class="fas fa-envelope me-2"></i>E-Mail-Einstellungen</h6>
                        <div class="row">
                            <div class="col-md-12">
                                <label for="emailRecipients" class="form-label">Empfänger</label>
                                <select class="form-select" id="emailRecipients">
                                    <option value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name'] . ' (' . ($_SESSION['email'] ?? 'Keine E-Mail') . ')'); ?>
                                    </option>
                                    <?php if (!empty($smtpFromEmail) && $smtpFromEmail !== ($_SESSION['email'] ?? '')): ?>
                                    <option value="<?php echo htmlspecialchars($smtpFromEmail); ?>">
                                        SMTP-Absender (<?php echo htmlspecialchars($smtpFromEmail); ?>)
                                    </option>
                                    <?php endif; ?>
                                    <option value="custom">Manuell eingeben...</option>
                                </select>
                                <input type="email" class="form-control mt-2" id="customRecipients" 
                                       placeholder="empfaenger@example.com" style="display: none;">
                                <div class="form-text mt-1">Mehrere E-Mail-Adressen mit Komma trennen</div>
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
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 2.5&nbsp;&nbsp;Alle Rechte vorbehalten</p>
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
            
            // Empfänger-Auswahl
            document.getElementById('emailRecipients').addEventListener('change', function() {
                const customRecipients = document.getElementById('customRecipients');
                if (this.value === 'custom') {
                    customRecipients.style.display = 'block';
                    customRecipients.required = true;
                } else {
                    customRecipients.style.display = 'none';
                    customRecipients.required = false;
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
                    // Das ausgewählte Element als Parameter übergeben
                    const selectedOption = document.querySelector('.export-option.selected');
                    exportToFile(selectedFormat, selectedOption);
                }
                
                // Button nach 3 Sekunden zurücksetzen
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 3000);
            });
        });
        
        function exportToFile(format, clickedElement) {
            if (format === 'print') {
                // PDF-Drucken: Direkt im neuen Fenster öffnen
                printPDF();
            } else {
                // PDF-Herunterladen: Echte PDF-Generierung
                const results = <?php echo json_encode($searchResults); ?>;
                const params = <?php echo json_encode($searchParams); ?>;
                
                // Button während der Generierung deaktivieren
                const button = clickedElement ? clickedElement.closest('.export-option') : null;
                let originalContent = '';
                
                if (button) {
                    originalContent = button.innerHTML;
                    button.innerHTML = '<div class="card-body"><i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i><h6 class="card-title">PDF wird erstellt...</h6></div>';
                    button.style.pointerEvents = 'none';
                }
                
                // PDF über API generieren
                fetch('../api/generate-pdf.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        results: results,
                        params: params
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('PDF-Generierung fehlgeschlagen');
                    }
                    return response.blob();
                })
                .then(response => {
                    // HTML-Content als Download bereitstellen
                    return response.text();
                })
                .then(html => {
                    // HTML als Datei herunterladen
                    const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `PA_Traeger_Liste_${new Date().toISOString().slice(0, 16).replace('T', '_')}.html`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                })
                .catch(error => {
                    console.error('PDF-Generierung Fehler:', error);
                    alert('Fehler beim Erstellen der PDF-Datei: ' + error.message);
                })
                .finally(() => {
                    // Button wieder aktivieren
                    if (button && originalContent) {
                        button.innerHTML = originalContent;
                        button.style.pointerEvents = 'auto';
                    }
                });
            }
            
            // Modal schließen
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
        }
        
        function printPDF() {
            // PDF-Drucken: HTML direkt im neuen Fenster öffnen
            const results = <?php echo json_encode($searchResults ?? []); ?>;
            const params = <?php echo json_encode($searchParams ?? []); ?>;
            
            // Debug: Daten prüfen
            console.log('Druck-Daten:', results);
            console.log('Parameter:', params);
            
            if (!results || results.length === 0) {
                alert('Keine Ergebnisse zum Drucken verfügbar.');
                return;
            }
            
            // HTML für Druck generieren
            const printHTML = generatePrintHTML(results, params);
            
            // Neues Fenster öffnen und HTML schreiben
            const printWindow = window.open('', '_blank', 'width=800,height=600');
            
            if (!printWindow) {
                alert('Pop-up-Blocker verhindert das Öffnen des Druckfensters. Bitte erlauben Sie Pop-ups für diese Seite.');
                return;
            }
            
            // HTML in das neue Fenster schreiben
            printWindow.document.open();
            printWindow.document.write(printHTML);
            printWindow.document.close();
            
            // Warten bis das Dokument vollständig geladen ist, dann drucken
            // Verwende sowohl onload als auch setTimeout als Fallback
            let printed = false;
            
            const doPrint = () => {
                if (!printed) {
                    printed = true;
                    try {
                        printWindow.focus();
                        printWindow.print();
                    } catch (e) {
                        console.error('Druckfehler:', e);
                        alert('Drucken fehlgeschlagen. Bitte verwenden Sie die Browser-Druckfunktion (Strg+P oder Cmd+P) im geöffneten Fenster.');
                    }
                }
            };
            
            // Versuche onload (funktioniert wenn das Dokument noch nicht geladen ist)
            if (printWindow.document.readyState === 'complete') {
                // Dokument ist bereits geladen
                setTimeout(doPrint, 100);
            } else {
                // Warte auf onload
                printWindow.onload = function() {
                    setTimeout(doPrint, 100);
                };
                
                // Fallback: Falls onload nicht funktioniert
                setTimeout(doPrint, 1000);
            }
        }
        
        function generatePrintHTML(results, params) {
            const uebungsDatum = params.uebungsDatum || '';
            const anzahl = params.anzahlPaTraeger || 'alle';
            const statusFilter = params.statusFilter || [];
            
            let html = `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PA-Träger Liste - Druck</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.4;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #dc3545;
            padding-bottom: 20px;
        }
        .header h1 { 
            color: #dc3545; 
            margin-bottom: 10px; 
            font-size: 28px;
        }
        .header h2 { 
            color: #6c757d; 
            font-size: 18px; 
            margin-bottom: 20px; 
        }
        .summary { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            border-left: 4px solid #dc3545;
        }
        .summary h3 { 
            margin-top: 0; 
            color: #495057; 
            font-size: 16px;
        }
        .summary p { 
            margin: 5px 0; 
            font-size: 14px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
            font-size: 12px;
        }
        th, td { 
            border: 1px solid #dee2e6; 
            padding: 8px; 
            text-align: left; 
            vertical-align: top;
        }
        th { 
            background-color: #e9ecef; 
            font-weight: bold; 
            font-size: 13px;
        }
        td strong {
            font-weight: bold;
            color: #212529;
        }
        .status-badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 11px; 
            font-weight: bold; 
            display: inline-block;
        }
        .status-tauglich { background-color: #d4edda; color: #155724; }
        .status-warnung { background-color: #fff3cd; color: #856404; }
        .status-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .status-uebung-abgelaufen { background-color: #f8d7da; color: #721c24; }
        .footer { 
            margin-top: 30px; 
            text-align: center; 
            color: #6c757d; 
            font-size: 12px; 
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔥 Feuerwehr App</h1>
        <h2>PA-Träger Liste für Übung</h2>
    </div>
    
    <div class="summary">
        <h3>Suchkriterien</h3>
        <p><strong>Übungsdatum:</strong> ${uebungsDatum ? new Date(uebungsDatum).toLocaleDateString('de-DE') : 'Nicht angegeben'}</p>
        <p><strong>Anzahl:</strong> ${anzahl === 'alle' ? 'Alle verfügbaren' : anzahl + ' PA-Träger'}</p>
        <p><strong>Status-Filter:</strong> ${statusFilter && statusFilter.length > 0 ? statusFilter.join(', ') : 'Keine Filter'}</p>
        <p><strong>Gefunden:</strong> ${results.length} PA-Träger</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Status</th>
                <th>Strecke</th>
                <th>G26.3</th>
                <th>Übung/Einsatz</th>
            </tr>
        </thead>
        <tbody>`;
            
            results.forEach((traeger, index) => {
                const statusClass = getStatusClass(traeger.status || 'Tauglich');
                // Name kann in verschiedenen Feldern sein: name, first_name/last_name, oder full_name
                let fullName = 'Name nicht verfügbar';
                if (traeger.name) {
                    fullName = traeger.name;
                } else if (traeger.first_name && traeger.last_name) {
                    fullName = traeger.last_name + ', ' + traeger.first_name;
                } else if (traeger.full_name) {
                    fullName = traeger.full_name;
                } else if (traeger.first_name) {
                    fullName = traeger.first_name;
                }
                
                // Datum formatieren
                const formatDate = (dateStr) => {
                    if (!dateStr) return 'N/A';
                    try {
                        const date = new Date(dateStr);
                        if (isNaN(date.getTime())) return 'N/A';
                        return date.toLocaleDateString('de-DE');
                    } catch (e) {
                        return dateStr; // Fallback: Original-String zurückgeben
                    }
                };
                
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td><strong>${fullName}</strong></td>
                        <td><span class="status-badge ${statusClass}">${traeger.status || 'Tauglich'}</span></td>
                        <td>${formatDate(traeger.strecke_am)}</td>
                        <td>${formatDate(traeger.g263_am)}</td>
                        <td>${formatDate(traeger.uebung_am)}${traeger.uebung_bis ? ' (bis ' + formatDate(traeger.uebung_bis) + ')' : ''}</td>
                    </tr>`;
            });
            
            html += `
        </tbody>
    </table>
    
    <div class="footer">
        <p>Erstellt am ${new Date().toLocaleDateString('de-DE')} ${new Date().toLocaleTimeString('de-DE')} | Feuerwehr App v2.5</p>
    </div>
</body>
</html>`;
            
            return html;
        }
        
        function getStatusClass(status) {
            const statusClasses = {
                'Tauglich': 'status-tauglich',
                'Warnung': 'status-warnung',
                'Abgelaufen': 'status-abgelaufen',
                'Übung abgelaufen': 'status-uebung-abgelaufen'
            };
            return statusClasses[status] || 'status-tauglich';
        }
        
        function exportViaEmail() {
            const recipientsSelect = document.getElementById('emailRecipients');
            const customRecipients = document.getElementById('customRecipients');
            const subject = document.getElementById('emailSubject').value;
            const message = document.getElementById('emailMessage').value;
            
            let recipients = recipientsSelect.value;
            if (recipientsSelect.value === 'custom') {
                recipients = customRecipients.value;
                if (!recipients) {
                    alert('Bitte geben Sie mindestens eine E-Mail-Adresse ein.');
                    return;
                }
            }
            
            if (!recipients) {
                alert('Bitte wählen Sie einen Empfänger aus.');
                return;
            }
            
            const emailData = {
                recipients: recipients.split(',').map(email => email.trim()),
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
