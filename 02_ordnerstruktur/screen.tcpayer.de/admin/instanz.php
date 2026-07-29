<?php
/**
 * admin/instanz.php
 *
 * Editor zum Anlegen/Bearbeiten einer Modul-Instanz (Schritt 5b).
 *   - Aufruf neu:        instanz.php?typ=<modul_typ>
 *   - Aufruf bearbeiten: instanz.php?id=<instanz_id>
 *
 * Einstellungsfelder werden generisch aus module.json erzeugt
 * (ModuleRegistry). Für Module mit has_inhalte (bild, ankuendigung) gibt es
 * zusätzlich einen Inhalte-Editor mit Mediathek-Bild-Picker.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$fehler = [];

// --- Kontext bestimmen: neu (typ) oder bearbeiten (id) ---
$instanz = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $instanz = ModulInstanz::find($id);
    if (!$instanz) {
        http_response_code(404);
        admin_header('Instanz', 'bibliothek');
        echo '<p class="adm-flash adm-flash-fehler">Instanz nicht gefunden.</p>';
        admin_footer();
        exit;
    }
    $modulTyp = $instanz['modul_typ'];
} else {
    $modulTyp = $_POST['modul_typ'] ?? ($_GET['typ'] ?? '');
}

if (!ModuleRegistry::exists($modulTyp)) {
    http_response_code(400);
    admin_header('Instanz', 'bibliothek');
    echo '<p class="adm-flash adm-flash-fehler">Unbekannter Modul-Typ.</p>';
    admin_footer();
    exit;
}

$meta       = ModuleRegistry::load($modulTyp);
$hasInhalte = !empty($meta['has_inhalte']);
$istNeu     = ($instanz === null);

// Hat das Modul ein Einstellungs-Feld vom Typ mediathek_bild (z.B. Uhr-
// Hintergrund)? Dann eigenen, leichtgewichtigen Bild-Picker einbinden.
$hatSettingBild = false;
foreach (($meta['settings'] ?? []) as $sbFeld) {
    if (($sbFeld['type'] ?? '') === 'mediathek_bild') { $hatSettingBild = true; break; }
}

// Vorbelegung
$werteName          = $instanz['name'] ?? '';
$werteAktiv         = $istNeu ? true : (bool)$instanz['aktiv'];
$werteEinstellungen = $istNeu ? [] : $instanz['einstellungen'];

// --- Speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $werteName          = trim((string)($_POST['name'] ?? ''));
    $werteAktiv         = !empty($_POST['aktiv']);
    $werteEinstellungen = ModuleRegistry::collectSettings($modulTyp, $_POST['einstellungen'] ?? []);

    if ($werteName === '') {
        $fehler[] = 'Bitte einen Namen für die Instanz angeben.';
    } elseif (ModulInstanz::nameExistiert($werteName, $modulTyp, $istNeu ? null : $id)) {
        $fehler[] = 'Es gibt bereits eine ' . $meta['label'] . '-Instanz mit diesem Namen. Bitte einen anderen Namen wählen.';
    }

    if (empty($fehler)) {
        if ($istNeu) {
            $id = ModulInstanz::create($modulTyp, $werteName, $werteEinstellungen);
        } else {
            ModulInstanz::update($id, $werteName, $werteEinstellungen);
        }
        ModulInstanz::setAktiv($id, $werteAktiv);

        if ($hasInhalte) {
            $inhalte = [];
            foreach (($_POST['inhalt'] ?? []) as $row) {
                $media = !empty($row['mediathek_id']) ? (int)$row['mediathek_id'] : null;
                $text  = trim((string)($row['text'] ?? ''));
                if ($modulTyp === 'bild') {
                    if ($media === null) { continue; }          // Bild-Eintrag braucht ein Bild
                    $inhalte[] = [
                        'mediathek_id' => $media,
                        'dauer_sek'    => (int)($row['dauer_sek'] ?? 10),
                        'gueltig_bis'  => $row['gueltig_bis'] ?? null,
                        'aktiv'        => !empty($row['aktiv']),
                    ];
                } elseif ($modulTyp === 'video') {
                    $videoDatei = !empty($row['video_datei_id']) ? (int)$row['video_datei_id'] : null;
                    $embedUrl   = trim((string)($row['video_embed_url'] ?? ''));
                    if ($videoDatei === null && $embedUrl === '') { continue; } // leere Zeile
                    $inhalte[] = [
                        'video_datei_id'  => $videoDatei,
                        'video_embed_url' => $embedUrl !== '' ? $embedUrl : null,
                        'dauer_sek'       => (int)($row['dauer_sek'] ?? 30),
                        'gueltig_bis'     => $row['gueltig_bis'] ?? null,
                        'aktiv'           => !empty($row['aktiv']),
                    ];
                } else { // ankuendigung
                    if ($text === '' && $media === null) { continue; } // leere Zeile
                    $inhalte[] = [
                        'mediathek_id' => $media,
                        'text'         => $text,
                        'dauer_sek'    => (int)($row['dauer_sek'] ?? 10),
                        'gueltig_bis'  => $row['gueltig_bis'] ?? null,
                        'aktiv'        => !empty($row['aktiv']),
                    ];
                }
            }
            ModulInstanz::ersetzeInhalte($id, $inhalte);
        }

        if (!empty($_POST['bleiben'])) {
            // „Speichern" — auf der Seite bleiben (auch nach Neuanlage: ab
            // jetzt im Bearbeiten-Modus mit der frischen ID weiterarbeiten)
            header('Location: instanz.php?id=' . $id . '&gespeichert=1');
        } else {
            // „Speichern & schließen" — zurück zur Typ-Übersicht
            header('Location: bibliothek.php?typ=' . urlencode($modulTyp) . '&gespeichert=1');
        }
        exit;
    }
}

// --- Inhalte für den Editor (als JSON für das JS) zusammenstellen ---
$uploadsBasis = rtrim(UPLOADS_URL, '/') . '/';
$inhalteFuerJs = [];
if ($hasInhalte) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Nach Validierungsfehler: abgeschickte Zeilen erhalten
        foreach (($_POST['inhalt'] ?? []) as $row) {
            $media = !empty($row['mediathek_id']) ? (int)$row['mediathek_id'] : null;
            $url = null;
            if ($media !== null) {
                $m = Mediathek::find($media);
                if ($m) { $url = $uploadsBasis . rawurlencode($m['dateiname']); }
            }
            $videoDatei = !empty($row['video_datei_id']) ? (int)$row['video_datei_id'] : null;
            $videoUrl = null;
            if ($videoDatei !== null) {
                $vd = Videothek::find($videoDatei);
                if ($vd) { $videoUrl = $uploadsBasis . rawurlencode($vd['dateiname']); }
            }
            $inhalteFuerJs[] = [
                'mediathek_id'    => $media,
                'url'             => $url,
                'text'            => (string)($row['text'] ?? ''),
                'dauer_sek'       => (int)($row['dauer_sek'] ?? 10),
                'gueltig_bis'     => $row['gueltig_bis'] ?? '',
                'aktiv'           => !empty($row['aktiv']),
                'video_datei_id'  => $videoDatei,
                'video_url'       => $videoUrl,
                'video_embed_url' => (string)($row['video_embed_url'] ?? ''),
            ];
        }
    } elseif (!$istNeu) {
        foreach (ModulInstanz::listInhalte($id) as $in) {
            $inhalteFuerJs[] = [
                'mediathek_id'    => $in['mediathek_id'] !== null ? (int)$in['mediathek_id'] : null,
                'url'             => $in['dateiname'] ? $uploadsBasis . rawurlencode($in['dateiname']) : null,
                'text'            => (string)($in['text_inhalt'] ?? ''),
                'dauer_sek'       => (int)$in['dauer_sek'],
                'gueltig_bis'     => $in['gueltig_bis'] ?? '',
                'aktiv'           => (bool)$in['aktiv'],
                'video_datei_id'  => $in['video_datei_id'] !== null ? (int)$in['video_datei_id'] : null,
                'video_url'       => !empty($in['video_dateiname']) ? $uploadsBasis . rawurlencode($in['video_dateiname']) : null,
                'video_embed_url' => (string)($in['video_embed_url'] ?? ''),
            ];
        }
    }
}

$standardDauer = (int)($werteEinstellungen['intervall_sek'] ?? 10);

admin_header(($istNeu ? 'Neue ' : '') . $meta['label'] . '-Instanz', 'bibliothek');
?>

<p><a href="bibliothek.php?typ=<?= urlencode($modulTyp) ?>" class="adm-zurueck">← zurück zur <?= htmlspecialchars($meta['label']) ?>-Übersicht</a></p>

<?php if (isset($_GET['gespeichert'])): ?>
    <div class="adm-flash">Instanz gespeichert.</div>
<?php endif; ?>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>

<form method="post" id="instanz-form">
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="modul_typ" value="<?= htmlspecialchars($modulTyp) ?>">

    <div class="adm-card">
        <div class="field">
            <label for="name">Name der Instanz</label>
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
        <h2>Einstellungen</h2>
        <?php
        // FRET: Computer-UUID als Dropdown der freigegebenen Geräte einspeisen.
        $dynamicOptions = [];
        if ($modulTyp === 'fret') {
            $opts = [['value' => '', 'label' => '— Gerät wählen —']];
            $vorhanden = [];
            foreach (FretGeraet::freigegebene() as $g) {
                $label = $g['anzeige_name'] !== null && $g['anzeige_name'] !== ''
                    ? $g['anzeige_name']
                    : ($g['fret_name'] !== '' && $g['fret_name'] !== null ? $g['fret_name'] : $g['uuid']);
                $opts[] = ['value' => $g['uuid'], 'label' => $label];
                $vorhanden[$g['uuid']] = true;
            }
            $aktuellUuid = (string)($werteEinstellungen['computer_id'] ?? '');
            if ($aktuellUuid !== '' && empty($vorhanden[$aktuellUuid])) {
                $opts[] = ['value' => $aktuellUuid, 'label' => $aktuellUuid . ' (nicht freigegeben)'];
            }
            $dynamicOptions['computer_id'] = $opts;
        }
        echo ModuleRegistry::renderSettingsForm($modulTyp, $werteEinstellungen, $dynamicOptions);
        ?>
        <?php if ($modulTyp === 'fret' && count(FretGeraet::freigegebene()) === 0): ?>
            <p class="adm-hilfe">Noch kein FRET-Gerät freigegeben — im Bereich
                <a href="fret-geraete.php">FRET-Geräte</a> aktualisieren und freigeben.</p>
        <?php endif; ?>
    </div>

    <?php if ($hasInhalte): ?>
    <div class="adm-card">
        <h2><?= $modulTyp === 'bild' ? 'Bilder' : ($modulTyp === 'video' ? 'Video-Einträge' : 'Ankündigungs-Einträge') ?></h2>
        <p class="adm-hilfe">
            <?php if ($modulTyp === 'bild'): ?>
                Bilder aus der Mediathek hinzufügen. Reihenfolge per ↑/↓. Pro Eintrag: Anzeigedauer,
                optionales Gültig-bis-Datum und Aktiv-Schalter.
            <?php elseif ($modulTyp === 'video'): ?>
                Pro Eintrag entweder eine eigene Videodatei (aus den <a href="videothek.php">Videos</a>)
                oder einen Embed-Link (YouTube oder PeerTube). Reihenfolge per ↑/↓. Die Weiterschaltung
                erfolgt automatisch nach Videoende, nicht nach der Anzeigedauer — diese dient nur als
                grobe Schätzung für die Spalten-Synchronisation mit anderen Modulen.
            <?php else: ?>
                Einträge mit Text und/oder Bild. Reihenfolge per ↑/↓. Pro Eintrag: Anzeigedauer,
                optionales Gültig-bis-Datum und Aktiv-Schalter.
            <?php endif; ?>
        </p>
        <div id="inhalte-liste" class="adm-inhalte"></div>
        <button type="button" id="zeile-hinzu" class="adm-btn">+ Eintrag hinzufügen</button>
    </div>
    <?php endif; ?>

    <div class="adm-aktionsleiste">
        <button type="submit" name="bleiben" value="1" class="adm-btn-primary">Speichern</button>
        <button type="submit" class="adm-btn-primary">Speichern &amp; schließen</button>
        <a href="bibliothek.php?typ=<?= urlencode($modulTyp) ?>" class="adm-btn adm-btn-grau">Abbrechen</a>
    </div>
</form>

<?php if ($hasInhalte): ?>
<!-- Bild-Picker-Dialog -->
<div id="picker-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3>Bild aus der Mediathek wählen</h3>
        <div class="adm-picker-filter">
            <select id="picker-ordner"><option value="">Alle Ordner</option></select>
            <select id="picker-tag"><option value="">Alle Tags</option></select>
            <input type="search" id="picker-suche" placeholder="Suchen …">
        </div>
        <div id="picker-galerie" class="adm-picker-galerie"></div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="picker-abbrechen" class="adm-btn-grau">Abbrechen</button>
        </div>
    </div>
</div>

<?php if ($modulTyp === 'video'): ?>
<!-- Video-Picker-Dialog -->
<div id="video-picker-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3>Video aus der Videothek wählen</h3>
        <div id="video-picker-galerie" class="adm-picker-galerie"></div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="video-picker-abbrechen" class="adm-btn-grau">Abbrechen</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($hatSettingBild): ?>
<!-- Bild-Picker für mediathek_bild-Einstellungsfelder (unabhängig vom Inhalte-Picker;
     bewusst NICHT im has_inhalte-Block, damit er auch für Module ohne Inhalte greift). -->
<div id="setting-bild-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3>Bild aus der Mediathek wählen</h3>
        <div class="adm-picker-filter">
            <input type="search" id="setting-bild-suche" placeholder="Suchen …">
        </div>
        <div id="setting-bild-galerie" class="adm-picker-galerie"></div>
        <div class="adm-dialog-aktionen">
            <button type="button" id="setting-bild-abbrechen" class="adm-btn-grau">Abbrechen</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
window.TM_INST = {
    modulTyp: <?= json_encode($modulTyp) ?>,
    stdDauer: <?= json_encode($standardDauer > 0 ? $standardDauer : 10) ?>,
    start:    <?= json_encode($inhalteFuerJs, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="/assets/js/admin/editor-core.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/editor-core.js') ?: time() ?>"></script>
<script src="/assets/js/admin/instanz.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin/instanz.js') ?: time() ?>"></script>

<?php
admin_footer();
