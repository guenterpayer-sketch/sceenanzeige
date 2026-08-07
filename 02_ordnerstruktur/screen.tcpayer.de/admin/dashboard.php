<?php
/**
 * admin/dashboard.php
 *
 * Übersichts-Startseite des Admin-Bereichs: eine Kachel je Monitor mit dem
 * Live-Status „was läuft JETZT" (Playlist(s), Ticker, nächster Wechsel heute)
 * plus Schnellzugriffe auf die häufigsten Arbeitsbereiche.
 *
 * Der Status kommt aus Monitor::aktuellePlaylistFuer()/aktiveTickerFuer() —
 * derselben Auswahl-Logik, die auch proxies/monitor.php (die echten
 * Saal-Bildschirme) benutzt. Das Dashboard kann daher nicht von der
 * Realität abweichen: eine Wahrheitsquelle, zwei Nutzer.
 *
 * Bewusst statisch — kein Polling, keine Auto-Aktualisierung. F5 genügt.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$monitore = Monitor::listAll();

// Status je Monitor über die zentrale Auswahl-Logik ermitteln
$status = [];
foreach ($monitore as $m) {
    $mid = (int)$m['id'];
    $status[$mid] = [
        'playlists' => Monitor::aktuellePlaylistFuer($mid),
        'ticker'    => Monitor::aktiveTickerFuer($mid),
        'wechsel'   => Monitor::naechsterWechselFuer($mid),
    ];
}

$schnellzugriffe = [
    ['📷', 'Bilder hochladen', 'mediathek.php'],
    ['🧩', 'Bibliothek',       'bibliothek.php'],
    ['🗂️', 'Playlists',        'playlists.php'],
    ['📰', 'Ticker',           'ticker.php'],
    ['🗓️', 'Kalender',         'wochenplan.php'],
];

admin_header('Übersicht', 'dashboard');
?>

<div class="adm-typgrid adm-dash-schnell">
    <?php foreach ($schnellzugriffe as [$icon, $label, $link]): ?>
        <a class="adm-typkachel" href="<?= htmlspecialchars($link) ?>">
            <span class="adm-typ-icon"><?= $icon ?></span>
            <span class="adm-typ-label"><?= htmlspecialchars($label) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<h2 class="adm-dash-abschnitt">Monitore</h2>
<details class="adm-hilfe-klapp">
    <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
    <p class="adm-hilfe">
        Was läuft gerade auf welchem Monitor? Die Anzeige nutzt dieselbe
        Zeitplan-Logik wie die Monitore selbst. Seite neu laden (F5) für den
        aktuellen Stand.
    </p>
</details>

<?php if (empty($monitore)): ?>
    <p class="adm-leer">Noch kein Monitor angelegt.</p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($monitore as $m):
        $st = $status[(int)$m['id']];
        $plNamen = array_map(static fn($p) => (string)$p['name'], $st['playlists']);
        $tkNamen = array_map(static fn($t) => (string)$t['name'], $st['ticker']);
    ?>
        <div class="adm-kachel">
            <div class="adm-kachel-vorschau info">
                <span class="adm-kachel-icon">🖥️</span>
                <span class="adm-kachel-info"><?= htmlspecialchars($m['subdomain']) ?></span>
            </div>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name"><?= htmlspecialchars($m['name']) ?></div>
                <div class="adm-dash-status">
                    <div class="adm-dash-zeile">
                        <span class="adm-dash-label">Jetzt:</span>
                        <?php if (!empty($plNamen)): ?>
                            <strong><?= htmlspecialchars(implode(' + ', $plNamen)) ?></strong>
                        <?php else: ?>
                            <span class="adm-dash-leer">— keine Playlist eingeplant</span>
                        <?php endif; ?>
                    </div>
                    <div class="adm-dash-zeile">
                        <span class="adm-dash-label">Ticker:</span>
                        <?php if (!empty($tkNamen)): ?>
                            <?= htmlspecialchars(implode(', ', $tkNamen)) ?>
                        <?php else: ?>
                            <span class="adm-dash-leer">—</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($st['wechsel'] !== null): ?>
                    <div class="adm-dash-zeile">
                        <span class="adm-dash-label">Nächster Wechsel:</span>
                        <?= htmlspecialchars($st['wechsel']) ?> Uhr
                    </div>
                    <?php endif; ?>
                </div>
                <div class="adm-kachel-aktionen">
                    <a class="adm-btn" href="monitor-zeitplan.php?id=<?= (int)$m['id'] ?>">Zeitplan</a>
                    <button type="button" class="adm-btn adm-vorschau-btn"
                            data-url="https://<?= htmlspecialchars($m['subdomain']) ?>"
                            data-name="<?= htmlspecialchars($m['name']) ?>">Vorschau</button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
admin_footer();
