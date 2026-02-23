<?php
/**
 * Einmaliges Cleanup: Entfernt globale Divera Access Keys.
 * Divera-Keys sind ab sofort nur noch einheitenspezifisch (Einstellungen → Einheit → Divera).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !is_superadmin($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cleanup'])) {
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = 'divera_access_key'");
        $stmt->execute();
        $settings_deleted = $stmt->rowCount();
        $stmt = $db->prepare("UPDATE users SET divera_access_key = NULL WHERE divera_access_key IS NOT NULL AND divera_access_key != ''");
        $stmt->execute();
        $users_cleared = $stmt->rowCount();
        $db->commit();
        $message = "Cleanup durchgeführt: $settings_deleted Eintrag(e) aus settings entfernt, $users_cleared Benutzer-Key(s) geleert.";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = 'Fehler: ' . $e->getMessage();
    }
}

$settings_has_key = false;
$users_with_key = 0;
try {
    $stmt = $db->query("SELECT 1 FROM settings WHERE setting_key = 'divera_access_key' AND setting_value IS NOT NULL AND TRIM(setting_value) != '' LIMIT 1");
    $settings_has_key = (bool)$stmt->fetch();
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE divera_access_key IS NOT NULL AND TRIM(divera_access_key) != ''");
    $users_with_key = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Divera Cleanup – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h1><i class="fas fa-broom"></i> Divera Cleanup</h1>
    <p class="text-muted">Entfernt globale Divera Access Keys. Ab sofort nur noch einheitenspezifische Keys (Einstellungen → Einheit → Divera).</p>
    <?php if (is_file(__DIR__ . '/../config/divera.local.php')): ?>
    <div class="alert alert-warning">Hinweis: <code>config/divera.local.php</code> existiert – prüfen Sie diese Datei, ob dort ein Key hinterlegt ist (wird bei Einheitsauswahl ignoriert, kann aber bei anderen Seiten wirken).</div>
    <?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <div class="card">
        <div class="card-body">
            <p><strong>Gefundene globale Keys:</strong></p>
            <ul>
                <li>settings-Tabelle: <?php echo $settings_has_key ? 'Key vorhanden' : 'kein Key'; ?></li>
                <li>users-Tabelle: <?php echo $users_with_key; ?> Benutzer mit Key</li>
            </ul>
            <?php if ($settings_has_key || $users_with_key > 0): ?>
            <form method="post">
                <input type="hidden" name="confirm_cleanup" value="1">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Globale Divera-Keys wirklich entfernen?');">Cleanup ausführen</button>
            </form>
            <?php else: ?>
            <p class="text-success mb-0">Keine globalen Keys gefunden – kein Cleanup nötig.</p>
            <?php endif; ?>
        </div>
    </div>
    <p class="mt-3"><a href="settings-global.php">← Zurück zu Einstellungen</a></p>
</div>
</body>
</html>
