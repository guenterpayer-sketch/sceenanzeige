<?php
/**
 * proxies/nc-locations.php
 *
 * Liefert die Standortliste für den Admin-Instanz-Editor (Stundenplan-Modul,
 * Location-Picker). Extrahiert einzigartige Standorte aus /timetable/data
 * (7-Tage-Fenster) — zuverlässiger als der unverifizierten /data/locations-
 * Endpunkt, da /timetable/data definitiv funktioniert.
 *
 * Nur vom Backend (Admin-Editor) aufgerufen — kein CORS nötig.
 * NC_API_KEY bleibt serverseitig, kommt nie ans Frontend.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function nc_loc_fehler(string $msg): never
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$apiKey = defined('NC_API_KEY') ? NC_API_KEY : '';
if ($apiKey === '') {
    nc_loc_fehler('NC_API_KEY ist nicht konfiguriert (config.php).');
}

$postFelder = http_build_query([
    'apikey' => $apiKey,
    'date'   => strtotime('today midnight'),
    'days'   => 7,
]);

$ch = curl_init(NC_API_BASE . '/timetable/data');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFelder,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$antwort  = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($antwort === false) {
    nc_loc_fehler('Verbindung zur Nimbuscloud fehlgeschlagen: ' . $curlErr);
}
if ($httpCode >= 400) {
    nc_loc_fehler('Nimbuscloud-Fehler (HTTP ' . $httpCode . ').');
}

$json = json_decode($antwort, true);
if (!is_array($json)) {
    nc_loc_fehler('Unerwartete Antwort von der NC-API.');
}

$content = $json['content'] ?? $json;
$events  = $content['events'] ?? [];

// Debug-Modus: rohe API-Antwort ausgeben (nur im Admin-Kontext, nie produktiv lassen)
if (($_GET['debug'] ?? '') === '1') {
    $sample = array_slice($events, 0, 3);
    echo json_encode([
        'debug'          => true,
        'event_anzahl'   => count($events),
        'erste_events'   => $sample,
        'content_keys'   => is_array($content) ? array_keys($content) : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Einzigartige Standorte aus den Events extrahieren (location_id + location)
$standorte = [];
foreach ($events as $ev) {
    if (empty($ev['isCourseEvent'])) { continue; }
    $lid  = isset($ev['location_id']) ? (int)$ev['location_id'] : 0;
    $name = isset($ev['location'])    ? trim((string)$ev['location']) : '';
    if ($lid === 0 || $name === '') { continue; }
    $standorte[$lid] = ['id' => $lid, 'name' => $name];
}

// Alphabetisch nach Name sortieren
usort($standorte, fn($a, $b) => strcmp($a['name'], $b['name']));

echo json_encode(['ok' => true, 'standorte' => array_values($standorte)]);
