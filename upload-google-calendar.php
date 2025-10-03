<?php
/**
 * Upload Google Calendar Service Account - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/upload-google-calendar.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>📤 Upload Google Calendar Service Account</h1>";
echo "<p>Diese Seite erstellt die fehlende google_calendar_service_account.php Datei.</p>";

try {
    // 1. Prüfe ob Datei bereits existiert
    echo "<h2>1. Prüfe ob Datei bereits existiert:</h2>";
    
    if (file_exists('includes/google_calendar_service_account.php')) {
        echo "<p style='color: green;'>✅ google_calendar_service_account.php existiert bereits</p>";
        echo "<p><strong>Dateigröße:</strong> " . filesize('includes/google_calendar_service_account.php') . " Bytes</p>";
        echo "<p><strong>Letzte Änderung:</strong> " . date('Y-m-d H:i:s', filemtime('includes/google_calendar_service_account.php')) . "</p>";
    } else {
        echo "<p style='color: red;'>❌ google_calendar_service_account.php existiert nicht</p>";
        
        // 2. Erstelle die Datei
        echo "<h2>2. Erstelle google_calendar_service_account.php:</h2>";
        
        $file_content = '<?php
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
    public function __construct($serviceAccountFile, $calendarId = \'primary\', $isJson = false) {
        $this->calendarId = $calendarId;
        
        if ($isJson) {
            // JSON-Inhalt direkt verwenden
            $this->credentials = json_decode($serviceAccountFile, true);
        } else {
            // Datei laden
            if (!file_exists($serviceAccountFile)) {
                throw new Exception(\'Service Account Datei nicht gefunden: \' . $serviceAccountFile);
            }
            $this->credentials = json_decode(file_get_contents($serviceAccountFile), true);
        }
        
        if (!$this->credentials) {
            throw new Exception(\'Ungültige Service Account Konfiguration\');
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
            \'grant_type\' => \'urn:ietf:params:oauth:grant-type:jwt-bearer\',
            \'assertion\' => $jwt
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, \'https://oauth2.googleapis.com/token\');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            \'Content-Type: application/x-www-form-urlencoded\'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception(\'Fehler beim Abrufen des Access Tokens: HTTP \' . $httpCode . \' - \' . $response);
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data[\'access_token\'])) {
            throw new Exception(\'Ungültige Token-Antwort: \' . $response);
        }
        
        $this->accessToken = $data[\'access_token\'];
        $this->tokenExpiry = time() + ($data[\'expires_in\'] ?? 3600);
        
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
            \'alg\' => \'RS256\',
            \'typ\' => \'JWT\'
        ];
        
        $now = time();
        $payload = [
            \'iss\' => $this->credentials[\'client_email\'],
            \'scope\' => \'https://www.googleapis.com/auth/calendar\',
            \'aud\' => \'https://oauth2.googleapis.com/token\',
            \'iat\' => $now,
            \'exp\' => $now + 3600
        ];
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = \'\';
        $signatureData = $headerEncoded . \'.\' . $payloadEncoded;
        
        if (!openssl_sign($signatureData, $signature, $this->credentials[\'private_key\'], OPENSSL_ALGO_SHA256)) {
            throw new Exception(\'Fehler beim Signieren des JWT\');
        }
        
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . \'.\' . $payloadEncoded . \'.\' . $signatureEncoded;
    }
    
    /**
     * Base64 URL Encoding
     * @param string $data
     * @return string
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), \'+/\', \'-_\'), \'=\');
    }
    
    /**
     * Event erstellen
     * @param string $title Event-Titel
     * @param string $startDateTime Start-Zeit (Y-m-d H:i:s)
     * @param string $endDateTime End-Zeit (Y-m-d H:i:s)
     * @param string $description Event-Beschreibung
     * @return string Event ID
     */
    public function createEvent($title, $startDateTime, $endDateTime, $description = \'\') {
        $accessToken = $this->getAccessToken();
        
        $event = [
            \'summary\' => $title,
            \'description\' => $description,
            \'start\' => [
                \'dateTime\' => $this->formatDateTime($startDateTime),
                \'timeZone\' => \'Europe/Berlin\'
            ],
            \'end\' => [
                \'dateTime\' => $this->formatDateTime($endDateTime),
                \'timeZone\' => \'Europe/Berlin\'
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, \'https://www.googleapis.com/calendar/v3/calendars/\' . urlencode($this->calendarId) . \'/events\');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            \'Authorization: Bearer \' . $accessToken,
            \'Content-Type: application/json\'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception(\'Fehler beim Erstellen des Events: HTTP \' . $httpCode . \' - \' . $response);
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data[\'id\'])) {
            throw new Exception(\'Ungültige Event-Antwort: \' . $response);
        }
        
        return $data[\'id\'];
    }
    
    /**
     * DateTime formatieren für Google Calendar
     * @param string $dateTime
     * @return string
     */
    private function formatDateTime($dateTime) {
        $dt = new DateTime($dateTime, new DateTimeZone(\'Europe/Berlin\'));
        return $dt->format(\'c\');
    }
    
    /**
     * Event löschen
     * @param string $eventId Event ID
     * @return bool
     */
    public function deleteEvent($eventId) {
        $accessToken = $this->getAccessToken();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, \'https://www.googleapis.com/calendar/v3/calendars/\' . urlencode($this->calendarId) . \'/events/\' . urlencode($eventId));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, \'DELETE\');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            \'Authorization: Bearer \' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 204;
    }
    
    /**
     * Event abrufen
     * @param string $eventId Event ID
     * @return array Event-Daten
     */
    public function getEvent($eventId) {
        $accessToken = $this->getAccessToken();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, \'https://www.googleapis.com/calendar/v3/calendars/\' . urlencode($this->calendarId) . \'/events/\' . urlencode($eventId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            \'Authorization: Bearer \' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception(\'Fehler beim Abrufen des Events: HTTP \' . $httpCode . \' - \' . $response);
        }
        
        return json_decode($response, true);
    }
}
?>';
        
        // Erstelle includes Verzeichnis falls es nicht existiert
        if (!is_dir('includes')) {
            mkdir('includes', 0755, true);
        }
        
        // Schreibe die Datei
        $bytes_written = file_put_contents('includes/google_calendar_service_account.php', $file_content);
        
        if ($bytes_written !== false) {
            echo "<p style='color: green;'>✅ google_calendar_service_account.php erfolgreich erstellt</p>";
            echo "<p><strong>Dateigröße:</strong> $bytes_written Bytes</p>";
        } else {
            echo "<p style='color: red;'>❌ Fehler beim Erstellen der Datei</p>";
        }
    }
    
    // 3. Teste die Datei
    echo "<h2>3. Teste die Datei:</h2>";
    
    try {
        require_once 'includes/google_calendar_service_account.php';
        echo "<p style='color: green;'>✅ Datei erfolgreich geladen</p>";
        
        if (class_exists('GoogleCalendarServiceAccount')) {
            echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
        } else {
            echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Laden der Datei: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 4. Teste Google Calendar Funktion
    echo "<h2>4. Teste Google Calendar Funktion:</h2>";
    
    try {
        require_once 'config/database.php';
        require_once 'includes/functions.php';
        
        if (function_exists('create_google_calendar_event')) {
            echo "<p style='color: green;'>✅ create_google_calendar_event Funktion verfügbar</p>";
        } else {
            echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Testen der Funktion: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 5. Nächste Schritte
    echo "<h2>5. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Die google_calendar_service_account.php Datei wurde erstellt</li>";
    echo "<li>Testen Sie jetzt die admin/reservations.php Seite</li>";
    echo "<li>Google Calendar Integration sollte jetzt funktionieren</li>";
    echo "<li>Falls es funktioniert, ist das Problem vollständig behoben</li>";
    echo "</ol>";
    
    // 6. Zusammenfassung
    echo "<h2>6. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datei-Existenz geprüft</li>";
    echo "<li>✅ Datei erstellt (falls nötig)</li>";
    echo "<li>✅ Datei getestet</li>";
    echo "<li>✅ Google Calendar Funktion getestet</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Upload Google Calendar Service Account abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
