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

// --- Badge-Highlight durchschleifen: kommt der Aufruf aus einer markierten
// Monitor-Übersicht (hl_playlist/hl_ticker), bleibt der Parameter über
// Speichern & schließen / Abbrechen erhalten — die Markierung der übrigen
// Monitore geht durch die Bearbeitung nicht verloren. (Das Formular postet
// auf die eigene URL inkl. Query-String, daher überlebt $_GET den POST.)
$hlQuery  = '';
$hlLeiste = null;
$hlPl = (int)($_GET['hl_playlist'] ?? 0);
$hlTk = (int)($_GET['hl_ticker'] ?? 0);
if ($hlPl > 0 && ($hlObj = Playlist::find($hlPl))) {
    $hlQuery  = '&hl_playlist=' . $hlPl;
    $hlLeiste = 'Du prüfst gerade: Playlist „<strong>' . htmlspecialchars($hlObj['name'])
              . '</strong>" — zurück/schließen führt zur markierten Monitor-Übersicht';
} elseif ($hlTk > 0 && ($hlObj = TickerPlaylist::find($hlTk))) {
    $hlQuery  = '&hl_ticker=' . $hlTk;
    $hlLeiste = 'Du prüfst gerade: Ticker „<strong>' . htmlspecialchars($hlObj['name'])
              . '</strong>" — zurück/schließen führt zur markierten Monitor-Übersicht';
}

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
        if (!empty($_POST['schliessen'])) {
            // „Speichern & schließen" — zurück zur Monitor-Übersicht
            header('Location: monitore.php?zeitplan_gespeichert=1' . $hlQuery);
        } else {
            // „Speichern" — auf der Seite bleiben
            header('Location: monitor-zeitplan.php?id=' . $id . '&gespeichert=1' . $hlQuery);
        }
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

<?php if ($hlLeiste !== null) { admin_hl_leiste($hlLeiste, 'monitor-zeitplan.php?id=' . $id); } ?>

<div class="adm-zeitplan-kopf">
    <a href="monitore.php<?= $hlQuery !== '' ? htmlspecialchars('?' . substr($hlQuery, 1)) : '' ?>" class="adm-zurueck">← zurück zu den Monitoren</a>
    <button class="adm-btn adm-vorschau-btn"
            data-url="https://<?= htmlspecialchars($monitor['subdomain']) ?>"
            data-name="<?= htmlspecialchars($monitor['name']) ?>">Vorschau</button>
</div>

<?php if (isset($_GET['gespeichert'])): ?>
    <div class="adm-flash">Zeitplan gespeichert. Die Monitore übernehmen die Änderung innerhalb von ca. 1 Minute — oder sofort über den Button „↺ Monitore neu laden" oben.</div>
<?php endif; ?>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= $f ?></div>
<?php endforeach; ?>

<h1 style="margin-top:0">Zeitplan: <?= htmlspecialchars($monitor['name']) ?>
    <span class="adm-eintrag-typ"><?= htmlspecialchars($monitor['subdomain']) ?></span></h1>

<?php $kommende = Monitor::kommendeTermineFuer($id); ?>
<?php if ($kommende['anzahl'] > 0): ?>
    <p class="adm-termin-hinweis">📅 Dieser Monitor hat
        <strong><?= $kommende['anzahl'] ?> kommende<?= $kommende['anzahl'] === 1 ? 'n' : '' ?> Kalender-Termin<?= $kommende['anzahl'] === 1 ? '' : 'e' ?></strong><?php
        if ($kommende['naechster'] !== null): ?> (nächster:
        <?= (new DateTimeImmutable($kommende['naechster']))->format('d.m.Y') ?>)<?php endif; ?>
        — Termine stechen diesen Regelbetrieb aus.
        <a href="wochenplan.php<?= $kommende['naechster'] !== null ? '?w=' . $kommende['naechster'] : '' ?>">Im Kalender ansehen</a>
    </p>
<?php endif; ?>

