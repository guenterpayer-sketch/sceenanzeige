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

        header('Location: bibliothek.php?gespeichert=1');
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

<p><a href="bibliothek.php" class="adm-zurueck">← zurück zur Bibliothek</a></p>

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
        <button type="submit" class="adm-btn-primary">Speichern</button>
        <a href="bibliothek.php" class="adm-btn adm-btn-grau">Abbrechen</a>
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

<script>
(function () {
    var MODUL_TYP = <?= json_encode($modulTyp) ?>;
    var STD_DAUER = <?= json_encode($standardDauer > 0 ? $standardDauer : 10) ?>;
    var START     = <?= json_encode($inhalteFuerJs, JSON_UNESCAPED_UNICODE) ?>;

    var liste = document.getElementById('inhalte-liste');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function bildVorschau(url) {
        return url
            ? '<img src="' + escapeHtml(url) + '" alt="">'
            : '<span class="adm-kein-bild">kein Bild</span>';
    }

    function videoVorschau(data) {
        if (data.video_url) {
            return '<video src="' + escapeHtml(data.video_url) + '" muted preload="metadata" style="max-width:160px;max-height:90px"></video>';
        }
        if (data.video_embed_url) {
            return '<span class="adm-kein-bild">Embed: ' + escapeHtml(data.video_embed_url) + '</span>';
        }
        return '<span class="adm-kein-bild">kein Video</span>';
    }

    function baueVideoZeile(data) {
        data = data || {};
        var zeile = document.createElement('div');
        zeile.className = 'adm-inhalt-zeile';
        var hatDatei = !!data.video_datei_id;

        var rname = 'quelle-' + Math.random().toString(36).slice(2);
        var quelleBlock =
            '<div class="adm-inhalt-bild">' +
                '<div class="adm-inhalt-vorschau">' + videoVorschau(data) + '</div>' +
                '<input type="hidden" data-feld="video_datei_id" value="' + (data.video_datei_id != null ? data.video_datei_id : '') + '">' +
                '<label class="adm-inhalt-aktiv"><input type="radio" name="' + rname + '" class="adm-video-quelle" value="datei" ' + (hatDatei || !data.video_embed_url ? 'checked' : '') + '> Datei hochladen</label>' +
                '<label class="adm-inhalt-aktiv"><input type="radio" name="' + rname + '" class="adm-video-quelle" value="embed" ' + (!hatDatei && data.video_embed_url ? 'checked' : '') + '> Embed-Link</label>' +
                '<button type="button" class="adm-btn adm-video-waehlen" ' + (hatDatei || !data.video_embed_url ? '' : 'hidden') + '>Video wählen</button>' +
                '<input type="url" class="adm-video-embed-feld" data-feld="video_embed_url" placeholder="https://youtube.com/... oder PeerTube-Embed-Link" value="' + escapeHtml(data.video_embed_url || '') + '" ' + (!hatDatei && data.video_embed_url ? '' : 'hidden') + '>' +
            '</div>';

        var metaBlock =
            '<div class="adm-inhalt-meta">' +
                '<label>Geschätzte Dauer (Sek.)<input type="number" min="1" data-feld="dauer_sek" value="' + (data.dauer_sek || STD_DAUER) + '"></label>' +
                '<label>Gültig bis<input type="date" data-feld="gueltig_bis" value="' + escapeHtml(data.gueltig_bis || '') + '"></label>' +
                '<label class="adm-inhalt-aktiv"><input type="checkbox" data-feld="aktiv" ' + (data.aktiv === false ? '' : 'checked') + '> aktiv</label>' +
            '</div>';

        var steuer =
            '<div class="adm-inhalt-steuer">' +
                '<button type="button" class="adm-mini" data-akt="hoch" title="nach oben">↑</button>' +
                '<button type="button" class="adm-mini" data-akt="runter" title="nach unten">↓</button>' +
                '<button type="button" class="adm-mini adm-mini-rot" data-akt="weg" title="entfernen">×</button>' +
            '</div>';

        zeile.innerHTML = quelleBlock + metaBlock + steuer;

        // Quelle umschalten: Datei-Button vs. Embed-Feld; jeweils anderes Feld leeren
        var radios = zeile.querySelectorAll('.adm-video-quelle');
        var waehlenBtn = zeile.querySelector('.adm-video-waehlen');
        var embedFeld = zeile.querySelector('.adm-video-embed-feld');
        var hiddenDatei = zeile.querySelector('[data-feld="video_datei_id"]');
        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                if (r.value === 'datei' && r.checked) {
                    waehlenBtn.hidden = false;
                    embedFeld.hidden = true;
                    embedFeld.value = '';
                } else if (r.value === 'embed' && r.checked) {
                    waehlenBtn.hidden = true;
                    embedFeld.hidden = false;
                    hiddenDatei.value = '';
                    zeile.querySelector('.adm-inhalt-vorschau').innerHTML = videoVorschau({});
                }
            });
        });

        return zeile;
    }

    function baueZeile(data) {
        if (MODUL_TYP === 'video') { return baueVideoZeile(data); }
        data = data || {};
        var zeile = document.createElement('div');
        zeile.className = 'adm-inhalt-zeile';
        zeile.setAttribute('data-mediathek', data.mediathek_id != null ? data.mediathek_id : '');

        var bildBlock =
            '<div class="adm-inhalt-bild">' +
                '<div class="adm-inhalt-vorschau">' + bildVorschau(data.url) + '</div>' +
                '<input type="hidden" data-feld="mediathek_id" value="' + (data.mediathek_id != null ? data.mediathek_id : '') + '">' +
                '<button type="button" class="adm-btn adm-bild-waehlen">Bild wählen</button>' +
                (MODUL_TYP === 'ankuendigung' ? '<button type="button" class="adm-btn adm-btn-grau adm-bild-entfernen">Bild entfernen</button>' : '') +
            '</div>';

        var textBlock = MODUL_TYP === 'ankuendigung'
            ? '<div class="adm-inhalt-text"><label>Text</label><textarea data-feld="text">' + escapeHtml(data.text || '') + '</textarea></div>'
            : '';

        var metaBlock =
            '<div class="adm-inhalt-meta">' +
                '<label>Dauer (Sek.)<input type="number" min="1" data-feld="dauer_sek" value="' + (data.dauer_sek || STD_DAUER) + '"></label>' +
                '<label>Gültig bis<input type="date" data-feld="gueltig_bis" value="' + escapeHtml(data.gueltig_bis || '') + '"></label>' +
                '<label class="adm-inhalt-aktiv"><input type="checkbox" data-feld="aktiv" ' + (data.aktiv === false ? '' : 'checked') + '> aktiv</label>' +
            '</div>';

        var steuer =
            '<div class="adm-inhalt-steuer">' +
                '<button type="button" class="adm-mini" data-akt="hoch" title="nach oben">↑</button>' +
                '<button type="button" class="adm-mini" data-akt="runter" title="nach unten">↓</button>' +
                '<button type="button" class="adm-mini adm-mini-rot" data-akt="weg" title="entfernen">×</button>' +
            '</div>';

        zeile.innerHTML = bildBlock + textBlock + metaBlock + steuer;
        return zeile;
    }

    function neueZeile(data) { liste.appendChild(baueZeile(data)); }

    // Startdaten rendern
    START.forEach(neueZeile);

    document.getElementById('zeile-hinzu').addEventListener('click', function () {
        neueZeile({});
    });

    // Zeilen-Aktionen (Delegation)
    liste.addEventListener('click', function (e) {
        var zeile = e.target.closest('.adm-inhalt-zeile');
        if (!zeile) { return; }

        if (e.target.closest('.adm-bild-waehlen')) { oeffnePicker(zeile); return; }
        if (e.target.closest('.adm-bild-entfernen')) {
            zeile.querySelector('[data-feld="mediathek_id"]').value = '';
            zeile.querySelector('.adm-inhalt-vorschau').innerHTML = bildVorschau(null);
            return;
        }
        if (e.target.closest('.adm-video-waehlen')) { oeffneVideoPicker(zeile); return; }
        var akt = e.target.getAttribute('data-akt');
        if (akt === 'weg') {
            admBestaetigen('Diesen Eintrag entfernen?', function (ok) { if (ok) { zeile.remove(); } }, 'Entfernen');
            return;
        }
        if (akt === 'hoch'   && zeile.previousElementSibling) { liste.insertBefore(zeile, zeile.previousElementSibling); }
        if (akt === 'runter' && zeile.nextElementSibling)     { liste.insertBefore(zeile.nextElementSibling, zeile); }
    });

    // Vor dem Absenden: Feldnamen sequenziell nach DOM-Reihenfolge vergeben
    document.getElementById('instanz-form').addEventListener('submit', function () {
        var zeilen = liste.querySelectorAll('.adm-inhalt-zeile');
        zeilen.forEach(function (zeile, i) {
            zeile.querySelectorAll('[data-feld]').forEach(function (feld) {
                feld.setAttribute('name', 'inhalt[' + i + '][' + feld.getAttribute('data-feld') + ']');
            });
        });
    });

    // ---- Bild-Picker ----
    var overlay   = document.getElementById('picker-overlay');
    var galerie   = document.getElementById('picker-galerie');
    var selOrdner = document.getElementById('picker-ordner');
    var selTag    = document.getElementById('picker-tag');
    var sucheInp  = document.getElementById('picker-suche');
    var zielZeile = null;
    var filterTimer = null;

    function oeffnePicker(zeile) { zielZeile = zeile; overlay.hidden = false; ladePicker(); }
    function schliessePicker() { overlay.hidden = true; zielZeile = null; }

    document.getElementById('picker-abbrechen').addEventListener('click', schliessePicker);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliessePicker(); } });
    [selOrdner, selTag].forEach(function (el) { el.addEventListener('change', ladePicker); });
    sucheInp.addEventListener('input', function () {
        clearTimeout(filterTimer); filterTimer = setTimeout(ladePicker, 250);
    });

    var filterGeladen = false;
    function ladePicker() {
        var p = new URLSearchParams();
        if (selOrdner.value) { p.set('ordner', selOrdner.value); }
        if (selTag.value)    { p.set('tag', selTag.value); }
        if (sucheInp.value.trim()) { p.set('q', sucheInp.value.trim()); }
        galerie.innerHTML = '<p class="adm-leer">Lade …</p>';

        fetch('api/mediathek-list.php?' + p.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { galerie.innerHTML = '<p class="adm-leer">Fehler beim Laden.</p>'; return; }
                if (!filterGeladen) {
                    data.ordner.forEach(function (o) {
                        var opt = document.createElement('option'); opt.value = o.id; opt.textContent = o.name + ' (' + o.anzahl + ')';
                        selOrdner.appendChild(opt);
                    });
                    data.tags.forEach(function (t) {
                        var opt = document.createElement('option'); opt.value = t.id; opt.textContent = t.name + ' (' + t.anzahl + ')';
                        selTag.appendChild(opt);
                    });
                    filterGeladen = true;
                }
                if (data.bilder.length === 0) { galerie.innerHTML = '<p class="adm-leer">Keine Bilder.</p>'; return; }
                galerie.innerHTML = '';
                data.bilder.forEach(function (b) {
                    var fig = document.createElement('button');
                    fig.type = 'button';
                    fig.className = 'adm-picker-bild';
                    fig.innerHTML = '<img src="' + escapeHtml(b.url) + '" alt="" loading="lazy">' +
                                    '<span>' + escapeHtml(b.original_name || b.dateiname) + '</span>';
                    fig.addEventListener('click', function () { waehleBild(b); });
                    galerie.appendChild(fig);
                });
            })
            .catch(function () { galerie.innerHTML = '<p class="adm-leer">Netzwerkfehler.</p>'; });
    }

    function waehleBild(b) {
        if (!zielZeile) { return; }
        zielZeile.querySelector('[data-feld="mediathek_id"]').value = b.id;
        zielZeile.querySelector('.adm-inhalt-vorschau').innerHTML = '<img src="' + escapeHtml(b.url) + '" alt="">';
        schliessePicker();
    }

    // ---- Video-Picker ----
    var videoOverlay = document.getElementById('video-picker-overlay');
    if (videoOverlay) {
        var videoGalerie  = document.getElementById('video-picker-galerie');
        var videoZielZeile = null;
        var videoGeladen   = false;

        function oeffneVideoPicker(zeile) {
            videoZielZeile = zeile;
            videoOverlay.hidden = false;
            ladeVideoPicker();
        }
        function schliesseVideoPicker() { videoOverlay.hidden = true; videoZielZeile = null; }

        document.getElementById('video-picker-abbrechen').addEventListener('click', schliesseVideoPicker);
        videoOverlay.addEventListener('click', function (e) { if (e.target === videoOverlay) { schliesseVideoPicker(); } });

        function ladeVideoPicker() {
            if (videoGeladen) { return; }
            videoGalerie.innerHTML = '<p class="adm-leer">Lade …</p>';
            fetch('api/video-list.php')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { videoGalerie.innerHTML = '<p class="adm-leer">Fehler beim Laden.</p>'; return; }
                    if (data.videos.length === 0) { videoGalerie.innerHTML = '<p class="adm-leer">Keine Videos. <a href="videothek.php">Jetzt hochladen</a>.</p>'; return; }
                    videoGeladen = true;
                    videoGalerie.innerHTML = '';
                    data.videos.forEach(function (v) {
                        var fig = document.createElement('button');
                        fig.type = 'button';
                        fig.className = 'adm-picker-bild';
                        fig.innerHTML = '<video src="' + escapeHtml(v.url) + '" muted preload="metadata"></video>' +
                                        '<span>' + escapeHtml(v.original_name || v.dateiname) + '</span>';
                        fig.addEventListener('click', function () { waehleVideo(v); });
                        videoGalerie.appendChild(fig);
                    });
                })
                .catch(function () { videoGalerie.innerHTML = '<p class="adm-leer">Netzwerkfehler.</p>'; });
        }

        function waehleVideo(v) {
            if (!videoZielZeile) { return; }
            videoZielZeile.querySelector('[data-feld="video_datei_id"]').value = v.id;
            videoZielZeile.querySelector('.adm-inhalt-vorschau').innerHTML = videoVorschau({ video_url: v.url });
            var embedFeld = videoZielZeile.querySelector('.adm-video-embed-feld');
            if (embedFeld) { embedFeld.value = ''; }
            schliesseVideoPicker();
        }
    }
})();
</script>
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
<script>
(function () {
    var overlay  = document.getElementById('setting-bild-overlay');
    var galerie  = document.getElementById('setting-bild-galerie');
    var suche    = document.getElementById('setting-bild-suche');
    var zielWrap = null;
    var timer    = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function schliesse() { overlay.hidden = true; zielWrap = null; }

    function lade() {
        var p = new URLSearchParams();
        if (suche.value.trim()) { p.set('q', suche.value.trim()); }
        galerie.innerHTML = '<p class="adm-leer">Lade …</p>';
        fetch('api/mediathek-list.php?' + p.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok || !data.bilder || data.bilder.length === 0) {
                    galerie.innerHTML = '<p class="adm-leer">Keine Bilder.</p>';
                    return;
                }
                galerie.innerHTML = '';
                data.bilder.forEach(function (b) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'adm-picker-bild';
                    btn.innerHTML = '<img src="' + esc(b.url) + '" alt="" loading="lazy">'
                        + '<span>' + esc(b.original_name || b.dateiname) + '</span>';
                    btn.addEventListener('click', function () {
                        if (!zielWrap) { return; }
                        zielWrap.querySelector('input[type="hidden"]').value = b.dateiname;
                        var img = zielWrap.querySelector('.adm-setting-bild-vorschau');
                        img.src = b.url;
                        img.hidden = false;
                        zielWrap.querySelector('.adm-setting-bild-entfernen').hidden = false;
                        schliesse();
                    });
                    galerie.appendChild(btn);
                });
            })
            .catch(function () { galerie.innerHTML = '<p class="adm-leer">Netzwerkfehler.</p>'; });
    }

    document.getElementById('setting-bild-abbrechen').addEventListener('click', schliesse);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliesse(); } });
    suche.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(lade, 250); });

    document.querySelectorAll('.adm-setting-bild').forEach(function (wrap) {
        wrap.querySelector('.adm-setting-bild-waehlen').addEventListener('click', function () {
            zielWrap = wrap;
            overlay.hidden = false;
            lade();
        });
        wrap.querySelector('.adm-setting-bild-entfernen').addEventListener('click', function () {
            wrap.querySelector('input[type="hidden"]').value = '';
            var img = wrap.querySelector('.adm-setting-bild-vorschau');
            img.hidden = true;
            img.removeAttribute('src');
            wrap.querySelector('.adm-setting-bild-entfernen').hidden = true;
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($modulTyp === 'stundenplan'): ?>
<script>
(function () {
    var picker = document.getElementById('f_location_ids');
    var hidden = document.getElementById('f_location_ids_hidden');
    if (!picker || !hidden) { return; }

    var selected = [];
    try { selected = JSON.parse(hidden.value || '[]'); } catch (e) {}
    if (!Array.isArray(selected)) { selected = []; }
    selected = selected.map(Number);

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function syncHidden() {
        var ids = [];
        picker.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
            ids.push(parseInt(cb.value, 10));
        });
        hidden.value = ids.length > 0 ? JSON.stringify(ids) : '';
    }

    var roomSelect = document.getElementById('f_room_id');
    var selectedRoom = roomSelect ? parseInt(roomSelect.getAttribute('data-selected') || '0', 10) : 0;
    var alleStandorte = [];

    // Baut das Saal-Select neu auf — gefiltert nach den aktuell angehakten Standorten.
    // Keine Haken = alle Standorte / alle Säle anzeigen.
    function rebuildRoomSelect() {
        if (!roomSelect) { return; }
        var currentVal = parseInt(roomSelect.value || '0', 10);

        var checkedIds = [];
        picker.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
            checkedIds.push(parseInt(cb.value, 10));
        });

        var sichtbar = checkedIds.length > 0
            ? alleStandorte.filter(function (s) { return checkedIds.indexOf(s.id) !== -1; })
            : alleStandorte;

        roomSelect.innerHTML = '<option value="0">— alle Säle —</option>';
        sichtbar.forEach(function (s) {
            if (!s.rooms || s.rooms.length === 0) { return; }
            var group = document.createElement('optgroup');
            group.label = s.name;
            s.rooms.forEach(function (r) {
                var opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.name;
                // Vorherige Auswahl erhalten, wenn der Saal noch sichtbar ist
                if (r.id === currentVal || (currentVal === 0 && r.id === selectedRoom)) {
                    opt.selected = true;
                    selectedRoom = 0; // einmalig anwenden
                }
                group.appendChild(opt);
            });
            roomSelect.appendChild(group);
        });
    }

    fetch('../proxies/nc-locations.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok || !data.standorte || data.standorte.length === 0) {
                picker.innerHTML = '<span class="adm-leer">'
                    + (data.error ? escHtml(data.error) : 'Keine Standorte von der NC-API erhalten.')
                    + '</span>';
                return;
            }

            alleStandorte = data.standorte;

            // Standort-Checkboxen füllen
            picker.innerHTML = '';
            data.standorte.forEach(function (s) {
                var checked = selected.indexOf(s.id) !== -1;
                var label = document.createElement('label');
                label.className = 'adm-location-option';
                label.innerHTML = '<input type="checkbox" value="' + s.id + '"'
                    + (checked ? ' checked' : '') + '> ' + escHtml(s.name);
                picker.appendChild(label);
            });
            syncHidden();

            // Saal-Select initial aufbauen + bei Checkbox-Wechsel neu aufbauen
            rebuildRoomSelect();
            picker.addEventListener('change', function () {
                syncHidden();
                rebuildRoomSelect();
            });
        })
        .catch(function () {
            picker.innerHTML = '<span class="adm-leer adm-flash-fehler" style="padding:4px 8px;border-radius:4px">'
                + 'Standorte konnten nicht geladen werden.</span>';
        });
})();
</script>
<?php endif; ?>

<?php
admin_footer();
