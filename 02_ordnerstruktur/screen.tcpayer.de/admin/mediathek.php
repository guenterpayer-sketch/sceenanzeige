<?php
/**
 * admin/mediathek.php
 *
 * Mediathek-Galerie (Schritt 5a + 5a.1/5a.2). Funktionen:
 *   - Drag&Drop-/Sammel-Upload mit SHA-256-Duplikat-Erkennung
 *   - Ordner (eine Ebene): anlegen/umbenennen/löschen, nach Ordner filtern,
 *     Bilder verschieben; Upload landet im gerade gewählten Ordner
 *   - Tags (n:m): pro Bild setzen, nach Tag filtern, Namens-Suche
 *
 * Filtern läuft über GET-Parameter (ordner, q, tag) mit Server-Render.
 * Upload, Bearbeiten (Ordner+Tags), Löschen und Ordner-Verwaltung laufen per
 * fetch über admin/api/* (Ordner-Verwaltung lädt danach neu).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

// --- aktuelle Filter aus der URL ---
$ordnerParam = $_GET['ordner'] ?? '';            // '' = alle, 'none' = ohne Ordner, "<id>"
$suche       = trim((string)($_GET['q'] ?? ''));
$tagFilter   = isset($_GET['tag']) && ctype_digit((string)$_GET['tag']) ? (int)$_GET['tag'] : 0;

$filter = [];
if ($ordnerParam === 'none') {
    $filter['ordner'] = 'none';
} elseif (ctype_digit((string)$ordnerParam)) {
    $filter['ordner'] = (int)$ordnerParam;
}
if ($suche !== '')   { $filter['suche'] = $suche; }
if ($tagFilter > 0)  { $filter['tag'] = $tagFilter; }

$ordnerListe = MediathekOrdner::listAllMitAnzahl();
$tagListe    = MediathekTag::listAllMitAnzahl();
$bilder      = Mediathek::listAll($filter);
$uploadsBasis = rtrim(UPLOADS_URL, '/') . '/';

// Zähler für die Ordner-Leiste
$pdo = get_pdo();
$gesamt     = (int)$pdo->query('SELECT COUNT(*) FROM mediathek')->fetchColumn();
$ohneOrdner = (int)$pdo->query('SELECT COUNT(*) FROM mediathek WHERE ordner_id IS NULL')->fetchColumn();

// Zielordner für neue Uploads = aktuell gewählter Ordner (sonst "Ohne Ordner")
$uploadOrdnerId = ctype_digit((string)$ordnerParam) ? (int)$ordnerParam : null;
$uploadOrdnerName = 'Ohne Ordner';
foreach ($ordnerListe as $o) {
    if ((int)$o['id'] === $uploadOrdnerId) { $uploadOrdnerName = $o['name']; }
}

/** Baut eine Galerie-URL mit geänderten Parametern (Rest bleibt erhalten). */
function mt_url(array $aenderung): string
{
    $p = ['ordner' => $_GET['ordner'] ?? '', 'q' => $_GET['q'] ?? '', 'tag' => $_GET['tag'] ?? ''];
    foreach ($aenderung as $k => $v) { $p[$k] = $v; }
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'mediathek.php' . ($p ? '?' . http_build_query($p) : '');
}

admin_header('Mediathek', 'mediathek');
?>

<p class="adm-hilfe">
    Bilder per <strong>Drag&amp;Drop</strong> hochladen (gleiche Bilder werden erkannt und nicht doppelt gespeichert),
    in <strong>Ordner</strong> einsortieren und mit <strong>Tags</strong> versehen. Suche und Tag-Filter wirken über alle Ordner hinweg.
</p>

