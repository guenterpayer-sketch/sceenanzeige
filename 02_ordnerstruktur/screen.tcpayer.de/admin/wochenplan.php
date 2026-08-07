<?php
/**
 * admin/wochenplan.php
 *
 * Kalender — alle Monitore (Schritt 24 „nur lesen", Schritt 29 Etappe B:
 * echte Kalenderwochen mit Datum + zweite Schicht Kalender-Termine):
 *
 *   - Wochen-Navigation (← Heute →, ?w=YYYY-MM-DD, KW-Anzeige)
 *   - Schicht 1: Regelbetrieb (monitor_zeitplan, Wochenmuster) — dezent
 *   - Schicht 2: Kalender-Termine (monitor_termine, konkretes Datum) — kräftig;
 *     sie stechen den Regelbetrieb auf den Monitoren aus
 *   - Identische Einträge auf mehreren Monitoren werden zu einem Block mit
 *     Monitor-Badges zusammengefasst; Checkboxen filtern Monitore.
 *
 * Der Regelbetrieb wird weiterhin pro Monitor unter Monitore → Zeitplan
 * bearbeitet. Termine anlegen/bearbeiten kommt mit Etappe C hierher.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

// --- Angezeigte Woche bestimmen (?w=Datum, normalisiert auf Montag) ---
$heute = new DateTimeImmutable('today');
try {
    $basis = ($_GET['w'] ?? '') !== '' ? new DateTimeImmutable((string)$_GET['w']) : $heute;
} catch (Exception $e) {
    $basis = $heute;
}
$wochenStart = $basis->modify('monday this week');
$wochenEnde  = $wochenStart->modify('+6 days');
$wVorherige  = $wochenStart->modify('-7 days')->format('Y-m-d');
$wNaechste   = $wochenStart->modify('+7 days')->format('Y-m-d');
$istAktuelleWoche = ($wochenStart->format('Y-m-d') === $heute->modify('monday this week')->format('Y-m-d'));

$monitore = Monitor::listAll();

$monitoreFuerJs = [];
$eintraegeFuerJs = [];
foreach ($monitore as $m) {
    $mid = (int)$m['id'];
    $monitoreFuerJs[] = [
        'id'        => $mid,
        'name'      => $m['name'],
        'subdomain' => $m['subdomain'],
    ];
    foreach (Monitor::ladeZeitplan($mid) as $z) {
        $tage = array_values(array_filter(array_map('intval', explode(',', (string)$z['wochentage'])),
            static fn($d) => $d >= 1 && $d <= 7));
        $eintraegeFuerJs[] = [
            'monitor_id'     => $mid,
            'playlist_id'    => (int)$z['playlist_id'],
            'playlist_name'  => $z['playlist_name'],
            'playlist_aktiv' => (bool)$z['playlist_aktiv'],
            'tage'           => $tage,
            'von'            => substr((string)$z['von_uhrzeit'], 0, 5),
            'bis'            => substr((string)$z['bis_uhrzeit'], 0, 5),
            'prio'           => (int)$z['prioritaet'],
        ];
    }
}

// Kalender-Termine der angezeigten Woche (Schicht 2) — gleiche Aufbereitung
// wie termin-aktion.php nach dem Speichern
$termineFuerJs = Monitor::termineFuerKalender($wochenStart->format('Y-m-d'), $wochenEnde->format('Y-m-d'));

// Playlists für den Termin-Dialog (Picker)
$playlistsFuerJs = array_map(static fn($p) => [
    'id'    => (int)$p['id'],
    'name'  => $p['name'],
    'aktiv' => (bool)$p['aktiv'],
], Playlist::listAll());

admin_header('Kalender', 'wochenplan');
?>

<h1 style="margin-top:0">Kalender — alle Monitore</h1>

<div class="adm-card">
    <div class="adm-wp-nav">
        <a class="adm-btn" href="wochenplan.php?w=<?= $wVorherige ?>">← Vorherige</a>
        <a class="adm-btn<?= $istAktuelleWoche ? ' adm-btn-primary' : '' ?>" href="wochenplan.php">Heute</a>
        <a class="adm-btn" href="wochenplan.php?w=<?= $wNaechste ?>">Nächste →</a>
        <span class="adm-wp-kw">KW <?= (int)$wochenStart->format('W') ?> ·
            <?= $wochenStart->format('d.m.') ?> – <?= $wochenEnde->format('d.m.Y') ?></span>
    </div>
    <details class="adm-hilfe-klapp">
        <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
        <p class="adm-hilfe">
            <strong>Klick auf eine freie Stelle</strong> legt einen neuen Termin an
            (in der Ganztags-Zeile: ganztägig), <strong>Klick auf einen Termin</strong>
            öffnet ihn zum Bearbeiten, Löschen oder Duplizieren — gespeichert wird
            sofort. Termine (goldener Rand) gelten für ihr konkretes Datum und
            stechen den wöchentlichen Regelbetrieb aus.
            Über <strong>„Regelbetrieb einblenden"</strong> lässt sich das
            Wochenmuster blass dazuschalten (bearbeiten unter
            <a href="monitore.php">Monitore → Zeitplan</a>).
            Ein Termin für mehrere Monitore wird als ein Block mit den
            Monitor-Namen angezeigt; Ganztägiges steht in der Ganztags-Zeile oben.
        </p>
    </details>
    <?php if (empty($monitore)): ?>
        <p class="adm-hilfe">Es gibt noch keine Monitore.</p>
    <?php else: ?>
        <div id="wp-monitor-filter" class="adm-wp-filter"></div>
        <div id="wp-kalender-grid" class="adm-kal-grid"></div>
    <?php endif; ?>
</div>

<!-- Termin-Dialog (anlegen/bearbeiten, Sofort-Speichern) -->
<div id="td-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3 id="td-titel">Termin</h3>
        <div class="adm-feld">
            <label>Playlist</label>
            <button type="button" id="td-playlist" class="adm-auswahl-kachel">
                <span class="adm-auswahl-leer">Playlist wählen …</span>
            </button>
        </div>
        <div class="adm-feld">
            <label>Monitore</label>
            <div id="td-monitore" class="adm-td-monitore"></div>
        </div>
        <div class="adm-feld adm-feld-zeit">
            <label>Datum von <input type="date" id="td-datum-von"></label>
            <label>Datum bis <input type="date" id="td-datum-bis"></label>
        </div>
        <div class="adm-feld">
            <label class="adm-inhalt-aktiv">
                <input type="checkbox" id="td-ganztags"> Ganztags (verdrängt den Regelbetrieb den ganzen Tag)
            </label>
        </div>
        <div class="adm-feld adm-feld-zeit">
            <span id="td-zeitfelder">
                <label>Von <input type="time" id="td-von" step="900"></label>
                <label>Bis <input type="time" id="td-bis" step="900"></label>
            </span>
            <label>Priorität <input type="number" id="td-prio" step="1" min="0" value="1" style="width:5em"></label>
            <label>Dauer&nbsp;(s) <input type="number" id="td-dauer" min="10" step="10" value="300" style="width:6em"></label>
        </div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="td-loeschen" class="adm-btn adm-btn-grau" hidden>Löschen</button>
            <button type="button" id="td-duplizieren" class="adm-btn adm-btn-grau" hidden
                    title="Diesen Termin als Vorlage übernehmen — anschließend Datum/Zeit anpassen und speichern">Duplizieren</button>
            <span style="flex:1"></span>
            <button type="button" id="td-abbrechen" class="adm-btn adm-btn-grau">Abbrechen</button>
            <button type="button" id="td-speichern" class="adm-btn-primary">Speichern</button>
        </div>
    </div>
</div>

<!-- Playlist-Picker für den Termin-Dialog -->
<div id="td-picker-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3>Playlist wählen</h3>
        <div id="td-picker-liste" class="adm-picker-instanzen"></div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="td-picker-abbrechen" class="adm-btn-grau">Schließen</button>
        </div>
    </div>
</div>

<script>
window.TM_WP = {
    monitore:     <?= json_encode($monitoreFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    eintraege:    <?= json_encode($eintraegeFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    termine:      <?= json_encode($termineFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    playlists:    <?= json_encode($playlistsFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    wochen_start: <?= json_encode($wochenStart->format('Y-m-d')) ?>,
    heute:        <?= json_encode($heute->format('Y-m-d')) ?>
};
</script>
<script src="/assets/js/admin/wochenplan.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/wochenplan.js') ?: time() ?>"></script>

<?php
admin_footer();
