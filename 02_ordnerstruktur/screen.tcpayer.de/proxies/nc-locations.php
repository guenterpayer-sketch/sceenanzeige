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

$ch = curl_init(NC_API_BASE . '/data/locations');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['apikey' => $apiKey]),
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

// Verifizierte Struktur: content.locations = [{id, name, shortname, rooms:[...]}, ...]
$locationListe = $content['locations'] ?? [];

$standorte = [];
foreach ($locationListe as $item) {
    if (!is_array($item)) { continue; }
    $lid  = isset($item['id'])   ? (int)$item['id']           : 0;
    $name = isset($item['name']) ? trim((string)$item['name']) : '';
    if ($lid === 0 || $name === '') { continue; }
    $standorte[$lid] = ['id' => $lid, 'name' => $name];
}

if (empty($standorte)) {
    nc_loc_fehler('Keine Standorte von der NC-API erhalten.');
}

usort($standorte, fn($a, $b) => strcmp($a['name'], $b['name']));

echo json_encode(['ok' => true, 'standorte' => array_values($standorte)]);
