<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !is_superadmin($_SESSION['user_id'])) {
    header('Location: ../login.php?error=superadmin_only');
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id > 0) {
    $_SESSION['current_einheit_id'] = $einheit_id;
}

require_once __DIR__ . '/../config/divera.php';

$message = '';
$error = '';
$alarms = [];
$filtered_alarms = [];
$selected_alarm_id = isset($_GET['alarm_id']) ? (int)$_GET['alarm_id'] : 0;
$selected_alarm = null;
$selected_alarm_detail = null;
$selected_alarm_reach = null;
$raw_alarm_detail = null;
$raw_alarm_reach = null;
$status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
if (!in_array($status_filter, ['all', 'open', 'closed'], true)) {
    $status_filter = 'all';
}
$keyword_filter = trim((string)($_GET['q'] ?? ''));
$from_filter_raw = trim((string)($_GET['from'] ?? ''));
$to_filter_raw = trim((string)($_GET['to'] ?? ''));
$from_filter_ts = $from_filter_raw !== '' ? strtotime($from_filter_raw) : false;
$to_filter_ts = $to_filter_raw !== '' ? strtotime($to_filter_raw) : false;
$auto_refresh_seconds = isset($_GET['auto_refresh']) ? (int)$_GET['auto_refresh'] : 0;
if (!in_array($auto_refresh_seconds, [0, 30, 60], true)) {
    $auto_refresh_seconds = 0;
}
$use_demo_data = isset($_GET['demo']) && $_GET['demo'] === '1';
$demo_custom = $_SESSION['tablet_demo_custom'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_demo_config', 'reset_demo_config'], true)) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        if ($_POST['action'] === 'reset_demo_config') {
            unset($_SESSION['tablet_demo_custom']);
            $redirect = 'tabletbereich.php?einheit_id=' . (int)$einheit_id . '&demo=1';
            header('Location: ' . $redirect);
            exit;
        }

        $decode_json_array = static function ($value, $fallback) {
            $value = trim((string)$value);
            if ($value === '') return $fallback;
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $fallback;
        };

        $_SESSION['tablet_demo_custom'] = [
            'title_suffix' => trim((string)($_POST['demo_title_suffix'] ?? '')),
            'address' => trim((string)($_POST['demo_address'] ?? '')),
            'text' => trim((string)($_POST['demo_text'] ?? '')),
            'location' => trim((string)($_POST['demo_location'] ?? '')),
            'keyword' => trim((string)($_POST['demo_keyword'] ?? 'F2')),
            'priority' => (int)($_POST['demo_priority'] ?? 2),
            'object' => trim((string)($_POST['demo_object'] ?? 'Freifläche')),
            'forces' => $decode_json_array($_POST['demo_forces_json'] ?? '', []),
            'vehicles' => $decode_json_array($_POST['demo_vehicles_json'] ?? '', []),
            'resources' => $decode_json_array($_POST['demo_resources_json'] ?? '', []),
            'reach' => $decode_json_array($_POST['demo_reach_json'] ?? '', []),
        ];
        $redirect = 'tabletbereich.php?einheit_id=' . (int)$einheit_id . '&demo=1';
        header('Location: ' . $redirect);
        exit;
    }
}

