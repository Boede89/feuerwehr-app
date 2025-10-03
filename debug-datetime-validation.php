<?php
/**
 * Debug-Skript für Datum/Uhrzeit-Validierung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "🔍 Datum/Uhrzeit-Validierung Debug\n";
echo "===================================\n\n";

// Test verschiedene Datum/Uhrzeit-Formate
$test_datetimes = [
    '2024-12-25T14:30',  // HTML datetime-local Format
    '2024-12-25 14:30',  // Standard Format
    '2024-12-25T14:30:00', // Mit Sekunden
    '2024-12-25 14:30:00', // Standard mit Sekunden
];

echo "1. Teste verschiedene Datum/Uhrzeit-Formate:\n";
foreach ($test_datetimes as $datetime) {
    $result_html = validate_datetime($datetime, 'Y-m-d\TH:i');
    $result_standard = validate_datetime($datetime, 'Y-m-d H:i');
    
    echo "   Datum: '$datetime'\n";
    echo "   HTML Format (Y-m-d\\TH:i): " . ($result_html ? '✅ GÜLTIG' : '❌ UNGÜLTIG') . "\n";
    echo "   Standard Format (Y-m-d H:i): " . ($result_standard ? '✅ GÜLTIG' : '❌ UNGÜLTIG') . "\n";
    echo "\n";
}

// Test mit aktuellen Daten
echo "2. Teste mit aktuellen Daten:\n";
$now = new DateTime();
$tomorrow = $now->modify('+1 day');
$next_week = $now->modify('+7 days');

$test_dates = [
    $now->format('Y-m-d\TH:i'),
    $tomorrow->format('Y-m-d\TH:i'),
    $next_week->format('Y-m-d\TH:i'),
];

foreach ($test_dates as $date) {
    $result = validate_datetime($date);
    echo "   Datum: '$date' -> " . ($result ? '✅ GÜLTIG' : '❌ UNGÜLTIG') . "\n";
}

echo "\n3. Teste strtotime() Funktion:\n";
foreach ($test_dates as $date) {
    $timestamp = strtotime($date);
    $formatted = date('Y-m-d H:i:s', $timestamp);
    echo "   '$date' -> strtotime: $timestamp -> formatiert: $formatted\n";
}

echo "\n4. Simuliere Reservierungsformular:\n";
$simulated_post = [
    'start_datetime_0' => '2024-12-25T14:30',
    'end_datetime_0' => '2024-12-25T16:30',
];

foreach ($simulated_post as $key => $value) {
    echo "   $key: '$value'\n";
    $result = validate_datetime($value);
    echo "   Validierung: " . ($result ? '✅ GÜLTIG' : '❌ UNGÜLTIG') . "\n";
    
    if ($result) {
        $timestamp = strtotime($value);
        echo "   strtotime: $timestamp\n";
        echo "   formatiert: " . date('Y-m-d H:i:s', $timestamp) . "\n";
    }
    echo "\n";
}

echo "🎯 Debug abgeschlossen!\n";
?>
