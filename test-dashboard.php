<?php
echo "Test 1: PHP funktioniert<br>";
try {
    $db = new PDO("mysql:host=feuerwehr_mysql;dbname=feuerwehr_app;charset=utf8", "feuerwehr_user", "feuerwehr_password");
    echo "Test 2: Datenbankverbindung erfolgreich<br>";
} catch (Exception $e) {
    echo "Test 2: Datenbankfehler: " . $e->getMessage() . "<br>";
}
echo "Test 3: Ende erreicht<br>";
?>
