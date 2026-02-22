<?php
/**
 * Erfordert gewählte Einheit für Admin-Seiten.
 * Nach dem Login einbinden, wenn die Seite einheitsspezifische Daten anzeigt.
 */
if (!isset($_SESSION['user_id'])) return;
if (!empty($_SESSION['is_system_user'])) return;

if (!function_exists('get_current_unit_id')) {
    require_once __DIR__ . '/../../includes/functions.php';
}
if (!get_current_unit_id()) {
    header('Location: ../unit-select.php');
    exit;
}
