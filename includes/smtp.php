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
    
    public function send($to, $subject, $message, $isHtml = false) {
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
            // Header korrekt formatieren (RFC 5322 konform)
            $from_name_clean = trim($this->from_name);
            $from_email_clean = trim($this->from_email);
            $to_clean = trim($to);
            $subject_clean = trim($subject);
            
            // Subject-Header für RFC 5322 konform machen
            $subject_clean = str_replace(["\r", "\n"], "", $subject_clean);
            $subject_clean = preg_replace('/\s+/', ' ', $subject_clean);
            
            // RFC 2047 MIME-Encoding für Subject mit Non-ASCII Zeichen
            if (preg_match('/[^\x20-\x7E]/', $subject_clean)) {
                $subject_clean = '=?UTF-8?B?' . base64_encode($subject_clean) . '?=';
            }
            
            // From-Name für RFC 5322 konform machen
            $from_name_clean = str_replace(["\r", "\n"], "", $from_name_clean);
            $from_name_clean = preg_replace('/\s+/', ' ', $from_name_clean);
            
            // Domain aus From-E-Mail extrahieren für Message-ID
            $domain = 'localhost';
            if (preg_match('/@([^@]+)$/', $from_email_clean, $matches)) {
                $domain = $matches[1];
            }
            
            // Eindeutige Message-ID generieren
            $messageId = sprintf('%s.%s@%s', 
                date('YmdHis'), 
                bin2hex(random_bytes(8)), 
                $domain
            );
            
            // RFC 5322 konforme From-Header Formatierung
            // Wenn der Name Sonderzeichen enthält, in Anführungszeichen setzen
            if (preg_match('/[^\x20-\x7E]/', $from_name_clean) || strpos($from_name_clean, ',') !== false || strpos($from_name_clean, ';') !== false) {
                // MIME-Encoding für Non-ASCII Zeichen (RFC 2047)
                $from_name_encoded = '=?UTF-8?B?' . base64_encode($from_name_clean) . '?=';
                $email_data = "From: {$from_name_encoded} <{$from_email_clean}>\r\n";
            } else {
                // Einfache Anführungszeichen für ASCII-Zeichen
                $email_data = "From: \"{$from_name_clean}\" <{$from_email_clean}>\r\n";
            }
            $email_data .= "To: {$to_clean}\r\n";
            $email_data .= "Reply-To: {$from_email_clean}\r\n";
            $email_data .= "Subject: {$subject_clean}\r\n";
            $email_data .= "Date: " . date('r') . "\r\n";
            $email_data .= "Message-ID: <{$messageId}>\r\n";
            $email_data .= "MIME-Version: 1.0\r\n";
            $email_data .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
            $email_data .= "Content-Transfer-Encoding: base64\r\n";
            $email_data .= "X-Mailer: Feuerwehr-App/1.0\r\n";
            $email_data .= "X-Priority: 3\r\n";
            $email_data .= "Auto-Submitted: auto-generated\r\n";
            $email_data .= "Precedence: bulk\r\n";
            $email_data .= "\r\n";
            // Nachricht als Base64 kodieren für bessere Zustellung
            $email_data .= chunk_split(base64_encode($message), 76, "\r\n");
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
