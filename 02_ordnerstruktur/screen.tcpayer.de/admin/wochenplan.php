<?php
/**
 * admin/wochenplan.php
 *
 * Globaler Wochenplan (Schritt 24, Variante 1 — nur lesen):
 * zeigt die Playlist-Zeitpläne ALLER Monitore in einem gemeinsamen
 * Wochenkalender. Identische Einträge (gleiche Playlist + Uhrzeit) auf
 * mehreren Monitoren werden zu einem Block mit Monitor-Badges
 * zusammengefasst. Oben Checkboxen zum Ein-/Ausblenden einzelner Monitore.
 *
 * Bearbeitet wird weiterhin pro Monitor unter Monitore → Zeitplan.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

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

admin_header('Wochenplan', 'wochenplan');
?>

<h1 style="margin-top:0">Wochenplan — alle Monitore</h1>

<div class="adm-card">
    <p class="adm-hilfe">
        Übersicht über die Playlist-Zeitpläne aller Monitore in einer Woche.
        Läuft derselbe Eintrag auf mehreren Monitoren, wird er als
        <strong>ein Block</strong> mit den Monitor-Namen angezeigt.
        Ganztägige Einträge (ohne Uhrzeit, Fallback) stehen in der
        <strong>Ganztags-Zeile</strong> oben in der jeweiligen Tagesspalte.
        Zum Bearbeiten den Zeitplan des jeweiligen Monitors unter
        <a href="monitore.php">Monitore</a> öffnen.
    </p>
    <?php if (empty($monitore)): ?>
        <p class="adm-hilfe">Es gibt noch keine Monitore.</p>
    <?php else: ?>
        <div id="wp-monitor-filter" class="adm-wp-filter"></div>
        <div id="wp-kalender-grid" class="adm-kal-grid"></div>
    <?php endif; ?>
</div>

<script>
window.TM_WP = {
    monitore:  <?= json_encode($monitoreFuerJs, JSON_UNESCAPED_UNICODE) ?>,
    eintraege: <?= json_encode($eintraegeFuerJs, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/js/admin/wochenplan.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/wochenplan.js') ?: time() ?>"></script>

<?php
admin_footer();
