<?php
/**
 * proxies/nc-locations.php
 *
 * Liefert die Standortliste aus der Nimbuscloud Legacy-API (POST /data/locations)
 * für den Admin-Instanz-Editor (Stundenplan-Modul, Location-Picker).
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

// Legacy-API verpackt Ergebnisse meist in "content"
$content = $json['content'] ?? $json;

$standorte = [];

// Erwartetes Format: [{id, name, rooms:[...]}, ...]
if (is_array($content)) {
    foreach ($content as $item) {
        if (!is_array($item)) { continue; }
        if (isset($item['id'], $item['name'])) {
            // Nur Location-Einträge (nicht Raum-Einträge bei flacher Liste)
            // Raum-Einträge haben typischerweise location_id oder type='room'
            if (isset($item['type']) && $item['type'] !== 'location') { continue; }
            if (isset($item['location_id'])) { continue; } // flache Liste: Raum-Zeile
            $standorte[] = ['id' => (int)$item['id'], 'name' => (string)$item['name']];
        }
    }
}

echo json_encode(['ok' => true, 'standorte' => $standorte]);
