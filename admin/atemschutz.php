<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Zugriff nur für Benutzer mit Atemschutz-Recht oder Admin-Berechtigung
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
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
			// Tabelle sicherstellen (nur anlegen, wenn sie noch nicht existiert)
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
					member_id INT NULL,
					created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
			);
			
			// member_id Spalte hinzufügen falls nicht vorhanden
			try {
				$db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
			} catch (Exception $e) {
				// Spalte existiert bereits, ignoriere Fehler
			}

			$stmt = $db->prepare("INSERT INTO atemschutz_traeger (first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktiv')");
			$stmt->execute([$firstName, $lastName, ($email !== '' ? $email : null), $birthdate, $streckeAm, $g263Am, $uebungAm]);
			$traeger_id = $db->lastInsertId();
			
			// Automatisch mit Mitglied verknüpfen
			link_traeger_to_member($traeger_id, $firstName, $lastName, ($email !== '' ? $email : null), $birthdate);
			
			$addSuccess = 'Geräteträger erfolgreich angelegt und mit Mitglied verknüpft.';
		} catch (Exception $e) {
			$addError = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
		}
	}
}

// POST: Mehrere Daten hinterlegen (Bulk-Update für Datumsspalten)
$bulkSuccess = null;
$bulkError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update_dates') {
	$field = $_POST['field'] ?? '';
	$dateValue = trim($_POST['date_value'] ?? '');
	$ids = $_POST['traeger_ids'] ?? [];
	
	$allowedFields = [ 'strecke_am', 'g263_am', 'uebung_am' ];
	if (!in_array($field, $allowedFields, true)) {
		$bulkError = 'Ungültiges Zielfeld.';
	} elseif ($dateValue === '') {
		$bulkError = 'Bitte ein Datum angeben.';
	} elseif (!is_array($ids) || count($ids) === 0) {
		$bulkError = 'Bitte mindestens einen Geräteträger auswählen.';
	} else {
		try {
			// Sicherstellen, dass Tabelle existiert
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
					member_id INT NULL,
					created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
			);
			
			// member_id Spalte hinzufügen falls nicht vorhanden
			try {
				$db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
			} catch (Exception $e) {
				// Spalte existiert bereits, ignoriere Fehler
			}
			
			$idsInt = array_map(static fn($v) => (int)$v, $ids);
			$idsInt = array_values(array_filter($idsInt, static fn($v) => $v > 0));
			if (count($idsInt) === 0) {
				throw new Exception('Keine gültigen IDs.');
			}
			$placeholders = implode(',', array_fill(0, count($idsInt), '?'));
			$sql = "UPDATE atemschutz_traeger SET {$field} = ? WHERE id IN ($placeholders)";
			$params = array_merge([$dateValue], $idsInt);
			$stmt = $db->prepare($sql);
			$stmt->execute($params);
			$bulkSuccess = 'Datum erfolgreich aktualisiert.';
		} catch (Exception $e) {
			$bulkError = 'Fehler beim Aktualisieren: ' . htmlspecialchars($e->getMessage());
		}
	}
}

// POST: Geräteträger löschen
$deleteSuccess = null;
$deleteError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_traeger') {
	$traeger_id = (int)($_POST['traeger_id'] ?? 0);
	
	if ($traeger_id <= 0) {
		$deleteError = 'Ungültige Geräteträger-ID.';
	} else {
		try {
			$db->beginTransaction();
			
			// Lade member_id vor dem Löschen
			$stmt = $db->prepare("SELECT member_id FROM atemschutz_traeger WHERE id = ?");
			$stmt->execute([$traeger_id]);
			$traeger = $stmt->fetch(PDO::FETCH_ASSOC);
			
			if (!$traeger) {
				$deleteError = 'Geräteträger nicht gefunden.';
				if ($db->inTransaction()) {
					$db->rollBack();
				}
			} else {
				// Lösche Geräteträger
				$stmt = $db->prepare("DELETE FROM atemschutz_traeger WHERE id = ?");
				$stmt->execute([$traeger_id]);
				
				// Deaktiviere PA-Träger Status im Mitglied (falls member_id vorhanden)
				if (!empty($traeger['member_id'])) {
					$stmt = $db->prepare("UPDATE members SET is_pa_traeger = 0 WHERE id = ?");
					$stmt->execute([$traeger['member_id']]);
				}
				
				if ($db->inTransaction()) {
					$db->commit();
				}
				$deleteSuccess = 'Geräteträger wurde erfolgreich gelöscht. Das Mitglied bleibt erhalten, aber der PA-Träger Status wurde deaktiviert.';
			}
		} catch (Exception $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			$deleteError = 'Fehler beim Löschen: ' . htmlspecialchars($e->getMessage());
			error_log("Fehler beim Löschen von Geräteträger: " . $e->getMessage());
		}
	}
}

