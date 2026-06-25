<?php
/**
 * admin/includes/layout.php
 *
 * Gemeinsamer HTML-Rahmen (Kopf + Navigation + Fuß) für alle Admin-Seiten.
 * Verwendung:
 *   admin_header('Mediathek', 'mediathek');
 *   ... Seiteninhalt ...
 *   admin_footer();
 */

declare(strict_types=1);

/**
 * @param string $titel Seitentitel (für <title> und <h1>)
 * @param string $aktiv Schlüssel des aktiven Nav-Punkts (z.B. 'mediathek')
 */
function admin_header(string $titel, string $aktiv = ''): void
{
    // Nav-Punkte: key => [Label, Link, aktiv?]. Noch nicht gebaute Bereiche
    // sind als "kommt später" deaktiviert.
    $istAdmin = tm_ist_admin();
    $nav = [
        'bibliothek'   => ['Bibliothek',  'bibliothek.php',   true],
        'mediathek'    => ['Mediathek',   'mediathek.php',    true],
        'playlists'    => ['Playlists',   'playlists.php',    true],
        'ticker'       => ['Ticker',      'ticker.php',       true],
        'vorschau'     => ['Vorschau',    'monitor-vorschau.php', true],
        'monitore'     => ['Monitore',    'monitore.php',     $istAdmin],
        'fret-geraete' => ['FRET-Geräte', 'fret-geraete.php', $istAdmin],
    ];
    $kommtNoch = [];
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titel) ?> — Monitor-Backend</title>
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<header class="adm-topbar">
    <div class="adm-brand">Tanzschule&nbsp;·&nbsp;Monitor-Backend</div>
    <nav class="adm-nav">
        <?php foreach ($nav as $key => [$label, $link, $verfuegbar]): ?>
            <?php if ($verfuegbar): ?>
                <a href="<?= htmlspecialchars($link) ?>" class="<?= $key === $aktiv ? 'aktiv' : '' ?>"><?= htmlspecialchars($label) ?></a>
            <?php else: ?>
                <span class="adm-nav-disabled" title="kommt in einem späteren Schritt"><?= htmlspecialchars($label) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($kommtNoch as $label): ?>
            <span class="adm-nav-disabled" title="kommt in einem späteren Schritt"><?= htmlspecialchars($label) ?></span>
        <?php endforeach; ?>
        <?php
        $reloadOk = isset($_GET['reload_ok']);
        ?>
        <?php if ($istAdmin): ?>
        <form method="post" action="reload_trigger.php" style="margin:0">
            <button type="submit" class="adm-nav-reload<?= $reloadOk ? ' adm-nav-reload--ok' : '' ?>">
                ↺ Monitore neu laden<?= $reloadOk ? ' ✓' : '' ?>
            </button>
        </form>
        <?php endif; ?>
        <a href="/admin/logout.php" class="adm-nav-logout">Abmelden</a>
    </nav>
</header>
<main class="adm-main">
<h1><?= htmlspecialchars($titel) ?></h1>
<?php
}

function admin_footer(): void
{
    ?>
</main>

<!-- ===== Vorschau-Modal (global, für alle Seiten) ===== -->
<div id="adm-vm-overlay" class="adm-vm-overlay" hidden>
    <div class="adm-vm-box">
        <div class="adm-vm-kopf">
            <span id="adm-vm-titel" class="adm-vm-name"></span>
            <a id="adm-vm-newtab" href="#" target="_blank" rel="noopener" class="adm-btn adm-btn-grau">↗ Vollbild</a>
            <button id="adm-vm-schliessen" class="adm-vm-close" aria-label="Schließen">×</button>
        </div>
        <div class="adm-vm-rahmen" id="adm-vm-rahmen">
            <iframe id="adm-vm-iframe" scrolling="no" title="Monitor-Vorschau"></iframe>
        </div>
    </div>
</div>
<script>
(function () {
    var overlay  = document.getElementById('adm-vm-overlay');
    var rahmen   = document.getElementById('adm-vm-rahmen');
    var iframe   = document.getElementById('adm-vm-iframe');
    var titel    = document.getElementById('adm-vm-titel');
    var newtab   = document.getElementById('adm-vm-newtab');
    var schlBtn  = document.getElementById('adm-vm-schliessen');

    function skaliere() {
        if (overlay.hidden) { return; }
        var scale = rahmen.clientWidth / 1920;
        iframe.style.width  = '1920px';
        iframe.style.height = '1080px';
        iframe.style.transform = 'scale(' + scale + ')';
        iframe.style.transformOrigin = 'top left';
        rahmen.style.height = Math.round(1080 * scale) + 'px';
    }

    function oeffne(url, name) {
        titel.textContent = name;
        newtab.href = url;
        iframe.src  = url;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        skaliere();
    }

    function schliesse() {
        overlay.hidden = true;
        document.body.style.overflow = '';
        iframe.src = 'about:blank';
    }

    schlBtn.addEventListener('click', schliesse);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { schliesse(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) { schliesse(); }
    });
    window.addEventListener('resize', skaliere);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.adm-vorschau-btn');
        if (btn) {
            e.preventDefault();
            oeffne(btn.dataset.url, btn.dataset.name);
        }
    });
})();
</script>

</body>
</html>
<?php
}