$build_demo_payload = static function () use ($base_upload_dir, $demo_custom) {
    $now = time();
    $alarm_id = 987654;
    $alarm_date = $now - 900;
    $demo_address = 'Markt 20, 41366 Schwalmtal';
    $demo_title_suffix = 'Brennt PKW auf Parkplatz';
    $demo_text = 'Mehrere Notrufe. Fahrzeugbrand droht auf weitere Fahrzeuge überzugreifen.';
    $demo_location = 'Parkplatz Supermarkt Nord';
    $demo_keyword = 'F2';
    $demo_priority = 2;
    $demo_object = 'Freifläche';
    $demo_preferred_plan_file = '';

    $objektplan_dirs = [
        $base_upload_dir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'objektplaene',
        $base_upload_dir . DIRECTORY_SEPARATOR . 'objektplaene',
        $base_upload_dir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'objektplaene',
    ];
    foreach ($objektplan_dirs as $dir) {
        if (!is_dir($dir)) continue;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                if (mb_strtolower((string)$file->getExtension(), 'UTF-8') !== 'pdf') continue;
                $name_wo_ext = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                $name_clean = preg_replace('/[_\-]+/', ' ', (string)$name_wo_ext);
                $name_clean = trim((string)$name_clean);
                if ($name_clean !== '') {
                    // Demo-Adresse bewusst aus vorhandenem Objektplan-Namen ableiten,
                    // damit die automatische Plan-Erkennung im Test sicher greift.
                    $demo_address = $name_clean;
                    $demo_title_suffix = $name_clean;
                }
                $full_path = $file->getPathname();
                $base_norm = str_replace('\\', '/', $base_upload_dir);
                $full_norm = str_replace('\\', '/', $full_path);
                if (str_starts_with($full_norm, $base_norm . '/')) {
                    $demo_preferred_plan_file = ltrim(substr($full_norm, strlen($base_norm)), '/');
                }
                break 2;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $alarm_list = [[
        'id' => $alarm_id,
        'title' => 'F2 - ' . $demo_title_suffix,
        'text' => $demo_text,
        'address' => $demo_address,
        'date' => $alarm_date,
        'ts_create' => $alarm_date,
        'closed' => false,
    ]];

    $alarm_detail_raw = [
        'success' => true,
        'data' => [
            'id' => $alarm_id,
            'number' => 'E-2026-0415-01',
            'title' => 'F2 - ' . $demo_title_suffix,
            'text' => $demo_text,
            'keyword' => $demo_keyword,
            'priority' => $demo_priority,
            'address' => $demo_address,
            'location' => $demo_location,
            'object' => $demo_object,
            'patient_count' => 0,
            'date' => $alarm_date,
            'ts_create' => $alarm_date,
            'closed' => false,
            'forces' => [
                ['name' => 'Löschgruppe Zentrum', 'type' => 'Feuerwehr', 'status' => 'alarmiert', 'count' => 9],
                ['name' => 'Löschgruppe Lobberich', 'type' => 'Feuerwehr', 'status' => 'alarmiert', 'count' => 8],
            ],
            'vehicles' => [
                ['name' => 'LF 20', 'radio' => 'FL-NET 1-LF20-1', 'status' => 'alarmiert'],
                ['name' => 'HLF 20', 'radio' => 'FL-NET 1-HLF20-1', 'status' => 'alarmiert'],
                ['name' => 'ELW 1', 'radio' => 'FL-NET 1-ELW1-1', 'status' => 'alarmiert'],
            ],
            'resources' => [
                ['category' => 'vehicle', 'name' => 'LF 20', 'count' => 1],
                ['category' => 'vehicle', 'name' => 'HLF 20', 'count' => 1],
                ['category' => 'personnel', 'name' => 'PA-Träger', 'count' => 6],
            ],
            'additional' => [
                'weather' => 'trocken, Wind mäßig',
                'caller' => 'Leitstelle Kreis Viersen',
                'note' => 'Zufahrt über Nordseite empfohlen',
            ],
        ],
    ];

    $reach_raw = [
        'success' => true,
        'data' => [
            'received' => [
                '251321' => ['name' => 'Max Mustermann', 'ts' => $now - 600],
                '251322' => ['name' => 'Lisa Beispiel', 'ts' => $now - 590],
                '251323' => ['name' => 'Tim Demo', 'ts' => $now - 570],
            ],
            'viewed' => [
                '251321' => ['name' => 'Max Mustermann', 'ts' => $now - 520],
                '251322' => ['name' => 'Lisa Beispiel', 'ts' => $now - 500],
            ],
            'confirmed' => [
                '251321' => ['name' => 'Max Mustermann', 'status_id' => 44986, 'status_label' => 'Komme', 'ts' => $now - 470],
            ],
            'declined' => [
                '251323' => ['name' => 'Tim Demo', 'status_id' => 44988, 'status_label' => 'Verhindert', 'ts' => $now - 430],
            ],
        ],
    ];

    if (!empty($demo_custom) && is_array($demo_custom)) {
        if (!empty($demo_custom['title_suffix'])) $demo_title_suffix = (string)$demo_custom['title_suffix'];
        if (!empty($demo_custom['address'])) $demo_address = (string)$demo_custom['address'];
        if (!empty($demo_custom['text'])) $demo_text = (string)$demo_custom['text'];
        if (!empty($demo_custom['location'])) $demo_location = (string)$demo_custom['location'];
        if (!empty($demo_custom['keyword'])) $demo_keyword = (string)$demo_custom['keyword'];
        if (isset($demo_custom['priority'])) $demo_priority = (int)$demo_custom['priority'];
        if (!empty($demo_custom['object'])) $demo_object = (string)$demo_custom['object'];

        $alarm_list[0]['title'] = 'F2 - ' . $demo_title_suffix;
        $alarm_list[0]['text'] = $demo_text;
        $alarm_list[0]['address'] = $demo_address;
        $alarm_detail_raw['data']['title'] = 'F2 - ' . $demo_title_suffix;
        $alarm_detail_raw['data']['text'] = $demo_text;
        $alarm_detail_raw['data']['keyword'] = $demo_keyword;
        $alarm_detail_raw['data']['priority'] = $demo_priority;
        $alarm_detail_raw['data']['address'] = $demo_address;
        $alarm_detail_raw['data']['location'] = $demo_location;
        $alarm_detail_raw['data']['object'] = $demo_object;
        if (!empty($demo_custom['forces']) && is_array($demo_custom['forces'])) $alarm_detail_raw['data']['forces'] = $demo_custom['forces'];
        if (!empty($demo_custom['vehicles']) && is_array($demo_custom['vehicles'])) $alarm_detail_raw['data']['vehicles'] = $demo_custom['vehicles'];
        if (!empty($demo_custom['resources']) && is_array($demo_custom['resources'])) $alarm_detail_raw['data']['resources'] = $demo_custom['resources'];
        if (!empty($demo_custom['reach']) && is_array($demo_custom['reach'])) $reach_raw['data'] = $demo_custom['reach'];
    }

    return [
        'alarms' => $alarm_list,
        'selected_alarm_id' => $alarm_id,
        'selected_alarm' => $alarm_list[0],
        'raw_alarm_detail' => $alarm_detail_raw,
        'raw_alarm_reach' => $reach_raw,
        'selected_alarm_detail' => $alarm_detail_raw['data'],
        'selected_alarm_reach' => $reach_raw['data'],
        'preferred_plan_file' => $demo_preferred_plan_file,
    ];
};

$api_base = rtrim(trim((string)($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
$divera_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string)($divera_config['access_key'] ?? '')));

if ($use_demo_data) {
    $demo = $build_demo_payload();
    $alarms = $demo['alarms'];
    $filtered_alarms = $demo['alarms'];
    $selected_alarm_id = (int)$demo['selected_alarm_id'];
    $selected_alarm = $demo['selected_alarm'];
    $raw_alarm_detail = $demo['raw_alarm_detail'];
    $raw_alarm_reach = $demo['raw_alarm_reach'];
    $selected_alarm_detail = $demo['selected_alarm_detail'];
    $selected_alarm_reach = $demo['selected_alarm_reach'];
    $message = 'Beispieldaten geladen. Keine Live-Divera-Daten.';
} elseif ($divera_key === '') {
    $error = 'Kein Divera-Access-Key für diese Einheit hinterlegt.';
} else {
    $alarms_error = null;
    $alarms = fetch_divera_alarms($divera_key, $api_base, $alarms_error);
    if ($alarms_error) {
        $error = $alarms_error;
    }

    $keyword_filter_lc = mb_strtolower($keyword_filter, 'UTF-8');
    foreach ($alarms as $alarm) {
        $is_closed = !empty($alarm['closed']);
        if ($status_filter === 'open' && $is_closed) continue;
        if ($status_filter === 'closed' && !$is_closed) continue;

        $alarm_ts = (int)($alarm['date'] ?? 0);
        if ($from_filter_ts !== false && $alarm_ts > 0 && $alarm_ts < (int)$from_filter_ts) continue;
        if ($to_filter_ts !== false && $alarm_ts > 0 && $alarm_ts > (int)$to_filter_ts) continue;

        if ($keyword_filter_lc !== '') {
            $haystack = mb_strtolower(implode(' ', [
                (string)($alarm['id'] ?? ''),
                (string)($alarm['title'] ?? ''),
                (string)($alarm['text'] ?? ''),
                (string)($alarm['address'] ?? '')
            ]), 'UTF-8');
            if (mb_strpos($haystack, $keyword_filter_lc) === false) continue;
        }

        $filtered_alarms[] = $alarm;
    }

    if ($selected_alarm_id <= 0 && !empty($filtered_alarms)) {
        $selected_alarm_id = (int)$filtered_alarms[0]['id'];
    }

    foreach ($filtered_alarms as $alarm) {
        if ((int)$alarm['id'] === $selected_alarm_id) {
            $selected_alarm = $alarm;
            break;
        }
    }
    if (!$selected_alarm && !empty($filtered_alarms)) {
        $selected_alarm = $filtered_alarms[0];
        $selected_alarm_id = (int)$selected_alarm['id'];
    }

    if ($selected_alarm_id > 0) {
        $url_alarm = $api_base . '/api/v2/alarms/' . $selected_alarm_id . '?accesskey=' . urlencode($divera_key);
        $url_reach = $api_base . '/api/v2/alarms/reach/' . $selected_alarm_id . '?accesskey=' . urlencode($divera_key);
        $ctx = stream_context_create(['http' => ['timeout' => 18, 'ignore_errors' => true]]);

        $raw_alarm = @file_get_contents($url_alarm, false, $ctx);
        if (is_string($raw_alarm) && $raw_alarm !== '') {
            $raw_alarm_detail = json_decode($raw_alarm, true);
            if (is_array($raw_alarm_detail)) {
                $selected_alarm_detail = is_array($raw_alarm_detail['data'] ?? null) ? $raw_alarm_detail['data'] : $raw_alarm_detail;
            }
        }

        $raw_reach = @file_get_contents($url_reach, false, $ctx);
        if (is_string($raw_reach) && $raw_reach !== '') {
            $raw_alarm_reach = json_decode($raw_reach, true);
            if (is_array($raw_alarm_reach)) {
                $selected_alarm_reach = is_array($raw_alarm_reach['data'] ?? null) ? $raw_alarm_reach['data'] : $raw_alarm_reach;
            }
        }
    }
}

$pretty_json = static function ($value) {
    if ($value === null) return 'Keine Daten';
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
};

$query_base = [
    'einheit_id' => (int)$einheit_id,
    'status' => $status_filter,
    'from' => $from_filter_raw,
    'to' => $to_filter_raw,
    'q' => $keyword_filter,
    'auto_refresh' => $auto_refresh_seconds,
    'demo' => $use_demo_data ? '1' : '0',
];
$build_link = static function (array $params) use ($query_base) {
    $query = array_merge($query_base, $params);
    foreach ($query as $k => $v) {
        if ($v === '' || $v === null) unset($query[$k]);
    }
    return 'tabletbereich.php?' . http_build_query($query);
};

$get_first_array_by_keys = static function ($source, array $keys) {
    if (!is_array($source)) return null;
    foreach ($keys as $key) {
        if (isset($source[$key]) && is_array($source[$key])) return $source[$key];
    }
    return null;
};

$forces_data = $get_first_array_by_keys($selected_alarm_detail, ['forces', 'units', 'groups', 'personnel', 'persons', 'users']);
$vehicles_data = $get_first_array_by_keys($selected_alarm_detail, ['vehicles', 'fahrzeuge', 'vehicle', 'resources_vehicle']);
if ($vehicles_data === null && isset($selected_alarm_detail['resources']) && is_array($selected_alarm_detail['resources'])) {
    $vehicles_data = array_values(array_filter($selected_alarm_detail['resources'], static function ($row) {
        if (!is_array($row)) return false;
        $text = mb_strtolower(json_encode($row, JSON_UNESCAPED_UNICODE), 'UTF-8');
        return mb_strpos($text, 'fahrzeug') !== false || mb_strpos($text, 'vehicle') !== false;
    }));
}

$force_cards = [];
if (is_array($forces_data)) {
    foreach ($forces_data as $force_key => $force_value) {
        if (!is_array($force_value)) {
            continue;
        }
        $name = trim((string)($force_value['name'] ?? $force_value['title'] ?? $force_value['unit_name'] ?? $force_value['group_name'] ?? ''));
        if ($name === '' && is_string($force_key)) {
            $name = trim($force_key);
        }
        if ($name === '') {
            $name = 'Einheit';
        }
        $type = trim((string)($force_value['type'] ?? $force_value['category'] ?? $force_value['organisation'] ?? ''));
        $status = trim((string)($force_value['status'] ?? $force_value['state'] ?? $force_value['alarm_status'] ?? ''));
        $count_raw = $force_value['count'] ?? $force_value['person_count'] ?? $force_value['members'] ?? null;
        $count = is_numeric($count_raw) ? (int)$count_raw : null;
        $details = [];
        foreach (['function', 'role', 'note', 'radio', 'identifier'] as $dkey) {
            if (!empty($force_value[$dkey])) {
                $details[] = ucfirst($dkey) . ': ' . (string)$force_value[$dkey];
            }
        }
        $force_cards[] = [
            'name' => $name,
            'type' => $type !== '' ? $type : '—',
            'status' => $status !== '' ? $status : '—',
            'count' => $count,
            'details' => $details,
        ];
    }
}

$vehicle_cards = [];
if (is_array($vehicles_data)) {
    foreach ($vehicles_data as $vehicle_key => $vehicle_value) {
        if (!is_array($vehicle_value)) {
            continue;
        }
        $name = trim((string)($vehicle_value['name'] ?? $vehicle_value['title'] ?? $vehicle_value['vehicle_name'] ?? $vehicle_value['funkrufname'] ?? ''));
        if ($name === '' && is_string($vehicle_key)) {
            $name = trim($vehicle_key);
        }
        if ($name === '') {
            $name = 'Fahrzeug';
        }
        $status = trim((string)($vehicle_value['status'] ?? $vehicle_value['state'] ?? $vehicle_value['alarm_status'] ?? ''));
        $radio = trim((string)($vehicle_value['radio'] ?? $vehicle_value['call_sign'] ?? $vehicle_value['funkruf'] ?? ''));
        $type = trim((string)($vehicle_value['type'] ?? $vehicle_value['category'] ?? $vehicle_value['kind'] ?? ''));
        $count_raw = $vehicle_value['count'] ?? $vehicle_value['amount'] ?? null;
        $count = is_numeric($count_raw) ? (int)$count_raw : null;
        $details = [];
        foreach (['location', 'note', 'identifier'] as $dkey) {
            if (!empty($vehicle_value[$dkey])) {
                $details[] = ucfirst($dkey) . ': ' . (string)$vehicle_value[$dkey];
            }
        }
        $vehicle_cards[] = [
            'name' => $name,
            'status' => $status !== '' ? $status : '—',
            'radio' => $radio,
            'type' => $type !== '' ? $type : '—',
            'count' => $count,
            'details' => $details,
        ];
    }
}

$reach_cards = [];
if (!empty($reach_entries)) {
    foreach ($reach_entries as $entry) {
        $details = [];
        if (is_array($entry['details'] ?? null)) {
            foreach (['ts', 'note', 'status_label'] as $k) {
                if (!empty($entry['details'][$k])) {
                    $details[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . (string)$entry['details'][$k];
                }
            }
        }
        $reach_cards[] = [
            'status' => (string)($entry['status'] ?? '—'),
            'name' => (string)($entry['name'] ?? 'Unbekannt'),
            'id' => (string)($entry['id'] ?? ''),
            'details' => $details,
        ];
    }
}

$reach_entries = [];
if (is_array($selected_alarm_reach)) {
    foreach (['received' => 'Empfangen', 'viewed' => 'Gesehen', 'confirmed' => 'Bestätigt'] as $key => $label) {
        $bucket = $selected_alarm_reach[$key] ?? null;
        if (!is_array($bucket)) continue;
        foreach ($bucket as $entry_key => $entry_value) {
            if (is_array($entry_value)) {
                $name = trim((string)($entry_value['name'] ?? $entry_value['user_name'] ?? $entry_value['display_name'] ?? ''));
                $reach_entries[] = [
                    'status' => $label,
                    'id' => (string)$entry_key,
                    'name' => $name !== '' ? $name : ('ID ' . (string)$entry_key),
                    'details' => $entry_value,
                ];
            } else {
                $reach_entries[] = [
                    'status' => $label,
                    'id' => (string)$entry_key,
                    'name' => is_scalar($entry_value) ? (string)$entry_value : ('ID ' . (string)$entry_key),
                    'details' => $entry_value,
                ];
            }
        }
    }
}

$selected_address = trim((string)($selected_alarm['address'] ?? $selected_alarm_detail['address'] ?? ''));
$selected_address_maps_url = $selected_address !== ''
    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($selected_address)
    : '';

$find_objektplan_for_alarm = static function (array $alarm_context) {
    $base_dir = realpath(__DIR__ . '/..');
    if ($base_dir === false) {
        return null;
    }

    $search_roots = [
        $base_dir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'objektplaene',
        $base_dir . DIRECTORY_SEPARATOR . 'objektplaene',
        $base_dir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'objektplaene',
    ];

    $normalize = static function ($value) {
        $value = mb_strtolower((string)$value, 'UTF-8');
        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string)$value);
    };

    $search_text = implode(' ', array_filter([
        (string)($alarm_context['address'] ?? ''),
        (string)($alarm_context['title'] ?? ''),
        (string)($alarm_context['location'] ?? ''),
        (string)($alarm_context['text'] ?? ''),
    ]));

    $collect_pdfs_in_directory = static function ($base_dir_local, $dir_full) {
        $items = [];
        if (!is_dir($dir_full)) return $items;
        try {
            $it = new FilesystemIterator($dir_full, FilesystemIterator::SKIP_DOTS);
            foreach ($it as $entry) {
                if (!$entry->isFile()) continue;
                if (mb_strtolower((string)$entry->getExtension(), 'UTF-8') !== 'pdf') continue;
                $full = $entry->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($full, strlen($base_dir_local))), '/');
                if ($relative === '') continue;
                $items[] = [
                    'filename' => $entry->getFilename(),
                    'url' => '../' . $relative,
                    'relative' => $relative,
                ];
            }
        } catch (Throwable $e) {
            return $items;
        }
        usort($items, static fn($a, $b) => strcasecmp($a['filename'], $b['filename']));
        return $items;
    };

    $preferred_plan_file = trim((string)($alarm_context['preferred_plan_file'] ?? ''));
    if ($preferred_plan_file !== '') {
        $preferred_full = realpath($base_dir . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $preferred_plan_file));
        if ($preferred_full !== false && is_file($preferred_full) && mb_strtolower((string)pathinfo($preferred_full, PATHINFO_EXTENSION), 'UTF-8') === 'pdf') {
            $relative = ltrim(str_replace('\\', '/', substr($preferred_full, strlen($base_dir))), '/');
            $dir_full = dirname($preferred_full);
            $all_in_dir = $collect_pdfs_in_directory($base_dir, $dir_full);
            return [
                'score' => 999,
                'filename' => basename($preferred_full),
                'url' => '../' . $relative,
                'all_in_dir' => $all_in_dir,
            ];
        }
    }

    $search_normalized = $normalize($search_text);
    if ($search_normalized === '') {
        return null;
    }

    $raw_tokens = preg_split('/\s+/u', $search_normalized, -1, PREG_SPLIT_NO_EMPTY);
    $ignore_tokens = ['strasse', 'str', 'straße', 'platz', 'einsatz', 'f2', 'f3', 'brand', 'feuerwehr', 'und', 'der', 'die', 'das'];
    $tokens = [];
    foreach ($raw_tokens as $token) {
        if (mb_strlen($token, 'UTF-8') < 3) continue;
        if (in_array($token, $ignore_tokens, true)) continue;
        $tokens[] = $token;
    }
    $tokens = array_values(array_unique($tokens));

    $best = null;
    foreach ($search_roots as $root) {
        if (!is_dir($root)) continue;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                if (mb_strtolower((string)$file->getExtension(), 'UTF-8') !== 'pdf') continue;

                $filename = $file->getFilename();
                $filename_norm = $normalize(pathinfo($filename, PATHINFO_FILENAME));
                $path = $file->getPathname();
                $relative_raw = ltrim(str_replace('\\', '/', substr($path, strlen($base_dir))), '/');
                $relative_norm = $normalize(pathinfo($relative_raw, PATHINFO_FILENAME));
                if ($filename_norm === '') continue;

                $score = 0;
                if ($selected_address = $normalize((string)($alarm_context['address'] ?? ''))) {
                    if ($selected_address !== '' && (mb_strpos($filename_norm, $selected_address) !== false || ($relative_norm !== '' && mb_strpos($relative_norm, $selected_address) !== false))) {
                        $score += 12;
                    }
                }
                foreach ($tokens as $token) {
                    if (mb_strpos($filename_norm, $token) !== false) {
                        $score += 2;
                    }
                    if ($relative_norm !== '' && mb_strpos($relative_norm, $token) !== false) {
                        $score += 2;
                    }
                }
                if ($score <= 0) continue;

                $relative = ltrim(str_replace('\\', '/', substr($path, strlen($base_dir))), '/');
                if ($relative === '') continue;
                $url = '../' . $relative;

                if ($best === null || $score > $best['score']) {
                    $dir_full = dirname($path);
                    $best = [
                        'score' => $score,
                        'filename' => $filename,
                        'url' => $url,
                        'all_in_dir' => $collect_pdfs_in_directory($base_dir, $dir_full),
                    ];
                }
            }
        } catch (Throwable $e) {
            // ignore directory scan errors
        }
    }

    return ($best !== null && $best['score'] >= 4) ? $best : null;
};

