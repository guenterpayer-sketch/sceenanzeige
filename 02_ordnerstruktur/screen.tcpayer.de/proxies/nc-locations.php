<?php
/**
 * proxies/nc-locations.php
 *
 * Liefert alle Standorte aus der Nimbuscloud Stammdaten-API (POST /data/locations)
 * für den Admin-Instanz-Editor (Stundenplan-Modul, Location-Picker).
 *
 * Berechtigung: Stammdaten — Lesezugriff (gleicher Key wie Stundenplan).
 * Nur vom Backend (Admin-Editor) aufgerufen — kein CORS nötig.
 * NC_API_KEY bleibt serverseitig, kommt nie ans Frontend.
 *
 * KONTINGENT: Standorte und Säle ändern sich praktisch nie, dieser Aufruf
 * lief aber bei JEDEM Öffnen einer Stundenplan-Instanz erneut los. Er nutzt
 * jetzt denselben Tages-Cache wie nc.php (inklusive Negativ-Gedächtnis und
 * Sperre) und wird ebenso protokolliert. Neu angelegte Säle erscheinen
 * spätestens am nächsten Tag, sofort über „Monitore neu laden" im Admin.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/NcCache.php';
require __DIR__ . '/../includes/NcProtokoll.php';

header('Content-Type: application/json; charset=utf-8');

function nc_loc_fehler(string $msg): never
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/** Sperre freigeben, Fehler merken und mit Fehlermeldung aussteigen. */
function nc_loc_abbruch(string $schluessel, $sperre, string $meldung): never
{
    NcCache::schreibeFehler($schluessel, $meldung);
    NcCache::sperreFreigeben($sperre);
    nc_loc_fehler($meldung);
}

$apiKey = defined('NC_API_KEY') ? NC_API_KEY : '';
if ($apiKey === '') {
    nc_loc_fehler('NC_API_KEY ist nicht konfiguriert (config.php).');
}

$cacheSchluessel = 'locations';
$sperre          = null;

$ausCache = NcCache::lese($cacheSchluessel);

if ($ausCache === null) {
    $gemerkt = NcCache::leseFehler($cacheSchluessel);
    if ($gemerkt !== null) {
        nc_loc_fehler($gemerkt['meldung']);
    }

    $sperre   = NcCache::sperreHolen($cacheSchluessel);
    $ausCache = NcCache::lese($cacheSchluessel);
    if ($ausCache === null) {
        $gemerkt = NcCache::leseFehler($cacheSchluessel);
        if ($gemerkt !== null) {
            NcCache::sperreFreigeben($sperre);
            nc_loc_fehler($gemerkt['meldung']);
        }
    }
}

if ($ausCache !== null) {
    NcCache::sperreFreigeben($sperre);
    echo json_encode(['ok' => true, 'standorte' => $ausCache['daten']]);
    exit;
}

$start = microtime(true);

$ch = curl_init(NC_API_BASE . '/data/locations');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['apikey' => $apiKey]),
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$antwort  = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$dauerMs = (int)round((microtime(true) - $start) * 1000);

if ($antwort === false) {
    NcProtokoll::eintragen('locations', 0, $dauerMs, false, $curlErr);
    nc_loc_abbruch($cacheSchluessel, $sperre, 'Verbindung zur Nimbuscloud fehlgeschlagen: ' . $curlErr);
}
if ($httpCode >= 400) {
    NcProtokoll::eintragen('locations', $httpCode, $dauerMs, false, 'HTTP ' . $httpCode);
    nc_loc_abbruch($cacheSchluessel, $sperre, 'Nimbuscloud-Fehler (HTTP ' . $httpCode . ').');
}

$json = json_decode($antwort, true);
if (!is_array($json)) {
    NcProtokoll::eintragen('locations', $httpCode, $dauerMs, false, 'Antwort nicht lesbar');
    nc_loc_abbruch($cacheSchluessel, $sperre, 'Unerwartete Antwort von der NC-API.');
}

$content = $json['content'] ?? $json;

// Verifizierte Struktur: content.locations = [{id, name, shortname, rooms:[...]}, ...]
$locationListe = $content['locations'] ?? [];

$standorte = [];
foreach ($locationListe as $item) {
    if (!is_array($item)) { continue; }
    $lid  = isset($item['id'])   ? (int)$item['id']           : 0;
    $name = isset($item['name']) ? trim((string)$item['name']) : '';
    if ($lid === 0 || $name === '') { continue; }
    $rooms = [];
    foreach ($item['rooms'] ?? [] as $r) {
        $rid   = isset($r['id'])   ? (int)$r['id']           : 0;
        $rname = isset($r['name']) ? trim((string)$r['name']) : '';
        if ($rid > 0 && $rname !== '') {
            $rooms[] = ['id' => $rid, 'name' => $rname];
        }
    }
    $standorte[$lid] = ['id' => $lid, 'name' => $name, 'rooms' => $rooms];
}

if (empty($standorte)) {
    NcProtokoll::eintragen('locations', $httpCode, $dauerMs, false, 'Keine Standorte in der Antwort');
    nc_loc_abbruch($cacheSchluessel, $sperre, 'Keine Standorte von der NC-API erhalten.');
}

usort($standorte, fn($a, $b) => strcmp($a['name'], $b['name']));
$standorte = array_values($standorte);

NcProtokoll::eintragen('locations', $httpCode, $dauerMs, true, count($standorte) . ' Standorte');

NcCache::schreibe($cacheSchluessel, $standorte, (int)strtotime('tomorrow midnight'));
NcCache::sperreFreigeben($sperre);

echo json_encode(['ok' => true, 'standorte' => $standorte]);
