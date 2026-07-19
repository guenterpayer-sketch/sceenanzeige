<?php
/**
 * admin/monitor-zeitplan.php
 *
 * Zeitplan-Editor für EINEN Monitor (monitor-zentrisches Modell):
 *   monitor-zeitplan.php?id=<monitor_id>
 *
 * (Übersicht/Verwaltung der Monitore liegt in admin/monitore.php.)
 *
 * Zwei Zeitpläne auf einer Seite:
 *   1. Playlist-Zeitplan: welche Playlist wann läuft (Wochentage + optionale
 *      Uhrzeit + Priorität; höhere Priorität gewinnt bei Überschneidung).
 *      Speichert nach monitor_zeitplan.
 *   2. Ticker-Zeitplan: welcher Ticker wann im Footer läuft (Wochentage +
 *      optionale Uhrzeit, OHNE Priorität — mehrere gleichzeitig aktive Ticker
 *      werden gemischt). Speichert nach ticker_zeitplan.
 *
 * Die Auswahl von Playlist bzw. Ticker je Eintrag erfolgt als anklickbare
 * Kachel (Picker-Dialog) — kein Dropdown.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$monitor = $id > 0 ? Monitor::find($id) : null;
if (!$monitor) {
    http_response_code(404);
    admin_header('Zeitplan', 'monitore');
    echo '<p class="adm-flash adm-flash-fehler">Monitor nicht gefunden.</p>';
    admin_footer();
    exit;
}

$fehler = [];

$playlists = Playlist::listAll();
$gueltigePlaylists = [];
foreach ($playlists as $p) { $gueltigePlaylists[(int)$p['id']] = true; }

$ticker = TickerPlaylist::listAll();
$gueltigeTicker = [];
foreach ($ticker as $t) { $gueltigeTicker[(int)$t['id']] = true; }

/**
 * Liest eine eingereichte Zeitplan-Zeile zu Wochentagen/Uhrzeit aus und
 * validiert sie. Liefert [tage(string), von, bis] oder null bei „leer".
 * Fügt Fehlermeldungen an $fehler an (per Referenz).
 */
function zr_zeit_pruefen(array $z, array &$fehler, bool &$leer): array
{
    $tage = array_values(array_unique(array_filter(
        array_map('intval', (array)($z['tage'] ?? [])),
        static fn($d) => $d >= 1 && $d <= 7
    )));
    sort($tage);
    $von = substr(trim((string)($z['von'] ?? '')), 0, 5);
    $bis = substr(trim((string)($z['bis'] ?? '')), 0, 5);
    $leer = (empty($tage) && $von === '' && $bis === '');
    if (empty($tage)) {
        $fehler[] = 'Jeder Eintrag braucht mindestens einen Wochentag.';
    }
    // Uhrzeit optional: entweder beide leer (= dauerhaft) ODER beide gesetzt mit von < bis.
    if (($von === '') !== ($bis === '')) {
        $fehler[] = 'Bitte entweder Von- UND Bis-Uhrzeit angeben oder beide leer lassen (dann läuft der Eintrag dauerhaft).';
    } elseif ($von !== '' && $von >= $bis) {
        $fehler[] = 'Bei einem Eintrag mit Uhrzeit muss „von" vor „bis" liegen (' . htmlspecialchars($von . '–' . $bis) . ').';
    }
    return [implode(',', $tage), $von, $bis];
}

