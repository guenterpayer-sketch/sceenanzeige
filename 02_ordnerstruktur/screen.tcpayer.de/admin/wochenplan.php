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

// Kalender-Termine der angezeigten Woche (Schicht 2)
$termineFuerJs = [];
foreach (Monitor::termineImZeitraum($wochenStart->format('Y-m-d'), $wochenEnde->format('Y-m-d')) as $t) {
    $termineFuerJs[] = [
        'id'             => (int)$t['id'],
        'monitor_id'     => (int)$t['monitor_id'],
        'playlist_id'    => (int)$t['playlist_id'],
        'playlist_name'  => $t['playlist_name'],
        'playlist_aktiv' => (bool)$t['playlist_aktiv'],
        'datum_von'      => (string)$t['datum_von'],
        'datum_bis'      => (string)$t['datum_bis'],
        'von'            => $t['von_uhrzeit'] !== null ? substr((string)$t['von_uhrzeit'], 0, 5) : '',
        'bis'            => $t['bis_uhrzeit'] !== null ? substr((string)$t['bis_uhrzeit'], 0, 5) : '',
        'prio'           => (int)$t['prioritaet'],
    ];
}

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
            Der Kalender zeigt <strong>Kalender-Termine</strong> (konkretes Datum,
            goldener Rand) — sie stechen den wöchentlichen Regelbetrieb aus.
            Über <strong>„Regelbetrieb einblenden"</strong> lässt sich das
            Wochenmuster blass dazuschalten (bearbeiten unter
            <a href="monitore.php">Monitore → Zeitplan</a>).
            Läuft derselbe Eintrag auf mehreren Monitoren, wird er als ein Block
            mit den Monitor-Namen angezeigt; Ganztägiges steht in der
            Ganztags-Zeile oben.
        </p>
    </details>
    <?php if (empty($monitore)): ?>
        <p class="adm-hilfe">Es gibt noch keine Monitore.</p>
    <?php else: ?>
        <div id="wp-monitor-filter" class="adm-wp-filter"></div>
        <div id="wp-kalender-grid" class="adm-kal-grid"></div>
    <?php endif; ?>
</div>

<script>
window.TM_WP = {
    monitore:     <?= json_encode($monitoreFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    eintraege:    <?= json_encode($eintraegeFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    termine:      <?= json_encode($termineFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    wochen_start: <?= json_encode($wochenStart->format('Y-m-d')) ?>,
    heute:        <?= json_encode($heute->format('Y-m-d')) ?>
};
</script>
<script src="/assets/js/admin/wochenplan.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/wochenplan.js') ?: time() ?>"></script>

<?php
admin_footer();
