<?php
/**
 * admin/playlist-preview.php
 *
 * Standalone-Vorschau einer Playlist im 1920×1080-Monitor-Layout.
 * Wird im Vorschau-Modal (adm-vorschau-btn) als iFrame geladen.
 * Benötigt Admin-Session (via bootstrap.php).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="de"><body style="color:#fff;background:#111;padding:40px">Keine Playlist-ID angegeben.</body></html>';
    exit;
}

$pdo = get_pdo();

$stmt = $pdo->prepare('SELECT id, name FROM playlists WHERE id = :id');
$stmt->execute([':id' => $id]);
$playlist = $stmt->fetch();
if (!$playlist) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="de"><body style="color:#fff;background:#111;padding:40px">Playlist nicht gefunden.</body></html>';
    exit;
}

$stmtLayout = $pdo->prepare('SELECT * FROM playlist_layout WHERE playlist_id = :id');
$stmtLayout->execute([':id' => $id]);
$layout = $stmtLayout->fetch();

$stmtSpalten = $pdo->prepare(
    'SELECT s.spalte, s.reihenfolge, m.id AS instanz_id, m.modul_typ, m.einstellungen
     FROM playlist_spalten_inhalte s
     JOIN modul_instanzen m ON m.id = s.modul_instanz_id AND m.aktiv = 1
     WHERE s.playlist_id = :id
     ORDER BY s.spalte, s.reihenfolge, s.id'
);
$stmtSpalten->execute([':id' => $id]);
$spaltenZeilen = $stmtSpalten->fetchAll();

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

// Ticker laden wenn footer_ticker aktiv — kein Zeitplan-Filter (kein Monitor-Kontext)
$tickerEintraege = [];
if (!empty($layout['footer_ticker'])) {
    $stmtTicker = $pdo->query(
        'SELECT te.text, te.dauer_sek
         FROM ticker_eintraege te
         JOIN ticker_playlists tp ON tp.id = te.ticker_playlist_id
         WHERE tp.aktiv = 1
         ORDER BY tp.id, te.reihenfolge, te.id'
    );
    $tickerEintraege = $stmtTicker->fetchAll();
}

$previewData = [
    'spalten_anzahl'  => (int)($layout['spalten_anzahl'] ?? 1),
    'spalte1_breite'  => isset($layout['spalte1_breite']) ? (int)$layout['spalte1_breite'] : null,
    'spalte2_breite'  => isset($layout['spalte2_breite']) ? (int)$layout['spalte2_breite'] : null,
    'spalte3_breite'  => isset($layout['spalte3_breite']) ? (int)$layout['spalte3_breite'] : null,
    'header_sichtbar' => (bool)($layout['header_sichtbar'] ?? false),
    'footer_ticker'   => !empty($layout['footer_ticker']) && count($tickerEintraege) > 0,
    'ticker'          => $tickerEintraege,
    'spalten'         => $spalten,
];

$playlistName = htmlspecialchars($playlist['name'], ENT_QUOTES, 'UTF-8');
$previewJson  = json_encode($previewData, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Vorschau: <?= $playlistName ?></title>
<link rel="stylesheet" href="/assets/css/monitor.css">
</head>
<body>

<div id="tm-wrapper">
    <header id="tm-header"<?= $previewData['header_sichtbar'] ? '' : ' class="tm-hidden"' ?>>
        <div id="tm-header-logo"></div>
        <div id="tm-header-text">Vorschau: <?= $playlistName ?></div>
        <div id="tm-header-uhrzeit">
            <div id="tm-header-zeit"></div>
            <div id="tm-header-datum"></div>
        </div>
    </header>
    <main id="tm-main"></main>
    <footer id="tm-footer"<?= $previewData['footer_ticker'] ? '' : ' class="tm-hidden"' ?>>
        <div class="tm-ticker-text"></div>
    </footer>
</div>

<script>
window.BACKEND_BASE = '';
window.UPLOADS_URL  = '/uploads';
var PREVIEW = <?= $previewJson ?>;
</script>
<script src="/assets/js/module-loader.js"></script>
<script>
(function () {
    var WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    function pad(n) { return String(n).padStart(2, '0'); }

    // Header-Uhrzeit
    var zeitEl  = document.getElementById('tm-header-zeit');
    var datumEl = document.getElementById('tm-header-datum');
    if (zeitEl && datumEl) {
        function tick() {
            var now = new Date();
            zeitEl.textContent  = pad(now.getHours()) + ':' + pad(now.getMinutes());
            datumEl.textContent = WOCHENTAGE[now.getDay()]
                + ' ' + pad(now.getDate()) + '.' + pad(now.getMonth() + 1) + '.' + now.getFullYear();
        }
        tick();
        setInterval(tick, 1000);
    }

    // Ticker (aus monitor.js übernommen, ohne Poll-Logik)
    var _tickerTimeout = null;
    var footerEl = document.getElementById('tm-footer');
    var ticker   = PREVIEW.ticker || [];

    function startTicker(eintraege) {
        if (_tickerTimeout) { clearTimeout(_tickerTimeout); _tickerTimeout = null; }
        var textEl = footerEl ? footerEl.querySelector('.tm-ticker-text') : null;
        if (!textEl || !eintraege || eintraege.length === 0) { return; }

        var einziger = eintraege.length === 1;
        var index    = 0;

        function zeigeNaechsten() {
            var eintrag  = eintraege[index];
            var dauerMs  = ((eintrag.dauer_sek > 0) ? eintrag.dauer_sek : 8) * 1000;
            index = (index + 1) % eintraege.length;

            textEl.style.transition  = 'none';
            textEl.style.transform   = 'none';
            textEl.style.opacity     = '0';
            textEl.textContent       = eintrag.text;
            footerEl.classList.remove('tm-ticker-zentriert');

            var containerWidth = footerEl.clientWidth - 56;
            var textWidth      = textEl.scrollWidth;

            if (textWidth > containerWidth * 0.95) {
                var scrollPx   = textWidth + containerWidth;
                var durationMs = Math.max(6000, (scrollPx / 90) * 1000);
                textEl.style.transform = 'translateX(' + containerWidth + 'px)';
                textEl.style.opacity   = '1';
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        textEl.style.transition = 'transform ' + (durationMs / 1000).toFixed(2) + 's linear';
                        textEl.style.transform  = 'translateX(-' + textWidth + 'px)';
                    });
                });
                _tickerTimeout = setTimeout(function () {
                    textEl.style.transition = 'none';
                    textEl.style.transform  = 'none';
                    zeigeNaechsten();
                }, durationMs + 300);
            } else {
                footerEl.classList.add('tm-ticker-zentriert');
                if (einziger) {
                    textEl.style.opacity = '1';
                } else {
                    requestAnimationFrame(function () {
                        textEl.style.transition = 'opacity 600ms ease';
                        textEl.style.opacity    = '1';
                    });
                    _tickerTimeout = setTimeout(function () {
                        textEl.style.opacity = '0';
                        _tickerTimeout = setTimeout(zeigeNaechsten, 700);
                    }, dauerMs);
                }
            }
        }
        zeigeNaechsten();
    }

    if (PREVIEW.footer_ticker && ticker.length > 0) {
        startTicker(ticker);
    }

    // Layout aufbauen
    var mainEl = document.getElementById('tm-main');
    var pl     = PREVIEW;
    var anzahl = pl.spalten_anzahl || 1;
    var b1     = pl.spalte1_breite || 100;
    var b2     = pl.spalte2_breite || 0;
    var b3     = pl.spalte3_breite || 0;

    var cols = b1 + '%';
    if (anzahl >= 2) { cols += ' ' + b2 + '%'; }
    if (anzahl >= 3) { cols += ' ' + b3 + '%'; }

    var layoutEl = document.createElement('div');
    layoutEl.className = 'tm-layout';
    layoutEl.style.gridTemplateColumns = cols;

    for (var s = 1; s <= anzahl; s++) {
        var spalteEl = document.createElement('div');
        spalteEl.className = 'tm-spalte';
        spalteEl.dataset.spalte = String(s);
        layoutEl.appendChild(spalteEl);
    }
    mainEl.appendChild(layoutEl);

    // Module je Spalte rendern (mit Rotation bei mehreren Instanzen)
    var spalten = pl.spalten || {};
    for (var col = 1; col <= anzahl; col++) {
        var spalteNode = layoutEl.querySelector('[data-spalte="' + col + '"]');
        if (!spalteNode) { continue; }
        var mods = spalten[col] || spalten[String(col)] || [];
        if (mods.length === 0) { continue; }

        (function (node, mods) {
            var index = 0;
            function zeigeNaechstes() {
                var mod = mods[index];
                index = (index + 1) % mods.length;
                var existing = node.querySelector('.tm-modul-container');
                if (existing) {
                    ['_tmTimeout','_tmInterval','_tmPoll','_tmTick'].forEach(function (k) {
                        if (existing[k]) { clearTimeout(existing[k]); existing[k] = null; }
                    });
                }
                node.innerHTML = '';
                var container = document.createElement('div');
                container.className = 'tm-modul-container';
                node.appendChild(container);
                window.TanzschuleLoader.render(mod.modul_typ, container, mod.einstellungen || {}, mod.inhalte || []);

                if (mods.length > 1) {
                    var dauerSek = 30;
                    if (mod.inhalte && mod.inhalte.length > 0) {
                        var sum = 0;
                        mod.inhalte.forEach(function (i) { sum += (i.dauer_sek > 0 ? i.dauer_sek : 10); });
                        dauerSek = sum;
                    } else if (mod.einstellungen && mod.einstellungen.anzeige_dauer_sek > 0) {
                        dauerSek = mod.einstellungen.anzeige_dauer_sek;
                    }
                    setTimeout(zeigeNaechstes, dauerSek * 1000);
                }
            }
            zeigeNaechstes();
        })(spalteNode, mods);
    }
})();
</script>

</body>
</html>