// Automatische Synchronisation: Alle Geräteträger mit Mitgliedern verknüpfen
// Dies wird beim ersten Laden der Seite ausgeführt
try {
    sync_all_traeger_to_members();
} catch (Exception $e) {
    error_log("Fehler bei automatischer Synchronisation: " . $e->getMessage());
}

// POST: Übung planen – Filter und Vorschlagsliste erzeugen
$planInfo = null;
$planError = null;
$planResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'plan_training') {
	$planDate = trim($_POST['plan_date'] ?? '');
	$needCount = (int)($_POST['need_count'] ?? 0);
	$allAvailable = isset($_POST['all_available']) ? true : false;
	$statuses = $_POST['statuses'] ?? [];
	if ($planDate === '') { $planDate = date('Y-m-d'); }

	try {
		// Basis: alle Träger
		$stmt = $db->prepare("SELECT id, first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am FROM atemschutz_traeger");
		$stmt->execute();
		$all = $stmt->fetchAll(PDO::FETCH_ASSOC);
		
		$warnDays = 90;
		try {
			$s = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='atemschutz_warn_days' LIMIT 1");
			$s->execute();
			$val = $s->fetchColumn();
			if ($val !== false && is_numeric($val)) { $warnDays = (int)$val; }
		} catch (Exception $e) {}

		$now = new DateTime('today');
		foreach ($all as $row) {
			$age = 0; if (!empty($row['birthdate'])) { try { $age = (new DateTime($row['birthdate']))->diff($now)->y; } catch (Exception $e) {} }
			// Bis-Daten berechnen
			$streckeBis = !empty($row['strecke_am']) ? (new DateTime($row['strecke_am']))->modify('+1 year')->format('Y-m-d') : null;
			$g263Bis = null; if (!empty($row['g263_am'])) { $g = new DateTime($row['g263_am']); $g->modify(($age < 50 ? '+3 year' : '+1 year')); $g263Bis = $g->format('Y-m-d'); }
			$uebungBis = !empty($row['uebung_am']) ? (new DateTime($row['uebung_am']))->modify('+1 year')->format('Y-m-d') : null;
			// Status bestimmen analog Liste
			$streckeExpired=false; $g263Expired=false; $uebungExpired=false; $anyWarn=false;
			if ($streckeBis) { $diff=(int)$now->diff(new DateTime($streckeBis))->format('%r%a'); if ($diff<0) $streckeExpired=true; elseif ($diff<= $warnDays) $anyWarn=true; }
			if ($g263Bis) { $diff=(int)$now->diff(new DateTime($g263Bis))->format('%r%a'); if ($diff<0) $g263Expired=true; elseif ($diff<= $warnDays) $anyWarn=true; }
			if ($uebungBis) { $diff=(int)$now->diff(new DateTime($uebungBis))->format('%r%a'); if ($diff<0) $uebungExpired=true; elseif ($diff<= $warnDays) $anyWarn=true; }
			if ($streckeExpired || $g263Expired) { $status = 'abgelaufen'; }
			elseif ($uebungExpired) { $status = 'uebung_abgelaufen'; }
			elseif ($anyWarn) { $status = 'warnung'; }
			else { $status = 'tauglich'; }

			$row['_status'] = $status;
			$row['_uebung_am'] = $row['uebung_am'];
			$row['_name'] = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? ''));
			$row['_age'] = $age;
			$row['_uebung_am_sort'] = $row['uebung_am'] ?: '0000-01-01';
			$planResults[] = $row;
		}
		// Filter nach Status
		if (!is_array($statuses) || empty($statuses)) { $statuses = ['tauglich','warnung','uebung_abgelaufen']; }
		$statuses = array_map('strval', $statuses);
		$planResults = array_values(array_filter($planResults, static function($r) use ($statuses){ return in_array($r['_status'], $statuses, true); }));
		// Sortierung: ältestes uebung_am zuerst
		usort($planResults, static function($a,$b){ return strcmp($a['_uebung_am_sort'], $b['_uebung_am_sort']); });
		// Anzahl begrenzen wenn nicht alle
		if (!$allAvailable && $needCount > 0) {
			$planResults = array_slice($planResults, 0, $needCount);
		}
		$planInfo = 'Vorschlagsliste erstellt.';
	} catch (Exception $e) {
		$planError = 'Fehler bei der Planung: ' . htmlspecialchars($e->getMessage());
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
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%94%A5%3C/text%3E%3C/svg%3E">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <style>
    /* Sicherstellen, dass Modals über Navbar/anderen Elementen liegen */
    .modal { z-index: 2000; }
    .modal-backdrop { z-index: 1990; }
    </style>
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
            <h1 class="h3 mb-0"><i class="fas fa-user-shield"></i> Atemschutz</h1>
        </div>

		<div class="row g-4 mb-4">
            <div class="col-12 col-md-6 col-lg-4">
				<button class="btn btn-primary w-100 py-4" id="btnAddTraeger" data-bs-toggle="modal" data-bs-target="#addTraegerModal">
                    <i class="fas fa-user-plus fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Geräteträger hinzufügen</span>
                </button>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <a class="btn btn-outline-primary w-100 py-4" href="atemschutz-liste.php" id="btnShowListLink">
                    <i class="fas fa-list fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Aktuelle Liste anzeigen</span>
                </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <a class="btn btn-outline-success w-100 py-4" id="btnPlanTraining" href="atemschutz-uebung-planen.php">
                    <i class="fas fa-calendar-plus fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Übung planen</span>
                </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <a class="btn btn-outline-info w-100 py-4" href="atemschutz-strecke-planung.php">
                    <i class="fas fa-route fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Strecke - Terminplanung</span>
                </a>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
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
		<?php if (!empty($bulkSuccess)): ?>
			<div class="alert alert-success"><?php echo htmlspecialchars($bulkSuccess); ?></div>
		<?php endif; ?>
		<?php if (!empty($bulkError)): ?>
			<div class="alert alert-danger"><?php echo htmlspecialchars($bulkError); ?></div>
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

	<!-- Modal: Daten hinterlegen (Bulk) -->
	<div class="modal fade" id="bulkDataModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header bg-primary text-white">
					<h5 class="modal-title"><i class="fas fa-pen-to-square me-2"></i> Daten hinterlegen</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<form method="post" action="atemschutz.php">
					<input type="hidden" name="action" value="bulk_update_dates">
					<div class="modal-body">
						<div class="row g-4">
							<div class="col-12">
								<div class="border rounded p-3 bg-light">
									<h6 class="mb-3">
										<i class="fas fa-users me-2"></i> Geräteträger auswählen
										<span id="bulkSelectedCount" class="badge bg-secondary ms-2">0 ausgewählt</span>
									</h6>
									<div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
										<div id="bulkTraegerList">
											<div class="text-center text-muted">
												<i class="fas fa-spinner fa-spin"></i> Lade Geräteträger...
											</div>
										</div>
									</div>
									<!-- Versteckte Inputs für ausgewählte Geräteträger -->
									<div id="bulkTraegerInputs"></div>
								</div>
							<div class="col-12 col-md-6">
								<label class="form-label">Feld</label>
								<select class="form-select" name="field" required>
									<option value="strecke_am">Strecke – Am</option>
									<option value="g263_am">G26.3 – Am</option>
									<option value="uebung_am">Übung/Einsatz – Am</option>
								</select>
							</div>
							<div class="col-12 col-md-6">
								<label class="form-label">Neues Datum</label>
								<input type="date" class="form-control" name="date_value" required>
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

	<!-- Modal: Übung planen -->
	<div class="modal fade" id="planTrainingModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header bg-success text-white">
					<h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i> Übung planen</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<form method="post" action="atemschutz.php">
					<input type="hidden" name="action" value="plan_training">
					<div class="modal-body">
						<div class="row g-3">
							<div class="col-12 col-md-4">
								<label class="form-label">Übungsdatum</label>
								<input type="date" class="form-control" name="plan_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
							</div>
							<div class="col-12 col-md-4">
								<label class="form-label">Benötigte PA-Träger</label>
								<input type="number" min="0" class="form-control" name="need_count" placeholder="z.B. 4">
								<div class="form-check mt-2">
									<input class="form-check-input" type="checkbox" name="all_available" id="all_available">
									<label class="form-check-label" for="all_available">Alle verfügbaren auswählen</label>
								</div>
							</div>
							<div class="col-12 col-md-4">
								<label class="form-label">Status-Filter</label>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" name="statuses[]" id="st_tauglich" value="tauglich" checked>
									<label class="form-check-label" for="st_tauglich">Tauglich</label>
								</div>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" name="statuses[]" id="st_warnung" value="warnung" checked>
									<label class="form-check-label" for="st_warnung">Warnung</label>
								</div>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" name="statuses[]" id="st_ueb_abg" value="uebung_abgelaufen" checked>
									<label class="form-check-label" for="st_ueb_abg">Übung abgelaufen</label>
								</div>
							</div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
						<button type="submit" class="btn btn-success">Vorschlag erstellen</button>
					</div>
				</form>
			</div>
		</div>
	</div>

    <style>
        .traeger-card {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .traeger-card:hover .card {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .traeger-card .card {
            border: 2px solid #e9ecef;
            transition: all 0.2s ease;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .traeger-card .card.border-primary {
            border-color: #0d6efd !important;
            background-color: #0d6efd !important;
            color: white !important;
        }
        
        .traeger-card .card.border-light {
            border-color: #e9ecef !important;
            background-color: white !important;
            color: #212529 !important;
        }
        
        .traeger-card .card-title {
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        /* Mobile Optimierung */
        @media (max-width: 768px) {
            .traeger-card .card {
                min-height: 50px;
                padding: 0.75rem;
            }
            
            .traeger-card .card-title {
                font-size: 0.9rem;
            }
        }
        
        /* Vertikales Layout für bessere mobile Darstellung */
        #bulkTraegerList {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
    </style>

    <script>
    // Globale Variable für ausgewählte Geräteträger im Bulk-Modal
    window.bulkSelectedTraeger = new Set();
    
    // Platzhalter-Handler – werden später mit Logik hinterlegt
    document.addEventListener('DOMContentLoaded', function(){
        const onClickInfo = (msg) => () => alert(msg + "\n(Funktion folgt)");
        const q = (id) => document.getElementById(id);
        // Button-Aktionen: Liste öffnet neue Seite
        // Kein JS-Redirect nötig – echter Link wird verwendet

        // Platzhalter für andere Buttons (btnAddTraeger öffnet Modal, daher kein Alert)
        const other = { };
        Object.entries(other).forEach(([id,label])=>{ const el=q(id); if(el) el.addEventListener('click', onClickInfo(label)); });

        const btnRecord = q('btnRecordData');
        if (btnRecord) {
            btnRecord.addEventListener('click', function(){
                const modal = new bootstrap.Modal(document.getElementById('bulkDataModal'));
                modal.show();
                // Geräteträger laden wenn Modal geöffnet wird
                loadBulkTraeger();
                // Heutiges Datum setzen
                setTodayDate();
            });
        }

        // btnPlanTraining ist jetzt ein normaler Link, kein Modal mehr
    });
    
    // Geräteträger umschalten im Bulk-Modal
    function toggleBulkTraeger(traegerId) {
        const card = document.querySelector(`[data-bulk-traeger-id="${traegerId}"]`);
        const cardBody = card.querySelector('.card');
        
        if (window.bulkSelectedTraeger.has(traegerId)) {
            // Geräteträger abwählen
            window.bulkSelectedTraeger.delete(traegerId);
            cardBody.classList.remove('border-primary', 'bg-primary', 'text-white');
            cardBody.classList.add('border-light');
        } else {
            // Geräteträger auswählen
            window.bulkSelectedTraeger.add(traegerId);
            cardBody.classList.remove('border-light');
            cardBody.classList.add('border-primary', 'bg-primary', 'text-white');
        }
        
        // Zähler aktualisieren
        updateBulkSelectedCount();
        
        // Versteckte Inputs aktualisieren
        updateBulkTraegerInputs();
    }
    
    // Zähler für ausgewählte Geräteträger aktualisieren
    function updateBulkSelectedCount() {
        const count = window.bulkSelectedTraeger.size;
        const countElement = document.getElementById('bulkSelectedCount');
        countElement.textContent = `${count} ausgewählt`;
        
        if (count > 0) {
            countElement.classList.remove('bg-secondary');
            countElement.classList.add('bg-primary');
        } else {
            countElement.classList.remove('bg-primary');
            countElement.classList.add('bg-secondary');
        }
    }
    
    // Versteckte Inputs für ausgewählte Geräteträger aktualisieren
    function updateBulkTraegerInputs() {
        const inputsContainer = document.getElementById('bulkTraegerInputs');
        inputsContainer.innerHTML = '';
        
        window.bulkSelectedTraeger.forEach(traegerId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'traeger_ids[]';
            input.value = traegerId;
            inputsContainer.appendChild(input);
        });
    }
    
    // Geräteträger für Bulk-Modal laden
    function loadBulkTraeger() {
        const traegerList = document.getElementById('bulkTraegerList');
        traegerList.innerHTML = '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Lade Geräteträger...</div>';
        
        fetch('../api/get-atemschutz-traeger.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    data.traeger.forEach(traeger => {
                        html += `
                            <div class="traeger-card mb-2" data-bulk-traeger-id="${traeger.id}" onclick="toggleBulkTraeger(${traeger.id})">
                                <div class="card h-100">
                                    <div class="card-body p-3 text-center">
                                        <h6 class="card-title mb-0">${traeger.first_name} ${traeger.last_name}</h6>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    traegerList.innerHTML = html;
                } else {
                    traegerList.innerHTML = '<div class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> Fehler beim Laden der Geräteträger</div>';
                }
            })
            .catch(error => {
                console.error('Fehler:', error);
                traegerList.innerHTML = '<div class="text-center text-danger"><i class="fas fa-exclamation-triangle"></i> Fehler beim Laden der Geräteträger</div>';
            });
    }
    
    // Heutiges Datum setzen
    function setTodayDate() {
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.querySelector('input[name="date_value"]');
        if (dateInput) {
            dateInput.value = today;
        }
    }
    
    // Modal Reset beim Schließen
    document.getElementById('bulkDataModal').addEventListener('hidden.bs.modal', function() {
        // Auswahl zurücksetzen
        window.bulkSelectedTraeger.clear();
        
        // Karten zurücksetzen
        document.querySelectorAll('.traeger-card .card').forEach(card => {
            card.classList.remove('border-primary', 'bg-primary', 'text-white');
            card.classList.add('border-light');
        });
        
        // Zähler zurücksetzen
        updateBulkSelectedCount();
        
        // Inputs zurücksetzen
        updateBulkTraegerInputs();
    });
    
    </script>

</body>
</html>


