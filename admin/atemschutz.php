<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Zugriff nur für Benutzer mit Atemschutz-Recht
if (!isset($_SESSION['user_id']) || !has_permission('atemschutz')) {
	header('Location: ../login.php?error=access_denied');
	exit;
}

// POST: Geräteträger hinzufügen
$addSuccess = null;
$addError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_traeger') {
	$firstName = trim($_POST['first_name'] ?? '');
	$lastName = trim($_POST['last_name'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$birthdate = trim($_POST['birthdate'] ?? '');
	$streckeAm = trim($_POST['strecke_am'] ?? '');
	$g263Am = trim($_POST['g263_am'] ?? '');
	$uebungAm = trim($_POST['uebung_am'] ?? '');

	if ($firstName === '' || $lastName === '' || $birthdate === '' || $streckeAm === '' || $g263Am === '' || $uebungAm === '') {
		$addError = 'Bitte alle Pflichtfelder ausfüllen (Vorname, Nachname, Geburtsdatum, Strecke Am, G26.3 Am, Übung/Einsatz Am).';
	} else {
		try {
			// Tabelle sicherstellen
			$db->exec(
				"CREATE TABLE IF NOT EXISTS atemschutz_traeger (
					id INT AUTO_INCREMENT PRIMARY KEY,
					first_name VARCHAR(100) NOT NULL,
					last_name VARCHAR(100) NOT NULL,
					email VARCHAR(255) NULL,
					birthdate DATE NOT NULL,
					strecke_am DATE NOT NULL,
					g263_am DATE NOT NULL,
					uebung_am DATE NOT NULL,
					status VARCHAR(50) NOT NULL DEFAULT 'Aktiv',
					created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
			);

			$stmt = $db->prepare("INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv')");
			$stmt->execute([$firstName, $lastName, ($email !== '' ? $email : null), $birthdate, $streckeAm, $g263Am, $uebungAm]);
			$addSuccess = 'Geräteträger erfolgreich angelegt.';
		} catch (Exception $e) {
			$addError = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
		}
	}
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atemschutz – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0"><i class="fas fa-lungs"></i> Atemschutz</h1>
        </div>

		<div class="row g-4 mb-4">
            <div class="col-12 col-md-6">
				<button class="btn btn-primary w-100 py-4" id="btnAddTraeger" data-bs-toggle="modal" data-bs-target="#addTraegerModal">
                    <i class="fas fa-user-plus fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Geräteträger hinzufügen</span>
                </button>
            </div>
            <div class="col-12 col-md-6">
                <a class="btn btn-outline-primary w-100 py-4" href="/admin/atemschutz-liste.php" id="btnShowListLink">
                    <i class="fas fa-list fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Aktuelle Liste anzeigen</span>
                </a>
            </div>
            <div class="col-12 col-md-6">
                <button class="btn btn-outline-success w-100 py-4" id="btnPlanTraining">
                    <i class="fas fa-calendar-plus fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Übung planen</span>
                </button>
            </div>
            <div class="col-12 col-md-6">
                <button class="btn btn-outline-secondary w-100 py-4" id="btnRecordData">
                    <i class="fas fa-pen-to-square fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Daten hinterlegen</span>
                </button>
            </div>
        </div>

		<div class="alert alert-info">
			Funktionen werden als nächstes implementiert. Wählen Sie einen Button, um fortzufahren.
		</div>

		<?php if (!empty($addSuccess)): ?>
			<div class="alert alert-success"><?php echo htmlspecialchars($addSuccess); ?></div>
		<?php endif; ?>
		<?php if (!empty($addError)): ?>
			<div class="alert alert-danger"><?php echo htmlspecialchars($addError); ?></div>
		<?php endif; ?>
    </div>

	<!-- Modal: Geräteträger hinzufügen -->
	<div class="modal fade" id="addTraegerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header bg-primary text-white">
					<h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> Geräteträger hinzufügen</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<form method="post" action="atemschutz.php">
					<input type="hidden" name="action" value="add_traeger">
					<div class="modal-body">
						<div class="row g-3">
							<div class="col-12">
								<div class="border rounded p-3 bg-light">
									<div class="row g-3">
										<div class="col-12 col-md-6">
											<label class="form-label">Vorname <span class="text-danger">*</span></label>
											<div class="input-group">
												<span class="input-group-text"><i class="fas fa-user"></i></span>
												<input type="text" class="form-control" name="first_name" placeholder="Max" required>
											</div>
										</div>
										<div class="col-12 col-md-6">
											<label class="form-label">Nachname <span class="text-danger">*</span></label>
											<div class="input-group">
												<span class="input-group-text"><i class="fas fa-user"></i></span>
												<input type="text" class="form-control" name="last_name" placeholder="Mustermann" required>
											</div>
										</div>
										<div class="col-12">
											<label class="form-label">E-Mail (optional)</label>
											<div class="input-group">
												<span class="input-group-text"><i class="fas fa-envelope"></i></span>
												<input type="email" class="form-control" name="email" placeholder="name@beispiel.de">
											</div>
										</div>
										<div class="col-12 col-md-6">
											<label class="form-label">Geburtsdatum <span class="text-danger">*</span></label>
											<div class="input-group">
												<span class="input-group-text"><i class="fas fa-cake-candles"></i></span>
												<input type="date" class="form-control" name="birthdate" required>
											</div>
											<div class="form-text">Alter wird automatisch berechnet.</div>
										</div>
									</div>
								</div>
							</div>

							<div class="col-12">
								<div class="border rounded p-3">
									<h6 class="mb-3"><i class="fas fa-road me-2"></i> Strecke</h6>
									<div class="row g-3">
										<div class="col-12 col-md-6">
											<label class="form-label">Am <span class="text-danger">*</span></label>
											<input type="date" class="form-control" name="strecke_am" required>
											<div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
										</div>
									</div>
								</div>
							</div>

							<div class="col-12">
								<div class="border rounded p-3">
									<h6 class="mb-3"><i class="fas fa-stethoscope me-2"></i> G26.3</h6>
									<div class="row g-3">
										<div class="col-12 col-md-6">
											<label class="form-label">Am <span class="text-danger">*</span></label>
											<input type="date" class="form-control" name="g263_am" required>
											<div class="form-text">Bis-Datum: unter 50 Jahre +3 Jahre, ab 50 +1 Jahr.</div>
										</div>
									</div>
								</div>
							</div>

							<div class="col-12">
								<div class="border rounded p-3">
									<h6 class="mb-3"><i class="fas fa-dumbbell me-2"></i> Übung/Einsatz</h6>
									<div class="row g-3">
										<div class="col-12 col-md-6">
											<label class="form-label">Am <span class="text-danger">*</span></label>
											<input type="date" class="form-control" name="uebung_am" required>
											<div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
						<button type="submit" class="btn btn-primary">Speichern</button>
					</div>
				</form>
			</div>
		</div>
	</div>

    <script>
    // Platzhalter-Handler – werden später mit Logik hinterlegt
    document.addEventListener('DOMContentLoaded', function(){
        const onClickInfo = (msg) => () => alert(msg + "\n(Funktion folgt)");
        const q = (id) => document.getElementById(id);
        // Button-Aktionen: Liste öffnet neue Seite
        // Kein JS-Redirect nötig – echter Link wird verwendet

        // Platzhalter für andere Buttons (btnAddTraeger öffnet Modal, daher kein Alert)
        const other = {
            btnPlanTraining: 'Übung planen',
            btnRecordData: 'Daten hinterlegen'
        };
        Object.entries(other).forEach(([id,label])=>{ const el=q(id); if(el) el.addEventListener('click', onClickInfo(label)); });
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


