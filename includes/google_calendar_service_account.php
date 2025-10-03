<?php
/**
 * Google Calendar Service Account Integration für Feuerwehr App
 */

class GoogleCalendarServiceAccount {
    private $service_account_file;
    private $service_account_json;
    private $calendar_id;
    private $scopes = ['https://www.googleapis.com/auth/calendar'];
    private $access_token;
    private $token_expires;
    
    public function __construct($service_account_file_or_json, $calendar_id = 'primary', $is_json = false) {
        if ($is_json) {
            $this->service_account_json = $service_account_file_or_json;
            $this->service_account_file = null;
        } else {
            $this->service_account_file = $service_account_file_or_json;
            $this->service_account_json = null;
        }
        $this->calendar_id = $calendar_id;
    }
    
    /**
     * Service Account JSON laden und Access Token generieren
     */
    private function getAccessToken() {
        // Token noch gültig?
        if ($this->access_token && $this->token_expires && time() < $this->token_expires) {
            return $this->access_token;
        }
        
        // Service Account JSON laden
        if ($this->service_account_json) {
            // Aus JSON-String laden
            $service_account = json_decode($this->service_account_json, true);
            if (!$service_account) {
                throw new Exception('Service Account JSON-Inhalt ist ungültig');
            }
        } else {
            // Aus Datei laden
            if (!file_exists($this->service_account_file)) {
                throw new Exception('Service Account JSON-Datei nicht gefunden: ' . $this->service_account_file);
            }
            
            $service_account = json_decode(file_get_contents($this->service_account_file), true);
            if (!$service_account) {
                throw new Exception('Service Account JSON-Datei ist ungültig');
            }
        }
        
        // JWT Header
        $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
        
        // JWT Payload
        $now = time();
        $payload = json_encode([
            'iss' => $service_account['client_email'],
            'scope' => implode(' ', $this->scopes),
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ]);
        
        // Base64 URL encode
        $header_encoded = $this->base64UrlEncode($header);
        $payload_encoded = $this->base64UrlEncode($payload);
        
        // Signatur erstellen
        $signature = '';
        $private_key = $service_account['private_key'];
        openssl_sign($header_encoded . '.' . $payload_encoded, $signature, $private_key, 'SHA256');
        $signature_encoded = $this->base64UrlEncode($signature);
        
        // JWT erstellen
        $jwt = $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
        
        // Access Token anfordern
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Fehler beim Token-Request: ' . $error);
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error_description']) ? $error_data['error_description'] : 'Unbekannter Fehler';
            throw new Exception('OAuth2 Fehler: ' . $error_message);
        }
        
        $token_data = json_decode($response, true);
        $this->access_token = $token_data['access_token'];
        $this->token_expires = time() + ($token_data['expires_in'] ?? 3600) - 60; // 1 Minute Puffer
        
        return $this->access_token;
    }
    
    /**
     * Base64 URL Encoding
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * HTTP Request mit Access Token
     */
    private function makeRequest($url, $method = 'GET', $data = null) {
        $access_token = $this->getAccessToken();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Fehler: ' . $error);
        }
        
        if ($http_code >= 400) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTP ' . $http_code;
            throw new Exception('Google Calendar API Fehler: ' . $error_message);
        }
        
        return $response;
    }
    
    /**
     * Event erstellen
     */
    public function createEvent($title, $start_datetime, $end_datetime, $description = '') {
        $event = [
            'summary' => $title,
            'description' => $description,
            'start' => [
                'dateTime' => $this->formatDateTime($start_datetime),
                'timeZone' => 'Europe/Berlin'
            ],
            'end' => [
                'dateTime' => $this->formatDateTime($end_datetime),
                'timeZone' => 'Europe/Berlin'
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60], // 24 Stunden vorher
                    ['method' => 'popup', 'minutes' => 60] // 1 Stunde vorher
                ]
            ]
        ];
        
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendar_id) . '/events';
        $response = $this->makeRequest($url, 'POST', $event);
        
        $event_data = json_decode($response, true);
        return $event_data['id'] ?? null;
    }
    
    /**
     * Event aktualisieren
     */
    public function updateEvent($event_id, $title, $start_datetime, $end_datetime, $description = '') {
        $event = [
            'summary' => $title,
            'description' => $description,
            'start' => [
                'dateTime' => $this->formatDateTime($start_datetime),
                'timeZone' => 'Europe/Berlin'
            ],
            'end' => [
                'dateTime' => $this->formatDateTime($end_datetime),
                'timeZone' => 'Europe/Berlin'
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'popup', 'minutes' => 60]
                ]
            ]
        ];
        
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendar_id) . '/events/' . urlencode($event_id);
        $this->makeRequest($url, 'PUT', $event);
        
        return true;
    }
    
    /**
     * Event löschen
     */
    public function deleteEvent($event_id) {
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendar_id) . '/events/' . urlencode($event_id);
        $this->makeRequest($url, 'DELETE');
        
        return true;
    }
    
    /**
     * Events für Zeitraum abrufen
     */
    public function getEvents($start_datetime, $end_datetime) {
        $start = urlencode($this->formatDateTime($start_datetime));
        $end = urlencode($this->formatDateTime($end_datetime));
        
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendar_id) . '/events';
        $url .= '?timeMin=' . $start . '&timeMax=' . $end;
        $url .= '&singleEvents=true&orderBy=startTime';
        
        $response = $this->makeRequest($url);
        $data = json_decode($response, true);
        
        return $data['items'] ?? [];
    }
    
    /**
     * Datum/Zeit für Google Calendar formatieren
     */
    private function formatDateTime($datetime) {
        $dt = new DateTime($datetime, new DateTimeZone('Europe/Berlin'));
        return $dt->format('c'); // ISO 8601 Format
    }
    
    /**
     * Service Account Verbindung testen
     */
    public function testConnection() {
        try {
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($this->calendar_id);
            $this->makeRequest($url);
            return true;
        } catch (Exception $e) {
            error_log('Google Calendar Service Account Test fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }
}
?>
