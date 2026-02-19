<?php
/**
 * Verfügbare Typen für Dienstplan und Sonstige Anwesenheit.
 * Hinweis: Übungsdienste und Dienste aus dem Dienstplan sind dasselbe – beide werden als Übungsdienst gewertet.
 */

/** Typen für Auswahl-Dropdowns */
function get_dienstplan_typen_auswahl() {
    return [
        'uebungsdienst' => 'Übungsdienst',
        'einsatz'       => 'Einsatz',
        'sonstiges'     => 'Sonstiges',
    ];
}

/** Alle Typen inkl. Legacy für Anzeige */
function get_dienstplan_typen() {
    return get_dienstplan_typen_auswahl() + [
        'dienst' => 'Übungsdienst',  // wie uebungsdienst
        'uebung' => 'Übungsdienst', // wie uebungsdienst
    ];
}

function get_dienstplan_typ_label($key) {
    $typen = get_dienstplan_typen();
    return $typen[$key] ?? $key;
}