// --- Speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    // 1. Playlist-Zeitplan
    $eintraege = [];
    foreach (($_POST['zeitplan'] ?? []) as $z) {
        $pid = (int)($z['playlist_id'] ?? 0);
        $leer = false;
        [$tage, $von, $bis] = zr_zeit_pruefen($z, $fehler, $leer);
        if ($pid <= 0 && $leer) { continue; } // komplett leere Zeile ignorieren
        if ($pid <= 0 || !isset($gueltigePlaylists[$pid])) {
            $fehler[] = 'Jeder Playlist-Eintrag braucht eine gültige Playlist.';
            continue;
        }
        if ($tage === '') { continue; } // Fehler schon vermerkt
        $eintraege[] = [
            'playlist_id' => $pid,
            'wochentage'  => $tage,
            'von'         => $von,
            'bis'         => $bis,
            'prioritaet'  => (int)($z['prio'] ?? 0),
            'dauer_sek'   => max(10, (int)($z['dauer_sek'] ?? 300)),
        ];
    }

    // 2. Ticker-Zeitplan (kein Prioritätsfeld)
    $tickerEintraege = [];
    foreach (($_POST['tickerplan'] ?? []) as $z) {
        $tid = (int)($z['ticker_id'] ?? 0);
        $leer = false;
        [$tage, $von, $bis] = zr_zeit_pruefen($z, $fehler, $leer);
        if ($tid <= 0 && $leer) { continue; }
        if ($tid <= 0 || !isset($gueltigeTicker[$tid])) {
            $fehler[] = 'Jeder Ticker-Eintrag braucht einen gültigen Ticker.';
            continue;
        }
        if ($tage === '') { continue; }
        $tickerEintraege[] = [
            'ticker_id'  => $tid,
            'wochentage' => $tage,
            'von'        => $von,
            'bis'        => $bis,
        ];
    }

    $fehler = array_values(array_unique($fehler));

    if (empty($fehler)) {
        Monitor::ersetzeZeitplan($id, $eintraege);
        Monitor::ersetzeTickerZeitplan($id, $tickerEintraege);
        header('Location: monitore.php?gespeichert=1');
        exit;
    }
}

// --- Daten für das JS (POST-Eingaben erhalten, sonst aus DB) ---
$zeitplanFuerJs = [];
$tickerplanFuerJs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['zeitplan'] ?? []) as $z) {
        $tage = array_values(array_filter(array_map('intval', (array)($z['tage'] ?? [])),
            static fn($d) => $d >= 1 && $d <= 7));
        $zeitplanFuerJs[] = [
            'playlist_id' => (int)($z['playlist_id'] ?? 0),
            'tage'        => $tage,
            'von'         => substr((string)($z['von'] ?? ''), 0, 5),
            'bis'         => substr((string)($z['bis'] ?? ''), 0, 5),
            'prio'        => (int)($z['prio'] ?? 0),
            'dauer_sek'   => max(10, (int)($z['dauer_sek'] ?? 300)),
        ];
    }
    foreach (($_POST['tickerplan'] ?? []) as $z) {
        $tage = array_values(array_filter(array_map('intval', (array)($z['tage'] ?? [])),
            static fn($d) => $d >= 1 && $d <= 7));
        $tickerplanFuerJs[] = [
            'ticker_id' => (int)($z['ticker_id'] ?? 0),
            'tage'      => $tage,
            'von'       => substr((string)($z['von'] ?? ''), 0, 5),
            'bis'       => substr((string)($z['bis'] ?? ''), 0, 5),
        ];
    }
} else {
    foreach (Monitor::ladeZeitplan($id) as $z) {
        $tage = array_values(array_filter(array_map('intval', explode(',', (string)$z['wochentage'])),
            static fn($d) => $d >= 1 && $d <= 7));
        $zeitplanFuerJs[] = [
            'playlist_id' => (int)$z['playlist_id'],
            'tage'        => $tage,
            'von'         => substr((string)$z['von_uhrzeit'], 0, 5),
            'bis'         => substr((string)$z['bis_uhrzeit'], 0, 5),
            'prio'        => (int)$z['prioritaet'],
            'dauer_sek'   => (int)$z['dauer_sek'],
        ];
    }
    foreach (Monitor::ladeTickerZeitplan($id) as $z) {
        $tage = array_values(array_filter(array_map('intval', explode(',', (string)$z['wochentage'])),
            static fn($d) => $d >= 1 && $d <= 7));
        $tickerplanFuerJs[] = [
            'ticker_id' => (int)$z['ticker_playlist_id'],
            'tage'      => $tage,
            'von'       => substr((string)$z['von_uhrzeit'], 0, 5),
            'bis'       => substr((string)$z['bis_uhrzeit'], 0, 5),
        ];
    }
}

