<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

// Einheitenverwaltung nur für Superadmins
if (!is_superadmin()) {
    header("Location: settings.php");
    exit;
}

$message = '';
$error = '';

// POST: Einheit hinzufügen, bearbeiten oder löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            if (isset($_POST['add_einheit'])) {
                $name = trim(sanitize_input($_POST['name'] ?? ''));
                $kurzbeschreibung = trim(sanitize_input($_POST['kurzbeschreibung'] ?? ''));
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                if (empty($name)) {
                    $error = 'Bitte geben Sie einen Namen für die Einheit ein.';
                } else {
                    $stmt = $db->prepare("INSERT INTO einheiten (name, kurzbeschreibung, sort_order) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $kurzbeschreibung, $sort_order]);
                    $message = 'Einheit wurde erfolgreich angelegt.';
                }
            } elseif (isset($_POST['edit_einheit'])) {
                $id = (int)($_POST['einheit_id'] ?? 0);
                $name = trim(sanitize_input($_POST['name'] ?? ''));
                $kurzbeschreibung = trim(sanitize_input($_POST['kurzbeschreibung'] ?? ''));
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                if (empty($name)) {
                    $error = 'Bitte geben Sie einen Namen für die Einheit ein.';
                } elseif ($id > 0) {
                    $stmt = $db->prepare("UPDATE einheiten SET name = ?, kurzbeschreibung = ?, sort_order = ? WHERE id = ?");
                    $stmt->execute([$name, $kurzbeschreibung, $sort_order, $id]);
                    $message = 'Einheit wurde erfolgreich aktualisiert.';
                }
            } elseif (isset($_POST['delete_einheit'])) {
                $id = (int)($_POST['einheit_id'] ?? 0);
                if ($id > 0) {
                    // Einheit_settings löschen (kein FK)
                    try {
                        $db->prepare("DELETE FROM einheit_settings WHERE einheit_id = ?")->execute([$id]);
                    } catch (Exception $e) {}
                    $stmt = $db->prepare("DELETE FROM einheiten WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = 'Einheit wurde gelöscht. Alle zugehörigen Daten wurden unwiderruflich entfernt.';
                }
            }
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

// Einheiten laden
$einheiten = [];
try {
    $stmt = $db->query("SELECT e.*, 
        (SELECT COUNT(*) FROM members m WHERE m.einheit_id = e.id) AS members_count,
        (SELECT COUNT(*) FROM vehicles v WHERE v.einheit_id = e.id) AS vehicles_count
        FROM einheiten e ORDER BY e.sort_order, e.name");
    $einheiten = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $error = $error ?: ('Fehler beim Laden der Einheiten: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einheiten Verwaltung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="settings.php">Einstellungen</a></li>
                        <li class="breadcrumb-item active">Einheiten</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-sitemap"></i> Einheiten Verwaltung
                    </h1>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#einheitModal" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Neue Einheit anlegen
                    </button>
                </div>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>

                <div class="row g-4">
                    <?php foreach ($einheiten as $e): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-building text-primary me-2"></i>
                                    <?php echo htmlspecialchars($e['name']); ?>
                                </h5>
                                <?php if (!empty($e['kurzbeschreibung'])): ?>
                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($e['kurzbeschreibung']); ?></p>
                                <?php endif; ?>
                                <p class="text-muted small mb-3">
                                    <?php echo (int)$e['members_count']; ?> Mitglieder &middot; 
                                    <?php echo (int)$e['vehicles_count']; ?> Fahrzeuge
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="settings-einheit.php?id=<?php echo (int)$e['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-cog"></i> Einstellungen
                                    </a>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="openEditModal(<?php echo (int)$e['id']; ?>, '<?php echo htmlspecialchars($e['name'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($e['kurzbeschreibung'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', <?php echo (int)($e['sort_order'] ?? 0); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm btn-delete-einheit"
                                        data-id="<?php echo (int)$e['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($e['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-members="<?php echo (int)$e['members_count']; ?>"
                                        data-vehicles="<?php echo (int)$e['vehicles_count']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($einheiten)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Keine Einheiten vorhanden. Klicken Sie auf „Neue Einheit anlegen“.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal: Einheit anlegen / bearbeiten -->
    <div class="modal fade" id="einheitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="einheitModalTitle">Neue Einheit anlegen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="einheit_id" id="modal_einheit_id" value="">
                        <div class="mb-3">
                            <label for="modal_name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="modal_name" name="name" required placeholder="z.B. Löschzug Amern">
                        </div>
                        <div class="mb-3">
                            <label for="modal_kurzbeschreibung" class="form-label">Kurzbeschreibung (optional)</label>
                            <input type="text" class="form-control" id="modal_kurzbeschreibung" name="kurzbeschreibung" placeholder="z.B. Hauptstandort">
                        </div>
                        <div class="mb-3">
                            <label for="modal_sort_order" class="form-label">Reihenfolge</label>
                            <input type="number" class="form-control" id="modal_sort_order" name="sort_order" value="0" min="0">
                            <div class="form-text">Kleinere Zahl = weiter oben in der Liste</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" name="add_einheit" id="btn_modal_add" class="btn btn-success"><i class="fas fa-plus"></i> Anlegen</button>
                        <button type="submit" name="edit_einheit" id="btn_modal_edit" class="btn btn-primary" style="display:none"><i class="fas fa-save"></i> Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Löschen bestätigen -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Einheit unwiderruflich löschen?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger mb-0">
                        <strong>Warnung:</strong> Diese Aktion löscht die Einheit <strong id="delete_name"></strong> und <strong>alle zugehörigen Daten unwiderruflich</strong>:
                        <ul class="mb-0 mt-2" id="delete_details">
                            <li>Einstellungen der Einheit (SMTP, Divera, etc.)</li>
                            <li id="delete_members">Mitglieder-Zuordnungen werden von der Einheit getrennt</li>
                            <li id="delete_vehicles">Fahrzeug-Zuordnungen werden von der Einheit getrennt</li>
                            <li>Benutzer-Zuordnungen</li>
                        </ul>
                    </div>
                    <p class="mt-3 mb-0 text-muted small">Diese Aktion kann nicht rückgängig gemacht werden. Sind Sie sicher?</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="deleteForm" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="einheit_id" id="delete_einheit_id" value="">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" name="delete_einheit" class="btn btn-danger"><i class="fas fa-trash"></i> Ja, unwiderruflich löschen</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.querySelectorAll('.btn-delete-einheit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            confirmDelete(
                parseInt(this.dataset.id, 10),
                this.dataset.name || '',
                parseInt(this.dataset.members || 0, 10),
                parseInt(this.dataset.vehicles || 0, 10)
            );
        });
    });
    function openAddModal() {
        document.getElementById('einheitModalTitle').textContent = 'Neue Einheit anlegen';
        document.getElementById('modal_einheit_id').value = '';
        document.getElementById('modal_name').value = '';
        document.getElementById('modal_kurzbeschreibung').value = '';
        document.getElementById('modal_sort_order').value = '0';
        document.getElementById('btn_modal_add').style.display = 'inline-block';
        document.getElementById('btn_modal_edit').style.display = 'none';
    }
    function openEditModal(id, name, kurzbeschreibung, sortOrder) {
        document.getElementById('einheitModalTitle').textContent = 'Einheit bearbeiten';
        document.getElementById('modal_einheit_id').value = id;
        document.getElementById('modal_name').value = name || '';
        document.getElementById('modal_kurzbeschreibung').value = (kurzbeschreibung || '').replace(/&#39;/g, "'");
        document.getElementById('modal_sort_order').value = sortOrder || 0;
        document.getElementById('btn_modal_add').style.display = 'none';
        document.getElementById('btn_modal_edit').style.display = 'inline-block';
        new bootstrap.Modal(document.getElementById('einheitModal')).show();
    }
    function confirmDelete(id, name, membersCount, vehiclesCount) {
        document.getElementById('delete_name').textContent = name || ('ID ' + id);
        document.getElementById('delete_einheit_id').value = id;
        document.getElementById('delete_members').textContent = 'Mitglieder-Zuordnungen (' + (membersCount || 0) + ' Mitglieder werden von der Einheit getrennt)';
        document.getElementById('delete_vehicles').textContent = 'Fahrzeug-Zuordnungen (' + (vehiclesCount || 0) + ' Fahrzeuge werden von der Einheit getrennt)';
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
    </script>
</body>
</html>
