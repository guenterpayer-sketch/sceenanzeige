<?php
/**
 * admin/bibliothek.php
 *
 * Bibliotheks-Übersicht (Schritt 5b): listet alle Modul-Instanzen gruppiert
 * nach Modultyp, mit Aktiv-Schalter, Bearbeiten und Löschen. "Neue Instanz"
 * führt zum Editor (instanz.php).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$hinweis = null;

// --- Aktionen (Toggle aktiv / Löschen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $aktion === 'toggle') {
        $inst = ModulInstanz::find($id);
        if ($inst) {
            ModulInstanz::setAktiv($id, !$inst['aktiv']);
        }
        header('Location: bibliothek.php');
        exit;
    }
    if ($id > 0 && $aktion === 'loeschen') {
        ModulInstanz::delete($id);
        header('Location: bibliothek.php?geloescht=1');
        exit;
    }
}
if (isset($_GET['geloescht']))   { $hinweis = 'Instanz gelöscht.'; }
if (isset($_GET['gespeichert'])) { $hinweis = 'Instanz gespeichert.'; }

$module    = ModuleRegistry::getAll();
$instanzen = ModulInstanz::listAll();

// Instanzen nach Modultyp gruppieren
$gruppen = [];
foreach ($instanzen as $inst) {
    $gruppen[$inst['modul_typ']][] = $inst;
}

admin_header('Bibliothek', 'bibliothek');
?>

<?php if ($hinweis): ?>
    <div class="adm-flash"><?= htmlspecialchars($hinweis) ?></div>
<?php endif; ?>

<p class="adm-hilfe">
    Modul-Instanzen sind die wiederverwendbaren Bausteine (z.&nbsp;B. eine Bild-Instanz
    „Veranstaltungen" oder eine Ankündigung „Sommerfest"), die später in Playlists
    eingesetzt werden. Hier legst du sie an und pflegst ihre Inhalte/Einstellungen.
</p>

<!-- Neue Instanz ------------------------------------------------------------->
<div class="adm-neuzeile">
    <form method="get" action="instanz.php" class="adm-neu-form">
        <label>Neue Instanz:
            <select name="typ">
                <?php foreach ($module as $id => $meta): ?>
                    <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Anlegen</button>
    </form>
</div>

<!-- Liste -------------------------------------------------------------------->
<?php if (empty($instanzen)): ?>
    <p class="adm-leer">Noch keine Modul-Instanzen angelegt.</p>
<?php else: ?>
    <?php foreach ($module as $typ => $meta): ?>
        <?php if (empty($gruppen[$typ])) { continue; } ?>
        <h2><?= htmlspecialchars($meta['label']) ?></h2>
        <div class="adm-instanzliste">
            <?php foreach ($gruppen[$typ] as $inst): ?>
                <div class="adm-instanz <?= $inst['aktiv'] ? '' : 'inaktiv' ?>">
                    <div class="adm-instanz-info">
                        <span class="adm-instanz-name"><?= htmlspecialchars($inst['name']) ?></span>
                        <?php if (!$inst['aktiv']): ?><span class="adm-badge-pause">pausiert</span><?php endif; ?>
                    </div>
                    <div class="adm-instanz-aktionen">
                        <a class="adm-btn" href="instanz.php?id=<?= (int)$inst['id'] ?>">Bearbeiten</a>
                        <form method="post" class="adm-inline">
                            <input type="hidden" name="aktion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$inst['id'] ?>">
                            <button type="submit" class="adm-btn adm-btn-grau"><?= $inst['aktiv'] ? 'Pausieren' : 'Aktivieren' ?></button>
                        </form>
                        <form method="post" class="adm-inline" onsubmit="return confirm('Instanz „<?= htmlspecialchars(addslashes($inst['name'])) ?>" wirklich löschen?');">
                            <input type="hidden" name="aktion" value="loeschen">
                            <input type="hidden" name="id" value="<?= (int)$inst['id'] ?>">
                            <button type="submit" class="adm-btn adm-btn-rot">Löschen</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
admin_footer();
