<?php
/**
 * Datenbankverbindung für Feuerwehr App
 */

class Database {
    private $host = 'localhost';
    private $db_name = 'feuerwehr_app';
    private $username = 'feuerwehr_user';
    private $password = 'feuerwehr_password';
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            echo "Verbindungsfehler: " . $exception->getMessage();
        }

        return $this->conn;
    }
}

// Globale Datenbankverbindung
$database = new Database();
$db = $database->getConnection();
?>
