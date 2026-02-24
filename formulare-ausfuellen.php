<?php
/**
 * Einzelnes Formular ausfüllen (und absenden).
 * Nur für eingeloggte Benutzer.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!has_form_fill_permission()) {
    header('Location: formulare.php?error=no_access');
    exit;
}

$form_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_submission_id = isset($_GET['edit']) ? (int)$_GET['edit'] : (isset($_POST['edit']) ? (int)$_POST['edit'] : 0);
$return_formularcenter = (isset($_GET['return']) && $_GET['return'] === 'formularcenter') || (isset($_POST['return']) && $_POST['return'] === 'formularcenter');
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';

if (!$form_id) {
    header('Location: formulare.php');
    exit;
}

$form = null;
try {
    $stmt = $db->prepare("SELECT * FROM app_forms WHERE id = ? AND is_active = 1");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabelle existiert evtl. noch nicht
}
if (!$form) {
    header('Location: formulare.php?error=not_found');
    exit;
}

$schema = [];
if (!empty($form['schema_json'])) {
    $schema = json_decode($form['schema_json'], true);
    if (!is_array($schema)) {
        $schema = [];
    }
}

$message = '';
$error = '';
$form_data = [];
$is_edit_mode = ($edit_submission_id > 0);

// Bearbeitungsmodus: bestehende Eingabe laden
if ($is_edit_mode && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $stmt = $db->prepare("SELECT form_data FROM app_form_submissions WHERE id = ? AND form_id = ?");
        $stmt->execute([$edit_submission_id, $form_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['form_data'])) {
            $form_data = json_decode($row['form_data'], true);
            if (!is_array($form_data)) $form_data = [];
        }
    } catch (Exception $e) {}
}

// POST: Formular absenden oder aktualisieren
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = [];
    foreach ($schema as $field) {
        $name = $field['name'] ?? '';
        if ($name === '') continue;
        $type = $field['type'] ?? 'text';
        if ($type === 'checkbox') {
            $form_data[$name] = isset($_POST[$name]) ? '1' : '0';
        } else {
            $form_data[$name] = isset($_POST[$name]) ? trim($_POST[$name]) : '';
        }
    }
    try {
        if ($is_edit_mode && $edit_submission_id > 0) {
            $stmt = $db->prepare("UPDATE app_form_submissions SET form_data = ?, updated_at = NOW() WHERE id = ? AND form_id = ?");
            $stmt->execute([json_encode($form_data, JSON_UNESCAPED_UNICODE), $edit_submission_id, $form_id]);
            if ($return_formularcenter) {
                header('Location: admin/formularcenter.php?tab=submissions&message=' . urlencode('Formulareingabe wurde aktualisiert.'));
                exit;
            }
            $message = 'Ihr Formular wurde erfolgreich aktualisiert.';
        } else {
            $stmt = $db->prepare("INSERT INTO app_form_submissions (form_id, user_id, form_data, einheit_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$form_id, $_SESSION['user_id'], json_encode($form_data, JSON_UNESCAPED_UNICODE), $einheit_id > 0 ? $einheit_id : null]);
            if ($return_formularcenter) {
                header('Location: admin/formularcenter.php?tab=submissions&message=' . urlencode('Formular wurde erfolgreich abgesendet.'));
                exit;
            }
            $message = 'Ihr Formular wurde erfolgreich abgesendet.';
        }
        $form_data = []; // Nach Erfolg leeren, damit Felder leer sind
    } catch (Exception $e) {
        $error = 'Speichern fehlgeschlagen. Bitte versuchen Sie es erneut.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($form['title']); ?> - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($form['title']); ?></h3>
                        <?php if (!empty($form['description'])): ?>
                            <p class="text-muted mb-0 mt-1"><?php echo nl2br(htmlspecialchars($form['description'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?>
                                <?php if ($return_formularcenter): ?>
                                <a href="admin/formularcenter.php?tab=submissions" class="alert-link">Zurück zum Formularcenter</a>
                                <?php else: ?>
                                <a href="formulare.php" class="alert-link">Zurück zur Formularübersicht</a>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <?php if (!$message): ?>
                        <form method="post" action="">
                            <?php if ($is_edit_mode): ?>
                            <input type="hidden" name="edit" value="<?php echo (int)$edit_submission_id; ?>">
                            <?php if ($return_formularcenter): ?><input type="hidden" name="return" value="formularcenter"><?php endif; ?>
                            <?php endif; ?>
                            <?php foreach ($schema as $field):
                                $name = $field['name'] ?? 'field_' . uniqid();
                                $label = $field['label'] ?? $name;
                                $type = $field['type'] ?? 'text';
                                $required = !empty($field['required']);
                                $value = isset($form_data[$name]) ? $form_data[$name] : '';
                                $options = $field['options'] ?? [];
                            ?>
                            <div class="mb-3">
                                <?php if ($type === 'checkbox'): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($name); ?>" id="f_<?php echo htmlspecialchars($name); ?>" value="1" <?php echo $value === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="f_<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($label); ?></label>
                                    </div>
                                <?php elseif ($type === 'textarea'): ?>
                                    <label for="f_<?php echo htmlspecialchars($name); ?>" class="form-label"><?php echo htmlspecialchars($label); ?><?php if ($required): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                    <textarea class="form-control" name="<?php echo htmlspecialchars($name); ?>" id="f_<?php echo htmlspecialchars($name); ?>" rows="4" <?php if ($required): ?>required<?php endif; ?>><?php echo htmlspecialchars($value); ?></textarea>
                                <?php elseif ($type === 'select'): ?>
                                    <label for="f_<?php echo htmlspecialchars($name); ?>" class="form-label"><?php echo htmlspecialchars($label); ?><?php if ($required): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                    <select class="form-select" name="<?php echo htmlspecialchars($name); ?>" id="f_<?php echo htmlspecialchars($name); ?>" <?php if ($required): ?>required<?php endif; ?>>
                                        <option value="">Bitte wählen...</option>
                                        <?php foreach ($options as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $value === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else:
                                    $input_type = in_array($type, ['number','date','email']) ? $type : 'text';
                                ?>
                                    <label for="f_<?php echo htmlspecialchars($name); ?>" class="form-label"><?php echo htmlspecialchars($label); ?><?php if ($required): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                    <input type="<?php echo $input_type; ?>" class="form-control" name="<?php echo htmlspecialchars($name); ?>" id="f_<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($value); ?>" <?php if ($required): ?>required<?php endif; ?>>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <?php if (!empty($schema)): ?>
                            <div class="d-flex gap-2 mt-4">
                                <?php if ($return_formularcenter): ?>
                                <a href="admin/formularcenter.php?tab=submissions" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück zum Formularcenter</a>
                                <?php else: ?>
                                <a href="formulare.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary"><?php if ($is_edit_mode): ?><i class="fas fa-save"></i> Speichern<?php else: ?><i class="fas fa-paper-plane"></i> Absenden<?php endif; ?></button>
                            </div>
                            <?php endif; ?>
                        </form>
                        <?php else: ?>
                            <a href="formulare.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Zurück zur Formularübersicht</a>
                        <?php endif; ?>

                        <?php if (empty($schema) && !$message): ?>
                            <p class="text-muted">Dieses Formular hat noch keine Felder. Bitte im Formularcenter Felder anlegen.</p>
                            <a href="formulare.php" class="btn btn-secondary">Zurück</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App&nbsp;&nbsp;Version: 3.0</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
