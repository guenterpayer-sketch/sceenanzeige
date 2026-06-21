<?php
/**
 * proxies/song.php
 *
 * Serverseitiger Proxy für die FRET-API (song-Modul).
 * Siehe Projektzusammenfassung_Song_Anzeige.md sowie Abschnitt 14 der Doku.
 *
 * WICHTIG (Sicherheit):
 *   - FRET_SCHOOL_ID bleibt serverseitig (config.php) und gelangt NIE ans
 *     Frontend — die FRET-API besitzt auch schreibende Endpunkte.
 *   - Nur player1 ist für die Anzeige relevant; player2 wird verworfen.
 *
 * Aufruf vom (Saal-)Frontend:
 *   GET proxies/song.php?action=list           → [{id,name}, ...] der Computer/Säle
 *   GET proxies/song.php?computer=<uuid>        → aufbereiteter Song-Status
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/_cors.php';

proxy_cors_und_json();

if (FRET_SCHOOL_ID === '') {
    proxy_fehler('FRET_SCHOOL_ID ist nicht konfiguriert (config.php).', 500);
}

$action   = $_GET['action'] ?? '';
$computer = $_GET['computer'] ?? '';

/** Ruft eine FRET-URL ab und gibt das dekodierte JSON zurück (oder bricht ab). */
function fret_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $antwort  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fehler   = curl_error($ch);
    curl_close($ch);

    if ($antwort === false) {
        proxy_fehler('Verbindung zur FRET-API fehlgeschlagen: ' . $fehler, 502);
    }
    if ($httpCode >= 400) {
        proxy_fehler('FRET-API-Fehler (HTTP ' . $httpCode . ').', 502);
    }
    $json = json_decode($antwort, true);
    if (!is_array($json)) {
        proxy_fehler('Unerwartete Antwort von der FRET-API.', 502);
    }
    return $json;
}

$base = rtrim(FRET_API_BASE, '/') . '/schools/' . rawurlencode(FRET_SCHOOL_ID);

// ----------------------------------------------------------------------------
// action=list → Computer/Säle auflisten (für die Backend-Auswahl)
// ----------------------------------------------------------------------------
if ($action === 'list') {
    $computers = fret_get($base . '/Computers');
    $liste = [];
    foreach ($computers as $c) {
        $liste[] = ['id' => $c['id'] ?? '', 'name' => $c['name'] ?? ''];
    }
    proxy_json_exit(['computers' => $liste]);
}

// ----------------------------------------------------------------------------
// computer=<uuid> → Song-Status aufbereiten
// ----------------------------------------------------------------------------
if ($computer === '') {
    proxy_fehler('Parameter "computer" (UUID) fehlt.', 400);
}

$players = fret_get($base . '/computers/' . rawurlencode($computer) . '/Players');
$player  = $players['player1'] ?? ['isPlaying' => false, 'songs' => []];

$isPlaying = (bool)($player['isPlaying'] ?? false);
$songs     = $player['songs'] ?? [];

/** Reduziert ein FRET-Song-Objekt auf die Anzeige-relevanten Felder. */
function song_aufbereiten(array $s): array
{
    $taenze = [];
    $dances = $s['dances'] ?? [];
    $hatPrimary = false;
    foreach ($dances as $d) {
        if (!empty($d['isPrimary'])) {
            $hatPrimary = true;
        }
    }
    $gesehen = [];
    foreach ($dances as $d) {
        $name = $d['longName'] ?? ($d['shortName'] ?? '');
        if ($name === '' || isset($gesehen[$name])) {
            continue;
        }
        $gesehen[$name] = true;
        $taenze[] = [
            'name'   => $name,
            'isMain' => $hatPrimary ? (bool)($d['isPrimary'] ?? false) : true,
        ];
    }

    return [
        'position'                   => $s['position'] ?? 0,
        'title'                      => $s['title'] ?? '',
        'artist'                     => $s['artist'] ?? '',
        'taenze'                     => $taenze,
        'duration'                   => $s['duration'] ?? null,
        'remainingSeconds'           => $s['remainingSeconds'] ?? null,
        'estimatedSecondsUntilStart' => $s['estimatedSecondsUntilStart'] ?? null,
        'coverImageUrl'              => $s['coverImageUrl'] ?? null,
        'year'                       => $s['year'] ?? null,
    ];
}

// position >= 0 (aktueller + kommende); Verlauf (position < 0) ignorieren.
$relevant = array_filter($songs, fn($s) => (int)($s['position'] ?? 0) >= 0);

$aktuell  = null;
$kommende = [];
foreach ($relevant as $s) {
    $pos = (int)($s['position'] ?? 0);
    if ($pos === 0 && $aktuell === null) {
        $aktuell = song_aufbereiten($s);
    } elseif ($pos > 0) {
        $kommende[] = song_aufbereiten($s);
    }
}
// Fallback: kein position==0 vorhanden → erstes relevantes als aktuell.
if ($aktuell === null && !empty($relevant)) {
    $aktuell = song_aufbereiten(array_values($relevant)[0]);
}

usort($kommende, fn($a, $b) => $a['position'] <=> $b['position']);

proxy_json_exit([
    'isPlaying' => $isPlaying,
    'aktuell'   => $aktuell,
    'kommende'  => $kommende,
]);
