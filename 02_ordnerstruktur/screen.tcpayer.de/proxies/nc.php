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
 *   Die NC-Legacy-API hat ein begrenztes Monatskontingent. Drei Vorkehrungen
 *   sorgen dafür, dass daraus EIN Abruf pro Tag wird statt einem je Monitor:
 *     1. Erfolge werden bis Mitternacht gecacht — geschlüsselt NUR nach den
 *        API-Parametern (Datum + Tagesanzahl), nicht nach Standort-/Saal-
 *        Filter. Die Filter laufen danach über die gecachten Daten, dadurch
 *        teilen sich alle Säle und alle Modul-Instanzen EINEN Abruf.
 *     2. Fehler werden ebenfalls kurz gemerkt (NcCache::FEHLER_TTL_SEK).
 *        Sonst geht jeder Wiederholungsversuch der Monitore (alle 10 Min.,
 *        pro Instanz) als echter API-Aufruf raus — ausgerechnet dann, wenn
 *        NC ohnehin klemmt.
 *     3. Eine Sperre verhindert, dass beim gleichzeitigen Ablauf (Mitternacht,
 *        „Monitore neu laden") alle Monitore zugleich losziehen.
 *   Frische Daten außer der Reihe gibt es über „Monitore neu laden" im
 *   Admin — das leert Daten UND gemerkte Fehler (admin/reload_trigger.php).
 *   Bewusst kein Query-Parameter dafür: dieser Endpunkt ist ohne Login
 *   erreichbar.
 *
 * Jeder ECHTE Netzabruf wird in includes/NcProtokoll.php festgehalten —
 * sichtbar unter „API-Protokoll" im Admin. Aus dem Cache bediente Anfragen
 * stehen dort nicht, sie kosten kein Kontingent.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/NcCache.php';
require __DIR__ . '/../includes/NcProtokoll.php';
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
$cacheSchluessel = 'timetable_' . $dateMitternacht . '_' . $days;

/** Sperre freigeben, Fehler merken und mit Fehlermeldung aussteigen. */
function nc_abbruch(string $schluessel, $sperre, string $meldung): never
{
    NcCache::schreibeFehler($schluessel, $meldung);
    NcCache::sperreFreigeben($sperre);
    proxy_fehler($meldung, 502);
}

$events   = null;
$geholtAm = 0;
$quelle   = 'cache';
$sperre   = null;

$ausCache = NcCache::lese($cacheSchluessel);

if ($ausCache === null) {
    // Steht ein frischer Fehler im Negativ-Gedächtnis? Dann NC in Ruhe lassen
    // und dem Monitor dieselbe Meldung geben — er zeigt weiter seine alten
    // Daten und versucht es später erneut.
    $gemerkt = NcCache::leseFehler($cacheSchluessel);
    if ($gemerkt !== null) {
        proxy_fehler($gemerkt['meldung'], 502);
    }

    // Nur einer geht los. Kommt die Sperre nicht zustande, wird trotzdem
    // weitergemacht — lieber ein Abruf zu viel als ein leerer Monitor.
    $sperre = NcCache::sperreHolen($cacheSchluessel);

    // Zweiter Blick: Während des Wartens hat der Sperrenhalter womöglich
    // schon geliefert (oder ist gescheitert).
    $ausCache = NcCache::lese($cacheSchluessel);
    if ($ausCache === null) {
        $gemerkt = NcCache::leseFehler($cacheSchluessel);
        if ($gemerkt !== null) {
            NcCache::sperreFreigeben($sperre);
            proxy_fehler($gemerkt['meldung'], 502);
        }
    }
}

if ($ausCache !== null) {
    // Auch wenn wir sie nur zum Nachschauen geholt haben: sofort freigeben,
    // damit niemand hinter uns unnötig wartet.
    NcCache::sperreFreigeben($sperre);

    $events   = $ausCache['daten'];
    $geholtAm = (int)$ausCache['geholt_am'];
    $quelle   = 'cache';
} else {
    $postFelder = http_build_query([
        'apikey' => $apiKey,
        'date'   => $dateMitternacht,
        'days'   => $days,
    ]);

    $start = microtime(true);

    $ch = curl_init(NC_API_BASE . '/timetable/data');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFelder,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $antwort   = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $dauerMs = (int)round((microtime(true) - $start) * 1000);

    if ($antwort === false) {
        $meldung = 'Verbindung zur Nimbuscloud fehlgeschlagen: ' . $curlError;
        NcProtokoll::eintragen('timetable', 0, $dauerMs, false, $curlError);
        nc_abbruch($cacheSchluessel, $sperre, $meldung);
    }
    if ($httpCode === 401) {
        NcProtokoll::eintragen('timetable', $httpCode, $dauerMs, false, 'API-Key abgelehnt');
        nc_abbruch($cacheSchluessel, $sperre, 'Nimbuscloud lehnt den API-Key ab (401).');
    }
    if ($httpCode >= 400) {
        NcProtokoll::eintragen('timetable', $httpCode, $dauerMs, false, 'HTTP ' . $httpCode);
        nc_abbruch($cacheSchluessel, $sperre, 'Nimbuscloud-Fehler (HTTP ' . $httpCode . ').');
    }

    $json = json_decode($antwort, true);
    if (!is_array($json)) {
        NcProtokoll::eintragen('timetable', $httpCode, $dauerMs, false, 'Antwort nicht lesbar');
        nc_abbruch($cacheSchluessel, $sperre, 'Unerwartete Antwort von der Nimbuscloud.');
    }

    // Legacy-API verpackt das Ergebnis in "content".
    $content = $json['content'] ?? $json;
    $events  = $content['events'] ?? [];

    if (!is_array($events)) {
        $events = [];
    }

    NcProtokoll::eintragen('timetable', $httpCode, $dauerMs, true, count($events) . ' Termine');

    // Nur Erfolge cachen — Fehlerfälle steigen oben schon aus.
    NcCache::schreibe($cacheSchluessel, $events, (int)strtotime('tomorrow midnight'));
    NcCache::sperreFreigeben($sperre);

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