<!-- Ordner-Leiste ------------------------------------------------------------>
<div class="adm-ordnerleiste">
    <a href="<?= htmlspecialchars(mt_url(['ordner' => ''])) ?>" class="adm-ordner <?= $ordnerParam === '' ? 'aktiv' : '' ?>">Alle <span class="adm-zahl"><?= $gesamt ?></span></a>
    <a href="<?= htmlspecialchars(mt_url(['ordner' => 'none'])) ?>" class="adm-ordner <?= $ordnerParam === 'none' ? 'aktiv' : '' ?>">Ohne Ordner <span class="adm-zahl"><?= $ohneOrdner ?></span></a>
    <?php foreach ($ordnerListe as $o): ?>
        <span class="adm-ordner-wrap">
            <a href="<?= htmlspecialchars(mt_url(['ordner' => (string)$o['id']])) ?>" class="adm-ordner <?= (string)$ordnerParam === (string)$o['id'] ? 'aktiv' : '' ?>">
                <?= htmlspecialchars($o['name']) ?> <span class="adm-zahl"><?= (int)$o['anzahl'] ?></span>
            </a>
            <button type="button" class="adm-ordner-edit" data-id="<?= (int)$o['id'] ?>" data-name="<?= htmlspecialchars($o['name']) ?>" title="Umbenennen">✎</button>
            <button type="button" class="adm-ordner-del" data-id="<?= (int)$o['id'] ?>" data-name="<?= htmlspecialchars($o['name']) ?>" title="Ordner löschen">×</button>
        </span>
    <?php endforeach; ?>
    <button type="button" id="ordner-neu" class="adm-ordner-neu">+ Neuer Ordner</button>
</div>

