<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !has_permission('courses')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert']);
    exit;
}

header('Content-Type: application/json');

$member_id = (int)($_GET['member_id'] ?? 0);

if ($member_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige Mitglieds-ID']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.name,
            c.description,
            mc.completed_date
        FROM member_courses mc
        INNER JOIN courses c ON c.id = mc.course_id
        WHERE mc.member_id = ?
        ORDER BY c.name
    ");
    $stmt->execute([$member_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ist Mitglied PA-Träger? Dann AGT-Lehrgang immer anzeigen (falls nicht schon in Liste)
    $stmt_pa = $db->prepare("SELECT 1 FROM atemschutz_traeger WHERE member_id = ? LIMIT 1");
    $stmt_pa->execute([$member_id]);
    $is_pa = (bool)$stmt_pa->fetch();
    if (!$is_pa) {
        $stmt_pa = $db->prepare("SELECT 1 FROM members WHERE id = ? AND is_pa_traeger = 1 LIMIT 1");
        $stmt_pa->execute([$member_id]);
        $is_pa = (bool)$stmt_pa->fetch();
    }
    if ($is_pa) {
        $stmt_agt = $db->prepare("SELECT id, name, description FROM courses WHERE LOWER(TRIM(name)) = 'agt' LIMIT 1");
        $stmt_agt->execute();
        $agt = $stmt_agt->fetch(PDO::FETCH_ASSOC);
        if ($agt) {
            $has_agt = false;
            foreach ($courses as $c) {
                if ((int)$c['id'] === (int)$agt['id']) {
                    $has_agt = true;
                    break;
                }
            }
            if (!$has_agt) {
                $courses[] = [
                    'id' => $agt['id'],
                    'name' => $agt['name'],
                    'description' => $agt['description'] ?? '',
                    'completed_date' => null,
                    'year' => ''
                ];
                usort($courses, function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            }
        }
    }
    
    // Jahr extrahieren für Anzeige
    foreach ($courses as &$course) {
        if (!empty($course['completed_date'])) {
            $date_parts = explode('-', $course['completed_date']);
            $course['year'] = $date_parts[0] ?? '';
        } else {
            $course['year'] = isset($course['year']) ? $course['year'] : '';
        }
    }
    unset($course);
    
    echo json_encode(['success' => true, 'courses' => $courses]);
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgänge: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