$playlistsFuerJs = array_map(static fn($p) => [
    'id'    => (int)$p['id'],
    'name'  => $p['name'],
    'aktiv' => (bool)$p['aktiv'],
], $playlists);

$tickersFuerJs = array_map(static fn($t) => [
    'id'    => (int)$t['id'],
    'name'  => $t['name'],
    'aktiv' => (bool)$t['aktiv'],
], $ticker);

admin_header('Zeitplan — ' . $monitor['name'], 'monitore');
?>

<div class="adm-zeitplan-kopf">
    <a href="monitore.php" class="adm-zurueck">← zurück zu den Monitoren</a>
    <button class="adm-btn adm-vorschau-btn"
            data-url="https://<?= htmlspecialchars($monitor['subdomain']) ?>"
            data-name="<?= htmlspecialchars($monitor['name']) ?>">Vorschau</button>
</div>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= $f ?></div>
<?php endforeach; ?>

<h1 style="margin-top:0">Zeitplan: <?= htmlspecialchars($monitor['name']) ?>
    <span class="adm-eintrag-typ"><?= htmlspecialchars($monitor['subdomain']) ?></span></h1>

<form method="post" id="zeitplan-form">
    <input type="hidden" name="aktion" value="speichern">

    <div class="adm-tabs adm-pl-tabs">
        <button type="button" class="adm-tab an" data-tab="klassisch">Klassisch (Liste)</button>
        <button type="button" class="adm-tab" data-tab="kalender">Wochenkalender</button>
    </div>

    <div class="adm-card adm-pl-ansicht" data-ansicht="klassisch">
        <h2>Playlist-Zeitplan</h2>
        <p class="adm-hilfe">
            Lege fest, welche Playlist wann auf diesem Monitor läuft. Playlist auswählen,
            Wochentage anklicken, und optional ein Uhrzeit-Fenster angeben.
            <strong>Ohne Uhrzeit</strong> läuft der Eintrag ganztags (Fallback).
            Bei mehreren passenden Einträgen gewinnt die <strong>höhere Priorität</strong>.
            Mit ↑/↓ die Reihenfolge bei gleicher Priorität festlegen.
        </p>
        <?php if (empty($playlists)): ?>
            <p class="adm-hilfe">Es gibt noch keine Playlists. Lege zuerst unter
                <a href="playlists.php">Playlists</a> eine an.</p>
        <?php endif; ?>
        <div id="zeitplan-liste" class="adm-zeitregeln"
             data-art="playlist" data-prefix="zeitplan" data-idfeld="playlist_id" data-prio="1"></div>
        <button type="button" id="zeitplan-hinzu" class="adm-btn" <?= empty($playlists) ? 'disabled' : '' ?>>+ Eintrag hinzufügen</button>
    </div>

    <div class="adm-card adm-pl-ansicht" data-ansicht="kalender" hidden>
        <h2>Playlist-Zeitplan · Wochenkalender</h2>
        <p class="adm-hilfe">
            Nur-Ansicht: zeigt die im Reiter „Klassisch" gepflegten Einträge visuell an.
            Ganztägige Einträge (ohne Uhrzeit) stehen oben als <strong>Fallback</strong>.
            Bei Überschneidungen gewinnt der Eintrag mit höherer Priorität (P-Badge).
            Zum Bearbeiten in den Reiter „Klassisch" wechseln.
        </p>
        <div id="pl-kalender-fallback" class="adm-kal-fallback"></div>
        <div id="pl-kalender-grid" class="adm-kal-grid"></div>
    </div>

    <div class="adm-card">
        <h2>Ticker-Zeitplan</h2>
        <p class="adm-hilfe">
            Lege fest, welcher Ticker wann im Footer dieses Monitors läuft. Pro
            Eintrag: Ticker (Kachel anklicken) + Wochentage + <strong>optional</strong>
            ein Uhrzeit-Fenster. <strong>Ohne Uhrzeit läuft der Ticker
            dauerhaft</strong> an den gewählten Tagen. Sind mehrere Ticker
            gleichzeitig aktiv, werden ihre Texte <strong>gemischt</strong>
            nacheinander angezeigt — <strong>keine Priorität</strong>.
        </p>
        <?php if (empty($ticker)): ?>
            <p class="adm-hilfe">Es gibt noch keine Ticker. Lege zuerst unter
                <a href="ticker.php">Ticker</a> einen an.</p>
        <?php endif; ?>
        <div id="ticker-liste" class="adm-zeitregeln"
             data-art="ticker" data-prefix="tickerplan" data-idfeld="ticker_id" data-prio="0"></div>
        <button type="button" id="ticker-hinzu" class="adm-btn" <?= empty($ticker) ? 'disabled' : '' ?>>+ Eintrag hinzufügen</button>
    </div>

    <div class="adm-aktionsleiste">
        <button type="submit" class="adm-btn-primary">Speichern</button>
        <a href="monitore.php" class="adm-btn adm-btn-grau">Abbrechen</a>
    </div>
