<?php
/**
 * proxies/monitor.php
 *
 * Öffentlicher API-Endpunkt für das Monitor-Frontend (Schritt 9).
 * Wird von saalN.tcpayer.de cross-origin aufgerufen — kein Login nötig.
 *
 * GET /proxies/monitor.php?subdomain=saal1
 *
 * Gibt JSON zurück:
 *   monitor_id    int|null
 *   monitor_name  string
 *   header_text   string         konfigurierbarer Begrüßungstext
 *   playlist      object|null    aktive Playlist inkl. Layout + Spalten-Inhalte
 *   ticker        array          gemischte Textzeilen aller aktiven Ticker
 *
 * Zeitplan-Logik:
 *   - Wochentag: ISO-Format 1=Mo … 7=So (FIND_IN_SET, Spalte wochentage = "1,2,5")
 *   - Uhrzeit: NULL/NULL = dauerhaft (Fallback); sonst aktuell im Fenster
 *   - Playlist: höchste prioritaet gewinnt
 *   - Ticker: ALLE passenden gemischt (kein Prioritätsfeld)
 *
 * CORS: saal1–3 erlaubt (analog zu fret.php/nc.php); bei neuen Saalen
 *   _cors.php + .htaccess erweitern.
 *
 * Hinweis MariaDB EMULATE_PREPARES=false: jeder benannte Platzhalter
 * darf im selben Statement nur einmal vorkommen (deshalb :zeit_von/:zeit_bis
 * statt zweimal :zeit).
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/_cors.php';

// Öffentlicher Lese-Endpunkt: kein Schlüssel/sensible Daten in der Antwort,
// daher Access-Control-Allow-Origin: * statt der engen Subdomain-Prüfung.
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$subdomain = trim((string)($_GET['subdomain'] ?? ''));
if ($subdomain === '') {
    proxy_fehler('Parameter "subdomain" fehlt.', 400);
}

$pdo = get_pdo();

// ── 1. Monitor per Subdomain ──────────────────────────────────────────────

$stmt = $pdo->prepare('SELECT id, name, header_text FROM monitore WHERE subdomain = :sub');
$stmt->execute([':sub' => $subdomain]);
$monitor = $stmt->fetch();

if (!$monitor) {
    proxy_json_exit(['monitor_id' => null, 'monitor_name' => '', 'header_text' => '',
                     'playlist' => null, 'ticker' => []]);
}

$monitorId  = (int)$monitor['id'];
$isoTag     = (string)(int)date('N'); // 1=Mo … 7=So
$jetztZeit  = date('H:i:s');

// ── 2. Aktive Playlist (höchste Priorität) ────────────────────────────────

$stmt = $pdo->prepare(
    'SELECT z.playlist_id
     FROM monitor_zeitplan z
     JOIN playlists p ON p.id = z.playlist_id AND p.aktiv = 1
     WHERE z.monitor_id = :mid
       AND FIND_IN_SET(:tag, z.wochentage) > 0
       AND (
             (z.von_uhrzeit IS NULL AND z.bis_uhrzeit IS NULL)
          OR (:zeit_von >= z.von_uhrzeit AND :zeit_bis <= z.bis_uhrzeit)
       )
     ORDER BY z.prioritaet DESC
     LIMIT 1'
);
$stmt->execute([
    ':mid'      => $monitorId,
    ':tag'      => $isoTag,
    ':zeit_von' => $jetztZeit,
    ':zeit_bis' => $jetztZeit,
]);
$zeitplanRow = $stmt->fetch();

$playlistData = null;

if ($zeitplanRow) {
    $playlistId = (int)$zeitplanRow['playlist_id'];

    // Playlist-Name
    $stmt = $pdo->prepare('SELECT name FROM playlists WHERE id = :id');
    $stmt->execute([':id' => $playlistId]);
    $playlist = $stmt->fetch();

    // Layout
    $stmt = $pdo->prepare('SELECT * FROM playlist_layout WHERE playlist_id = :id');
    $stmt->execute([':id' => $playlistId]);
    $layout = $stmt->fetch();

    // Spalten-Inhalte (nur aktive Instanzen)
    $stmt = $pdo->prepare(
        'SELECT s.spalte, s.reihenfolge, m.id AS instanz_id, m.modul_typ, m.einstellungen
         FROM playlist_spalten_inhalte s
         JOIN modul_instanzen m ON m.id = s.modul_instanz_id AND m.aktiv = 1
         WHERE s.playlist_id = :id
         ORDER BY s.spalte, s.reihenfolge, s.id'
    );
    $stmt->execute([':id' => $playlistId]);
    $spaltenZeilen = $stmt->fetchAll();

    // Pro Instanz die Inhalte laden (aktiv=1, nicht abgelaufen)
    $stmtInhalte = $pdo->prepare(
        'SELECT i.id, COALESCE(med.dateiname, i.dateiname) AS dateiname,
                i.text_inhalt, i.gueltig_bis, i.reihenfolge, i.dauer_sek, i.aktiv
         FROM modul_instanz_inhalte i
         LEFT JOIN mediathek med ON med.id = i.mediathek_id
         WHERE i.modul_instanz_id = :mid
           AND i.aktiv = 1
           AND (i.gueltig_bis IS NULL OR i.gueltig_bis >= CURDATE())
         ORDER BY i.reihenfolge, i.id'
    );

    $spalten = [];
    foreach ($spaltenZeilen as $zeile) {
        $spalte = (int)$zeile['spalte'];
        $stmtInhalte->execute([':mid' => (int)$zeile['instanz_id']]);
        $inhalte = $stmtInhalte->fetchAll();

        $spalten[$spalte][] = [
            'modul_typ'     => $zeile['modul_typ'],
            'einstellungen' => json_decode($zeile['einstellungen'] ?? '{}', true) ?: [],
            'inhalte'       => $inhalte,
        ];
    }

    $playlistData = [
        'id'             => $playlistId,
        'name'           => $playlist['name'] ?? '',
        'spalten_anzahl' => (int)($layout['spalten_anzahl'] ?? 1),
        'spalte1_breite' => isset($layout['spalte1_breite']) ? (int)$layout['spalte1_breite'] : null,
        'spalte2_breite' => isset($layout['spalte2_breite']) ? (int)$layout['spalte2_breite'] : null,
        'spalte3_breite' => isset($layout['spalte3_breite']) ? (int)$layout['spalte3_breite'] : null,
        'header_uhrzeit' => (bool)($layout['header_uhrzeit'] ?? false),
        'footer_ticker'  => (bool)($layout['footer_ticker'] ?? true),
        'spalten'        => $spalten,
    ];
}

// ── 3. Aktive Ticker (ALLE passenden, dann mischen) ───────────────────────

$stmt = $pdo->prepare(
    'SELECT te.text, te.dauer_sek
     FROM ticker_zeitplan z
     JOIN ticker_playlists tp ON tp.id = z.ticker_playlist_id AND tp.aktiv = 1
     JOIN ticker_eintraege te ON te.ticker_playlist_id = tp.id
     WHERE z.monitor_id = :mid
       AND FIND_IN_SET(:tag, z.wochentage) > 0
       AND (
             (z.von_uhrzeit IS NULL AND z.bis_uhrzeit IS NULL)
          OR (:t_von >= z.von_uhrzeit AND :t_bis <= z.bis_uhrzeit)
       )
     ORDER BY te.reihenfolge, te.id'
);
$stmt->execute([
    ':mid'   => $monitorId,
    ':tag'   => $isoTag,
    ':t_von' => $jetztZeit,
    ':t_bis' => $jetztZeit,
]);
$tickerEintraege = $stmt->fetchAll();

shuffle($tickerEintraege);

// ── 4. Antwort ────────────────────────────────────────────────────────────

proxy_json_exit([
    'monitor_id'   => $monitorId,
    'monitor_name' => $monitor['name'],
    'header_text'  => $monitor['header_text'] ?? '',
    'playlist'     => $playlistData,
    'ticker'       => $tickerEintraege,
]);
