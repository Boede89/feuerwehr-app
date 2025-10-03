<?php
/**
 * Einfache SMTP-Implementierung
 */

class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $from_email;
    private $from_name;
    private $connection;
    
    public function __construct($host, $port, $username, $password, $encryption, $from_email, $from_name) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = $encryption;
        $this->from_email = $from_email;
        $this->from_name = $from_name;
    }
    
    public function send($to, $subject, $message) {
        try {
            // Verbindung herstellen
            $this->connection = fsockopen($this->host, $this->port, $errno, $errstr, 30);
            
            if (!$this->connection) {
                error_log("SMTP Verbindungsfehler: $errstr ($errno)");
                return false;
            }
            
            // Server-Begrüßung lesen
            $response = fgets($this->connection, 512);
            error_log("SMTP Server: " . trim($response));
            
            if (strpos($response, '220') !== 0) {
                error_log("SMTP: Ungültige Server-Antwort");
                fclose($this->connection);
                return false;
            }
            
            // EHLO senden
            $this->sendCommand("EHLO localhost");
            
            // STARTTLS senden
            $this->sendCommand("STARTTLS");
            
            // TLS-Verbindung starten
            if (!stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("SMTP: TLS-Verbindung fehlgeschlagen");
                fclose($this->connection);
                return false;
            }
            
            // EHLO nach TLS senden
            $this->sendCommand("EHLO localhost");
            
            // AUTH LOGIN
            $this->sendCommand("AUTH LOGIN");
            $this->sendCommand(base64_encode($this->username));
            $this->sendCommand(base64_encode($this->password));
            
            // E-Mail senden
            $this->sendCommand("MAIL FROM: <{$this->from_email}>");
            $this->sendCommand("RCPT TO: <$to>");
            $this->sendCommand("DATA");
            
            // E-Mail-Header und -Inhalt senden
            $email_data = "From: {$this->from_name} <{$this->from_email}>\r\n";
            $email_data .= "To: $to\r\n";
            $email_data .= "Subject: $subject\r\n";
            $email_data .= "Content-Type: text/html; charset=UTF-8\r\n";
            $email_data .= "\r\n";
            $email_data .= $message . "\r\n";
            $email_data .= ".\r\n";
            
            fwrite($this->connection, $email_data);
            $response = fgets($this->connection, 512);
            error_log("SMTP E-Mail: " . trim($response));
            
            $this->sendCommand("QUIT");
            fclose($this->connection);
            
            return strpos($response, '250') === 0;
            
        } catch (Exception $e) {
            error_log("SMTP Fehler: " . $e->getMessage());
            if ($this->connection) {
                fclose($this->connection);
            }
            return false;
        }
    }
    
    private function sendCommand($command) {
        fwrite($this->connection, $command . "\r\n");
        $response = fgets($this->connection, 512);
        
        // Debug-Ausgabe
        error_log("SMTP: $command -> " . trim($response));
        
        // Für EHLO-Kommando: Gmail sendet mehrere 250-Antworten
        if ($command === "EHLO localhost" && strpos($response, '250-') === 0) {
            do {
                $response = fgets($this->connection, 512);
                error_log("SMTP: EHLO (2) -> " . trim($response));
            } while (strpos($response, '250-') === 0);
        }
        
        return $response;
    }
}
?>