$objektplan_match = null;
$objektplan_files = [];
if (!empty($selected_alarm_id)) {
    $preferred_plan_file_for_match = '';
    if ($use_demo_data && isset($demo) && is_array($demo)) {
        $preferred_plan_file_for_match = (string)($demo['preferred_plan_file'] ?? '');
    }
    $objektplan_match = $find_objektplan_for_alarm([
        'address' => $selected_address,
        'title' => (string)($selected_alarm['title'] ?? $selected_alarm_detail['title'] ?? ''),
        'location' => (string)($selected_alarm_detail['location'] ?? ''),
        'text' => (string)($selected_alarm['text'] ?? $selected_alarm_detail['text'] ?? ''),
        'preferred_plan_file' => $preferred_plan_file_for_match,
    ]);
    if ($objektplan_match !== null) {
        $objektplan_files = is_array($objektplan_match['all_in_dir'] ?? null) ? $objektplan_match['all_in_dir'] : [];
        if (empty($objektplan_files) && !empty($objektplan_match['url'])) {
            $objektplan_files[] = [
                'filename' => (string)($objektplan_match['filename'] ?? 'Objektplan.pdf'),
                'url' => (string)$objektplan_match['url'],
                'relative' => '',
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabletbereich - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .tablet-shell { max-width: 860px; margin: 0 auto; }
        .tablet-card { border-radius: 14px; border: 1px solid #e5e7eb; }
        .tablet-card .card-header { border-top-left-radius: 14px; border-top-right-radius: 14px; font-weight: 600; }
        .alarm-list-item { border-radius: 10px; margin-bottom: .5rem; border: 1px solid #e5e7eb; }
        .alarm-list-item.active { border-color: #0d6efd; background: #eef5ff; }
        .kv-label { color: #6c757d; font-size: .85rem; }
        .kv-value { font-weight: 600; word-break: break-word; }
        .filter-grid .form-control, .filter-grid .form-select { min-height: 42px; }
        .force-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: .75rem; background: #fff; }
        .force-title { font-weight: 700; color: #1f2937; }
        .force-meta { font-size: .85rem; color: #6b7280; }
        .force-badges .badge { font-size: .75rem; }
        .vehicle-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: .75rem; background: #fff; }
        .reply-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: .75rem; background: #fff; }
        pre.tablet-pre {
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 44vh;
            overflow: auto;
            background: #111827;
            color: #f9fafb;
            border-radius: 10px;
            padding: .75rem;
            font-size: .78rem;
        }
        @media (max-width: 768px) {
            .container-fluid { padding-left: .75rem; padding-right: .75rem; }
            .btn, .form-select { min-height: 44px; }
            .h3 { font-size: 1.2rem; }
            .nav-tabs .nav-link { font-size: .92rem; padding: .55rem .65rem; }
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-3 py-md-4">
        <div class="tablet-shell">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h1 class="h3 mb-0"><i class="fas fa-tablet-alt text-dark me-2"></i>Tabletbereich</h1>
                <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
            </div>

            <div class="card tablet-card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end filter-grid">
                        <div class="col-12 col-md-3">
                            <label class="form-label mb-1">Einheit-ID</label>
                            <input type="number" class="form-control" name="einheit_id" value="<?php echo (int)$einheit_id; ?>" min="0">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label mb-1">Einsatz-ID (optional)</label>
                            <input type="number" class="form-control" name="alarm_id" value="<?php echo $selected_alarm_id > 0 ? (int)$selected_alarm_id : ''; ?>" min="0" placeholder="Automatisch = neuester Einsatz">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Alle</option>
                                <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Offen</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Geschlossen</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label mb-1">Auto-Refresh</label>
                            <select class="form-select" name="auto_refresh" id="autoRefreshSelect">
                                <option value="0" <?php echo $auto_refresh_seconds === 0 ? 'selected' : ''; ?>>Aus</option>
                                <option value="30" <?php echo $auto_refresh_seconds === 30 ? 'selected' : ''; ?>>30s</option>
                                <option value="60" <?php echo $auto_refresh_seconds === 60 ? 'selected' : ''; ?>>60s</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label mb-1">Stichwort</label>
                            <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($keyword_filter); ?>" placeholder="Titel, Adresse, Text oder ID">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1">Von</label>
                            <input type="datetime-local" class="form-control" name="from" value="<?php echo htmlspecialchars($from_filter_raw); ?>">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label mb-1">Bis</label>
                            <input type="datetime-local" class="form-control" name="to" value="<?php echo htmlspecialchars($to_filter_raw); ?>">
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-download me-1"></i>Divera-Daten laden</button>
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <a href="<?php echo htmlspecialchars($build_link(['alarm_id' => 0, 'demo' => '1'])); ?>" class="btn btn-outline-dark"><i class="fas fa-flask me-1"></i>Beispieldaten laden</a>
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <a href="<?php echo htmlspecialchars($build_link(['alarm_id' => 0, 'demo' => '0'])); ?>" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i>Live-Daten zurücksetzen</a>
                        </div>
                    </form>
                    <div class="small text-muted mt-2">API: <code><?php echo htmlspecialchars($api_base); ?></code></div>
                    <?php if ($auto_refresh_seconds > 0): ?>
                        <div class="small text-success mt-1"><i class="fas fa-sync-alt me-1"></i>Auto-Refresh aktiv: <span id="refreshCountdown"><?php echo (int)$auto_refresh_seconds; ?></span>s</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?>
                <?php echo show_error($error); ?>
            <?php elseif ($message): ?>
                <?php echo show_success($message); ?>
            <?php endif; ?>

            <?php if ($use_demo_data): ?>
            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-sliders-h me-2 text-dark"></i>Beispieldaten manuell anpassen</div>
                <div class="card-body">
                    <?php
                        $demo_prefill = isset($demo) && is_array($demo) ? $demo : $build_demo_payload();
                        $demo_data = $demo_prefill['selected_alarm_detail'] ?? [];
                        $demo_reach = $demo_prefill['selected_alarm_reach'] ?? [];
                    ?>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="save_demo_config">
                        <div class="col-12 col-md-6"><label class="form-label">Titel-Zusatz</label><input class="form-control" name="demo_title_suffix" value="<?php echo htmlspecialchars((string)($demo_data['title'] ?? '')); ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Adresse</label><input class="form-control" name="demo_address" value="<?php echo htmlspecialchars((string)($demo_data['address'] ?? '')); ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Ort</label><input class="form-control" name="demo_location" value="<?php echo htmlspecialchars((string)($demo_data['location'] ?? '')); ?>"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Stichwort</label><input class="form-control" name="demo_keyword" value="<?php echo htmlspecialchars((string)($demo_data['keyword'] ?? 'F2')); ?>"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Priorität</label><input type="number" class="form-control" name="demo_priority" value="<?php echo (int)($demo_data['priority'] ?? 2); ?>"></div>
                        <div class="col-12"><label class="form-label">Text</label><textarea class="form-control" rows="2" name="demo_text"><?php echo htmlspecialchars((string)($demo_data['text'] ?? '')); ?></textarea></div>
                        <div class="col-12 col-md-4"><label class="form-label">Objekt</label><input class="form-control" name="demo_object" value="<?php echo htmlspecialchars((string)($demo_data['object'] ?? '')); ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Kräfte (JSON-Array)</label><textarea class="form-control" rows="6" name="demo_forces_json"><?php echo htmlspecialchars(json_encode($demo_data['forces'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></div>
                        <div class="col-12 col-md-4"><label class="form-label">Fahrzeuge (JSON-Array)</label><textarea class="form-control" rows="6" name="demo_vehicles_json"><?php echo htmlspecialchars(json_encode($demo_data['vehicles'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></div>
                        <div class="col-12 col-md-6"><label class="form-label">Ressourcen (JSON-Array)</label><textarea class="form-control" rows="6" name="demo_resources_json"><?php echo htmlspecialchars(json_encode($demo_data['resources'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></div>
                        <div class="col-12 col-md-6"><label class="form-label">Reach (JSON-Objekt)</label><textarea class="form-control" rows="6" name="demo_reach_json"><?php echo htmlspecialchars(json_encode($demo_reach ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-dark"><i class="fas fa-save me-1"></i>Demo-Daten speichern</button>
                    </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="action" value="reset_demo_config">
                                <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Demo zurücksetzen</button>
                            </form>
                        </div>
                    <div class="small text-muted mt-2">Hinweis: JSON-Felder müssen gültiges JSON sein. Bei ungültigem JSON werden Standarddaten verwendet.</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-bell me-2 text-primary"></i>Aktive Divera-Einsätze</div>
                <div class="card-body">
                    <?php if (empty($filtered_alarms)): ?>
                        <p class="text-muted mb-0">Keine Einsätze für den aktuellen Filter gefunden.</p>
                    <?php else: ?>
                        <?php foreach ($filtered_alarms as $alarm): ?>
                            <?php
                                $aid = (int)$alarm['id'];
                                $active = $aid === (int)$selected_alarm_id;
                                $link = $build_link(['alarm_id' => $aid]);
                            ?>
                            <a class="d-block p-2 p-md-3 text-decoration-none alarm-list-item <?php echo $active ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($link); ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($alarm['title'] ?: ('Einsatz #' . $aid)); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($alarm['address'] ?? ''); ?></div>
                                    </div>
                                    <span class="badge bg-secondary">#<?php echo $aid; ?></span>
                                </div>
                                <div class="small mt-1">
                                    <?php if (!empty($alarm['closed'])): ?>
                                        <span class="badge bg-dark">Geschlossen</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Offen</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($alarm['date'])): ?>
                                    <div class="small text-muted mt-1"><?php echo date('d.m.Y H:i', (int)$alarm['date']); ?> Uhr</div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selected_alarm_id > 0): ?>
            <div class="card tablet-card mb-3">
                <div class="card-header bg-white"><i class="fas fa-info-circle me-2 text-success"></i>Einsatzdetails</div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" id="tabletAlarmTabs" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab">Übersicht</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-forces" type="button" role="tab">Kräfte</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-vehicles" type="button" role="tab">Fahrzeuge</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-replies" type="button" role="tab">Rückmeldungen</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-raw" type="button" role="tab">Rohdaten</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="kv-label">Einsatz-ID</div>
                            <div class="kv-value"><?php echo (int)$selected_alarm_id; ?></div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="kv-label">Titel</div>
                            <div class="kv-value"><?php echo htmlspecialchars((string)($selected_alarm['title'] ?? $selected_alarm_detail['title'] ?? '')); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="kv-label">Adresse</div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <div class="kv-value"><?php echo htmlspecialchars($selected_address); ?></div>
                                <?php if ($selected_address_maps_url !== ''): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($selected_address_maps_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-map-marker-alt me-1"></i>In Google Maps
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($objektplan_files)): ?>
                                    <a class="btn btn-sm btn-outline-danger" href="<?php echo htmlspecialchars($objektplan_files[0]['url']); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-file-pdf me-1"></i>Objektplan öffnen
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($objektplan_files)): ?>
                                <div class="small text-muted mt-1">Gefundene Pläne im passenden Ordner: <?php echo count($objektplan_files); ?></div>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <?php foreach ($objektplan_files as $plan): ?>
                                        <a class="btn btn-sm btn-outline-danger" href="<?php echo htmlspecialchars($plan['url']); ?>" target="_blank" rel="noopener noreferrer">
                                            <i class="fas fa-file-pdf me-1"></i><?php echo htmlspecialchars($plan['filename']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="small text-muted mt-1">Kein passender Objektplan gefunden (gesucht in `uploads/objektplaene`, `objektplaene`, `assets/objektplaene`).</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="kv-label">Text / Zusatzinfo</div>
                            <div class="kv-value"><?php echo nl2br(htmlspecialchars((string)($selected_alarm['text'] ?? $selected_alarm_detail['text'] ?? ''))); ?></div>
                        </div>
                    </div>
                        </div>
                        <div class="tab-pane fade" id="tab-forces" role="tabpanel">
                            <?php if (!empty($force_cards)): ?>
                                <div class="row g-2">
                                    <?php foreach ($force_cards as $force): ?>
                                        <div class="col-12 col-md-6">
                                            <div class="force-card h-100">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <div class="force-title"><?php echo htmlspecialchars($force['name']); ?></div>
                                                        <div class="force-meta"><?php echo htmlspecialchars($force['type']); ?></div>
                                                    </div>
                                                    <div class="force-badges d-flex gap-1 flex-wrap justify-content-end">
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($force['status']); ?></span>
                                                        <?php if ($force['count'] !== null): ?>
                                                            <span class="badge bg-primary"><?php echo (int)$force['count']; ?> Pers.</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($force['details'])): ?>
                                                    <div class="small text-muted mt-2">
                                                        <?php echo htmlspecialchars(implode(' · ', $force['details'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Keine strukturierten Kräfte-Daten im Divera-Response gefunden.</p>
                            <?php endif; ?>
                        </div>
                        <div class="tab-pane fade" id="tab-vehicles" role="tabpanel">
                            <?php if (!empty($vehicle_cards)): ?>
                                <div class="row g-2">
                                    <?php foreach ($vehicle_cards as $vehicle): ?>
                                        <div class="col-12 col-md-6">
                                            <div class="vehicle-card h-100">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <div class="force-title"><?php echo htmlspecialchars($vehicle['name']); ?></div>
                                                        <div class="force-meta"><?php echo htmlspecialchars($vehicle['type']); ?></div>
                                                        <?php if ($vehicle['radio'] !== ''): ?>
                                                            <div class="small text-muted mt-1"><i class="fas fa-broadcast-tower me-1"></i><?php echo htmlspecialchars($vehicle['radio']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="force-badges d-flex gap-1 flex-wrap justify-content-end">
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($vehicle['status']); ?></span>
                                                        <?php if ($vehicle['count'] !== null): ?>
                                                            <span class="badge bg-primary">x<?php echo (int)$vehicle['count']; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($vehicle['details'])): ?>
                                                    <div class="small text-muted mt-2"><?php echo htmlspecialchars(implode(' · ', $vehicle['details'])); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Keine strukturierten Fahrzeug-Daten im Divera-Response gefunden.</p>
                            <?php endif; ?>
                        </div>
                        <div class="tab-pane fade" id="tab-replies" role="tabpanel">
                            <?php
                                $received = is_array($selected_alarm_reach['received'] ?? null) ? count($selected_alarm_reach['received']) : 0;
                                $viewed = is_array($selected_alarm_reach['viewed'] ?? null) ? count($selected_alarm_reach['viewed']) : 0;
                                $confirmed = is_array($selected_alarm_reach['confirmed'] ?? null) ? count($selected_alarm_reach['confirmed']) : 0;
                            ?>
                            <div class="row g-2 mb-3">
                                <div class="col-4"><div class="p-2 border rounded text-center"><div class="small text-muted">Empfangen</div><div class="h5 mb-0"><?php echo (int)$received; ?></div></div></div>
                                <div class="col-4"><div class="p-2 border rounded text-center"><div class="small text-muted">Gesehen</div><div class="h5 mb-0"><?php echo (int)$viewed; ?></div></div></div>
                                <div class="col-4"><div class="p-2 border rounded text-center"><div class="small text-muted">Bestätigt</div><div class="h5 mb-0"><?php echo (int)$confirmed; ?></div></div></div>
                            </div>
                            <?php if (!empty($reach_cards)): ?>
                                <div class="row g-2">
                                    <?php foreach ($reach_cards as $reply): ?>
                                        <div class="col-12 col-md-6">
                                            <div class="reply-card h-100">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <div class="force-title"><?php echo htmlspecialchars($reply['name']); ?></div>
                                                        <?php if ($reply['id'] !== ''): ?>
                                                            <div class="force-meta">ID: <?php echo htmlspecialchars($reply['id']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($reply['status']); ?></span>
                                                </div>
                                                <?php if (!empty($reply['details'])): ?>
                                                    <div class="small text-muted mt-2"><?php echo htmlspecialchars(implode(' · ', $reply['details'])); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Keine Rückmeldungsdetails vorhanden.</p>
                            <?php endif; ?>
                        </div>
                        <div class="tab-pane fade" id="tab-raw" role="tabpanel">
                            <h6 class="mb-2">Alarm-Detail (<code>/api/v2/alarms/<?php echo (int)$selected_alarm_id; ?></code>)</h6>
                            <pre class="tablet-pre"><?php echo htmlspecialchars($pretty_json($raw_alarm_detail)); ?></pre>
                            <h6 class="mb-2 mt-3">Reach (<code>/api/v2/alarms/reach/<?php echo (int)$selected_alarm_id; ?></code>)</h6>
                            <pre class="tablet-pre"><?php echo htmlspecialchars($pretty_json($raw_alarm_reach)); ?></pre>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($auto_refresh_seconds > 0): ?>
    <script>
        (function () {
            var seconds = <?php echo (int)$auto_refresh_seconds; ?>;
            var countdownEl = document.getElementById('refreshCountdown');
            setInterval(function () {
                seconds -= 1;
                if (seconds <= 0) {
                    window.location.reload();
                    return;
                }
                if (countdownEl) countdownEl.textContent = String(seconds);
            }, 1000);
        })();
    </script>
    <?php endif; ?>
</body>
</html>
