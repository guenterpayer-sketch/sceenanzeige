<?php
/**
 * admin/playlist-editor.php
 *
 * Editor zum Anlegen/Bearbeiten einer Playlist (Schritt 6, Playlist-Editor).
 *   - Aufruf neu:        playlist-editor.php
 *   - Aufruf bearbeiten: playlist-editor.php?id=<playlist_id>
 *
 * (Übersicht/Verwaltung der Playlists liegt in admin/playlists.php.)
 *
 * Umfang (CLAUDE.md Abschnitt 6): Name, Aktiv, Layout-Auswahl (Spaltenanzahl),
 * Spaltenbreiten (2-spaltig: gekoppelter Regler; 3-spaltig: fest gleich),
 * Header(Uhrzeit)/Footer(Ticker)-Schalter, pro Spalte Modul-Instanzen aus der
 * Bibliothek zuweisen (Picker + ↑/↓-Reihenfolge), schematische Vorschau.
 *
 * NICHT hier: Zeitplanung + Monitor-Zuordnung (monitor-zentrisch, jetzt unter
 * Monitore → „Zeitplan"), Monitor-Rendering (Schritt 9), Live-Vorschau-iFrame
 * (Schritt 10). layout_override pro Instanz ist bewusst auf Schritt 9 vertagt
 * (Spalte bleibt in der DB ungenutzt).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$fehler = [];

// --- Kontext: neu oder bearbeiten ---
$playlist = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $playlist = Playlist::find($id);
    if (!$playlist) {
        http_response_code(404);
        admin_header('Playlist', 'playlists');
        echo '<p class="adm-flash adm-flash-fehler">Playlist nicht gefunden.</p>';
        admin_footer();
        exit;
    }
}
$istNeu = ($playlist === null);

$layouts = LayoutRegistry::getAll();

// --- Vorbelegung ---
$werteName   = $playlist['name'] ?? '';
$werteAktiv  = $istNeu ? true : (bool)$playlist['aktiv'];
$layoutRow   = $istNeu ? null : Playlist::ladeLayout($id);
$werteLayout = Playlist::layoutIdAus($layoutRow) ?? '1-spaltig';
$werteB1     = (int)($layoutRow['spalte1_breite'] ?? $layouts[$werteLayout]['default_breiten'][0] ?? 100);
$werteHeader = $istNeu ? true : (bool)($layoutRow['header_sichtbar'] ?? 1);
$werteFooter = $istNeu ? true : (bool)($layoutRow['footer_ticker'] ?? 1);

// Bereits zugewiesene Spalten-Inhalte (für das JS)
$inhalteFuerJs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['inhalt'] ?? []) as $row) {
        $mid = (int)($row['modul_instanz_id'] ?? 0);
        if ($mid <= 0) { continue; }
        $inst = ModulInstanz::find($mid);
        if (!$inst) { continue; }
        $meta = ModuleRegistry::exists($inst['modul_typ']) ? ModuleRegistry::load($inst['modul_typ']) : [];
        $inhalteFuerJs[] = [
            'spalte'           => max(1, min(3, (int)($row['spalte'] ?? 1))),
            'modul_instanz_id' => $mid,
            'name'             => $inst['name'],
            'modul_typ'        => $inst['modul_typ'],
            'typ_label'        => $meta['label'] ?? $inst['modul_typ'],
            'icon'             => $meta['icon'] ?? '',
            'aktiv'            => (bool)$inst['aktiv'],
        ];
    }
} elseif (!$istNeu) {
    foreach (Playlist::listSpaltenInhalte($id) as $row) {
        $meta = ModuleRegistry::exists($row['modul_typ']) ? ModuleRegistry::load($row['modul_typ']) : [];
        $inhalteFuerJs[] = [
            'spalte'           => (int)$row['spalte'],
            'modul_instanz_id' => (int)$row['modul_instanz_id'],
            'name'             => $row['instanz_name'],
            'modul_typ'        => $row['modul_typ'],
            'typ_label'        => $meta['label'] ?? $row['modul_typ'],
            'icon'             => $meta['icon'] ?? '',
            'aktiv'            => (bool)$row['instanz_aktiv'],
        ];
    }
}

// --- Speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $werteName   = trim((string)($_POST['name'] ?? ''));
    $werteAktiv  = !empty($_POST['aktiv']);
    $werteHeader = !empty($_POST['header_sichtbar']);
    $werteFooter = !empty($_POST['footer_ticker']);

    $werteLayout = (string)($_POST['layout_id'] ?? '1-spaltig');
    if (!LayoutRegistry::exists($werteLayout)) { $werteLayout = '1-spaltig'; }
    $lmeta   = LayoutRegistry::load($werteLayout);
    $spalten = (int)$lmeta['spalten'];

    if ($spalten === 1) {
        $breiten = [100];
        $werteB1 = 100;
    } elseif ($spalten === 2) {
        $werteB1 = (int)($_POST['spalte1_breite'] ?? $lmeta['default_breiten'][0]);
        $werteB1 = max(10, min(90, $werteB1));
        $breiten = [$werteB1, 100 - $werteB1];
    } else { // 3 Spalten: immer gleichmäßig
        $breiten = LayoutRegistry::gleichBreiten(3);
        $werteB1 = $breiten[0];
    }

    if ($werteName === '') {
        $fehler[] = 'Bitte einen Namen für die Playlist angeben.';
    } elseif (Playlist::nameExistiert($werteName, $istNeu ? null : $id)) {
        $fehler[] = 'Es gibt bereits eine Playlist mit diesem Namen. Bitte einen anderen Namen wählen.';
    }

    if (empty($fehler)) {
        if ($istNeu) {
            $id = Playlist::create($werteName);
        } else {
            Playlist::update($id, $werteName);
        }
        Playlist::setAktiv($id, $werteAktiv);
        Playlist::speichereLayout($id, $spalten, $breiten, $werteHeader, $werteFooter);

        // Nur Inhalte in aktiven Spalten (1..$spalten) übernehmen
        $inhalte = [];
        foreach (($_POST['inhalt'] ?? []) as $row) {
            $mid = (int)($row['modul_instanz_id'] ?? 0);
            $sp  = (int)($row['spalte'] ?? 1);
            if ($mid > 0 && $sp >= 1 && $sp <= $spalten) {
                $inhalte[] = ['spalte' => $sp, 'modul_instanz_id' => $mid];
            }
        }
        Playlist::ersetzeSpaltenInhalte($id, $inhalte);

        if (!empty($_POST['bleiben'])) {
            header('Location: playlist-editor.php?id=' . $id . '&gespeichert=1');
        } else {
            header('Location: playlists.php?gespeichert=1');
        }
        exit;
    }
}

admin_header(($istNeu ? 'Neue Playlist' : 'Playlist bearbeiten'), 'playlists');

/** Emoji-Icon je module.json-icon (gleich wie in bibliothek.php). */
function pl_modul_icon(string $icon): string
{
    return [
        'clock' => '🕒', 'image' => '🖼️', 'calendar' => '📅',
        'megaphone' => '📢', 'music' => '🎵',
    ][$icon] ?? '🧩';
}
?>

