<?php
/**
 * proxies/nc.php
 *
 * Serverseitiger Proxy für die Nimbuscloud Legacy-API (stundenplan-Modul).
 * Siehe NC_Legacy_API_Stundenplan.md sowie Abschnitt 9 der Projektdoku.
 *
 * WICHTIG (Sicherheit):
 *   - Der API-Key wird AUSSCHLIESSLICH serverseitig aus der Tabelle
 *     `einstellungen` (Spalte nc_api_key_stundenplan) gelesen und niemals
 *     ans Frontend übertragen.
 *   - Auth-Mechanismus der Legacy-API: API-Key als POST-FORM-Parameter
 *     `apikey` (NICHT im Header X-API-Key — Unterschied zur aktuellen API!).
 *
 * Aufruf vom (Saal-)Frontend:
 *   GET proxies/nc.php?saal_id=<int>[&nur_heute=0|1][&anzahl=<int>]
 *
 * Nicht-sensible Anzeige-Einstellungen (nur_heute, anzahl) dürfen als
 * Query-Parameter kommen, da sie ohnehin in den Modul-Instanz-Einstellungen
 * stehen. Der Key kommt getrennt serverseitig dazu.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/_cors.php';

proxy_cors_und_json();

// ----------------------------------------------------------------------------
// Parameter einlesen
// ----------------------------------------------------------------------------
$saalId   = isset($_GET['saal_id']) ? (int)$_GET['saal_id'] : 0;
$nurHeute = ($_GET['nur_heute'] ?? '1') !== '0';
$anzahl   = isset($_GET['anzahl']) ? max(0, (int)$_GET['anzahl']) : 0; // 0 = ohne Begrenzung
$days     = $nurHeute ? 1 : 7; // Legacy-API: max. 7 Tage

// ----------------------------------------------------------------------------
// API-Key serverseitig laden (pro Saal; Fallback: erster gesetzter Key)
// ----------------------------------------------------------------------------
try {
    $pdo = get_pdo();
    if ($saalId > 0) {
        $stmt = $pdo->prepare('SELECT nc_api_key_stundenplan FROM einstellungen WHERE saal_id = :sid');
        $stmt->execute([':sid' => $saalId]);
        $apiKey = $stmt->fetchColumn();
    } else {
        $apiKey = $pdo->query(
            "SELECT nc_api_key_stundenplan FROM einstellungen
             WHERE nc_api_key_stundenplan IS NOT NULL AND nc_api_key_stundenplan <> ''
             ORDER BY saal_id LIMIT 1"
        )->fetchColumn();
    }
} catch (Throwable $e) {
    proxy_fehler('Datenbankfehler beim Laden des API-Keys.', 500);
}

if (!$apiKey) {
    proxy_fehler('Kein Nimbuscloud-Stundenplan-Key für diesen Saal hinterlegt.', 500);
}

// ----------------------------------------------------------------------------
// Legacy-API aufrufen: POST /timetable/data mit FORM-Parametern
// ----------------------------------------------------------------------------
$dateMitternacht = strtotime('today midnight');

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

if ($anzahl > 0) {
    $kurse = array_slice($kurse, 0, $anzahl);
}

proxy_json_exit(['kurse' => $kurse]);
