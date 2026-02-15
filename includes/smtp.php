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
    
    /**
     * E-Mail mit PDF-Anhang senden
     */
    public function sendWithAttachment($to, $subject, $message, $isHtml, $attachmentContent, $attachmentFilename) {
        $boundary = '----=_Part_' . md5(uniqid(mt_rand(), true));
        $boundary2 = '----=_Part2_' . md5(uniqid(mt_rand(), true));
        $from_name_clean = trim($this->from_name);
        $from_email_clean = trim($this->from_email);
        $to_clean = trim($to);
        $subject_clean = trim($subject);
        $subject_clean = str_replace(["\r", "\n"], "", $subject_clean);
        $subject_clean = preg_replace('/\s+/', ' ', $subject_clean);
        if (preg_match('/[^\x20-\x7E]/', $subject_clean)) {
            $subject_clean = '=?UTF-8?B?' . base64_encode($subject_clean) . '?=';
        }
        $from_name_clean = str_replace(["\r", "\n"], "", $from_name_clean);
        $from_name_clean = preg_replace('/\s+/', ' ', $from_name_clean);
        $domain = 'localhost';
        if (preg_match('/@([^@]+)$/', $from_email_clean, $matches)) $domain = $matches[1];
        $messageId = sprintf('%s.%s@%s', date('YmdHis'), bin2hex(random_bytes(8)), $domain);
        if (preg_match('/[^\x20-\x7E]/', $from_name_clean) || strpos($from_name_clean, ',') !== false || strpos($from_name_clean, ';') !== false) {
            $from_name_encoded = '=?UTF-8?B?' . base64_encode($from_name_clean) . '?=';
            $email_data = "From: {$from_name_encoded} <{$from_email_clean}>\r\n";
        } else {
            $email_data = "From: \"{$from_name_clean}\" <{$from_email_clean}>\r\n";
        }
        $email_data .= "To: {$to_clean}\r\nReply-To: {$from_email_clean}\r\nReturn-Path: <{$from_email_clean}>\r\n";
        $email_data .= "Subject: {$subject_clean}\r\nDate: " . date('r') . "\r\nMessage-ID: <{$messageId}>\r\n";
        $email_data .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        $email_data .= "--{$boundary}\r\nContent-Type: multipart/alternative; boundary=\"{$boundary2}\"\r\n\r\n";
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $message));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace('/\n{3,}/', "\n\n", trim($plainText));
        $email_data .= "--{$boundary2}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $email_data .= chunk_split(base64_encode($plainText), 76, "\r\n");
        $email_data .= "--{$boundary2}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $email_data .= chunk_split(base64_encode($message), 76, "\r\n");
        $email_data .= "--{$boundary2}--\r\n";
        $email_data .= "--{$boundary}\r\nContent-Type: application/pdf; name=\"" . addslashes($attachmentFilename) . "\"\r\n";
        $email_data .= "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . addslashes($attachmentFilename) . "\"\r\n\r\n";
        $email_data .= chunk_split(base64_encode($attachmentContent), 76, "\r\n");
        $email_data .= "--{$boundary}--\r\n";
        return $this->sendRaw($to, $email_data);
    }

    private function sendRaw($to, $email_data) {
        try {
            $protocol = ($this->encryption === 'ssl' || $this->port == 465) ? 'ssl://' : '';
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
            $this->connection = stream_socket_client($protocol . $this->host . ':' . $this->port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
            if (!$this->connection) { error_log("SMTP Verbindungsfehler: $errstr ($errno)"); return false; }
            fgets($this->connection, 512);
            $this->sendCommand("EHLO localhost");
            if ($this->encryption !== 'ssl' && $this->port != 465) {
                $this->sendCommand("STARTTLS");
                if (!stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($this->connection); return false; }
                $this->sendCommand("EHLO localhost");
            }
            $this->sendCommand("AUTH LOGIN");
            $this->sendCommand(base64_encode($this->username));
            $this->sendCommand(base64_encode($this->password));
            $this->sendCommand("MAIL FROM: <{$this->from_email}>");
            $this->sendCommand("RCPT TO: <$to>");
            $this->sendCommand("DATA");
            fwrite($this->connection, $email_data . ".\r\n");
            $response = fgets($this->connection, 512);
            $this->sendCommand("QUIT");
            fclose($this->connection);
            return strpos($response, '250') === 0;
        } catch (Exception $e) {
            error_log("SMTP Fehler: " . $e->getMessage());
            if (isset($this->connection)) fclose($this->connection);
            return false;
        }
    }

    public function send($to, $subject, $message, $isHtml = false) {
        try {
            // Verbindungsprotokoll bestimmen
            $protocol = '';
            if ($this->encryption === 'ssl' || $this->port == 465) {
                $protocol = 'ssl://';
            }
            
            // Verbindung herstellen
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            $this->connection = stream_socket_client(
                $protocol . $this->host . ':' . $this->port,
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
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
            
            // STARTTLS nur wenn nicht bereits SSL
            if ($this->encryption !== 'ssl' && $this->port != 465) {
                $this->sendCommand("STARTTLS");
                
                // TLS-Verbindung starten
                if (!stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log("SMTP: TLS-Verbindung fehlgeschlagen");
                    fclose($this->connection);
                    return false;
                }
                
                // EHLO nach TLS senden
                $this->sendCommand("EHLO localhost");
            }
            
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
            $email_data .= "Return-Path: <{$from_email_clean}>\r\n";
            $email_data .= "Subject: {$subject_clean}\r\n";
            $email_data .= "Date: " . date('r') . "\r\n";
            $email_data .= "Message-ID: <{$messageId}>\r\n";
            $email_data .= "MIME-Version: 1.0\r\n";
            
            if ($isHtml) {
                // Multipart E-Mail mit Text und HTML-Alternative (bessere Zustellung)
                $boundary = '----=_Part_' . md5(uniqid(mt_rand(), true));
                $email_data .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
                $email_data .= "\r\n";
                
                // Plain-Text Version (aus HTML extrahieren)
                $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $message));
                $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
                $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText);
                $plainText = trim($plainText);
                
                // Text-Teil
                $email_data .= "--{$boundary}\r\n";
                $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $email_data .= "Content-Transfer-Encoding: base64\r\n";
                $email_data .= "\r\n";
                $email_data .= chunk_split(base64_encode($plainText), 76, "\r\n");
                
                // HTML-Teil
                $email_data .= "--{$boundary}\r\n";
                $email_data .= "Content-Type: text/html; charset=UTF-8\r\n";
                $email_data .= "Content-Transfer-Encoding: base64\r\n";
                $email_data .= "\r\n";
                $email_data .= chunk_split(base64_encode($message), 76, "\r\n");
                
                $email_data .= "--{$boundary}--\r\n";
            } else {
                $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $email_data .= "Content-Transfer-Encoding: base64\r\n";
                $email_data .= "\r\n";
                $email_data .= chunk_split(base64_encode($message), 76, "\r\n");
            }
            
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