<form method="post" id="zeitplan-form">
    <input type="hidden" name="aktion" value="speichern">

    <div class="adm-tabs adm-pl-tabs">
        <button type="button" class="adm-tab an" data-tab="kalender">Wochenkalender</button>
        <button type="button" class="adm-tab" data-tab="klassisch">Klassisch (Liste)</button>
    </div>

    <div class="adm-card adm-pl-ansicht" data-ansicht="klassisch" hidden>
        <h2>Playlist-Zeitplan</h2>
        <details class="adm-hilfe-klapp">
            <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
            <p class="adm-hilfe">
                Lege fest, welche Playlist wann auf diesem Monitor läuft. Playlist auswählen,
                Wochentage anklicken, und optional ein Uhrzeit-Fenster angeben.
                <strong>Ohne Uhrzeit</strong> läuft der Eintrag ganztags (Fallback).
                Bei mehreren passenden Einträgen gewinnt die <strong>höhere Priorität</strong>.
                Mit ↑/↓ die Reihenfolge bei gleicher Priorität festlegen.
            </p>
        </details>
        <?php if (empty($playlists)): ?>
            <p class="adm-hilfe">Es gibt noch keine Playlists. Lege zuerst unter
                <a href="playlists.php">Playlists</a> eine an.</p>
        <?php endif; ?>
        <div id="zeitplan-liste" class="adm-zeitregeln"
             data-art="playlist" data-prefix="zeitplan" data-idfeld="playlist_id" data-prio="1"></div>
        <button type="button" id="zeitplan-hinzu" class="adm-btn" <?= empty($playlists) ? 'disabled' : '' ?>>+ Eintrag hinzufügen</button>
    </div>

    <div class="adm-card adm-pl-ansicht" data-ansicht="kalender">
        <h2>Playlist-Zeitplan · Wochenkalender</h2>
        <details class="adm-hilfe-klapp">
            <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
            <p class="adm-hilfe">
                Klick auf eine freie Stelle legt einen neuen Eintrag an, Klick auf einen
                Block öffnet ihn zum Bearbeiten. Blöcke lassen sich <strong>ziehen</strong>
                (Uhrzeit ändern, quer = Wochentag tauschen) und an <strong>Ober-/Unterkante</strong>
                in 15-Minuten-Schritten anpassen. Ganztägige Einträge (Fallback) stehen in der
                <strong>Ganztags-Zeile</strong> oben in ihrer Tagesspalte — Klick auf eine
                freie Stelle dort legt einen neuen Fallback für den Tag an.
                Überlappende Einträge stehen nebeneinander —
                der mit höherer Priorität (P-Badge) links, er gewinnt auf dem Monitor.
                Änderungen gelten erst nach <strong>Speichern</strong>.
            </p>
        </details>
        <div id="pl-kalender-grid" class="adm-kal-grid"></div>
        <div id="pl-kalender-legende" class="adm-kal-legende"></div>
    </div>

    <div class="adm-card">
        <h2>Ticker-Zeitplan</h2>
        <details class="adm-hilfe-klapp">
            <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
            <p class="adm-hilfe">
                Lege fest, welcher Ticker wann im Footer dieses Monitors läuft. Pro
                Eintrag: Ticker (Kachel anklicken) + Wochentage + <strong>optional</strong>
                ein Uhrzeit-Fenster. <strong>Ohne Uhrzeit läuft der Ticker
                dauerhaft</strong> an den gewählten Tagen. Sind mehrere Ticker
                gleichzeitig aktiv, werden ihre Texte <strong>gemischt</strong>
                nacheinander angezeigt — <strong>keine Priorität</strong>.
            </p>
        </details>
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
        <button type="submit" name="schliessen" value="1" class="adm-btn-primary">Speichern &amp; schließen</button>
        <a href="monitore.php<?= $hlQuery !== '' ? htmlspecialchars('?' . substr($hlQuery, 1)) : '' ?>" class="adm-btn adm-btn-grau">Abbrechen</a>
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
<script src="/assets/js/admin/editor-core.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/editor-core.js') ?: time() ?>"></script>
<script src="/assets/js/admin/monitor-zeitplan.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/monitor-zeitplan.js') ?: time() ?>"></script>

<?php
admin_footer();
