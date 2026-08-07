<?php
/**
 * admin/playlists.php
 *
 * Übersicht aller Playlists als Kacheln (Schritt 6), analog zur Bibliothek:
 *   - Kachel je Playlist mit Layout-Kurzinfo + Modul-Anzahl
 *   - Aktiv-Toggle (pausieren ohne löschen), Bearbeiten, Löschen (mit Rückfrage)
 *   - „+ Neue Playlist"
 *
 * Zeitregeln + Saal-Zuweisung erscheinen hier (noch) nicht — das ist Schritt 7.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$hinweis = null;

// --- Aktionen (Toggle aktiv / Löschen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $aktion === 'toggle') {
        $pl = Playlist::find($id);
        if ($pl) { Playlist::setAktiv($id, !$pl['aktiv']); }
        header('Location: playlists.php');
        exit;
    }
    if ($id > 0 && $aktion === 'loeschen') {
        Playlist::delete($id);
        header('Location: playlists.php?geloescht=1');
        exit;
    }
}
$hinweisAktion = null; // [href, label] — geführter nächster Schritt im Flash
if (isset($_GET['geloescht']))   { $hinweis = 'Playlist gelöscht.'; }
if (isset($_GET['gespeichert'])) {
    $hinweis       = 'Playlist gespeichert. Eingeplante Monitore übernehmen Änderungen '
                   . 'innerhalb von ca. 1 Minute — oder sofort über „↺ Monitore neu laden" oben.';
    // Kommt die gespeicherte Playlist-ID mit, hebt der Aktions-Link drüben
    // gleich die Monitore hervor, auf denen sie schon eingeplant ist.
    $gespId        = (int)($_GET['id'] ?? 0);
    $hinweisAktion = [
        $gespId > 0 ? 'monitore.php?hl_playlist=' . $gespId : 'monitore.php',
        '→ Jetzt auf einem Monitor einplanen',
    ];
}

$playlists = Playlist::listAll();

// --- Badge-Highlight: „in N Playlists"-Badge (Bibliothek) markiert hier die
// Playlists, die die Modul-Instanz enthalten. Die Bearbeiten-Links schleifen
// den Parameter durch den Playlist-Editor zurück.
$hlIds    = [];
$hlQuery  = '';
$hlLeiste = null;
$hlIn = (int)($_GET['hl_instanz'] ?? 0);
if ($hlIn > 0 && ($hlObj = ModulInstanz::find($hlIn))) {
    $hlIds    = Playlist::idsMitInstanz($hlIn);
    $hlQuery  = '&hl_instanz=' . $hlIn;
    $hlLeiste = 'Hervorgehoben: Playlists mit Modul-Instanz „<strong>'
              . htmlspecialchars($hlObj['name']) . '</strong>"';
    if (empty($hlIds)) { $hlLeiste .= ' — derzeit in keiner Playlist enthalten'; }
}

/** Kurzbeschreibung des Layouts aus den gespeicherten Werten. */
function pl_layout_text(array $p): string
{
    $n = (int)($p['spalten_anzahl'] ?? 1);
    if ($n <= 1) {
        return '1 Spalte';
    }
    $breiten = array_values(array_filter([
        $p['spalte1_breite'] ?? null,
        $p['spalte2_breite'] ?? null,
        $p['spalte3_breite'] ?? null,
    ], static fn($v) => $v !== null));
    return $n . ' Spalten · ' . implode(' / ', array_map('intval', $breiten)) . ' %';
}

admin_header('Playlists', 'playlists');
?>

<?php if ($hlLeiste !== null) { admin_hl_leiste($hlLeiste, 'playlists.php'); } ?>

<?php if ($hinweis): ?>
    <div class="adm-flash<?= $hinweisAktion ? ' adm-flash--mit-aktion' : '' ?>">
        <span><?= htmlspecialchars($hinweis) ?></span>
        <?php if ($hinweisAktion): ?>
            <a class="adm-btn adm-flash-btn" href="<?= htmlspecialchars($hinweisAktion[0]) ?>"><?= htmlspecialchars($hinweisAktion[1]) ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<details class="adm-hilfe-klapp">
    <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
    <p class="adm-hilfe">
        Hier legst du Playlists an. Jede Playlist hat ein Layout (1–3 Spalten) mit
        Modul-Instanzen je Spalte. Wann welche Playlist läuft, steuerst du unter
        <a href="monitore.php">Monitore → Zeitplan</a>.
    </p>
</details>

<div class="adm-neuzeile">
    <a class="adm-btn-primary" href="playlist-editor.php">+ Neue Playlist</a>
</div>

<?php if (empty($playlists)): ?>
    <p class="adm-leer">Noch keine Playlist angelegt. Mit dem Button oben anlegen.</p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($playlists as $p):
        $istHl = in_array((int)$p['id'], $hlIds, true);
    ?>
        <div class="adm-kachel <?= $p['aktiv'] ? '' : 'inaktiv' ?><?= $istHl ? ' adm-kachel--highlight' : '' ?>">
            <div class="adm-kachel-vorschau info">
                <span class="adm-kachel-icon">🗂️</span>
                <span class="adm-kachel-info">
                    <?= htmlspecialchars(pl_layout_text($p)) ?><br>
                    <?= (int)$p['anzahl_module'] ?> Modul-Instanz<?= (int)$p['anzahl_module'] === 1 ? '' : 'en' ?>
                </span>
            </div>
            <div class="adm-kachel-badges">
                <a class="adm-meta-badge adm-monitore-badge<?= (int)$p['anzahl_monitore'] > 0 ? ' adm-monitore-badge--aktiv' : '' ?>"
                   href="monitore.php<?= (int)$p['anzahl_monitore'] > 0 ? '?hl_playlist=' . (int)$p['id'] : '' ?>"
                   data-monitore="<?= htmlspecialchars($p['monitor_namen'] ?? '') ?>">🖥️ auf <?= (int)$p['anzahl_monitore'] ?> Monitor<?= (int)$p['anzahl_monitore'] === 1 ? '' : 'en' ?><?= (int)$p['anzahl_monitore'] === 0 ? ' — einplanen' : '' ?></a>
            </div>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name">
                    <?= htmlspecialchars($p['name']) ?>
                    <?php if (!$p['aktiv']): ?><span class="adm-badge-pause">pausiert</span><?php endif; ?>
                </div>
                <div class="adm-kachel-aktionen">
                    <a class="adm-btn" href="playlist-editor.php?id=<?= (int)$p['id'] . htmlspecialchars($hlQuery) ?>">Bearbeiten</a>
                    <button type="button" class="adm-btn adm-vorschau-btn"
                            data-url="playlist-preview.php?id=<?= (int)$p['id'] ?>"
                            data-name="<?= htmlspecialchars($p['name']) ?>">Vorschau</button>
                    <form method="post" class="adm-inline">
                        <input type="hidden" name="aktion" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-grau"><?= $p['aktiv'] ? 'Pausieren' : 'Aktivieren' ?></button>
                    </form>
                    <form method="post" class="adm-inline adm-del-form" data-name="<?= htmlspecialchars($p['name']) ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-rot">Löschen</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.adm-del-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        admBestaetigen('Playlist „' + (f.dataset.name || '') + '" wirklich löschen?', function (ok) {
            if (ok) { f.submit(); }
        }, 'Löschen');
    });
});
</script>

<?php
admin_footer();
