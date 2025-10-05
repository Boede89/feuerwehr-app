<?php
/**
 * Google Calendar Service Account Integration
 * Diese Klasse ermöglicht die Integration mit Google Calendar über einen Service Account JSON-Schlüssel
 */

class GoogleCalendarServiceAccount {
    private $credentials;
    private $calendarId;
    private $accessToken;
    
    /**
     * Konstruktor
     * @param string $serviceAccountFile Pfad zur Service Account JSON-Datei oder JSON-Inhalt
     * @param string $calendarId Google Calendar ID
     * @param bool $isJson True wenn $serviceAccountFile JSON-Inhalt ist, false wenn Dateipfad
     */
    public function __construct($serviceAccountFile, $calendarId = 'primary', $isJson = false) {
        $this->calendarId = $calendarId;
        
        if ($isJson) {
            // JSON-Inhalt direkt verwenden
            $this->credentials = json_decode($serviceAccountFile, true);
        } else {
            // Datei laden
            if (!file_exists($serviceAccountFile)) {
                throw new Exception('Service Account Datei nicht gefunden: ' . $serviceAccountFile);
            }
            $this->credentials = json_decode(file_get_contents($serviceAccountFile), true);
        }
        
        if (!$this->credentials) {
            throw new Exception('Ungültige Service Account Konfiguration');
        }
    }
    
    /**
     * Access Token abrufen
     * @return string Access Token
     */
    private function getAccessToken() {
        if ($this->accessToken && $this->isTokenValid()) {
            return $this->accessToken;
        }
        
        $jwt = $this->createJWT();
        
        $postData = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Fehler beim Abrufen des Access Tokens: HTTP ' . $httpCode . ' - ' . $response);
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            throw new Exception('Ungültige Token-Antwort: ' . $response);
        }
        
        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);
        
        return $this->accessToken;
    }
    
    /**
     * Prüfen ob Token noch gültig ist
     * @return bool
     */
    private function isTokenValid() {
        return isset($this->tokenExpiry) && $this->tokenExpiry > time() + 60; // 60 Sekunden Puffer
    }
    
    /**
     * JWT erstellen
     * @return string JWT Token
     */
    private function createJWT() {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        
        $now = time();
        $payload = [
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ];
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = '';
        $signatureData = $headerEncoded . '.' . $payloadEncoded;
        
        if (!openssl_sign($signatureData, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new Exception('Fehler beim Signieren des JWT');
        }
        
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Base64 URL Encoding
     * @param string $data
     * @return string
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Event erstellen
     * @param string $title Event-Titel
     * @param string $startDateTime Start-Zeit (Y-m-d H:i:s)
     * @param string $endDateTime End-Zeit (Y-m-d H:i:s)
     * @param string $description Event-Beschreibung
     * @return string Event ID
     */
    public function createEvent($title, $startDateTime, $endDateTime, $description = '') {
        $accessToken = $this->getAccessToken();
        
        $event = [
            'summary' => $title,
            'description' => $description,
            'start' => [
                'dateTime' => $this->formatDateTime($startDateTime),
                'timeZone' => 'Europe/Berlin'
            ],
            'end' => [
                'dateTime' => $this->formatDateTime($endDateTime),
                'timeZone' => 'Europe/Berlin'
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendarId) . '/events');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Fehler beim Erstellen des Events: HTTP ' . $httpCode . ' - ' . $response);
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['id'])) {
            throw new Exception('Ungültige Event-Antwort: ' . $response);
        }
        
        return $data['id'];
    }
    
    /**
     * DateTime formatieren für Google Calendar
     * @param string $dateTime
     * @return string
     */
    private function formatDateTime($dateTime) {
        $dt = new DateTime($dateTime, new DateTimeZone('Europe/Berlin'));
        return $dt->format('c');
    }
    
    /**
     * Event löschen
     * @param string $eventId Event ID
     * @return bool
     */
    public function deleteEvent($eventId) {
        $accessToken = $this->getAccessToken();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendarId) . '/events/' . urlencode($eventId));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Log für Debugging
        error_log("Google Calendar deleteEvent - Event ID: $eventId, HTTP Code: $httpCode, Response: $response, Error: $error");
        
        // HTTP 204 = erfolgreich gelöscht
        // HTTP 410 = bereits gelöscht (auch als Erfolg betrachten)
        return $httpCode === 204 || $httpCode === 410;
    }
    
    /**
     * Event vollständig löschen (auch stornierte Events)
     * @param string $eventId Event ID
     * @return bool
     */
    public function forceDeleteEvent($eventId) {
        $accessToken = $this->getAccessToken();
        
        // Zuerst versuchen, das Event normal zu löschen
        $deleted = $this->deleteEvent($eventId);
        
        if ($deleted) {
            error_log("Event erfolgreich gelöscht (normal): $eventId");
            return true;
        }
        
        // Falls das nicht funktioniert, prüfe ob das Event noch existiert
        try {
            $event = $this->getEvent($eventId);
            
            // Wenn das Event existiert und "cancelled" ist, betrachte es als erfolgreich gelöscht
            if (isset($event['status']) && $event['status'] === 'cancelled') {
                error_log("Event ist cancelled - betrachte als erfolgreich gelöscht: $eventId");
                return true; // Stornierte Events sind praktisch gelöscht
            }
            
            // Wenn das Event noch aktiv ist, versuche es erneut zu löschen
            if (isset($event['status']) && $event['status'] === 'confirmed') {
                error_log("Event ist noch aktiv, versuche erneutes Löschen: $eventId");
                sleep(1);
                return $this->deleteEvent($eventId);
            }
            
        } catch (Exception $e) {
            // Event existiert nicht mehr (404) = erfolgreich gelöscht
            if (strpos($e->getMessage(), '404') !== false) {
                error_log("Event nicht gefunden (404) - erfolgreich gelöscht: $eventId");
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Prüfe ob Event wirklich gelöscht ist (ignoriert stornierte Events)
     * @param string $eventId Event ID
     * @return bool true wenn gelöscht oder storniert
     */
    public function isEventDeleted($eventId) {
        try {
            $event = $this->getEvent($eventId);
            
            // Wenn das Event existiert und "cancelled" ist, betrachte es als gelöscht
            if (isset($event['status']) && $event['status'] === 'cancelled') {
                return true;
            }
            
            // Wenn das Event noch aktiv ist, ist es nicht gelöscht
            return false;
            
        } catch (Exception $e) {
            // Event existiert nicht mehr (404) = erfolgreich gelöscht
            if (strpos($e->getMessage(), '404') !== false) {
                return true;
            }
            
            // Andere Fehler = nicht sicher ob gelöscht
            return false;
        }
    }
    
    /**
     * Event abrufen
     * @param string $eventId Event ID
     * @return array Event-Daten
     */
    public function getEvent($eventId) {
        $accessToken = $this->getAccessToken();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendarId) . '/events/' . urlencode($eventId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Fehler beim Abrufen des Events: HTTP ' . $httpCode . ' - ' . $response);
        }
        
        return json_decode($response, true);
    }
}
?>