<?php
/**
 * admin/bibliothek.php
 *
 * Bibliothek in zwei Ebenen, beide als Kacheln (Schritt 5b-Redesign):
 *   1. Übersicht: je eine Kachel pro Modulart (auto. aus der Registry, neue
 *      Modularten hängen sich von selbst an) mit Instanz-Anzahl.
 *   2. Typ-Ansicht (?typ=…): "[Modulart] anlegen" + alle Instanzen dieses Typs
 *      als Kacheln mit statischer Mini-Vorschau (Variante A).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$hinweis = null;

// --- Aktionen (Toggle aktiv / Löschen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $zurueck = isset($_POST['typ']) ? '?typ=' . urlencode((string)$_POST['typ']) : '';
    if ($id > 0 && $aktion === 'toggle') {
        $inst = ModulInstanz::find($id);
        if ($inst) { ModulInstanz::setAktiv($id, !$inst['aktiv']); }
        header('Location: bibliothek.php' . $zurueck);
        exit;
    }
    if ($id > 0 && $aktion === 'loeschen') {
        ModulInstanz::delete($id);
        header('Location: bibliothek.php' . $zurueck . ($zurueck ? '&' : '?') . 'geloescht=1');
        exit;
    }
}
if (isset($_GET['geloescht']))   { $hinweis = 'Instanz gelöscht.'; }
if (isset($_GET['gespeichert'])) { $hinweis = 'Instanz gespeichert.'; }

$module    = ModuleRegistry::getAll();
$instanzen = ModulInstanz::listAll();

// Instanzen nach Modultyp gruppieren + zählen
$gruppen = [];
foreach ($instanzen as $inst) { $gruppen[$inst['modul_typ']][] = $inst; }

$uploadsBasis = rtrim(UPLOADS_URL, '/') . '/';

/** Emoji-Icon je module.json-icon (Fallback für neue Modularten). */
function modul_icon(string $icon): string
{
    return [
        'clock'     => '🕒',
        'image'     => '🖼️',
        'calendar'  => '📅',
        'megaphone' => '📢',
        'music'     => '🎵',
    ][$icon] ?? '🧩';
}

/** Statische Mini-Vorschau (Variante A) für eine Instanz. */
function instanz_vorschau(array $inst, array $meta, string $uploadsBasis, array $fretMap): string
{
    $typ = $inst['modul_typ'];
    $e   = $inst['einstellungen'];

    if ($typ === 'bild') {
        $bilder = ModulInstanz::listInhalte((int)$inst['id']);
        $erstes = null;
        foreach ($bilder as $b) { if (!empty($b['dateiname'])) { $erstes = $b['dateiname']; break; } }
        if ($erstes) {
            return '<div class="adm-kachel-vorschau bild" style="background-image:url(\''
                . htmlspecialchars($uploadsBasis . rawurlencode($erstes)) . '\')"></div>';
        }
        return '<div class="adm-kachel-vorschau leer">' . modul_icon($meta['icon'] ?? '') . ' keine Bilder</div>';
    }

    if ($typ === 'ankuendigung') {
        $eintraege = ModulInstanz::listInhalte((int)$inst['id']);
        $ersterText = '';
        $bild = null;
        foreach ($eintraege as $en) {
            if ($ersterText === '' && !empty($en['text_inhalt'])) { $ersterText = $en['text_inhalt']; }
            if ($bild === null && !empty($en['dateiname'])) { $bild = $en['dateiname']; }
        }
        $txt = $ersterText !== '' ? mb_substr($ersterText, 0, 90) : '(kein Text)';
        $bildHtml = $bild
            ? '<span class="adm-kachel-mini" style="background-image:url(\'' . htmlspecialchars($uploadsBasis . rawurlencode($bild)) . '\')"></span>'
            : '';
        return '<div class="adm-kachel-vorschau text">' . $bildHtml
            . '<span class="adm-kachel-txt">' . htmlspecialchars($txt) . '</span></div>';
    }

    // Dynamische Module: Icon + Kurz-Info aus den Einstellungen
    $info = [];
    if ($typ === 'uhrzeit') {
        $info[] = 'Format ' . ($e['format_zeit'] ?? 'H:i');
        $info[] = !empty($e['zeige_datum']) ? 'mit Datum' : 'ohne Datum';
    } elseif ($typ === 'stundenplan') {
        $anz = (int)($e['anzahl_kurse'] ?? 0);
        $info[] = $anz > 0 ? "$anz Kurse" : 'alle Kurse';
        $info[] = !empty($e['nur_heute']) ? 'nur heute' : '7 Tage';
    } elseif ($typ === 'fret') {
        $uuid = (string)($e['computer_id'] ?? '');
        $info[] = $uuid !== '' ? ('Gerät: ' . ($fretMap[$uuid] ?? 'unbekannt')) : 'kein Gerät gewählt';
    } else {
        $info[] = $meta['label'];
    }
    $iconBig = '<span class="adm-kachel-icon">' . modul_icon($meta['icon'] ?? '') . '</span>';
    return '<div class="adm-kachel-vorschau info">' . $iconBig
        . '<span class="adm-kachel-info">' . htmlspecialchars(implode(' · ', $info)) . '</span></div>';
}

