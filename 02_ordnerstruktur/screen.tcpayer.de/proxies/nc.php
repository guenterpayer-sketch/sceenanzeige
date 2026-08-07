<?php
/**
 * proxies/nc.php
 *
 * Serverseitiger Proxy für die Nimbuscloud Legacy-API (stundenplan-Modul).
 * Siehe NC_Legacy_API_Stundenplan.md sowie Abschnitt 9 der Projektdoku.
 *
 * WICHTIG (Sicherheit):
 *   - Es gibt genau EINEN NC-API-Key pro Schule. Er wird serverseitig aus
 *     `config.php` (Konstante NC_API_KEY) gelesen und niemals ans Frontend
 *     übertragen.
 *   - Auth-Mechanismus der Legacy-API: API-Key als POST-FORM-Parameter
 *     `apikey` (NICHT im Header X-API-Key — Unterschied zur aktuellen API!).
 *
 * Aufruf vom (Saal-)Frontend:
 *   GET proxies/nc.php[?nur_heute=0|1][&anzahl=<int>]
 *
 * Nicht-sensible Anzeige-Einstellungen (nur_heute, anzahl) dürfen als
 * Query-Parameter kommen, da sie ohnehin in den Modul-Instanz-Einstellungen
 * stehen. Der Key kommt getrennt serverseitig dazu.
 *
 * KONTINGENT (siehe includes/NcCache.php):
 *   Die NC-Legacy-API hat ein begrenztes Monatskontingent. Die rohe
 *   Event-Liste wird deshalb serverseitig bis Mitternacht gecacht —
 *   geschlüsselt NUR nach den API-Parametern (Datum + Tagesanzahl), nicht
 *   nach Standort-/Saal-Filter. Die Filter laufen danach über die
 *   gecachten Daten, dadurch teilen sich alle Säle und alle Modul-
 *   Instanzen EINEN Abruf pro Tag.
 *   Frische Daten außer der Reihe gibt es über „Monitore neu laden" im
 *   Admin — das leert den Cache (admin/reload_trigger.php). Bewusst kein
 *   Query-Parameter dafür: dieser Endpunkt ist ohne Login erreichbar.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/NcCache.php';
require __DIR__ . '/_cors.php';

// CORS wird zentral per .htaccess gesetzt.
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ----------------------------------------------------------------------------
// Parameter einlesen (nur nicht-sensible Anzeige-Einstellungen)
// ----------------------------------------------------------------------------
$nurHeute    = ($_GET['nur_heute'] ?? '1') !== '0';
$days        = $nurHeute ? 1 : 7; // Legacy-API: max. 7 Tage

$locationIds = [];
$rawLoc = $_GET['location_ids'] ?? '';
if ($rawLoc !== '') {
    $decoded = json_decode($rawLoc, true);
    if (is_array($decoded)) {
        $locationIds = array_map('intval', $decoded);
    }
}

$roomId = (int)($_GET['room_id'] ?? 0);

// ----------------------------------------------------------------------------
// API-Key serverseitig aus config.php (ein Key pro Schule, schulweit)
// ----------------------------------------------------------------------------
$apiKey = defined('NC_API_KEY') ? NC_API_KEY : '';
if ($apiKey === '') {
    proxy_fehler('NC_API_KEY ist nicht konfiguriert (config.php).', 500);
}

// ----------------------------------------------------------------------------
// Events besorgen: erst Cache, sonst Legacy-API (POST /timetable/data)
// ----------------------------------------------------------------------------
$dateMitternacht = strtotime('today midnight');

// Schlüssel nur aus den API-Parametern — die Standort-/Saal-Filter greifen
// erst weiter unten, damit sich alle Instanzen einen Abruf teilen.
$cacheSchluessel = $dateMitternacht . '_' . $days;
$ausCache        = NcCache::lese($cacheSchluessel);

if ($ausCache !== null) {
    $events   = $ausCache['events'];
    $geholtAm = (int)$ausCache['geholt_am'];
    $quelle   = 'cache';
} else {
    $postFelder = http_build_query([
        'apikey' => $apiKey,
        'date'   => $dateMitternacht,
        'days'   => $days,
    ]);

    $ch = curl_init(NC_API_BASE . '/timetable/data');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFelder,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $antwort   = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($antwort === false) {
        proxy_fehler('Verbindung zur Nimbuscloud fehlgeschlagen: ' . $curlError, 502);
    }
    if ($httpCode === 401) {
        proxy_fehler('Nimbuscloud lehnt den API-Key ab (401).', 502);
    }
    if ($httpCode >= 400) {
        proxy_fehler('Nimbuscloud-Fehler (HTTP ' . $httpCode . ').', 502);
    }

    $json = json_decode($antwort, true);
    if (!is_array($json)) {
        proxy_fehler('Unerwartete Antwort von der Nimbuscloud.', 502);
    }

    // Legacy-API verpackt das Ergebnis in "content".
    $content = $json['content'] ?? $json;
    $events  = $content['events'] ?? [];

    if (!is_array($events)) {
        $events = [];
    }

    // Nur Erfolge cachen — Fehlerfälle steigen oben schon aus.
    NcCache::schreibe($cacheSchluessel, $events, (int)strtotime('tomorrow midnight'));

    $geholtAm = time();
    $quelle   = 'api';
}

// ----------------------------------------------------------------------------
// Auf die fürs Modul relevanten Felder reduzieren + filtern
// ----------------------------------------------------------------------------
$kurse = [];
foreach ($events as $ev) {
    // Nur echte, im Stundenplan sichtbare Kurstermine.
    if (empty($ev['isCourseEvent'])) {
        continue;
    }
    if (array_key_exists('showInTimetable', $ev) && !$ev['showInTimetable']) {
        continue;
    }
    // Standort-Filter: nur Events mit passender locationId durchlassen.
    if (!empty($locationIds) && !in_array((int)($ev['locationId'] ?? 0), $locationIds, true)) {
        continue;
    }
    // Saal-Filter: nur Events mit passender room_id/roomId durchlassen.
    if ($roomId > 0) {
        $evRoomId = (int)(($ev['room_id'] ?? $ev['roomId'] ?? 0));
        if ($evRoomId !== $roomId) {
            continue;
        }
    }
    $kurse[] = [
        'displayName' => $ev['displayName'] ?? ($ev['text'] ?? ''),
        'course_key'  => $ev['course_key'] ?? '',
        'start_date'  => $ev['start_date'] ?? '',
        'end_date'    => $ev['end_date'] ?? '',
        'room'        => $ev['room'] ?? '',
        'teacher'     => $ev['teacher'] ?? '',
        'type'        => $ev['type'] ?? '',
        'level'       => $ev['level'] ?? '',
        'color'       => $ev['color'] ?? '',
        'textColor'   => $ev['textColor'] ?? '',
    ];
}

// quelle/stand sind reine Diagnose-Felder (keine sensiblen Daten) — damit
// im Browser-Netzwerktab sichtbar ist, ob gerade die API oder der Cache
// geantwortet hat. Das Frontend wertet sie nicht aus.
proxy_json_exit([
    'kurse'  => $kurse,
    'quelle' => $quelle,
    'stand'  => date('c', $geholtAm),
]);
