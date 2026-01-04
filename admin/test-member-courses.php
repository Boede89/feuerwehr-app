<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !has_permission('courses')) {
    die('Zugriff verweigert');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Test: Member Courses</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Test: Member Courses</h1>
    
    <h2>1. Direkte Abfrage: member_courses Tabelle</h2>
    <?php
    try {
        $stmt = $db->query("SELECT * FROM member_courses ORDER BY id DESC LIMIT 20");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Anzahl Einträge: " . count($results) . "</p>";
        if (!empty($results)) {
            echo "<table>";
            echo "<tr><th>ID</th><th>member_id</th><th>course_id</th><th>completed_date</th><th>created_at</th></tr>";
            foreach ($results as $row) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['member_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['course_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['completed_date'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['created_at'] ?? '') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: red;'>KEINE EINTRÄGE GEFUNDEN!</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    
    <h2>2. Abfrage mit JOIN: Mitglieder mit Lehrgängen</h2>
    <?php
    try {
        $stmt = $db->prepare("
            SELECT 
                m.id as member_id,
                CONCAT(m.first_name, ' ', m.last_name) as member_name,
                c.id as course_id,
                c.name as course_name,
                mc.completed_date
            FROM members m
            LEFT JOIN member_courses mc ON mc.member_id = m.id
            LEFT JOIN courses c ON c.id = mc.course_id
            ORDER BY m.last_name, m.first_name, c.name
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Anzahl Zeilen: " . count($results) . "</p>";
        if (!empty($results)) {
            echo "<table>";
            echo "<tr><th>Mitglied ID</th><th>Mitglied Name</th><th>Lehrgang ID</th><th>Lehrgang Name</th><th>Abschlussdatum</th></tr>";
            foreach ($results as $row) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['member_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['member_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['course_id'] ?? 'NULL') . "</td>";
                echo "<td>" . htmlspecialchars($row['course_name'] ?? 'NULL') . "</td>";
                echo "<td>" . htmlspecialchars($row['completed_date'] ?? '') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: red;'>KEINE EINTRÄGE GEFUNDEN!</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    
    <h2>3. GROUP_CONCAT Test (wie in get-member-courses.php)</h2>
    <?php
    try {
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
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Anzahl Mitglieder: " . count($results) . "</p>";
        if (!empty($results)) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Name</th><th>courses_data (RAW)</th><th>Anzahl Lehrgänge</th></tr>";
            foreach ($results as $row) {
                $courses_data = $row['courses_data'];
                $courses_count = 0;
                if (!empty($courses_data) && $courses_data !== null && trim($courses_data) !== '') {
                    $courses_array = explode('||', $courses_data);
                    $courses_count = count(array_filter($courses_array, function($c) { return !empty(trim($c)); }));
                }
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                echo "<td>" . htmlspecialchars($courses_data ?? 'NULL') . "</td>";
                echo "<td>" . $courses_count . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: red;'>KEINE EINTRÄGE GEFUNDEN!</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    
    <h2>4. Alle Lehrgänge</h2>
    <?php
    try {
        $stmt = $db->query("SELECT id, name FROM courses ORDER BY name");
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Anzahl Lehrgänge: " . count($courses) . "</p>";
        if (!empty($courses)) {
            echo "<ul>";
            foreach ($courses as $course) {
                echo "<li>ID: " . htmlspecialchars($course['id']) . " - Name: " . htmlspecialchars($course['name']) . "</li>";
            }
            echo "</ul>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
</body>
</html>

