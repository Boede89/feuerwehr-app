<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';

// Einstellungen laden
$settings = [];
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Einstellungen: ' . $e->getMessage();
}

// Verfügbare Formulare
$forms = [
    'maengelbericht' => [
        'name' => 'Mängelbericht',
        'icon' => 'fa-exclamation-triangle',
        'description' => 'Einstellungen für den Mängelbericht',
        'enabled' => $settings['form_maengelbericht_enabled'] ?? '1',
        'fields' => json_decode($settings['form_maengelbericht_fields'] ?? '[]', true) ?: []
    ]
];

// Feldtypen
$field_types = [
    'text' => 'Freitext',
    'textarea' => 'Mehrzeiliger Text',
    'select' => 'Auswahlfeld',
    'date' => 'Datum',
    'checkbox' => 'Checkbox'
];

// Feld hinzufügen/bearbeiten/löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $db->beginTransaction();

            // Feld hinzufügen/bearbeiten
            if (isset($_POST['add_field']) || isset($_POST['edit_field'])) {
                $form_key = sanitize_input($_POST['form_key'] ?? '');
                $field_id = isset($_POST['edit_field']) ? (int)$_POST['field_id'] : null;
                $field_label = sanitize_input($_POST['field_label'] ?? '');
                $field_type = sanitize_input($_POST['field_type'] ?? 'text');
                $field_required = isset($_POST['field_required']) ? '1' : '0';
                $field_options = '';
                
                // Für Select-Felder: Optionen speichern
                if ($field_type === 'select' && !empty($_POST['field_options'])) {
                    $options = array_filter(array_map('trim', explode("\n", $_POST['field_options'])));
                    $field_options = json_encode($options);
                }
                
                if (empty($field_label)) {
                    $error = 'Bitte geben Sie einen Feldnamen ein.';
                } else {
                    $fields_key = 'form_' . $form_key . '_fields';
                    $current_fields = json_decode($settings[$fields_key] ?? '[]', true) ?: [];
                    
                    $field_data = [
                        'label' => $field_label,
                        'type' => $field_type,
                        'required' => $field_required,
                        'options' => $field_options
                    ];
                    
                    if ($field_id !== null && isset($current_fields[$field_id])) {
                        // Feld bearbeiten
                        $current_fields[$field_id] = $field_data;
                    } else {
                        // Neues Feld hinzufügen
                        $current_fields[] = $field_data;
                    }
                    
                    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                    $stmt->execute([$fields_key, json_encode($current_fields)]);
                    
                    $message = isset($_POST['edit_field']) ? 'Feld wurde erfolgreich bearbeitet.' : 'Feld wurde erfolgreich hinzugefügt.';
                }
            }
            
            // Feld löschen
            if (isset($_POST['delete_field'])) {
                $form_key = sanitize_input($_POST['form_key'] ?? '');
                $field_id = (int)$_POST['field_id'];
                
                $fields_key = 'form_' . $form_key . '_fields';
                $current_fields = json_decode($settings[$fields_key] ?? '[]', true) ?: [];
                
                if (isset($current_fields[$field_id])) {
                    unset($current_fields[$field_id]);
                    $current_fields = array_values($current_fields); // Index neu nummerieren
                    
                    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                    $stmt->execute([$fields_key, json_encode($current_fields)]);
                    
                    $message = 'Feld wurde erfolgreich gelöscht.';
                }
            }
            
            // Formular-Einstellungen speichern
            foreach ($forms as $form_key => $form_data) {
                $enabled_key = 'form_' . $form_key . '_enabled';
                $enabled_value = isset($_POST[$enabled_key]) ? '1' : '0';
                
                $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                $stmt->execute([$enabled_key, $enabled_value]);
            }

            $db->commit();
            if (empty($message)) {
                $message = 'Formular-Einstellungen wurden erfolgreich gespeichert.';
            }

            // Einstellungen neu laden
            $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings');
            $stmt->execute();
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Formulare aktualisieren
            foreach ($forms as $form_key => &$form_data) {
                $form_data['enabled'] = $settings['form_' . $form_key . '_enabled'] ?? '1';
                $form_data['fields'] = json_decode($settings['form_' . $form_key . '_fields'] ?? '[]', true) ?: [];
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formular-Einstellungen - Feuerwehr App</title>
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
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-file-alt"></i> Formular-Einstellungen
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="">
            <?php echo generate_csrf_token(); ?>
            
            <div class="row g-4">
                <?php foreach ($forms as $form_key => $form_data): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas <?php echo htmlspecialchars($form_data['icon']); ?> me-2"></i>
                                <?php echo htmlspecialchars($form_data['name']); ?>
                            </h5>
                        </div>
                        <div class="card-body d-flex flex-column">
                            <p class="text-muted"><?php echo htmlspecialchars($form_data['description']); ?></p>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" 
                                       id="form_<?php echo htmlspecialchars($form_key); ?>_enabled" 
                                       name="form_<?php echo htmlspecialchars($form_key); ?>_enabled"
                                       <?php echo ($form_data['enabled'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="form_<?php echo htmlspecialchars($form_key); ?>_enabled">
                                    Formular aktiviert
                                </label>
                            </div>
                            
                            <div class="mt-auto">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" 
                                        data-bs-target="#settings_<?php echo htmlspecialchars($form_key); ?>">
                                    <i class="fas fa-cog"></i> Erweiterte Einstellungen
                                </button>
                            </div>
                            
                            <div class="collapse mt-3" id="settings_<?php echo htmlspecialchars($form_key); ?>">
                                <div class="card card-body bg-light">
                                    <h6 class="mb-3"><i class="fas fa-list"></i> Formularfelder</h6>
                                    
                                    <!-- Bestehende Felder -->
                                    <div id="fields_list_<?php echo htmlspecialchars($form_key); ?>" class="mb-3">
                                        <?php if (!empty($form_data['fields'])): ?>
                                            <?php foreach ($form_data['fields'] as $field_id => $field): ?>
                                                <div class="card mb-2 field-item" data-field-id="<?php echo $field_id; ?>">
                                                    <div class="card-body p-2">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($field['label']); ?></strong>
                                                                <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($field_types[$field['type']] ?? $field['type']); ?></span>
                                                                <?php if ($field['required'] == '1'): ?>
                                                                    <span class="badge bg-danger ms-1">Pflichtfeld</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div>
                                                                <button type="button" class="btn btn-sm btn-outline-primary edit-field-btn" 
                                                                        data-form-key="<?php echo htmlspecialchars($form_key); ?>"
                                                                        data-field-id="<?php echo $field_id; ?>"
                                                                        data-field-label="<?php echo htmlspecialchars($field['label']); ?>"
                                                                        data-field-type="<?php echo htmlspecialchars($field['type']); ?>"
                                                                        data-field-required="<?php echo $field['required']; ?>"
                                                                        data-field-options="<?php echo htmlspecialchars($field['options'] ?? ''); ?>">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-outline-danger delete-field-btn"
                                                                        data-form-key="<?php echo htmlspecialchars($form_key); ?>"
                                                                        data-field-id="<?php echo $field_id; ?>">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-muted small mb-0">Noch keine Felder definiert.</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Feld hinzufügen/bearbeiten Formular -->
                                    <div class="border-top pt-3">
                                        <h6 class="mb-3" id="field_form_title_<?php echo htmlspecialchars($form_key); ?>">
                                            <i class="fas fa-plus"></i> Neues Feld hinzufügen
                                        </h6>
                                        <form id="field_form_<?php echo htmlspecialchars($form_key); ?>" class="field-form">
                                            <input type="hidden" name="form_key" value="<?php echo htmlspecialchars($form_key); ?>">
                                            <input type="hidden" name="field_id" id="field_id_<?php echo htmlspecialchars($form_key); ?>" value="">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Feldname / Label *</label>
                                                <input type="text" class="form-control" name="field_label" id="field_label_<?php echo htmlspecialchars($form_key); ?>" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Feldtyp *</label>
                                                <select class="form-select" name="field_type" id="field_type_<?php echo htmlspecialchars($form_key); ?>" required>
                                                    <?php foreach ($field_types as $type_key => $type_label): ?>
                                                        <option value="<?php echo htmlspecialchars($type_key); ?>"><?php echo htmlspecialchars($type_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3" id="field_options_container_<?php echo htmlspecialchars($form_key); ?>" style="display: none;">
                                                <label class="form-label">Optionen (eine pro Zeile) *</label>
                                                <textarea class="form-control" name="field_options" id="field_options_<?php echo htmlspecialchars($form_key); ?>" rows="3" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                                                <small class="form-text text-muted">Nur für Auswahlfelder. Geben Sie jede Option in eine neue Zeile ein.</small>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="field_required" id="field_required_<?php echo htmlspecialchars($form_key); ?>">
                                                    <label class="form-check-label" for="field_required_<?php echo htmlspecialchars($form_key); ?>">
                                                        Pflichtfeld
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="add_field" class="btn btn-primary btn-sm" id="add_field_btn_<?php echo htmlspecialchars($form_key); ?>">
                                                    <i class="fas fa-plus"></i> Feld hinzufügen
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-sm" id="cancel_edit_btn_<?php echo htmlspecialchars($form_key); ?>" style="display: none;">
                                                    <i class="fas fa-times"></i> Abbrechen
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between">
                        <a href="settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück zu Einstellungen
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Einstellungen speichern
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Für jedes Formular
        <?php foreach ($forms as $form_key => $form_data): ?>
        (function() {
            const formKey = '<?php echo htmlspecialchars($form_key); ?>';
            const fieldTypeSelect = document.getElementById('field_type_' + formKey);
            const optionsContainer = document.getElementById('field_options_container_' + formKey);
            const fieldForm = document.getElementById('field_form_' + formKey);
            const addFieldBtn = document.getElementById('add_field_btn_' + formKey);
            const cancelEditBtn = document.getElementById('cancel_edit_btn_' + formKey);
            const fieldFormTitle = document.getElementById('field_form_title_' + formKey);
            const fieldIdInput = document.getElementById('field_id_' + formKey);
            const fieldLabelInput = document.getElementById('field_label_' + formKey);
            const fieldRequiredInput = document.getElementById('field_required_' + formKey);
            const fieldOptionsInput = document.getElementById('field_options_' + formKey);
            
            // Feldtyp ändern - Optionsfeld anzeigen/verstecken
            fieldTypeSelect.addEventListener('change', function() {
                if (this.value === 'select') {
                    optionsContainer.style.display = 'block';
                    fieldOptionsInput.required = true;
                } else {
                    optionsContainer.style.display = 'none';
                    fieldOptionsInput.required = false;
                }
            });
            
            // Feld bearbeiten
            document.querySelectorAll('.edit-field-btn[data-form-key="' + formKey + '"]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const fieldId = this.dataset.fieldId;
                    const fieldLabel = this.dataset.fieldLabel;
                    const fieldType = this.dataset.fieldType;
                    const fieldRequired = this.dataset.fieldRequired === '1';
                    const fieldOptions = this.dataset.fieldOptions || '';
                    
                    fieldIdInput.value = fieldId;
                    fieldLabelInput.value = fieldLabel;
                    fieldTypeSelect.value = fieldType;
                    fieldRequiredInput.checked = fieldRequired;
                    
                    if (fieldType === 'select') {
                        optionsContainer.style.display = 'block';
                        const options = JSON.parse(fieldOptions || '[]');
                        fieldOptionsInput.value = options.join('\n');
                        fieldOptionsInput.required = true;
                    } else {
                        optionsContainer.style.display = 'none';
                        fieldOptionsInput.value = '';
                        fieldOptionsInput.required = false;
                    }
                    
                    addFieldBtn.innerHTML = '<i class="fas fa-save"></i> Feld speichern';
                    addFieldBtn.name = 'edit_field';
                    cancelEditBtn.style.display = 'inline-block';
                    fieldFormTitle.innerHTML = '<i class="fas fa-edit"></i> Feld bearbeiten';
                    
                    // Zum Formular scrollen
                    fieldForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            });
            
            // Bearbeitung abbrechen
            cancelEditBtn.addEventListener('click', function() {
                fieldForm.reset();
                fieldIdInput.value = '';
                addFieldBtn.innerHTML = '<i class="fas fa-plus"></i> Feld hinzufügen';
                addFieldBtn.name = 'add_field';
                cancelEditBtn.style.display = 'none';
                fieldFormTitle.innerHTML = '<i class="fas fa-plus"></i> Neues Feld hinzufügen';
                optionsContainer.style.display = 'none';
                fieldOptionsInput.required = false;
            });
            
            // Feld löschen
            document.querySelectorAll('.delete-field-btn[data-form-key="' + formKey + '"]').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (confirm('Möchten Sie dieses Feld wirklich löschen?')) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                        form.innerHTML = `
                            <input type="hidden" name="csrf_token" value="${csrfToken}">
                            <input type="hidden" name="form_key" value="${this.dataset.formKey}">
                            <input type="hidden" name="field_id" value="${this.dataset.fieldId}">
                            <input type="hidden" name="delete_field" value="1">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
            
            // Formular absenden
            fieldForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Optionsfeld validieren wenn Select
                if (fieldTypeSelect.value === 'select') {
                    const options = fieldOptionsInput.value.trim().split('\n').filter(opt => opt.trim() !== '');
                    if (options.length === 0) {
                        alert('Bitte geben Sie mindestens eine Option für das Auswahlfeld ein.');
                        return;
                    }
                }
                
                // Formular zum Hauptformular hinzufügen
                const mainForm = document.querySelector('form[method="POST"]');
                const formData = new FormData(fieldForm);
                
                // Alle Felder zum Hauptformular hinzufügen
                for (let [key, value] of formData.entries()) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    mainForm.appendChild(input);
                }
                
                mainForm.submit();
            });
        })();
        <?php endforeach; ?>
    </script>
</body>
</html>