<!-- Suche + Tag-Filter -------------------------------------------------------->
<div class="adm-filterzeile">
    <form method="get" class="adm-suche">
        <input type="hidden" name="ordner" value="<?= htmlspecialchars($ordnerParam) ?>">
        <?php if ($tagFilter): ?><input type="hidden" name="tag" value="<?= $tagFilter ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= htmlspecialchars($suche) ?>" placeholder="Nach Dateiname suchen …">
        <button type="submit">Suchen</button>
        <?php if ($suche !== ''): ?><a class="adm-clear" href="<?= htmlspecialchars(mt_url(['q' => ''])) ?>">×</a><?php endif; ?>
    </form>

    <?php if (!empty($tagListe)): ?>
    <div class="adm-tagfilter">
        <span class="adm-tagfilter-label">Tags:</span>
        <?php foreach ($tagListe as $t): ?>
            <a href="<?= htmlspecialchars(mt_url(['tag' => $tagFilter === (int)$t['id'] ? '' : (string)$t['id']])) ?>"
               class="adm-tagchip <?= $tagFilter === (int)$t['id'] ? 'aktiv' : '' ?>">
                <?= htmlspecialchars($t['name']) ?> <span class="adm-zahl"><?= (int)$t['anzahl'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Upload -------------------------------------------------------------------->
<div id="dropzone" class="adm-dropzone" data-ordner="<?= $uploadOrdnerId !== null ? $uploadOrdnerId : '' ?>">
    <p>Bilder hierher ziehen oder <span class="adm-link">auswählen</span> — Ziel: <strong><?= htmlspecialchars($uploadOrdnerName) ?></strong></p>
    <input type="file" id="dateiauswahl" accept="image/*" multiple hidden>
</div>
<div id="upload-status" class="adm-upload-status" hidden></div>

<h2>Bilder (<span id="anzahl"><?= count($bilder) ?></span>)</h2>

<div id="galerie" class="adm-galerie"
     data-uploads="<?= htmlspecialchars($uploadsBasis) ?>"
     data-filter-ordner="<?= htmlspecialchars($ordnerParam) ?>">
    <?php if (empty($bilder)): ?>
        <p id="leer-hinweis" class="adm-leer">Keine Bilder in dieser Ansicht.</p>
    <?php endif; ?>
    <?php foreach ($bilder as $b): ?>
        <?php $tagText = implode(', ', $b['tags']); ?>
        <figure class="adm-bild" data-id="<?= (int)$b['id'] ?>"
                data-ordner="<?= $b['ordner_id'] !== null ? (int)$b['ordner_id'] : '' ?>"
                data-tags="<?= htmlspecialchars($tagText) ?>"
                data-name="<?= htmlspecialchars((string)($b['original_name'] ?? $b['dateiname'])) ?>">
            <img src="<?= htmlspecialchars($uploadsBasis . rawurlencode($b['dateiname'])) ?>" alt="<?= htmlspecialchars((string)$b['original_name']) ?>" loading="lazy">
            <figcaption>
                <span class="adm-bild-name" title="<?= htmlspecialchars((string)$b['original_name']) ?>"><?= htmlspecialchars((string)($b['original_name'] ?? $b['dateiname'])) ?></span>
                <span class="adm-bild-meta"><?= (int)$b['breite'] ?>×<?= (int)$b['hoehe'] ?> px</span>
                <span class="adm-bild-tags"><?php foreach ($b['tags'] as $tg): ?><span class="adm-minitag"><?= htmlspecialchars($tg) ?></span><?php endforeach; ?></span>
            </figcaption>
            <button type="button" class="adm-bild-edit" data-id="<?= (int)$b['id'] ?>" title="Ordner &amp; Tags bearbeiten">✎</button>
            <button type="button" class="adm-bild-del" data-id="<?= (int)$b['id'] ?>" title="Löschen">×</button>
        </figure>
    <?php endforeach; ?>
</div>

<!-- Bearbeiten-Dialog --------------------------------------------------------->
<div id="edit-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog">
        <h3>Bild bearbeiten</h3>
        <p id="edit-name" class="adm-dialog-name"></p>
        <label class="adm-feld">
            <span>Ordner</span>
            <select id="edit-ordner">
                <option value="">Ohne Ordner</option>
                <?php foreach ($ordnerListe as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="adm-feld">
            <span>Tags (durch Komma getrennt)</span>
            <input type="text" id="edit-tags" placeholder="z.B. weihnachten, logo, sommer">
        </label>
        <div class="adm-dialog-aktionen">
            <button type="button" id="edit-abbrechen" class="adm-btn-grau">Abbrechen</button>
            <button type="button" id="edit-speichern">Speichern</button>
        </div>
    </div>
</div>

<script>
(function () {
    var dropzone   = document.getElementById('dropzone');
    var dateiInput = document.getElementById('dateiauswahl');
    var galerie    = document.getElementById('galerie');
    var statusBox  = document.getElementById('upload-status');
    var anzahlEl   = document.getElementById('anzahl');
    var UPLOADS    = galerie.getAttribute('data-uploads');
    var FILTER_ORDNER = galerie.getAttribute('data-filter-ordner'); // '', 'none' oder "<id>"
    var UPLOAD_ORDNER = dropzone.getAttribute('data-ordner');       // '' oder "<id>"

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function setStatus(text) {
        if (!text) { statusBox.hidden = true; statusBox.innerHTML = ''; return; }
        statusBox.hidden = false; statusBox.innerHTML = text;
    }
    function aktualisiereAnzahl(delta) {
        anzahlEl.textContent = String(Math.max(0, parseInt(anzahlEl.textContent, 10) + delta));
    }
    function entferneLeerHinweis() {
        var h = document.getElementById('leer-hinweis');
        if (h) { h.remove(); }
    }
    function bildEl(id) { return galerie.querySelector('.adm-bild[data-id="' + id + '"]'); }

    function tagsHtml(tags) {
        return (tags || []).map(function (t) { return '<span class="adm-minitag">' + escapeHtml(t) + '</span>'; }).join('');
    }

    function ergaenzeKachel(e) {
        entferneLeerHinweis();
        var fig = document.createElement('figure');
        fig.className = 'adm-bild adm-neu';
        fig.setAttribute('data-id', e.id);
        fig.setAttribute('data-ordner', e.ordner_id != null ? e.ordner_id : '');
        fig.setAttribute('data-tags', (e.tags || []).join(', '));
        fig.setAttribute('data-name', e.original_name || e.dateiname);
        fig.innerHTML =
            '<img src="' + escapeHtml(e.url) + '" alt="' + escapeHtml(e.original_name || '') + '">' +
            '<figcaption>' +
                '<span class="adm-bild-name" title="' + escapeHtml(e.original_name || '') + '">' + escapeHtml(e.original_name || e.dateiname) + '</span>' +
                '<span class="adm-bild-meta">' + (e.breite || '?') + '×' + (e.hoehe || '?') + ' px</span>' +
                '<span class="adm-bild-tags">' + tagsHtml(e.tags) + '</span>' +
            '</figcaption>' +
            '<button type="button" class="adm-bild-edit" data-id="' + e.id + '" title="Ordner & Tags bearbeiten">✎</button>' +
            '<button type="button" class="adm-bild-del" data-id="' + e.id + '" title="Löschen">×</button>';
        galerie.insertBefore(fig, galerie.firstChild);
    }

    // --- Upload ---
    function ladeHoch(files) {
        var liste = Array.prototype.slice.call(files).filter(function (f) { return f.type.indexOf('image/') === 0; });
        if (liste.length === 0) { return; }
        var neu = 0, dup = 0, fehler = 0;

        function naechste(i) {
            if (i >= liste.length) {
                setStatus('Fertig: ' + neu + ' neu, ' + dup + ' Duplikat(e)' + (fehler ? ', ' + fehler + ' Fehler' : '') + '.');
                return;
            }
            var fd = new FormData();
            fd.append('datei', liste[i]);
            if (UPLOAD_ORDNER) { fd.append('ordner_id', UPLOAD_ORDNER); }
            setStatus('Lade hoch … (' + (i + 1) + '/' + liste.length + ')');

            fetch('api/mediathek-upload.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { fehler++; console.error('Upload-Fehler:', data.error); return; }
                    if (data.duplikat) {
                        dup++;
                        var vorhanden = bildEl(data.eintrag.id);
                        if (vorhanden) {
                            vorhanden.classList.add('adm-dup-blink');
                            setTimeout(function () { vorhanden.classList.remove('adm-dup-blink'); }, 1500);
                        }
                        // Duplikat in anderem Ordner -> in dieser Ansicht ggf. nicht sichtbar, kein Einfügen
                    } else {
                        neu++;
                        ergaenzeKachel(data.eintrag);
                        aktualisiereAnzahl(1);
                    }
                })
                .catch(function (err) { fehler++; console.error(err); })
                .finally(function () { naechste(i + 1); });
        }
        naechste(0);
    }

    ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('aktiv'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('aktiv'); });
    });
    dropzone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files) { ladeHoch(e.dataTransfer.files); }
    });
    dropzone.addEventListener('click', function () { dateiInput.click(); });
    dateiInput.addEventListener('change', function () {
        if (dateiInput.files.length) { ladeHoch(dateiInput.files); dateiInput.value = ''; }
    });

    // --- Galerie: Löschen + Bearbeiten (Delegation) ---
    galerie.addEventListener('click', function (e) {
        var del = e.target.closest('.adm-bild-del');
        if (del) {
            var id = del.getAttribute('data-id');
            if (!confirm('Dieses Bild wirklich löschen?')) { return; }
            var fd = new FormData(); fd.append('id', id);
            fetch('api/mediathek-delete.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) { var f = bildEl(id); if (f) { f.remove(); aktualisiereAnzahl(-1); } }
                    else { alert(data.error || 'Löschen nicht möglich.'); }
                })
                .catch(function () { alert('Löschen fehlgeschlagen (Netzwerkfehler).'); });
            return;
        }
        var edit = e.target.closest('.adm-bild-edit');
        if (edit) { oeffneEdit(edit.getAttribute('data-id')); }
    });

    // --- Bearbeiten-Dialog ---
    var overlay   = document.getElementById('edit-overlay');
    var editName  = document.getElementById('edit-name');
    var editOrdner= document.getElementById('edit-ordner');
    var editTags  = document.getElementById('edit-tags');
    var aktuelleId = null;

    function oeffneEdit(id) {
        var fig = bildEl(id);
        if (!fig) { return; }
        aktuelleId = id;
        editName.textContent = fig.getAttribute('data-name') || '';
        editOrdner.value = fig.getAttribute('data-ordner') || '';
        editTags.value = fig.getAttribute('data-tags') || '';
        overlay.hidden = false;
        editTags.focus();
    }
    function schliesseEdit() { overlay.hidden = true; aktuelleId = null; }

    document.getElementById('edit-abbrechen').addEventListener('click', schliesseEdit);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliesseEdit(); } });

    document.getElementById('edit-speichern').addEventListener('click', function () {
        if (!aktuelleId) { return; }
        var fd = new FormData();
        fd.append('id', aktuelleId);
        fd.append('ordner_id', editOrdner.value);
        fd.append('tags', editTags.value);
        fetch('api/mediathek-update.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert(data.error || 'Speichern fehlgeschlagen.'); return; }
                var fig = bildEl(aktuelleId);
                var neuerOrdner = data.ordner_id != null ? String(data.ordner_id) : '';
                if (fig) {
                    fig.setAttribute('data-ordner', neuerOrdner);
                    fig.setAttribute('data-tags', (data.tags || []).join(', '));
                    var tagSpan = fig.querySelector('.adm-bild-tags');
                    if (tagSpan) { tagSpan.innerHTML = tagsHtml(data.tags); }
                    // Wenn nach Ordner gefiltert wird und das Bild rausgewandert ist: entfernen
                    var raus = (FILTER_ORDNER === 'none' && neuerOrdner !== '') ||
                               (FILTER_ORDNER !== '' && FILTER_ORDNER !== 'none' && neuerOrdner !== FILTER_ORDNER);
                    if (raus) { fig.remove(); aktualisiereAnzahl(-1); }
                }
                schliesseEdit();
            })
            .catch(function () { alert('Speichern fehlgeschlagen (Netzwerkfehler).'); });
    });

    // --- Ordner-Verwaltung ---
    function ordnerAktion(params, fehlertext) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        return fetch('api/ordner.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert(data.error || fehlertext); return false; }
                return true;
            })
            .catch(function () { alert(fehlertext + ' (Netzwerkfehler).'); return false; });
    }

    document.getElementById('ordner-neu').addEventListener('click', function () {
        var name = prompt('Name des neuen Ordners:');
        if (name == null || name.trim() === '') { return; }
        ordnerAktion({ action: 'create', name: name.trim() }, 'Ordner konnte nicht angelegt werden.')
            .then(function (ok) { if (ok) { location.reload(); } });
    });

    document.querySelector('.adm-ordnerleiste').addEventListener('click', function (e) {
        var ed = e.target.closest('.adm-ordner-edit');
        if (ed) {
            e.preventDefault();
            var name = prompt('Ordner umbenennen:', ed.getAttribute('data-name'));
            if (name == null || name.trim() === '') { return; }
            ordnerAktion({ action: 'rename', id: ed.getAttribute('data-id'), name: name.trim() }, 'Umbenennen fehlgeschlagen.')
                .then(function (ok) { if (ok) { location.reload(); } });
            return;
        }
        var dl = e.target.closest('.adm-ordner-del');
        if (dl) {
            e.preventDefault();
            if (!confirm('Ordner „' + dl.getAttribute('data-name') + '" löschen? Die Bilder bleiben erhalten und landen in „Ohne Ordner".')) { return; }
            ordnerAktion({ action: 'delete', id: dl.getAttribute('data-id') }, 'Löschen fehlgeschlagen.')
                .then(function (ok) { if (ok) { location.href = 'mediathek.php'; } });
        }
    });
})();
</script>

<?php
admin_footer();
