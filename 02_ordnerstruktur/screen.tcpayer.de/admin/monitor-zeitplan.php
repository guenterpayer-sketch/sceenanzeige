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
(function () {
    var ZEITPLAN   = <?= json_encode($zeitplanFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var TICKERPLAN = <?= json_encode($tickerplanFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var PLAYLISTS  = <?= json_encode($playlistsFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var TICKERS    = <?= json_encode($tickersFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var TAGE    = [ [1,'Mo'], [2,'Di'], [3,'Mi'], [4,'Do'], [5,'Fr'], [6,'Sa'], [7,'So'] ];
    var PRESETS = { alle:[1,2,3,4,5,6,7], woche:[1,2,3,4,5], we:[6,7] };
    var META = {
        playlist: { items: PLAYLISTS, icon: '🗂️', label: 'Playlist', titel: 'Playlist wählen', leer: 'Playlist wählen …' },
        ticker:   { items: TICKERS,   icon: '📰', label: 'Ticker',   titel: 'Ticker wählen',   leer: 'Ticker wählen …' }
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
    function itemName(art, idVal) {
        var items = META[art].items, n = null;
        items.forEach(function (it) { if (it.id === parseInt(idVal, 10)) { n = it; } });
        return n;
    }

    // ---- Eine Zeitplan-Zeile (Playlist oder Ticker) ----
    function baueZeile(liste, data) {
        data = data || {};
        var art   = liste.getAttribute('data-art');
        var idFeld= liste.getAttribute('data-idfeld');
        var prio  = liste.getAttribute('data-prio') === '1';
        var tage  = data.tage || [];
        var idVal = data[idFeld] || 0;
        var gewaehlt = itemName(art, idVal);

        var zeile = document.createElement('div');
        zeile.className = 'adm-zeitregel';

        var tageBtns = TAGE.map(function (t) {
            var an = tage.indexOf(t[0]) !== -1;
            return '<button type="button" class="adm-tag-btn' + (an ? ' an' : '') +
                   '" data-tag="' + t[0] + '" aria-pressed="' + (an ? 'true' : 'false') + '">' + t[1] + '</button>';
        }).join('');

        var auswahlInner = gewaehlt
            ? '<span class="adm-eintrag-icon">' + META[art].icon + '</span>' +
              '<span class="adm-eintrag-text"><span class="adm-eintrag-name">' + escapeHtml(gewaehlt.name) +
                  (gewaehlt.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span></span>'
            : '<span class="adm-auswahl-leer">' + escapeHtml(META[art].leer) + '</span>';

        var prioFeld = prio
            ? '<label>Priorität <input type="number" data-feld="prio" value="' + (data.prio || 0) + '" step="1" style="width:5em"></label>'
            : '';
        var dauerFeld = (art === 'playlist')
            ? '<label>Dauer&nbsp;(s) <input type="number" data-feld="dauer_sek" value="' + (data.dauer_sek || 300) + '" min="10" step="10" style="width:5.5em" title="Wie lange diese Playlist läuft bevor zur nächsten rotiert wird"></label>'
            : '';

        zeile.innerHTML =
            '<div class="adm-zr-sortier">' +
                '<button type="button" class="adm-mini adm-zr-hoch" title="Nach oben">↑</button>' +
                '<button type="button" class="adm-mini adm-zr-runter" title="Nach unten">↓</button>' +
                '<button type="button" class="adm-zr-weg" title="Eintrag entfernen" aria-label="Eintrag entfernen">×</button>' +
            '</div>' +
            '<div class="adm-zr-feld adm-zr-auswahl">' +
                '<span class="adm-zr-label">' + META[art].label + '</span>' +
                '<input type="hidden" data-feld="' + idFeld + '" value="' + (parseInt(idVal, 10) || '') + '">' +
                '<button type="button" class="adm-auswahl-kachel' + (gewaehlt ? ' gewaehlt' : '') + '">' + auswahlInner + '</button>' +
            '</div>' +
            '<div class="adm-zr-feld adm-zr-tage">' +
                '<span class="adm-zr-label">Wochentage</span>' +
                '<div class="adm-tag-btns">' + tageBtns + '</div>' +
                '<div class="adm-zr-presets">' +
                    '<button type="button" class="adm-mini" data-preset="alle">Alle</button>' +
                    '<button type="button" class="adm-mini" data-preset="woche">Mo–Fr</button>' +
                    '<button type="button" class="adm-mini" data-preset="we">Wochenende</button>' +
                '</div>' +
            '</div>' +
            '<div class="adm-zr-feld adm-zr-zeit">' +
                '<span class="adm-zr-label">Uhrzeit <em>(optional)</em></span>' +
                '<div class="adm-zr-zeit-felder">' +
                    '<label>von <input type="time" data-feld="von" value="' + escapeHtml(data.von || '') + '"></label>' +
                    '<label>bis <input type="time" data-feld="bis" value="' + escapeHtml(data.bis || '') + '"></label>' +
                    prioFeld +
                    dauerFeld +
                '</div>' +
            '</div>';
        return zeile;
    }

    function bindeListe(liste, hinzuBtn) {
        function neueZeile(data) { liste.appendChild(baueZeile(liste, data)); }
        if (hinzuBtn) { hinzuBtn.addEventListener('click', function () { neueZeile({}); }); }

        liste.addEventListener('click', function (e) {
            var zeile = e.target.closest('.adm-zeitregel');
            if (!zeile) { return; }
            if (e.target.closest('.adm-zr-weg')) { zeile.remove(); return; }
            if (e.target.closest('.adm-zr-hoch')) {
                var prev = zeile.previousElementSibling;
                if (prev && prev.classList.contains('adm-zeitregel')) { liste.insertBefore(zeile, prev); }
                return;
            }
            if (e.target.closest('.adm-zr-runter')) {
                var next = zeile.nextElementSibling;
                if (next && next.classList.contains('adm-zeitregel')) { liste.insertBefore(next, zeile); }
                return;
            }
            if (e.target.closest('.adm-auswahl-kachel')) { oeffnePicker(liste, zeile); return; }
            var tagBtn = e.target.closest('.adm-tag-btn');
            if (tagBtn) {
                var an = tagBtn.classList.toggle('an');
                tagBtn.setAttribute('aria-pressed', an ? 'true' : 'false');
                return;
            }
            var preset = e.target.getAttribute('data-preset');
            if (preset && PRESETS[preset]) {
                var set = PRESETS[preset];
                zeile.querySelectorAll('.adm-tag-btn').forEach(function (b) {
                    var an = set.indexOf(parseInt(b.getAttribute('data-tag'), 10)) !== -1;
                    b.classList.toggle('an', an);
                    b.setAttribute('aria-pressed', an ? 'true' : 'false');
                });
            }
        });
        return neueZeile;
    }

    // ---- Picker-Dialog ----
    var overlay   = document.getElementById('picker-overlay');
    var pickListe = document.getElementById('picker-liste');
    var pickTitel = document.getElementById('picker-titel');
    var aktiveZeile = null, aktiveListe = null, aktiveCallback = null;

    // Picker öffnen; wenn callback gesetzt, wird bei Auswahl nur callback(it)
    // aufgerufen (für den Kalender-Dialog); sonst wird wie bisher die
    // Klassisch-Zeile befüllt.
    function oeffnePicker(liste, zeile, callback) {
        aktiveListe = liste; aktiveZeile = zeile; aktiveCallback = callback || null;
        var art = liste.getAttribute('data-art');
        pickTitel.textContent = META[art].titel;
        pickListe.innerHTML = '';
        if (!META[art].items.length) {
            pickListe.innerHTML = '<p class="adm-leer">Nichts vorhanden.</p>';
        }
        var idFeld = liste.getAttribute('data-idfeld');
        var aktuell = parseInt(zeile.querySelector('[data-feld="' + idFeld + '"]').value, 10) || 0;
        META[art].items.forEach(function (it) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'adm-picker-instanz' + (it.aktiv ? '' : ' inaktiv') + (it.id === aktuell ? ' gewaehlt' : '');
            btn.innerHTML =
                '<span class="adm-eintrag-icon">' + META[art].icon + '</span>' +
                '<span class="adm-eintrag-text"><span class="adm-eintrag-name">' + escapeHtml(it.name) +
                    (it.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span></span>';
            btn.addEventListener('click', function () { waehle(it); });
            pickListe.appendChild(btn);
        });
        overlay.hidden = false;
    }
    function schliessePicker() { overlay.hidden = true; aktiveZeile = null; aktiveListe = null; aktiveCallback = null; }
    function waehle(it) {
        if (aktiveCallback) { var cb = aktiveCallback; schliessePicker(); cb(it); return; }
        if (!aktiveZeile || !aktiveListe) { return; }
        var art = aktiveListe.getAttribute('data-art');
        var idFeld = aktiveListe.getAttribute('data-idfeld');
        aktiveZeile.querySelector('[data-feld="' + idFeld + '"]').value = it.id;
        var kachel = aktiveZeile.querySelector('.adm-auswahl-kachel');
        kachel.classList.add('gewaehlt');
        kachel.innerHTML =
            '<span class="adm-eintrag-icon">' + META[art].icon + '</span>' +
            '<span class="adm-eintrag-text"><span class="adm-eintrag-name">' + escapeHtml(it.name) +
                (it.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span></span>';
        schliessePicker();
    }
    document.getElementById('picker-abbrechen').addEventListener('click', schliessePicker);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliessePicker(); } });

    // ---- Vor dem Absenden: Feldnamen je Liste sequenziell vergeben ----
    document.getElementById('zeitplan-form').addEventListener('submit', function () {
        [pl, tk].forEach(function (cfg) {
            var prefix = cfg.liste.getAttribute('data-prefix');
            cfg.liste.querySelectorAll('.adm-zeitregel').forEach(function (zeile, j) {
                zeile.querySelectorAll('[data-feld]').forEach(function (el) {
                    el.setAttribute('name', prefix + '[' + j + '][' + el.getAttribute('data-feld') + ']');
                });
                zeile.querySelectorAll('input.adm-zr-taghidden').forEach(function (h) { h.remove(); });
                zeile.querySelectorAll('.adm-tag-btn.an').forEach(function (b) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.className = 'adm-zr-taghidden';
                    h.name = prefix + '[' + j + '][tage][]';
                    h.value = b.getAttribute('data-tag');
                    zeile.appendChild(h);
                });
            });
        });
    });

    // ---- Initialaufbau beider Listen ----
    var pl = { liste: document.getElementById('zeitplan-liste') };
    var tk = { liste: document.getElementById('ticker-liste') };
    pl.neu = bindeListe(pl.liste, document.getElementById('zeitplan-hinzu'));
    tk.neu = bindeListe(tk.liste, document.getElementById('ticker-hinzu'));
    ZEITPLAN.forEach(pl.neu);
    TICKERPLAN.forEach(tk.neu);

    // ---- Wochenkalender-Ansicht (Etappe A: nur lesen) ------------------------
    // Liest bei jedem Tab-Wechsel den aktuellen DOM-Zustand von zeitplan-liste
    // (nicht das JSON-ZEITPLAN), damit ungespeicherte Änderungen sofort sichtbar
    // sind. Zeitfenster fix 6:00–24:00; ganztägige Einträge landen im Fallback.
    var KAL_START_H = 9, KAL_END_H = 24, KAL_ROW_H = 40;

    function playlistFarbe(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) { h = (h * 31 + name.charCodeAt(i)) >>> 0; }
        return 'hsl(' + (h % 360) + ' 55% 38%)';
    }

    function leseEintraegeAusDom() {
        var out = [];
        pl.liste.querySelectorAll('.adm-zeitregel').forEach(function (z) {
            var pid = parseInt(z.querySelector('[data-feld="playlist_id"]').value, 10) || 0;
            if (!pid) { return; }
            var tage = Array.prototype.map.call(
                z.querySelectorAll('.adm-tag-btn.an'),
                function (b) { return parseInt(b.getAttribute('data-tag'), 10); }
            );
            if (!tage.length) { return; }
            out.push({
                playlist_id: pid,
                tage:        tage,
                von:         z.querySelector('[data-feld="von"]').value || '',
                bis:         z.querySelector('[data-feld="bis"]').value || '',
                prio:        parseInt(z.querySelector('[data-feld="prio"]').value, 10) || 0
            });
        });
        return out;
    }

    function zeitZuMin(s) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(s || '');
        if (!m) { return null; }
        return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    }

    function rendereKalender() {
        var eintraege = leseEintraegeAusDom();
        var fallbackEl = document.getElementById('pl-kalender-fallback');
        var gridEl     = document.getElementById('pl-kalender-grid');

        // Fallback-Zeile: ganztägige Einträge (kein Uhrzeit-Fenster)
        var fallbacks = eintraege.filter(function (e) { return !e.von && !e.bis; });
        if (!fallbacks.length) {
            fallbackEl.innerHTML = '<span class="adm-kal-fallback-leer">Kein Fallback gesetzt — außerhalb der Zeitfenster wird nichts angezeigt.</span>';
        } else {
            fallbackEl.innerHTML = '<span class="adm-kal-fallback-label">Fallback (ganztags):</span> '
                + fallbacks.map(function (e) {
                    var pl = itemName('playlist', e.playlist_id);
                    var name = pl ? pl.name : '?';
                    var tage = e.tage.length === 7 ? 'täglich'
                        : e.tage.map(function (t) { return TAGE[t-1][1]; }).join(' ');
                    return '<span class="adm-kal-fallback-item" style="background:' + playlistFarbe(name) + '">'
                        + '<span class="adm-kal-fallback-name">' + escapeHtml(name) + '</span>'
                        + '<span class="adm-kal-fallback-meta">' + escapeHtml(tage)
                        + (e.prio ? ' · P' + e.prio : '') + '</span></span>';
                }).join(' ');
        }

        // Grid-Skelett aufbauen
        var std = KAL_END_H - KAL_START_H;
        var head = '<div class="adm-kal-corner"></div>';
        for (var t = 0; t < 7; t++) { head += '<div class="adm-kal-tagkopf">' + TAGE[t][1] + '</div>'; }
        var stunden = '';
        for (var h = 0; h < std; h++) {
            stunden += '<div class="adm-kal-std" style="top:' + (h * KAL_ROW_H) + 'px">'
                + String(KAL_START_H + h).padStart(2, '0') + ':00</div>';
        }
        var spalten = '';
        for (var d = 1; d <= 7; d++) {
            spalten += '<div class="adm-kal-tag" data-tag="' + d + '" style="height:' + (std * KAL_ROW_H) + 'px"></div>';
        }
        gridEl.innerHTML =
            '<div class="adm-kal-kopf">' + head + '</div>' +
            '<div class="adm-kal-body">' +
                '<div class="adm-kal-stundenspalte" style="height:' + (std * KAL_ROW_H) + 'px">' + stunden + '</div>' +
                '<div class="adm-kal-spalten">' + spalten + '</div>' +
            '</div>';

        // Zeitgebundene Einträge einsetzen — pro Wochentag ein Block
        var startMin = KAL_START_H * 60, endMin = KAL_END_H * 60;
        eintraege.forEach(function (e) {
            if (!e.von || !e.bis) { return; }
            var vMin = zeitZuMin(e.von), bMin = zeitZuMin(e.bis);
            if (vMin == null || bMin == null || bMin <= vMin) { return; }
            var pl   = itemName('playlist', e.playlist_id);
            var name = pl ? pl.name : '?';
            var farbe = playlistFarbe(name);
            var vClamp = Math.max(vMin, startMin), bClamp = Math.min(bMin, endMin);
            if (bClamp <= vClamp) { return; }
            var topPx = (vClamp - startMin) / 60 * KAL_ROW_H;
            var hPx   = (bClamp - vClamp) / 60 * KAL_ROW_H;
            e.tage.forEach(function (tag) {
                var col = gridEl.querySelector('.adm-kal-tag[data-tag="' + tag + '"]');
                if (!col) { return; }
                var b = document.createElement('div');
                b.className = 'adm-kal-block';
                b.style.top = topPx + 'px';
                b.style.height = hPx + 'px';
                b.style.background = farbe;
                b.style.zIndex = String(10 + e.prio);
                b.innerHTML = '<span class="adm-kal-block-titel">' + escapeHtml(name) + '</span>'
                    + '<span class="adm-kal-block-meta">' + e.von + '–' + e.bis
                    + (e.prio ? ' · P' + e.prio : '')
                    + (pl && !pl.aktiv ? ' · pausiert' : '') + '</span>';
                b.title = name + ' · ' + e.von + '–' + e.bis + (e.prio ? ' · Priorität ' + e.prio : '');
                col.appendChild(b);
            });
        });
    }

    var tabs = document.querySelector('.adm-pl-tabs');
    if (tabs) {
        tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.adm-tab');
            if (!btn) { return; }
            var tab = btn.getAttribute('data-tab');
            tabs.querySelectorAll('.adm-tab').forEach(function (b) {
                b.classList.toggle('an', b === btn);
            });
            document.querySelectorAll('.adm-pl-ansicht').forEach(function (v) {
                v.hidden = (v.getAttribute('data-ansicht') !== tab);
            });
            if (tab === 'kalender') { rendereKalender(); }
        });
    }

    // ---- Etappe B: Bearbeiten im Kalender ----------------------------------
    // Wahrheit sind die Klassisch-Zeilen. Der Kalender liest von dort und
    // schreibt dorthin zurück; jede Zeile bekommt eine stabile zeit-id, die
    // auch die Kalender-Blöcke tragen. Nach jeder Änderung wird der Kalender
    // aus dem aktuellen DOM neu gerendert.

    var _zeitIdZaehler = 0;
    function neueZeitId() { return 'z' + (++_zeitIdZaehler); }
    function stelleZeitIdsSicher() {
        pl.liste.querySelectorAll('.adm-zeitregel').forEach(function (z) {
            if (!z.getAttribute('data-zeit-id')) { z.setAttribute('data-zeit-id', neueZeitId()); }
        });
    }
    function findeZeile(zid) {
        return pl.liste.querySelector('.adm-zeitregel[data-zeit-id="' + zid + '"]');
    }
    function setzeZeileTage(zeile, tage) {
        zeile.querySelectorAll('.adm-tag-btn').forEach(function (b) {
            var an = tage.indexOf(parseInt(b.getAttribute('data-tag'), 10)) !== -1;
            b.classList.toggle('an', an);
            b.setAttribute('aria-pressed', an ? 'true' : 'false');
        });
    }
    function schreibeZeile(zid, patch) {
        var z = findeZeile(zid);
        if (!z) { return; }
        if (patch.playlist_id !== undefined) {
            z.querySelector('[data-feld="playlist_id"]').value = patch.playlist_id;
            var it = itemName('playlist', patch.playlist_id);
            var k  = z.querySelector('.adm-auswahl-kachel');
            if (it && k) {
                k.classList.add('gewaehlt');
                k.innerHTML =
                    '<span class="adm-eintrag-icon">' + META.playlist.icon + '</span>' +
                    '<span class="adm-eintrag-text"><span class="adm-eintrag-name">' + escapeHtml(it.name) +
                        (it.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span></span>';
            }
        }
        if (patch.tage !== undefined) { setzeZeileTage(z, patch.tage); }
        if (patch.von  !== undefined) { z.querySelector('[data-feld="von"]').value = patch.von; }
        if (patch.bis  !== undefined) { z.querySelector('[data-feld="bis"]').value = patch.bis; }
        if (patch.prio !== undefined) {
            var pf = z.querySelector('[data-feld="prio"]'); if (pf) { pf.value = patch.prio; }
        }
        if (patch.dauer_sek !== undefined) {
            var df = z.querySelector('[data-feld="dauer_sek"]'); if (df) { df.value = patch.dauer_sek; }
        }
    }
    function leseZeile(zid) {
        var z = findeZeile(zid);
        if (!z) { return null; }
        return {
            playlist_id: parseInt(z.querySelector('[data-feld="playlist_id"]').value, 10) || 0,
            tage: Array.prototype.map.call(z.querySelectorAll('.adm-tag-btn.an'),
                function (b) { return parseInt(b.getAttribute('data-tag'), 10); }),
            von: z.querySelector('[data-feld="von"]').value || '',
            bis: z.querySelector('[data-feld="bis"]').value || '',
            prio: parseInt(z.querySelector('[data-feld="prio"]').value, 10) || 0,
            dauer_sek: parseInt((z.querySelector('[data-feld="dauer_sek"]') || {}).value || '300', 10) || 300
        };
    }
    function loescheZeile(zid) { var z = findeZeile(zid); if (z) { z.remove(); } }
    function legeNeuZeileAn(daten) {
        pl.neu(daten);
        var zeilen = pl.liste.querySelectorAll('.adm-zeitregel');
        var neu = zeilen[zeilen.length - 1];
        var zid = neueZeitId();
        neu.setAttribute('data-zeit-id', zid);
        // baueZeile hat schon Auswahl/Tage/Uhrzeit/Prio gesetzt; nichts zu tun
        return zid;
    }

    // Kalender neu rendern nachdem sich Klassisch geändert hat
    function refreshKalender() {
        if (document.querySelector('.adm-pl-ansicht[data-ansicht="kalender"]').hidden) { return; }
        rendereKalender();
    }

    // Die vom Etappe-A-Rendering erzeugten Blöcke bekommen zeit-id + Tag als data-attrs.
    // Dafür wickele ich rendereKalender ein und ergänze nach dem Aufbau.
    var _renderOriginal = rendereKalender;
    rendereKalender = function () {
        stelleZeitIdsSicher();
        _renderOriginal();
        // Jetzt: Blöcke mit zeit-id verknüpfen, Klick-Zonen aktivieren,
        // Resize-Handles anhängen, Fallback-Chips klickbar machen.
        var eintraege = leseEintraegeAusDomMitId();
        var gridEl    = document.getElementById('pl-kalender-grid');
        var fbEl      = document.getElementById('pl-kalender-fallback');

        // Blöcke im Grid — pro Tag der passende Kalender-Block
        eintraege.forEach(function (e) {
            if (!e.von || !e.bis) { return; }
            e.tage.forEach(function (tag) {
                var col = gridEl.querySelector('.adm-kal-tag[data-tag="' + tag + '"]');
                if (!col) { return; }
                // Nimm den letzten noch ungebundenen Block in dieser Spalte,
                // der zu unseren Top/Height passt — einfacher: alle Blöcke ohne
                // data-zeit-id nach Reihenfolge auffüllen.
                var kandidat = col.querySelector('.adm-kal-block:not([data-zeit-id])');
                if (!kandidat) { return; }
                kandidat.setAttribute('data-zeit-id', e.zid);
                kandidat.setAttribute('data-tag', tag);
                kandidat.classList.add('adm-kal-block--interaktiv');
                var handle = document.createElement('div');
                handle.className = 'adm-kal-block-resize';
                handle.title = 'Ende ziehen';
                kandidat.appendChild(handle);
            });
        });

        // Fallback-Chips klickbar machen — jedem Chip die passende zeit-id anhängen
        var fbChips = fbEl.querySelectorAll('.adm-kal-fallback-item');
        var fbEintraege = eintraege.filter(function (e) { return !e.von && !e.bis; });
        fbChips.forEach(function (chip, i) {
            if (fbEintraege[i]) {
                chip.setAttribute('data-zeit-id', fbEintraege[i].zid);
                chip.classList.add('adm-kal-fallback-item--interaktiv');
                chip.title = 'Bearbeiten';
            }
        });

        // "+ Fallback"-Button in Fallback-Zeile
        if (!fbEl.querySelector('.adm-kal-fallback-neu')) {
            var neu = document.createElement('button');
            neu.type = 'button';
            neu.className = 'adm-kal-fallback-neu';
            neu.textContent = '+ Fallback';
            neu.title = 'Ganztägigen Eintrag hinzufügen';
            fbEl.appendChild(neu);
        }
    };

    function leseEintraegeAusDomMitId() {
        var out = [];
        pl.liste.querySelectorAll('.adm-zeitregel').forEach(function (z) {
            var pid = parseInt(z.querySelector('[data-feld="playlist_id"]').value, 10) || 0;
            if (!pid) { return; }
            var tage = Array.prototype.map.call(z.querySelectorAll('.adm-tag-btn.an'),
                function (b) { return parseInt(b.getAttribute('data-tag'), 10); });
            if (!tage.length) { return; }
            out.push({
                zid:  z.getAttribute('data-zeit-id'),
                playlist_id: pid, tage: tage,
                von: z.querySelector('[data-feld="von"]').value || '',
                bis: z.querySelector('[data-feld="bis"]').value || '',
                prio: parseInt(z.querySelector('[data-feld="prio"]').value, 10) || 0
            });
        });
        return out;
    }

    // ---- Detail-Dialog -----------------------------------------------------
    var zd = {
        overlay:   document.getElementById('zd-overlay'),
        titel:     document.getElementById('zd-titel'),
        plBtn:     document.getElementById('zd-playlist'),
        tageWrap:  document.getElementById('zd-tage'),
        ganztags:  document.getElementById('zd-ganztags'),
        zeitfelder:document.getElementById('zd-zeitfelder'),
        von:       document.getElementById('zd-von'),
        bis:       document.getElementById('zd-bis'),
        prio:      document.getElementById('zd-prio'),
        dauer:     document.getElementById('zd-dauer'),
        loeschen:  document.getElementById('zd-loeschen'),
        abbrechen: document.getElementById('zd-abbrechen'),
        speichern: document.getElementById('zd-speichern')
    };
    // Tag-Buttons in den Dialog
    zd.tageWrap.innerHTML = TAGE.map(function (t) {
        return '<button type="button" class="adm-tag-btn" data-tag="' + t[0] + '" aria-pressed="false">' + t[1] + '</button>';
    }).join('');

    var zdState = null; // { zid|null, playlist_id, tage, von, bis, prio, dauer, ganztags }

    function zdBefuelle() {
        // Playlist-Button
        var it = itemName('playlist', zdState.playlist_id);
        zd.plBtn.classList.toggle('gewaehlt', !!it);
        zd.plBtn.innerHTML = it
            ? '<span class="adm-eintrag-icon">' + META.playlist.icon + '</span>' +
              '<span class="adm-eintrag-text"><span class="adm-eintrag-name">' + escapeHtml(it.name) +
                  (it.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span></span>'
            : '<span class="adm-auswahl-leer">Playlist wählen …</span>';
        // Tage
        zd.tageWrap.querySelectorAll('.adm-tag-btn').forEach(function (b) {
            var an = zdState.tage.indexOf(parseInt(b.getAttribute('data-tag'), 10)) !== -1;
            b.classList.toggle('an', an);
            b.setAttribute('aria-pressed', an ? 'true' : 'false');
        });
        // Zeit/Prio/Dauer + Ganztags
        zd.ganztags.checked = zdState.ganztags;
        zd.von.value = zdState.von || '';
        zd.bis.value = zdState.bis || '';
        zd.prio.value = zdState.prio;
        zd.dauer.value = zdState.dauer;
        zd.zeitfelder.style.opacity = zdState.ganztags ? '0.4' : '1';
        zd.von.disabled = zd.bis.disabled = zdState.ganztags;
        zd.loeschen.hidden = !zdState.zid;
    }

    function zdOeffnen(daten) {
        zdState = Object.assign({
            zid: null, playlist_id: 0, tage: [], von: '', bis: '',
            prio: 1, dauer: 300, ganztags: false
        }, daten);
        zd.titel.textContent = zdState.zid ? 'Eintrag bearbeiten' : 'Neuer Eintrag';
        zdBefuelle();
        zd.overlay.hidden = false;
    }
    function zdSchliessen() { zd.overlay.hidden = true; zdState = null; }

    zd.plBtn.addEventListener('click', function () {
        oeffnePicker(pl.liste, null, function (it) {
            zdState.playlist_id = it.id;
            zdBefuelle();
            zd.overlay.hidden = false; // Dialog wieder in Vordergrund
        });
        zd.overlay.hidden = true; // während Picker zu; wird beim Callback wieder geöffnet
    });
    zd.tageWrap.addEventListener('click', function (e) {
        var b = e.target.closest('.adm-tag-btn');
        if (!b) { return; }
        var t = parseInt(b.getAttribute('data-tag'), 10);
        var i = zdState.tage.indexOf(t);
        if (i === -1) { zdState.tage.push(t); zdState.tage.sort(); } else { zdState.tage.splice(i, 1); }
        zdBefuelle();
    });
    zd.overlay.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            zdState.tage = PRESETS[btn.getAttribute('data-preset')].slice();
            zdBefuelle();
        });
    });
    zd.ganztags.addEventListener('change', function () {
        zdState.ganztags = zd.ganztags.checked;
        if (zdState.ganztags) { zdState.von = ''; zdState.bis = ''; zdState.prio = 0; zd.prio.value = 0; }
        zdBefuelle();
    });
    ['von', 'bis'].forEach(function (k) {
        zd[k].addEventListener('input', function () { zdState[k] = zd[k].value; });
    });
    zd.prio.addEventListener('input',  function () { zdState.prio  = parseInt(zd.prio.value, 10) || 0; });
    zd.dauer.addEventListener('input', function () { zdState.dauer = parseInt(zd.dauer.value, 10) || 300; });
    zd.abbrechen.addEventListener('click', zdSchliessen);
    zd.overlay.addEventListener('click', function (e) { if (e.target === zd.overlay) { zdSchliessen(); } });
    zd.loeschen.addEventListener('click', function () {
        admBestaetigen('Diesen Zeitplan-Eintrag löschen?', function (ok) {
            if (!ok) { return; }
            if (zdState.zid) { loescheZeile(zdState.zid); }
            zdSchliessen();
            refreshKalender();
        }, 'Löschen');
    });
    zd.speichern.addEventListener('click', function () {
        if (!zdState.playlist_id) { admMeldung('Bitte eine Playlist wählen.'); return; }
        if (!zdState.tage.length) { admMeldung('Bitte mindestens einen Wochentag wählen.'); return; }
        if (!zdState.ganztags) {
            if (!zdState.von || !zdState.bis) { admMeldung('Bitte Von- und Bis-Uhrzeit angeben oder „Ganztags" wählen.'); return; }
            if (zdState.von >= zdState.bis)   { admMeldung('„Von" muss vor „Bis" liegen.'); return; }
        }
        var patch = {
            playlist_id: zdState.playlist_id,
            tage: zdState.tage.slice(),
            von:  zdState.ganztags ? '' : zdState.von,
            bis:  zdState.ganztags ? '' : zdState.bis,
            prio: zdState.prio,
            dauer_sek: zdState.dauer
        };
        if (zdState.zid) { schreibeZeile(zdState.zid, patch); }
        else {
            // Beim Anlegen: pl.neu erwartet das Klassisch-Format (playlist_id, tage, von, bis, prio, dauer_sek)
            legeNeuZeileAn(patch);
        }
        zdSchliessen();
        refreshKalender();
    });

    // ---- Klick + Drag im Grid ---------------------------------------------
    var gridEl = document.getElementById('pl-kalender-grid');
    var fbEl   = document.getElementById('pl-kalender-fallback');

    function snap15Min(minGesamt) { return Math.round(minGesamt / 15) * 15; }
    function minZuHhmm(m) {
        var h = Math.floor(m / 60), mm = m % 60;
        return String(h).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
    }

    // Drag-Tracking (Verschieben eines Blocks bzw. Ziehen der Unterkante)
    var drag = null; // { zid, tag, kind: 'move'|'resize', startY, urVon, urBis, spalteEl, blockEl }

    gridEl.addEventListener('mousedown', function (e) {
        var block = e.target.closest('.adm-kal-block[data-zeit-id]');
        if (!block) { return; }
        var handle = e.target.closest('.adm-kal-block-resize');
        var zid = block.getAttribute('data-zeit-id');
        var eintrag = leseZeile(zid);
        if (!eintrag) { return; }
        drag = {
            zid: zid,
            tag: parseInt(block.getAttribute('data-tag'), 10),
            kind: handle ? 'resize' : 'move',
            startY: e.clientY,
            urVon: eintrag.von, urBis: eintrag.bis,
            blockEl: block,
            distanz: 0
        };
        block.classList.add('wird-bewegt');
        e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
        if (!drag) { return; }
        var dy = e.clientY - drag.startY;
        drag.distanz = Math.max(drag.distanz, Math.abs(dy));
        // Live-Preview: nur visuell, echte Werte kommen erst bei mouseup
        if (drag.kind === 'move') {
            drag.blockEl.style.transform = 'translateY(' + dy + 'px)';
        } else {
            var neu = parseInt(drag.blockEl.style.height, 10) + dy;
            drag.blockEl.style.height = Math.max(KAL_ROW_H / 4, neu) + 'px';
            drag.startY = e.clientY;
        }
    });

    document.addEventListener('mouseup', function (e) {
        if (!drag) { return; }
        var d = drag; drag = null;
        // Zustand VOR dem Reset lesen
        var dyMove = 0, neueH = 0;
        if (d.kind === 'move') {
            var m = /translateY\(([-\d.]+)px\)/.exec(d.blockEl.style.transform || '');
            dyMove = m ? parseFloat(m[1]) : 0;
        } else {
            neueH = parseInt(d.blockEl.style.height, 10) || 0;
        }
        // Optik zurücksetzen — wird gleich vom refreshKalender neu gemalt
        d.blockEl.classList.remove('wird-bewegt');
        d.blockEl.style.transform = '';

        // Klick (kaum bewegt) → Detail-Dialog öffnen
        if (d.distanz < 5) {
            var eintrag = leseZeile(d.zid);
            if (eintrag) {
                zdOeffnen({
                    zid: d.zid,
                    playlist_id: eintrag.playlist_id,
                    tage: eintrag.tage,
                    von: eintrag.von, bis: eintrag.bis,
                    prio: eintrag.prio, dauer: eintrag.dauer_sek,
                    ganztags: !eintrag.von && !eintrag.bis
                });
            }
            return;
        }
        // Bewegung → Werte anpassen
        var eintrag = leseZeile(d.zid);
        if (!eintrag) { return; }
        var vonMinAlt = zeitZuMin(d.urVon), bisMinAlt = zeitZuMin(d.urBis);
        if (d.kind === 'move') {
            var deltaMin = snap15Min(dyMove / KAL_ROW_H * 60);
            var vonMin = Math.max(0, Math.min(24*60, vonMinAlt + deltaMin));
            var bisMin = vonMin + (bisMinAlt - vonMinAlt);
            if (bisMin > 24*60) { bisMin = 24*60; vonMin = bisMin - (bisMinAlt - vonMinAlt); }
            schreibeZeile(d.zid, { von: minZuHhmm(vonMin), bis: minZuHhmm(bisMin) });
        } else {
            var dauerMin = snap15Min(neueH / KAL_ROW_H * 60);
            if (dauerMin < 15) { dauerMin = 15; }
            var bisMin = Math.min(24*60, vonMinAlt + dauerMin);
            schreibeZeile(d.zid, { bis: minZuHhmm(bisMin) });
        }
        refreshKalender();
    });

    // Klick auf leere Grid-Zelle → Neu-Dialog mit Tag + Zeit vorbelegt
    gridEl.addEventListener('click', function (e) {
        if (drag) { return; }
        // Klick auf Block wird schon in mouseup verarbeitet
        if (e.target.closest('.adm-kal-block')) { return; }
        var col = e.target.closest('.adm-kal-tag');
        if (!col) { return; }
        var rect = col.getBoundingClientRect();
        var yInCol = e.clientY - rect.top;
        var vonMin = snap15Min(yInCol / KAL_ROW_H * 60) + KAL_START_H * 60;
        vonMin = Math.max(KAL_START_H * 60, Math.min(KAL_END_H * 60 - 60, vonMin));
        var tag = parseInt(col.getAttribute('data-tag'), 10);
        zdOeffnen({
            tage: [tag],
            von:  minZuHhmm(vonMin),
            bis:  minZuHhmm(vonMin + 60), // Standarddauer 1 h
            prio: 1, dauer: 300, ganztags: false
        });
    });

    // Fallback: Chip = Bearbeiten, "+ Fallback"-Button = Neu (ganztags)
    fbEl.addEventListener('click', function (e) {
        if (e.target.closest('.adm-kal-fallback-neu')) {
            zdOeffnen({ tage: [1,2,3,4,5,6,7], ganztags: true, prio: 0, dauer: 300 });
            return;
        }
        var chip = e.target.closest('.adm-kal-fallback-item[data-zeit-id]');
        if (!chip) { return; }
        var zid = chip.getAttribute('data-zeit-id');
        var eintrag = leseZeile(zid);
        if (!eintrag) { return; }
        zdOeffnen({
            zid: zid,
            playlist_id: eintrag.playlist_id, tage: eintrag.tage,
            von: '', bis: '',
            prio: eintrag.prio, dauer: eintrag.dauer_sek,
            ganztags: true
        });
    });
})();
</script>

<?php
admin_footer();
