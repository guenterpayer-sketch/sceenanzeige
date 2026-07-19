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
        'videos'       => ['Videos',      'videothek.php',    true],
        'playlists'    => ['Playlists',   'playlists.php',    true],
        'ticker'       => ['Ticker',      'ticker.php',       true],
        'vorschau'     => ['Vorschau',    'monitor-vorschau.php', true],
        'wochenplan'   => ['Wochenplan',  'wochenplan.php',   true],
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
                <span class="adm-nav-disabled"><?= htmlspecialchars($label) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($kommtNoch as $label): ?>
            <span class="adm-nav-disabled"><?= htmlspecialchars($label) ?></span>
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
        <?php
        $versionFile = __DIR__ . '/../../version.php';
        if (file_exists($versionFile)) {
            require_once $versionFile;
        }
        $versionStr  = defined('APP_VERSION')      ? APP_VERSION      : null;
        $versionDate = defined('APP_VERSION_DATE')  ? APP_VERSION_DATE : null;
        $versionEnv  = defined('APP_ENV')           ? APP_ENV          : null;
        if ($versionStr): ?>
        <span class="adm-nav-version<?= $versionEnv === 'staging' ? ' adm-nav-version--staging' : '' ?>">
            <?= htmlspecialchars($versionStr) ?> · <?= htmlspecialchars($versionDate) ?><?= $versionEnv === 'staging' ? ' · STAGING' : '' ?>
        </span>
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

<!-- ===== Globale Dialoge (ersetzt confirm / alert / prompt auf allen Seiten) ===== -->
<div id="adm-dlg-confirm" class="adm-overlay" hidden>
    <div class="adm-dialog">
        <p id="adm-dlg-confirm-text" style="margin-bottom:20px;font-size:15px;white-space:pre-line;"></p>
        <div class="adm-dialog-aktionen">
            <button type="button" id="adm-dlg-confirm-nein" class="adm-btn adm-btn-grau">Abbrechen</button>
            <button type="button" id="adm-dlg-confirm-ja" class="adm-btn" style="background:#c0392b;color:#fff;"></button>
        </div>
    </div>
</div>
<div id="adm-dlg-meldung" class="adm-overlay" hidden>
    <div class="adm-dialog">
        <p id="adm-dlg-meldung-text" style="margin-bottom:20px;font-size:15px;white-space:pre-line;"></p>
        <div class="adm-dialog-aktionen">
            <button type="button" id="adm-dlg-meldung-ok" class="adm-btn">OK</button>
        </div>
    </div>
</div>
<div id="adm-dlg-eingabe" class="adm-overlay" hidden>
    <div class="adm-dialog">
        <p id="adm-dlg-eingabe-text" style="margin-bottom:12px;font-size:15px;"></p>
        <input type="text" id="adm-dlg-eingabe-input" style="width:100%;margin-bottom:16px;padding:8px;border:1px solid #ccd3db;border-radius:4px;font-size:15px;">
        <div class="adm-dialog-aktionen">
            <button type="button" id="adm-dlg-eingabe-nein" class="adm-btn adm-btn-grau">Abbrechen</button>
            <button type="button" id="adm-dlg-eingabe-ok" class="adm-btn">OK</button>
        </div>
    </div>
</div>
<script>
(function () {
    // --- Bestätigung (ersetzt confirm) ---
    var cOv  = document.getElementById('adm-dlg-confirm');
    var cTxt = document.getElementById('adm-dlg-confirm-text');
    var cJa  = document.getElementById('adm-dlg-confirm-ja');
    var cNein= document.getElementById('adm-dlg-confirm-nein');
    window.admBestaetigen = function (text, callback, jaLabel) {
        cTxt.textContent  = text;
        cJa.textContent   = jaLabel || 'OK';
        cOv.hidden = false;
        function auf() {
            cOv.hidden = true;
            cJa.removeEventListener('click', ja);
            cNein.removeEventListener('click', nein);
            cOv.removeEventListener('click', ov);
        }
        function ja()   { auf(); callback(true); }
        function nein() { auf(); callback(false); }
        function ov(e)  { if (e.target === cOv) { auf(); callback(false); } }
        cJa.addEventListener('click', ja);
        cNein.addEventListener('click', nein);
        cOv.addEventListener('click', ov);
    };

    // --- Meldung (ersetzt alert) ---
    var mOv  = document.getElementById('adm-dlg-meldung');
    var mTxt = document.getElementById('adm-dlg-meldung-text');
    var mOk  = document.getElementById('adm-dlg-meldung-ok');
    window.admMeldung = function (text) {
        mTxt.textContent = text;
        mOv.hidden = false;
    };
    mOk.addEventListener('click', function () { mOv.hidden = true; });
    mOv.addEventListener('click', function (e) { if (e.target === mOv) { mOv.hidden = true; } });

    // --- Eingabe (ersetzt prompt) ---
    var eOv  = document.getElementById('adm-dlg-eingabe');
    var eTxt = document.getElementById('adm-dlg-eingabe-text');
    var eInp = document.getElementById('adm-dlg-eingabe-input');
    var eOk  = document.getElementById('adm-dlg-eingabe-ok');
    var eNein= document.getElementById('adm-dlg-eingabe-nein');
    window.admEingabe = function (text, standard, callback) {
        eTxt.textContent = text;
        eInp.value = standard || '';
        eOv.hidden = false;
        setTimeout(function () { eInp.focus(); eInp.select(); }, 50);
        function auf() {
            eOv.hidden = true;
            eOk.removeEventListener('click', ok);
            eNein.removeEventListener('click', nein);
            eOv.removeEventListener('click', ov);
            eInp.removeEventListener('keydown', kd);
        }
        function ok()   { var v = eInp.value.trim(); auf(); callback(v === '' ? null : v); }
        function nein() { auf(); callback(null); }
        function ov(e)  { if (e.target === eOv) { auf(); callback(null); } }
        function kd(e)  { if (e.key === 'Enter') { ok(); } else if (e.key === 'Escape') { nein(); } }
        eOk.addEventListener('click', ok);
        eNein.addEventListener('click', nein);
        eOv.addEventListener('click', ov);
        eInp.addEventListener('keydown', kd);
    };
})();
</script>

</body>
</html>
<?php
}
