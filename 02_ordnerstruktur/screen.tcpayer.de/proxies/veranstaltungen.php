<?php
/**
 * proxies/veranstaltungen.php
 *
 * Serverseitiger Proxy für die WordPress "The Events Calendar" REST-API
 * (öffentlich, kein API-Key erforderlich).
 *
 * Aufruf vom Frontend:
 *   GET proxies/veranstaltungen.php[?anzahl=<int>][&kategorien=<id>,<id>]
 *
 * Liefert:
 *   { "events": [ { titel, start_date, end_date, bild_url, venue, beschreibung } ] }
 */

declare(strict_types=1);

require __DIR__ . '/_cors.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$anzahl = max(1, min(20, (int)($_GET['anzahl'] ?? 5)));
$heute  = date('Y-m-d');

$apiUrl = 'https://tcpayer.de/wp-json/tribe/events/v1/events'
    . '?per_page=' . $anzahl
    . '&start_date=' . urlencode($heute);

// Kategorie-Filter (Instanz-Einstellung "kategorien", hier als "8,10").
// Gefiltert wird bei der API selbst, damit "anzahl" auch nach dem Filtern
// stimmt. Unbekannte IDs werden vorher aussortiert — die API würde sonst die
// ganze Abfrage mit HTTP 400 ablehnen. Bleibt nichts übrig, gibt es bewusst
// eine leere Liste statt aller Termine.
$kategorienParam = trim((string)($_GET['kategorien'] ?? ''));
if ($kategorienParam !== '') {
    require __DIR__ . '/../includes/VeranstaltungKategorien.php';
    $gewuenscht = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $kategorienParam)),
        static fn($id) => $id > 0
    )));
    $gueltig = VeranstaltungKategorien::gueltigeIds($gewuenscht);
    if (empty($gueltig)) {
        proxy_json_exit(['events' => []]);
    }
    $apiUrl .= '&categories=' . implode(',', $gueltig);
}

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_USERAGENT      => 'TanzschuleMonitor/1.0',
]);
$antwort   = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($antwort === false) {
    proxy_fehler('Verbindung zur tcpayer.de fehlgeschlagen: ' . $curlError, 502);
}
if ($httpCode >= 400) {
    proxy_fehler('Fehler von tcpayer.de (HTTP ' . $httpCode . ').', 502);
}

$json = json_decode($antwort, true);
if (!is_array($json) || !isset($json['events'])) {
    proxy_fehler('Unerwartete Antwort von der Events-API.', 502);
}

$events = [];
foreach ($json['events'] as $ev) {
    // Bild: image ist false oder ein Objekt mit url/width/height
    $bildUrl    = null;
    $bildBreite = null;
    $bildHoehe  = null;
    if (!empty($ev['image']) && is_array($ev['image']) && !empty($ev['image']['url'])) {
        $bildUrl    = (string)$ev['image']['url'];
        $bildBreite = isset($ev['image']['width'])  ? (int)$ev['image']['width']  : null;
        $bildHoehe  = isset($ev['image']['height']) ? (int)$ev['image']['height'] : null;
    }

    // Venue-Name aus dem venue-Objekt (Feld "venue" enthält den Namen als String)
    $venue = '';
    if (!empty($ev['venue']) && is_array($ev['venue'])) {
        $venue = (string)($ev['venue']['venue'] ?? '');
    }

    // Beschreibung: HTML entfernen + kürzen
    $beschreibung = '';
    if (!empty($ev['description'])) {
        $beschreibung = trim(strip_tags((string)$ev['description']));
        if (mb_strlen($beschreibung) > 160) {
            $beschreibung = mb_substr($beschreibung, 0, 157) . '…';
        }
    }

    $events[] = [
        'titel'        => (string)($ev['title'] ?? ''),
        'start_date'   => (string)($ev['start_date'] ?? ''),
        'end_date'     => (string)($ev['end_date'] ?? ''),
        'bild_url'     => $bildUrl,
        'bild_breite'  => $bildBreite,
        'bild_hoehe'   => $bildHoehe,
        'venue'        => $venue,
        'beschreibung' => $beschreibung,
    ];
}

proxy_json_exit(['events' => $events]);
