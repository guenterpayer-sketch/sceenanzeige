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
if (isset($_GET['geloescht']))   { $hinweis = 'Playlist gelöscht.'; }
if (isset($_GET['gespeichert'])) { $hinweis = 'Playlist gespeichert.'; }

$playlists = Playlist::listAll();

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

<?php if ($hinweis): ?>
    <div class="adm-flash"><?= htmlspecialchars($hinweis) ?></div>
<?php endif; ?>

<p class="adm-hilfe">
    Playlists füllen die Monitor-Hauptfläche: ein Layout (1–3 Spalten) mit
    Modul-Instanzen je Spalte. Zeitregeln und Saal-Zuweisung folgen in einem
    späteren Schritt.
</p>

<div class="adm-neuzeile">
    <a class="adm-btn-primary" href="playlist.php">+ Neue Playlist</a>
</div>

<?php if (empty($playlists)): ?>
    <p class="adm-leer">Noch keine Playlist angelegt. Mit dem Button oben anlegen.</p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($playlists as $p): ?>
        <div class="adm-kachel <?= $p['aktiv'] ? '' : 'inaktiv' ?>">
            <div class="adm-kachel-vorschau info">
                <span class="adm-kachel-icon">🗂️</span>
                <span class="adm-kachel-info">
                    <?= htmlspecialchars(pl_layout_text($p)) ?><br>
                    <?= (int)$p['anzahl_module'] ?> Modul-Instanz<?= (int)$p['anzahl_module'] === 1 ? '' : 'en' ?>
                </span>
            </div>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name">
                    <?= htmlspecialchars($p['name']) ?>
                    <?php if (!$p['aktiv']): ?><span class="adm-badge-pause">pausiert</span><?php endif; ?>
                </div>
                <div class="adm-kachel-aktionen">
                    <a class="adm-btn" href="playlist.php?id=<?= (int)$p['id'] ?>">Bearbeiten</a>
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
        if (!confirm('Playlist „' + (f.dataset.name || '') + '" wirklich löschen?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php
admin_footer();
