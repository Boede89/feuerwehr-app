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
$has_unit = get_current_unit_id();
$has_einheit = function_exists('get_current_einheit_id') && get_current_einheit_id();
if (!$has_unit && !$has_einheit) {
    header('Location: ../index.php');
    exit;
}
