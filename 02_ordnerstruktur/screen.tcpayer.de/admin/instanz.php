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
            $inhalteFuerJs[] = [
                'mediathek_id' => $media,
                'url'          => $url,
                'text'         => (string)($row['text'] ?? ''),
                'dauer_sek'    => (int)($row['dauer_sek'] ?? 10),
                'gueltig_bis'  => $row['gueltig_bis'] ?? '',
                'aktiv'        => !empty($row['aktiv']),
            ];
        }
    } elseif (!$istNeu) {
        foreach (ModulInstanz::listInhalte($id) as $in) {
            $inhalteFuerJs[] = [
                'mediathek_id' => $in['mediathek_id'] !== null ? (int)$in['mediathek_id'] : null,
                'url'          => $in['dateiname'] ? $uploadsBasis . rawurlencode($in['dateiname']) : null,
                'text'         => (string)($in['text_inhalt'] ?? ''),
                'dauer_sek'    => (int)$in['dauer_sek'],
                'gueltig_bis'  => $in['gueltig_bis'] ?? '',
                'aktiv'        => (bool)$in['aktiv'],
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
        <h2><?= $modulTyp === 'bild' ? 'Bilder' : 'Ankündigungs-Einträge' ?></h2>
        <p class="adm-hilfe">
            <?php if ($modulTyp === 'bild'): ?>
                Bilder aus der Mediathek hinzufügen. Reihenfolge per ↑/↓. Pro Eintrag: Anzeigedauer,
                optionales Gültig-bis-Datum und Aktiv-Schalter.
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

    function baueZeile(data) {
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
        var akt = e.target.getAttribute('data-akt');
        if (akt === 'weg')   { if (confirm('Diesen Eintrag entfernen?')) { zeile.remove(); } }
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

    fetch('../proxies/nc-locations.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok || !data.standorte || data.standorte.length === 0) {
                picker.innerHTML = '<span class="adm-leer">'
                    + (data.error ? escHtml(data.error) : 'Keine Standorte von der NC-API erhalten.')
                    + '</span>';
                return;
            }

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
            picker.addEventListener('change', syncHidden);

            // Saal-Select füllen (Optgruppen je Standort)
            if (roomSelect) {
                roomSelect.innerHTML = '<option value="0">— alle Säle —</option>';
                data.standorte.forEach(function (s) {
                    if (!s.rooms || s.rooms.length === 0) { return; }
                    var group = document.createElement('optgroup');
                    group.label = escHtml(s.name);
                    s.rooms.forEach(function (r) {
                        var opt = document.createElement('option');
                        opt.value = r.id;
                        opt.textContent = r.name;
                        if (r.id === selectedRoom) { opt.selected = true; }
                        group.appendChild(opt);
                    });
                    roomSelect.appendChild(group);
                });
            }
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
