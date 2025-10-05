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
     * @param string $location Event-Ort
     * @return string Event ID
     */
    public function createEvent($title, $startDateTime, $endDateTime, $description = '', $location = '') {
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
        
        // Ort hinzufügen, wenn angegeben
        if (!empty($location)) {
            $event['location'] = $location;
        }
        
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
        // Verwende Service Account direkt für vollständiges Löschen
        error_log("Verwende Service Account direkt für vollständiges Löschen: $eventId");
        return $this->deleteEventDirectly($eventId);
    }
    
    /**
     * Event mit Access Token löschen
     * @param string $eventId Event ID
     * @param string $accessToken Access Token
     * @return bool
     */
    private function deleteEventWithToken($eventId, $accessToken) {
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
        error_log("Google Calendar deleteEventWithToken - Event ID: $eventId, HTTP Code: $httpCode, Response: $response, Error: $error");
        
        // HTTP 204 = erfolgreich gelöscht
        // HTTP 410 = bereits gelöscht (auch als Erfolg betrachten)
        return $httpCode === 204 || $httpCode === 410;
    }
    
    /**
     * Event mit Service Account direkt löschen (ohne Access Token)
     * @param string $eventId Event ID
     * @return bool
     */
    private function deleteEventDirectly($eventId) {
        // Erstelle JWT für Service Account
        $jwt = $this->createJWT();
        
        // Hole Access Token mit JWT
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Service Account Token Request für deleteEventDirectly - HTTP Code: $httpCode, Response: $response, Error: $error");
        
        if ($httpCode !== 200) {
            error_log("Service Account Token Request für deleteEventDirectly fehlgeschlagen");
            return false;
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            error_log("Ungültige Token-Antwort von Service Account für deleteEventDirectly");
            return false;
        }
        
        $accessToken = $data['access_token'];
        
        // Versuche mehrfaches Löschen mit verschiedenen Methoden
        $success = false;
        
        // Methode 1: Standard DELETE
        $success = $this->deleteEventWithStandardMethod($eventId, $accessToken);
        if ($success) {
            error_log("Event erfolgreich mit Standard-Methode gelöscht: $eventId");
            return true;
        }
        
        // Methode 2: DELETE mit showDeleted=false Parameter
        $success = $this->deleteEventWithShowDeletedFalse($eventId, $accessToken);
        if ($success) {
            error_log("Event erfolgreich mit showDeleted=false gelöscht: $eventId");
            return true;
        }
        
        // Methode 3: Event zuerst stornieren, dann löschen
        $success = $this->deleteEventByCancellingFirst($eventId, $accessToken);
        if ($success) {
            error_log("Event erfolgreich durch Stornierung gelöscht: $eventId");
            return true;
        }
        
        error_log("Alle Lösch-Methoden fehlgeschlagen für Event: $eventId");
        return false;
    }
    
    /**
     * Event mit Standard DELETE Methode löschen
     * @param string $eventId Event ID
     * @param string $accessToken Access Token
     * @return bool
     */
    private function deleteEventWithStandardMethod($eventId, $accessToken) {
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
        
        error_log("Standard DELETE - Event ID: $eventId, HTTP Code: $httpCode, Response: $response, Error: $error");
        
        return $httpCode === 204 || $httpCode === 410;
    }
    
    /**
     * Event mit showDeleted=false Parameter löschen
     * @param string $eventId Event ID
     * @param string $accessToken Access Token
     * @return bool
     */
    private function deleteEventWithShowDeletedFalse($eventId, $accessToken) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendarId) . '/events/' . urlencode($eventId) . '?showDeleted=false');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("showDeleted=false DELETE - Event ID: $eventId, HTTP Code: $httpCode, Response: $response, Error: $error");
        
        return $httpCode === 204 || $httpCode === 410;
    }
    
    /**
     * Event durch Stornierung löschen
     * @param string $eventId Event ID
     * @param string $accessToken Access Token
     * @return bool
     */
    private function deleteEventByCancellingFirst($eventId, $accessToken) {
        // Zuerst Event stornieren
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendarId) . '/events/' . urlencode($eventId));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['status' => 'cancelled']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Event stornieren - Event ID: $eventId, HTTP Code: $httpCode, Response: $response, Error: $error");
        
        if ($httpCode !== 200) {
            return false;
        }
        
        // Warte kurz
        sleep(1);
        
        // Jetzt Event löschen
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
        
        error_log("Event nach Stornierung löschen - Event ID: $eventId, HTTP Code: $httpCode, Response: $response, Error: $error");
        
        return $httpCode === 204 || $httpCode === 410;
    }
    
    /**
     * Event mit Service Account direkt löschen
     * @param string $eventId Event ID
     * @return bool
     */
    private function deleteEventWithServiceAccount($eventId) {
        // Erstelle JWT für Service Account
        $jwt = $this->createJWT();
        
        // Hole Access Token mit JWT
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Service Account Token Request - HTTP Code: $httpCode, Response: $response, Error: $error");
        
        if ($httpCode !== 200) {
            error_log("Service Account Token Request fehlgeschlagen");
            return false;
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            error_log("Ungültige Token-Antwort von Service Account");
            return false;
        }
        
        $accessToken = $data['access_token'];
        return $this->deleteEventWithToken($eventId, $accessToken);
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
     * Event wirklich vollständig löschen (auch stornierte Events)
     * @param string $eventId Event ID
     * @return bool
     */
    public function reallyDeleteEvent($eventId) {
        // Verwende Service Account direkt für vollständiges Löschen
        error_log("Verwende Service Account direkt für reallyDeleteEvent: $eventId");
        return $this->deleteEventDirectly($eventId);
    }
    
    /**
     * Event mit Access Token wirklich löschen
     * @param string $eventId Event ID
     * @param string $accessToken Access Token
     * @return bool
     */
    private function reallyDeleteEventWithToken($eventId, $accessToken) {
        // Mehrfache Löschversuche für stornierte Events
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            error_log("Löschversuch $attempt für Event: $eventId");
            
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
            
            error_log("Löschversuch $attempt - HTTP Code: $httpCode, Response: $response, Error: $error");
            
            // HTTP 204 = erfolgreich gelöscht
            // HTTP 410 = bereits gelöscht
            if ($httpCode === 204 || $httpCode === 410) {
                error_log("Event erfolgreich gelöscht (Versuch $attempt): $eventId");
                return true;
            }
            
            // Warte kurz vor dem nächsten Versuch
            if ($attempt < 3) {
                sleep(2);
            }
        }
        
        // Prüfe ob Event noch existiert
        try {
            $event = $this->getEvent($eventId);
            
            if (isset($event['status']) && $event['status'] === 'cancelled') {
                error_log("Event ist cancelled nach Löschversuchen - betrachte als gelöscht: $eventId");
                return true;
            }
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                error_log("Event nicht gefunden (404) nach Löschversuchen - erfolgreich gelöscht: $eventId");
                return true;
            }
        }
        
        error_log("Event konnte nicht vollständig gelöscht werden: $eventId");
        return false;
    }
    
    /**
     * Event mit Service Account wirklich löschen
     * @param string $eventId Event ID
     * @return bool
     */
    private function reallyDeleteEventWithServiceAccount($eventId) {
        // Erstelle JWT für Service Account
        $jwt = $this->createJWT();
        
        // Hole Access Token mit JWT
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        error_log("Service Account Token Request für reallyDeleteEvent - HTTP Code: $httpCode, Response: $response, Error: $error");
        
        if ($httpCode !== 200) {
            error_log("Service Account Token Request für reallyDeleteEvent fehlgeschlagen");
            return false;
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            error_log("Ungültige Token-Antwort von Service Account für reallyDeleteEvent");
            return false;
        }
        
        $accessToken = $data['access_token'];
        return $this->reallyDeleteEventWithToken($eventId, $accessToken);
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
    
    /**
     * Event aktualisieren
     * @param string $eventId Event ID
     * @param array $eventData Event-Daten
     * @return bool
     */
    public function updateEvent($eventId, $eventData) {
        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                error_log('Kein Access Token für Event-Update verfügbar');
                return false;
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendarId) . '/events/' . urlencode($eventId));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                error_log('cURL Fehler beim Event-Update: ' . $error);
                return false;
            }
            
            if ($httpCode === 200) {
                error_log('Event erfolgreich aktualisiert: ' . $eventId);
                return true;
            } else {
                error_log('Fehler beim Event-Update: HTTP ' . $httpCode . ' - ' . $response);
                return false;
            }
            
        } catch (Exception $e) {
            error_log('Exception beim Event-Update: ' . $e->getMessage());
            return false;
        }
    }
}
?>