<p><a href="playlists.php" class="adm-zurueck">← zurück zu den Playlists</a></p>

<?php if (isset($_GET['gespeichert'])): ?>
    <div class="adm-flash">Playlist gespeichert.</div>
<?php endif; ?>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>

<form method="post" id="playlist-form">
    <input type="hidden" name="aktion" value="speichern">

    <div class="adm-card">
        <div class="field">
            <label for="name">Name der Playlist</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($werteName) ?>" required>
        </div>
        <div class="field field-bool">
            <label for="aktiv">
                <input type="checkbox" id="aktiv" name="aktiv" value="1" <?= $werteAktiv ? 'checked' : '' ?>>
                Aktiv (deaktiviert = pausiert, ohne zu löschen)
            </label>
        </div>
    </div>

    <div class="adm-card">
        <h2>Layout</h2>
        <div class="adm-layoutwahl">
            <?php foreach ($layouts as $lid => $lm): ?>
                <label class="adm-layoutopt">
                    <input type="radio" name="layout_id" value="<?= htmlspecialchars($lid) ?>"
                           data-spalten="<?= (int)$lm['spalten'] ?>"
                           data-default-b1="<?= (int)($lm['default_breiten'][0] ?? 100) ?>"
                           data-frei="<?= !empty($lm['breiten_frei']) ? '1' : '0' ?>"
                           <?= $lid === $werteLayout ? 'checked' : '' ?>>
                    <span class="adm-layout-mini" data-spalten="<?= (int)$lm['spalten'] ?>">
                        <?php for ($i = 0; $i < (int)$lm['spalten']; $i++): ?>
                            <span style="flex:<?= (int)($lm['default_breiten'][$i] ?? 1) ?>"></span>
                        <?php endfor; ?>
                    </span>
                    <span class="adm-layout-label"><?= htmlspecialchars($lm['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="field" id="breiten-block">
            <label for="spalte1_breite">Spaltenbreite</label>
            <div class="adm-breitenregler">
                <input type="range" id="spalte1_breite" name="spalte1_breite"
                       min="10" max="90" step="5" value="<?= (int)$werteB1 ?>">
                <span class="adm-breiten-anzeige" id="breiten-anzeige"></span>
            </div>
            <p class="adm-hilfe" id="breiten-hinweis"></p>
        </div>

        <div class="field field-bool">
            <label for="header_sichtbar">
                <input type="checkbox" id="header_sichtbar" name="header_sichtbar" value="1" <?= $werteHeader ? 'checked' : '' ?>>
                Header anzeigen
            </label>
        </div>
        <div class="field field-bool">
            <label for="footer_ticker">
                <input type="checkbox" id="footer_ticker" name="footer_ticker" value="1" <?= $werteFooter ? 'checked' : '' ?>>
                Footer-Ticker aktiv (Ticker-Inhalte siehe Bereich „Ticker")
            </label>
        </div>

        <h2>Vorschau (schematisch)</h2>
        <div style="display:flex;gap:24px;align-items:flex-start">
            <div class="adm-vorschau" id="vorschau" style="flex:0 0 480px;width:480px;">
                <div class="adm-vorschau-header" id="vorschau-header">Uhrzeit / Datum</div>
                <div class="adm-vorschau-spalten" id="vorschau-spalten"></div>
                <div class="adm-vorschau-footer" id="vorschau-footer">Ticker</div>
            </div>
            <div id="px-info" style="font-size:13px;color:#666;line-height:1.9;flex-shrink:0;padding-top:4px"></div>
        </div>
    </div>

    <div class="adm-card">
        <h2>Spalten-Inhalte</h2>
        <p class="adm-hilfe">
            Pro Spalte eine oder mehrere Modul-Instanzen aus der Bibliothek. Reihenfolge per ↑/↓ oder per Drag&amp;Drop. Mehrere Instanzen in einer Spalte rotieren automatisch.
        </p>
        <div class="adm-spalten" id="spalten"></div>
    </div>

    <p class="adm-hilfe">
        Wann diese Playlist auf welchem Monitor läuft, steuerst du unter
        <a href="monitore.php">Monitore → Zeitplan</a>.
    </p>

    <div class="adm-aktionsleiste">
        <button type="submit" name="bleiben" value="1" class="adm-btn-primary">Speichern</button>
        <button type="submit" class="adm-btn-primary">Speichern &amp; schließen</button>
        <a href="playlists.php" class="adm-btn adm-btn-grau">Abbrechen</a>
    </div>
</form>

<!-- Instanz-Picker-Dialog -->
<div id="picker-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3>Modul-Instanz wählen</h3>
        <div class="adm-picker-filter">
            <select id="picker-typ"><option value="">Alle Modularten</option></select>
        </div>
        <div id="picker-liste" class="adm-picker-instanzen"></div>
        <div class="adm-dialog-aktionen adm-dialog-aktionen--mit-hinweis">
            <a class="adm-btn adm-btn-grau" href="bibliothek.php" target="_blank" rel="noopener">+ Neue Instanz anlegen ↗</a>
            <span class="adm-picker-hinweis">öffnet die Bibliothek in neuem Tab — danach hier neu laden</span>
            <button type="button" id="picker-abbrechen" class="adm-btn-grau">Schließen</button>
        </div>
    </div>
</div>

<script>
window.TM_PLED = {
    start: <?= json_encode($inhalteFuerJs, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/js/admin/editor-core.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/editor-core.js') ?: time() ?>"></script>
<script src="/assets/js/admin/playlist-editor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/playlist-editor.js') ?: time() ?>"></script>

<?php
admin_footer();
