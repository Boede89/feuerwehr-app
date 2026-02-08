<?php
/**
 * Verfügbare Typen für Dienstplan und Sonstige Anwesenheit.
 */

/** Typen für Auswahl-Dropdowns (nur die 4 Haupttypen) */
function get_dienstplan_typen_auswahl() {
    return [
        'uebungsdienst'         => 'Übungsdienst',
        'einsatz'               => 'Einsatz',
        'jahreshauptversammlung' => 'Jahreshauptversammlung',
        'sonstiges'             => 'Sonstiges',
    ];
}

/** Alle Typen inkl. Legacy für Anzeige */
function get_dienstplan_typen() {
    return get_dienstplan_typen_auswahl() + [
        'dienst' => 'Dienst',
        'uebung' => 'Übung',
    ];
}

function get_dienstplan_typ_label($key) {
    $typen = get_dienstplan_typen();
    return $typen[$key] ?? $key;
}
