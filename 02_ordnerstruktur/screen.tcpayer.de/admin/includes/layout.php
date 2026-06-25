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
</body>
</html>
<?php
}
