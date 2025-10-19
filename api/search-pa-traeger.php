<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Berechtigung prüfen
if (!isset($_SESSION['user_id']) || (!has_permission('atemschutz') && !hasAdminPermission())) {
    http_response_code(403);
    echo json_encode(['error' => 'Zugriff verweigert']);
    exit;
}

// POST-Daten empfangen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST-Requests erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige JSON-Daten']);
    exit;
}

$uebungsDatum = $input['uebungsDatum'] ?? '';
$anzahlPaTraeger = $input['anzahlPaTraeger'] ?? 'alle';
$statusFilter = $input['statusFilter'] ?? [];

// Validierung
if (empty($uebungsDatum) || empty($statusFilter)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Parameter']);
    exit;
}

try {
    // Warnschwelle laden (Standard: 90 Tage)
    $warn_days = 90;
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
    $stmt->execute();
    $setting = $stmt->fetch();
    if ($setting && is_numeric($setting['setting_value'])) {
        $warn_days = (int)$setting['setting_value'];
    }
    
    // Lade alle aktiven Geräteträger
    $stmt = $db->prepare("
        SELECT * FROM atemschutz_traeger 
        WHERE status = 'Aktiv'
        ORDER BY last_name ASC, first_name ASC
    ");
    $stmt->execute();
    $all_traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filtered_traeger = [];
    $now = new DateTime('today');
    
    foreach ($all_traeger as $traeger) {
        $streckeExpired = false; $g263Expired = false; $uebungExpired = false;
        $streckeWarn = false; $g263Warn = false; $uebungWarn = false;
        
        // Prüfe Strecke (1 Jahr Gültigkeit)
        $streckeAm = new DateTime($traeger['strecke_am']);
        $streckeBis = clone $streckeAm;
        $streckeBis->add(new DateInterval('P1Y'));
        $diff = (int)$now->diff($streckeBis)->format('%r%a');
        if ($diff < 0) {
            $streckeExpired = true;
        } elseif ($diff <= $warn_days) {
            $streckeWarn = true;
        }
        
        // Prüfe G26.3 (3 Jahre unter 50, 1 Jahr über 50)
        $g263Am = new DateTime($traeger['g263_am']);
        $birthdate = new DateTime($traeger['birthdate']);
        $age = $birthdate->diff(new DateTime())->y;
        
        $g263Bis = clone $g263Am;
        if ($age < 50) {
            $g263Bis->add(new DateInterval('P3Y'));
        } else {
            $g263Bis->add(new DateInterval('P1Y'));
        }
        
        $diff = (int)$now->diff($g263Bis)->format('%r%a');
        if ($diff < 0) {
            $g263Expired = true;
        } elseif ($diff <= $warn_days) {
            $g263Warn = true;
        }
        
        // Prüfe Übung (1 Jahr Gültigkeit)
        $uebungAm = new DateTime($traeger['uebung_am']);
        $uebungBis = clone $uebungAm;
        $uebungBis->add(new DateInterval('P1Y'));
        
        $diff = (int)$now->diff($uebungBis)->format('%r%a');
        if ($diff < 0) {
            $uebungExpired = true;
        } elseif ($diff <= $warn_days) {
            $uebungWarn = true;
        }
        
        // Status berechnen
        $status = 'Tauglich';
        if ($streckeExpired || $g263Expired || $uebungExpired) {
            if ($uebungExpired && !$streckeExpired && !$g263Expired) {
                $status = 'Übung abgelaufen';
            } else {
                $status = 'Abgelaufen';
            }
        } elseif ($streckeWarn || $g263Warn || $uebungWarn) {
            $status = 'Warnung';
        }
        
        // Prüfe ob Status im Filter enthalten ist
        if (in_array($status, $statusFilter)) {
            $traeger['calculated_status'] = $status;
            $traeger['uebung_bis'] = $uebungBis->format('Y-m-d');
            $filtered_traeger[] = $traeger;
        }
    }
    
    // Sortiere nach Übung bis (älteste zuerst)
    usort($filtered_traeger, function($a, $b) {
        return strcmp($a['uebung_bis'], $b['uebung_bis']);
    });
    
    // Begrenze auf gewünschte Anzahl
    if ($anzahlPaTraeger !== 'alle' && is_numeric($anzahlPaTraeger)) {
        $filtered_traeger = array_slice($filtered_traeger, 0, (int)$anzahlPaTraeger);
    }
    
    // Formatiere Daten für Ausgabe
    $result = [];
    foreach ($filtered_traeger as $traeger) {
        $result[] = [
            'id' => $traeger['id'],
            'name' => $traeger['first_name'] . ' ' . $traeger['last_name'],
            'email' => $traeger['email'],
            'status' => $traeger['calculated_status'],
            'strecke_am' => $traeger['strecke_am'],
            'g263_am' => $traeger['g263_am'],
            'uebung_am' => $traeger['uebung_am'],
            'uebung_bis' => $traeger['uebung_bis']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($result),
        'traeger' => $result
    ]);
    
} catch (Exception $e) {
    error_log("Fehler bei PA-Träger-Suche: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Fehler bei der Suche: ' . $e->getMessage()]);
}
?>

