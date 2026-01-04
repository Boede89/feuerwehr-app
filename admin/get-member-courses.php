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

$type = $_GET['type'] ?? '';

try {
    if ($type === 'by_name') {
        // Liste nach Namen: Alle Mitglieder mit ihren Lehrgängen
        // Zuerst prüfen, ob Daten in member_courses vorhanden sind
        $debug_stmt = $db->query("SELECT COUNT(*) as cnt FROM member_courses");
        $debug_result = $debug_stmt->fetch(PDO::FETCH_ASSOC);
        error_log("DEBUG: Anzahl Einträge in member_courses: " . $debug_result['cnt']);
        
        $stmt = $db->prepare("
            SELECT 
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) as name,
                GROUP_CONCAT(
                    DISTINCT CONCAT(c.name, '|', COALESCE(mc.completed_date, ''))
                    ORDER BY c.name
                    SEPARATOR '||'
                ) as courses_data
            FROM members m
            LEFT JOIN member_courses mc ON mc.member_id = m.id
            LEFT JOIN courses c ON c.id = mc.course_id AND c.name IS NOT NULL
            GROUP BY m.id, m.first_name, m.last_name
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute();
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("DEBUG: Anzahl Mitglieder gefunden: " . count($members));
        
        $result = [];
        foreach ($members as $member) {
            $courses = [];
            // Debug-Logging
            $courses_data_raw = $member['courses_data'] ?? null;
            error_log("DEBUG: Mitglied ID " . $member['id'] . " (" . $member['name'] . ") - courses_data: " . var_export($courses_data_raw, true));
            
            // Prüfe ob courses_data nicht NULL und nicht leer ist
            if (!empty($courses_data_raw) && $courses_data_raw !== null && trim($courses_data_raw) !== '') {
                $courses_array = explode('||', $courses_data_raw);
                error_log("DEBUG: Exploded courses_array: " . print_r($courses_array, true));
                foreach ($courses_array as $course_data) {
                    if (!empty($course_data) && trim($course_data) !== '') {
                        $parts = explode('|', $course_data, 2);
                        error_log("DEBUG: Course data parts: " . print_r($parts, true));
                        if (count($parts) >= 1 && !empty(trim($parts[0]))) {
                            $courses[] = [
                                'name' => trim($parts[0]),
                                'completed_date' => isset($parts[1]) ? trim($parts[1]) : ''
                            ];
                        }
                    }
                }
            }
            
            error_log("DEBUG: Mitglied ID " . $member['id'] . " - Anzahl Lehrgänge: " . count($courses));
            
            $result[] = [
                'id' => $member['id'],
                'name' => $member['name'],
                'courses' => $courses
            ];
        }
        
        echo json_encode(['success' => true, 'members' => $result]);
        
    } elseif ($type === 'by_course') {
        // Liste nach Lehrgang: Mitglieder die einen bestimmten Lehrgang haben
        $course_id = (int)($_GET['course_id'] ?? 0);
        
        if ($course_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ungültige Lehrgangs-ID']);
            exit;
        }
        
        $stmt = $db->prepare("
            SELECT 
                m.id,
                CONCAT(m.first_name, ' ', m.last_name) as name,
                mc.completed_date
            FROM member_courses mc
            INNER JOIN members m ON m.id = mc.member_id
            WHERE mc.course_id = ?
            ORDER BY m.last_name, m.first_name
        ");
        $stmt->execute([$course_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'members' => $members]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Ungültiger Typ']);
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden der Lehrgangsliste: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