</form>

<!-- Detail-Dialog: Zeitplan-Eintrag im Kalender bearbeiten/anlegen -->
<div id="zd-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3 id="zd-titel">Zeitplan-Eintrag</h3>
        <div class="adm-feld">
            <label>Playlist</label>
            <button type="button" id="zd-playlist" class="adm-auswahl-kachel">
                <span class="adm-auswahl-leer">Playlist wählen …</span>
            </button>
        </div>
        <div class="adm-feld">
            <label>Wochentage</label>
            <div id="zd-tage" class="adm-tag-btns"></div>
            <div class="adm-zr-presets" style="margin-top:6px">
                <button type="button" class="adm-mini" data-preset="alle">Alle</button>
                <button type="button" class="adm-mini" data-preset="woche">Mo–Fr</button>
                <button type="button" class="adm-mini" data-preset="we">Wochenende</button>
            </div>
        </div>
        <div class="adm-feld">
            <label class="adm-inhalt-aktiv">
                <input type="checkbox" id="zd-ganztags"> Ganztags (Fallback — läuft, wenn nichts Spezifischeres passt)
            </label>
        </div>
        <div class="adm-feld adm-feld-zeit" id="zd-zeitfelder">
            <label>Von <input type="time" id="zd-von" step="900"></label>
            <label>Bis <input type="time" id="zd-bis" step="900"></label>
            <label>Priorität <input type="number" id="zd-prio" step="1" min="0" value="1" style="width:5em"></label>
            <label>Dauer&nbsp;(s) <input type="number" id="zd-dauer" min="10" step="10" value="300" style="width:6em"></label>
        </div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="zd-loeschen" class="adm-btn adm-btn-grau" hidden>Löschen</button>
            <button type="button" id="zd-duplizieren" class="adm-btn adm-btn-grau" hidden title="Diesen Eintrag als Vorlage übernehmen — anschließend Tage/Zeit anpassen und speichern">Duplizieren</button>
            <span style="flex:1"></span>
            <button type="button" id="zd-abbrechen" class="adm-btn adm-btn-grau">Abbrechen</button>
            <button type="button" id="zd-speichern" class="adm-btn-primary">Übernehmen</button>
        </div>
    </div>
</div>

<!-- Picker-Dialog (Playlist bzw. Ticker als Kacheln) -->
<div id="picker-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3 id="picker-titel">Auswählen</h3>
        <div id="picker-liste" class="adm-picker-instanzen"></div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="picker-abbrechen" class="adm-btn-grau">Schließen</button>
        </div>
    </div>
</div>

<script>
window.TM_ZP = {
    zeitplan:   <?= json_encode($zeitplanFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    tickerplan: <?= json_encode($tickerplanFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    playlists:  <?= json_encode($playlistsFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    tickers:    <?= json_encode($tickersFuerJs, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/js/admin/monitor-zeitplan.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/monitor-zeitplan.js') ?: time() ?>"></script>

<?php
admin_footer();
