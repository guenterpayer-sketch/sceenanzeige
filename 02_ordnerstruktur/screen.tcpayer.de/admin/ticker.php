<?php
/**
 * admin/ticker.php
 *
 * Übersicht aller Ticker als Kacheln (Schritt 8), analog zu playlists.php:
 *   - Kachel je Ticker mit Anzahl Texteinträge + auf wie vielen Monitoren
 *   - Aktiv-Toggle (pausieren ohne löschen), Bearbeiten, Löschen (Rückfrage)
 *   - „+ Neuer Ticker"
 *
 * Der Ticker ist ein eigenständiges Footer-System (kein Modul, keine Playlist).
 * WANN er auf WELCHEM Monitor läuft, legst du monitor-zentrisch unter
 * Monitore → „Zeitplan" (Abschnitt „Ticker-Zeitplan") fest.
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
        $t = TickerPlaylist::find($id);
        if ($t) { TickerPlaylist::setAktiv($id, !$t['aktiv']); }
        header('Location: ticker.php');
        exit;
    }
    if ($id > 0 && $aktion === 'loeschen') {
        TickerPlaylist::delete($id);
        header('Location: ticker.php?geloescht=1');
        exit;
    }
}
if (isset($_GET['geloescht']))   { $hinweis = 'Ticker gelöscht.'; }
if (isset($_GET['gespeichert'])) { $hinweis = 'Ticker gespeichert.'; }

$ticker = TickerPlaylist::listAll();

admin_header('Ticker', 'ticker');
?>

<?php if ($hinweis): ?>
    <div class="adm-flash"><?= htmlspecialchars($hinweis) ?></div>
<?php endif; ?>

<p class="adm-hilfe">
    Ticker laufen als Lauftext im Footer der Monitore — unabhängig von den
    Playlists. Ein Ticker ist eine benannte Sammlung von Textzeilen (mit
    Anzeigedauer). <strong>Wann</strong> ein Ticker auf <strong>welchem
    Monitor</strong> läuft, legst du unter <a href="monitore.php">Monitore</a>
    → „Zeitplan" fest. Sind mehrere Ticker gleichzeitig aktiv, werden ihre
    Texte <strong>gemischt</strong> nacheinander angezeigt (keine Priorität).
</p>

<div class="adm-neuzeile">
    <a class="adm-btn-primary" href="ticker-edit.php">+ Neuer Ticker</a>
</div>

<?php if (empty($ticker)): ?>
    <p class="adm-leer">Noch kein Ticker angelegt. Mit dem Button oben anlegen.</p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($ticker as $t): ?>
        <div class="adm-kachel <?= $t['aktiv'] ? '' : 'inaktiv' ?>">
            <div class="adm-kachel-vorschau info">
                <span class="adm-kachel-icon">📰</span>
                <span class="adm-kachel-info">
                    <?= (int)$t['anzahl_eintraege'] ?> Textzeile<?= (int)$t['anzahl_eintraege'] === 1 ? '' : 'n' ?>
                </span>
            </div>
            <div class="adm-kachel-badges">
                <span class="adm-meta-badge" title="auf so vielen Monitoren eingeplant">🖥️ auf <?= (int)$t['anzahl_monitore'] ?> Monitor<?= (int)$t['anzahl_monitore'] === 1 ? '' : 'en' ?></span>
            </div>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name">
                    <?= htmlspecialchars($t['name']) ?>
                    <?php if (!$t['aktiv']): ?><span class="adm-badge-pause">pausiert</span><?php endif; ?>
                </div>
                <div class="adm-kachel-aktionen">
                    <a class="adm-btn" href="ticker-edit.php?id=<?= (int)$t['id'] ?>">Bearbeiten</a>
                    <form method="post" class="adm-inline">
                        <input type="hidden" name="aktion" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-grau"><?= $t['aktiv'] ? 'Pausieren' : 'Aktivieren' ?></button>
                    </form>
                    <form method="post" class="adm-inline adm-del-form" data-name="<?= htmlspecialchars($t['name']) ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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
        if (!confirm('Ticker „' + (f.dataset.name || '') + '" wirklich löschen?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php
admin_footer();
