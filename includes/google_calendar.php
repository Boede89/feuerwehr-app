<?php
/**
 * Google Calendar Integration für Feuerwehr App
 */

class GoogleCalendar {
    private $api_key;
    private $calendar_id;
    private $base_url = 'https://www.googleapis.com/calendar/v3';
    
    public function __construct($api_key, $calendar_id = 'primary') {
        $this->api_key = $api_key;
        $this->calendar_id = $calendar_id;
    }
    
    /**
     * Event erstellen
     */
    public function createEvent($title, $start_datetime, $end_datetime, $description = '') {
        if (empty($this->api_key)) {
            throw new Exception('Google Calendar API Key ist nicht konfiguriert');
        }
        
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
        
        $url = $this->base_url . '/calendars/' . urlencode($this->calendar_id) . '/events';
        $url .= '?key=' . urlencode($this->api_key);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Fehler: ' . $error);
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unbekannter Fehler';
            throw new Exception('Google Calendar API Fehler: ' . $error_message);
        }
        
        $event_data = json_decode($response, true);
        return $event_data['id'] ?? null;
    }
    
    /**
     * Event aktualisieren
     */
    public function updateEvent($event_id, $title, $start_datetime, $end_datetime, $description = '') {
        if (empty($this->api_key)) {
            throw new Exception('Google Calendar API Key ist nicht konfiguriert');
        }
        
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
        
        $url = $this->base_url . '/calendars/' . urlencode($this->calendar_id) . '/events/' . urlencode($event_id);
        $url .= '?key=' . urlencode($this->api_key);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Fehler: ' . $error);
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unbekannter Fehler';
            throw new Exception('Google Calendar API Fehler: ' . $error_message);
        }
        
        return true;
    }
    
    /**
     * Event löschen
     */
    public function deleteEvent($event_id) {
        if (empty($this->api_key)) {
            throw new Exception('Google Calendar API Key ist nicht konfiguriert');
        }
        
        $url = $this->base_url . '/calendars/' . urlencode($this->calendar_id) . '/events/' . urlencode($event_id);
        $url .= '?key=' . urlencode($this->api_key);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Fehler: ' . $error);
        }
        
        if ($http_code !== 204) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unbekannter Fehler';
            throw new Exception('Google Calendar API Fehler: ' . $error_message);
        }
        
        return true;
    }
    
    /**
     * Events für Zeitraum abrufen
     */
    public function getEvents($start_datetime, $end_datetime) {
        if (empty($this->api_key)) {
            throw new Exception('Google Calendar API Key ist nicht konfiguriert');
        }
        
        $start = urlencode($this->formatDateTime($start_datetime));
        $end = urlencode($this->formatDateTime($end_datetime));
        
        $url = $this->base_url . '/calendars/' . urlencode($this->calendar_id) . '/events';
        $url .= '?key=' . urlencode($this->api_key);
        $url .= '&timeMin=' . $start . '&timeMax=' . $end;
        $url .= '&singleEvents=true&orderBy=startTime';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Fehler: ' . $error);
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unbekannter Fehler';
            throw new Exception('Google Calendar API Fehler: ' . $error_message);
        }
        
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
     * API Key testen
     */
    public function testConnection() {
        try {
            $url = $this->base_url . '/calendars/' . urlencode($this->calendar_id);
            $url .= '?key=' . urlencode($this->api_key);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $http_code === 200;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