// --- Welcher View? ---
$typ = $_GET['typ'] ?? '';
$istTypAnsicht = ($typ !== '' && ModuleRegistry::exists($typ));

if ($istTypAnsicht) {
    $meta    = ModuleRegistry::load($typ);
    $fretMap = ($typ === 'fret') ? FretGeraet::alleAlsMap() : [];
    admin_header($meta['label'], 'bibliothek');
} else {
    admin_header('Bibliothek', 'bibliothek');
}
?>

<?php if ($hinweis): ?>
    <div class="adm-flash"><?= htmlspecialchars($hinweis) ?></div>
<?php endif; ?>

<?php if (!$istTypAnsicht): ?>
<!-- ===== Ebene 1: Modulart-Kacheln ===== -->
<p class="adm-hilfe">
    Wähle eine Modulart. Darin legst du benannte Instanzen an (z.&nbsp;B. eine Bild-Instanz
    „Veranstaltungen"), die später in Playlists eingesetzt werden.
</p>
<div class="adm-typgrid">
    <?php foreach ($module as $id => $meta): ?>
        <a class="adm-typkachel" href="bibliothek.php?typ=<?= urlencode($id) ?>">
            <span class="adm-typ-icon"><?= modul_icon($meta['icon'] ?? '') ?></span>
            <span class="adm-typ-label"><?= htmlspecialchars($meta['label']) ?></span>
            <span class="adm-typ-anzahl"><?= count($gruppen[$id] ?? []) ?> angelegt</span>
        </a>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ===== Ebene 2: Instanzen eines Typs ===== -->
<p><a href="bibliothek.php" class="adm-zurueck">← zurück zur Übersicht</a></p>

<div class="adm-neuzeile">
    <a class="adm-btn-primary" href="instanz.php?typ=<?= urlencode($typ) ?>">+ <?= htmlspecialchars($meta['label']) ?> anlegen</a>
</div>

<?php $liste = $gruppen[$typ] ?? []; ?>
<?php if (empty($liste)): ?>
    <p class="adm-leer">Noch keine Instanz dieser Modulart. Mit dem Button oben anlegen.</p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($liste as $inst): ?>
        <div class="adm-kachel <?= $inst['aktiv'] ? '' : 'inaktiv' ?>">
            <?= instanz_vorschau($inst, $meta, $uploadsBasis, $fretMap) ?>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name">
                    <?= htmlspecialchars($inst['name']) ?>
                    <?php if (!$inst['aktiv']): ?><span class="adm-badge-pause">pausiert</span><?php endif; ?>
                </div>
                <div class="adm-kachel-aktionen">
                    <a class="adm-btn" href="instanz.php?id=<?= (int)$inst['id'] ?>">Bearbeiten</a>
                    <form method="post" class="adm-inline">
                        <input type="hidden" name="aktion" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$inst['id'] ?>">
                        <input type="hidden" name="typ" value="<?= htmlspecialchars($typ) ?>">
                        <button type="submit" class="adm-btn adm-btn-grau"><?= $inst['aktiv'] ? 'Pausieren' : 'Aktivieren' ?></button>
                    </form>
                    <form method="post" class="adm-inline adm-del-form" data-name="<?= htmlspecialchars($inst['name']) ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$inst['id'] ?>">
                        <input type="hidden" name="typ" value="<?= htmlspecialchars($typ) ?>">
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
        if (!confirm('Instanz „' + (f.dataset.name || '') + '" wirklich löschen?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php endif; ?>

<?php
admin_footer();
