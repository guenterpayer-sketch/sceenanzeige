<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$monitore = Monitor::listAll();
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($monitore[0]) ? (int)$monitore[0]['id'] : 0);

$aktuell = null;
foreach ($monitore as $m) {
    if ((int)$m['id'] === $id) { $aktuell = $m; break; }
}

admin_header('Live-Vorschau', 'vorschau');
?>

<div class="adm-vorschau-bar">
    <?php if (count($monitore) > 1): ?>
    <form method="get" class="adm-inline">
        <label for="id">Monitor:</label>
        <select name="id" id="id" onchange="this.form.submit()" class="adm-vorschau-sel">
            <?php foreach ($monitore as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['subdomain']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php elseif ($aktuell): ?>
    <strong><?= htmlspecialchars($aktuell['name']) ?></strong>
    <span class="adm-eintrag-typ"><?= htmlspecialchars($aktuell['subdomain']) ?></span>
    <?php endif; ?>
    <?php if ($aktuell): ?>
    <a href="https://<?= htmlspecialchars($aktuell['subdomain']) ?>"
       target="_blank" rel="noopener" class="adm-btn">↗ Im neuen Tab öffnen</a>
    <a href="monitor-zeitplan.php?id=<?= (int)$aktuell['id'] ?>" class="adm-btn adm-btn-grau">Zeitplan</a>
    <?php endif; ?>
</div>

<?php if ($aktuell): ?>
<div class="adm-vorschau-leinwand" id="vorschau-rahmen">
    <iframe id="vorschau-iframe"
            src="https://<?= htmlspecialchars($aktuell['subdomain']) ?>"
            scrolling="no"
            title="Vorschau <?= htmlspecialchars($aktuell['name']) ?>">
    </iframe>
</div>
<p class="adm-hilfe" style="margin-top:10px">
    Die Vorschau zeigt den aktuellen Live-Stand des Monitors. Inhalte rotieren und
    der Ticker läuft — genauso wie auf dem echten TV.
</p>
<script>
(function () {
    var rahmen = document.getElementById('vorschau-rahmen');
    var iframe = document.getElementById('vorschau-iframe');
    function skaliere() {
        var scale = rahmen.clientWidth / 1920;
        iframe.style.width  = '1920px';
        iframe.style.height = '1080px';
        iframe.style.transform = 'scale(' + scale + ')';
        iframe.style.transformOrigin = 'top left';
        rahmen.style.height = Math.round(1080 * scale) + 'px';
    }
    skaliere();
    window.addEventListener('resize', skaliere);
})();
</script>
<?php elseif (empty($monitore)): ?>
<p class="adm-leer">Noch kein Monitor angelegt.
    <?php if (tm_ist_admin()): ?>
    <a href="monitore.php">Monitore verwalten →</a>
    <?php endif; ?>
</p>
<?php endif; ?>

<?php
admin_footer();
