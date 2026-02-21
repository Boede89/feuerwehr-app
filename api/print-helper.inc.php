<?php
/**
 * Gemeinsame Druck-Logik für print-*.php APIs.
 * Ermittelt CUPS-Server, Drucker-Ziel und führt lp aus.
 */
if (!defined('PRINT_HELPER_LOADED')) {
define('PRINT_HELPER_LOADED', 1);

function print_helper_get_cups_servers($printer_cups_server) {
    $list = [
        $printer_cups_server,
        getenv('CUPS_SERVER') ? trim(getenv('CUPS_SERVER')) : '',
        'host.docker.internal:631',
        '172.17.0.1:631',
        '172.18.0.1:631',
        '172.17.0.1',
        '172.18.0.1'
    ];
    return array_values(array_unique(array_filter($list)));
}

/**
 * Holt den Standard-Drucker von CUPS (lpstat -d).
 * @return string|null Druckername oder null
 */
function print_helper_get_default_printer($cups_servers) {
    $lpstat_bin = (file_exists('/usr/bin/lpstat') && is_executable('/usr/bin/lpstat')) ? '/usr/bin/lpstat' : 'lpstat';
    foreach ($cups_servers as $cups_srv) {
        $env_prefix = ($cups_srv !== '') ? 'CUPS_SERVER=' . escapeshellarg($cups_srv) . ' ' : '';
        $lines = [];
        exec($env_prefix . escapeshellarg($lpstat_bin) . ' -d 2>/dev/null', $lines, $ret);
        if ($ret === 0) {
            foreach ($lines as $line) {
                if (preg_match('/^system default destination:\s*(\S+)/', $line, $m)) {
                    return $m[1];
                }
            }
        }
    }
    return null;
}

/**
 * Führt lp aus und gibt [success, output, return_var, cups_used] zurück.
 */
function print_helper_run_lp($lp_cmd, $cups_servers) {
    $output = [];
    $return_var = -1;
    $cups_used = '';
    foreach ($cups_servers as $cups_srv) {
        $env_prefix = ($cups_srv !== '') ? 'CUPS_SERVER=' . escapeshellarg($cups_srv) . ' ' : '';
        exec($env_prefix . $lp_cmd, $output, $return_var);
        $cups_used = $cups_srv ?: '(Standard)';
        if ($return_var === 0) break;
    }
    return [$return_var === 0, $output, $return_var, $cups_used];
}

/**
 * Parst Job-ID aus lp-Ausgabe (z.B. "request id is HP_LaserJet-42 (1 file(s))").
 */
function print_helper_parse_job_id($output) {
    $text = is_array($output) ? implode(' ', $output) : (string)$output;
    if (preg_match('/request id is (\S+-\d+)/', $text, $m)) {
        return $m[1];
    }
    return null;
}

}