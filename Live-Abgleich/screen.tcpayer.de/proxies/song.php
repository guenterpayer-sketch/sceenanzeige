<?php
/**
 * proxies/song.php
 *
 * Serverseitiger Proxy zur FRET-API (siehe Projektzusammenfassung_Song_
 * Anzeige.md). schoolId/computerId stammen aus den Einstellungen der
 * jeweiligen "song"-Modul-Instanz und werden NIEMALS an den Browser
 * weitergegeben — die echte FRET-API hat auch schreibende Endpunkte
 * (Abschnitt 4 der Song-Doku).
 *
 * Aufruf: /proxies/song.php?modul_instanz_id=Y
 * Liefert ausschließlich player1 (player2 ist laut Song-Doku Abschnitt 3
 * intern und für die Anzeige irrelevant).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/ModulInstanz.php';

const FRET_BASE = 'https://fret-api.azurewebsites.net/api/v1';

function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$modulInstanzId = isset($_GET['modul_instanz_id']) ? (int)$_GET['modul_instanz_id'] : 0;
if ($modulInstanzId <= 0) {
    fail(400, 'modul_instanz_id fehlt oder ungültig.');
}

try {
    $instanz = ModulInstanz::find($modulInstanzId);
    if (!$instanz || $instanz['modul_typ'] !== 'song') {
        fail(404, 'Song-Modul-Instanz nicht gefunden.');
    }

    $settings = $instanz['einstellungen'];
    $schoolId = (string)($settings['schoolId'] ?? '');
    $computerId = (string)($settings['computerId'] ?? '');

    if ($schoolId === '' || $computerId === '') {
        fail(400, 'schoolId/computerId sind in den Einstellungen dieser Modul-Instanz nicht gesetzt.');
    }

    $url = FRET_BASE . '/schools/' . rawurlencode($schoolId) . '/computers/' . rawurlencode($computerId) . '/Players';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        // Bewusst generischer Fehler statt Detail-Fehlertext, siehe Song-Doku
        // Abschnitt 3 ("Bekanntes Infrastruktur-Problem" -> Kunden nicht
        // verwirren). $curlErr wird absichtlich NICHT an den Client gegeben.
        fail(502, 'Verbindung zur Musiksoftware unterbrochen.');
    }

    $decoded = json_decode($response, true);
    $player1 = $decoded['player1'] ?? null;

    if ($player1 === null) {
        fail(502, 'Unerwartete Antwort der Musiksoftware.');
    }

    echo json_encode([
        'player'      => $player1,
        'anzeigename' => $settings['anzeigename'] ?? '',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    fail(500, 'Proxy-Fehler: ' . $e->getMessage());
}
