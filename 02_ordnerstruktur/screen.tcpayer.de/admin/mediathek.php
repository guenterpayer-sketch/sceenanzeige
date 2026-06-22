<?php
/**
 * admin/mediathek.php
 *
 * Mediathek in zwei Ebenen (analog Bibliothek):
 *   1. Ordner-Übersicht: Kacheln "Alle Bilder", "Ohne Ordner", je Ordner eine
 *      Kachel (Cover = neuestes Bild + Anzahl), "+ Neuer Ordner".
 *   2. Bild-Ansicht (?ordner=alle|none|<id>): Drag&Drop-Upload (Ziel = dieser
 *      Ordner), Suche, Tag-Filter, Bearbeiten (Anzeigename/Ordner/Tags), Löschen.
 *
 * Upload/Bearbeiten/Löschen laufen per fetch über admin/api/*; Ordner-Verwaltung
 * lädt danach neu.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$ordnerParam = $_GET['ordner'] ?? null;                 // null = Übersicht
$suche       = trim((string)($_GET['q'] ?? ''));
$tagFilter   = isset($_GET['tag']) && ctype_digit((string)$_GET['tag']) ? (int)$_GET['tag'] : 0;

// Übersicht nur ohne jeden Filter; sobald gesucht/getaggt wird → Bild-Ansicht ("alle").
$view = ($ordnerParam === null && $suche === '' && $tagFilter === 0) ? 'uebersicht' : 'bilder';

$ordnerListe  = MediathekOrdner::listAllMitAnzahl();
$uploadsBasis = rtrim(UPLOADS_URL, '/') . '/';
$pdo = get_pdo();
$gesamt     = (int)$pdo->query('SELECT COUNT(*) FROM mediathek')->fetchColumn();
$ohneOrdner = (int)$pdo->query('SELECT COUNT(*) FROM mediathek WHERE ordner_id IS NULL')->fetchColumn();

/** Cover-URL (neuestes Bild) für eine WHERE-Bedingung. */
function cover_url(string $where, array $params, string $basis): ?string
{
    $stmt = get_pdo()->prepare("SELECT dateiname FROM mediathek WHERE $where ORDER BY hochgeladen_am DESC, id DESC LIMIT 1");
    $stmt->execute($params);
    $d = $stmt->fetchColumn();
    return $d ? $basis . rawurlencode($d) : null;
}

if ($view === 'uebersicht') {
    admin_header('Mediathek', 'mediathek');
    ?>
    <p class="adm-hilfe">
        Wähle einen Ordner oder „Alle Bilder". Bilder lädst du in der Ordner-Ansicht per
        Drag&amp;Drop hoch; gleiche Bilder werden erkannt und nicht doppelt gespeichert.
    </p>
    <div class="adm-ordnergrid">
        <a class="adm-ordnerkachel" href="mediathek.php?ordner=alle">
            <span class="adm-ordner-cover" style="<?= ($c = cover_url('1=1', [], $uploadsBasis)) ? "background-image:url('" . htmlspecialchars($c) . "')" : '' ?>"><?= $c ? '' : '🖼️' ?></span>
            <span class="adm-ordner-titel">Alle Bilder</span>
            <span class="adm-ordner-zahl"><?= $gesamt ?></span>
        </a>
        <a class="adm-ordnerkachel" href="mediathek.php?ordner=none">
            <span class="adm-ordner-cover" style="<?= ($c = cover_url('ordner_id IS NULL', [], $uploadsBasis)) ? "background-image:url('" . htmlspecialchars($c) . "')" : '' ?>"><?= $c ? '' : '🗂️' ?></span>
            <span class="adm-ordner-titel">Ohne Ordner</span>
            <span class="adm-ordner-zahl"><?= $ohneOrdner ?></span>
        </a>
        <?php foreach ($ordnerListe as $o): ?>
            <div class="adm-ordnerkachel-wrap">
                <a class="adm-ordnerkachel" href="mediathek.php?ordner=<?= (int)$o['id'] ?>">
                    <span class="adm-ordner-cover" style="<?= ($c = cover_url('ordner_id = ?', [(int)$o['id']], $uploadsBasis)) ? "background-image:url('" . htmlspecialchars($c) . "')" : '' ?>"><?= $c ? '' : '📁' ?></span>
                    <span class="adm-ordner-titel"><?= htmlspecialchars($o['name']) ?></span>
                    <span class="adm-ordner-zahl"><?= (int)$o['anzahl'] ?></span>
                </a>
                <div class="adm-ordner-tools">
                    <button type="button" class="adm-ordner-edit" data-id="<?= (int)$o['id'] ?>" data-name="<?= htmlspecialchars($o['name']) ?>" title="Umbenennen">✎</button>
                    <button type="button" class="adm-ordner-del" data-id="<?= (int)$o['id'] ?>" data-name="<?= htmlspecialchars($o['name']) ?>" title="Ordner löschen">×</button>
                </div>
            </div>
        <?php endforeach; ?>
        <button type="button" id="ordner-neu" class="adm-ordnerkachel adm-ordner-neu">
            <span class="adm-ordner-cover">＋</span>
            <span class="adm-ordner-titel">Neuer Ordner</span>
        </button>
    </div>

    <script>
    (function () {
        function ordnerAktion(params, fehlertext) {
            var fd = new FormData();
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
            return fetch('api/ordner.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (!data.ok) { alert(data.error || fehlertext); return false; } return true; })
                .catch(function () { alert(fehlertext + ' (Netzwerkfehler).'); return false; });
        }
        document.getElementById('ordner-neu').addEventListener('click', function () {
            var name = prompt('Name des neuen Ordners:');
            if (name == null || name.trim() === '') { return; }
            ordnerAktion({ action: 'create', name: name.trim() }, 'Ordner konnte nicht angelegt werden.')
                .then(function (ok) { if (ok) { location.reload(); } });
        });
        document.querySelector('.adm-ordnergrid').addEventListener('click', function (e) {
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
                    .then(function (ok) { if (ok) { location.reload(); } });
            }
        });
    })();
    </script>
    <?php
    admin_footer();
    exit;
}

// ===== Bild-Ansicht =====
$filter = [];
$aktuellerName = 'Alle Bilder';
$uploadOrdnerId = null;        // Ziel für neue Uploads (Ohne Ordner, falls null)
$filterOrdnerJs = '';          // für das Edit-Dialog-Verhalten ('' = alle, 'none', '<id>')

if ($ordnerParam === 'none') {
    $filter['ordner'] = 'none';
    $aktuellerName = 'Ohne Ordner';
    $filterOrdnerJs = 'none';
} elseif (ctype_digit((string)$ordnerParam)) {
    $oid = (int)$ordnerParam;
    $filter['ordner'] = $oid;
    $uploadOrdnerId = $oid;
    $filterOrdnerJs = (string)$oid;
    foreach ($ordnerListe as $o) { if ((int)$o['id'] === $oid) { $aktuellerName = $o['name']; } }
}
if ($suche !== '')  { $filter['suche'] = $suche; }
if ($tagFilter > 0) { $filter['tag'] = $tagFilter; }

$tagListe = MediathekTag::listAllMitAnzahl();
$bilder   = Mediathek::listAll($filter);

/** Galerie-URL mit geänderten Parametern (Rest bleibt erhalten). */
function mt_url(array $aenderung): string
{
    $p = ['ordner' => $_GET['ordner'] ?? 'alle', 'q' => $_GET['q'] ?? '', 'tag' => $_GET['tag'] ?? ''];
    foreach ($aenderung as $k => $v) { $p[$k] = $v; }
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'mediathek.php' . ($p ? '?' . http_build_query($p) : '');
}

admin_header('Mediathek – ' . $aktuellerName, 'mediathek');
?>

<p><a href="mediathek.php" class="adm-zurueck">← zurück zur Ordnerübersicht</a></p>

<h1 style="margin-top:0;"><?= htmlspecialchars($aktuellerName) ?></h1>

<div id="dropzone" class="adm-dropzone" data-ordner="<?= $uploadOrdnerId !== null ? $uploadOrdnerId : '' ?>">
    <p>Bilder hierher ziehen oder <span class="adm-link">auswählen</span> — Ziel: <strong><?= htmlspecialchars($aktuellerName === 'Alle Bilder' ? 'Ohne Ordner' : $aktuellerName) ?></strong></p>
    <input type="file" id="dateiauswahl" accept="image/*" multiple hidden>
</div>
<div id="upload-status" class="adm-upload-status" hidden></div>

<div class="adm-filterzeile">
    <form method="get" class="adm-suche">
        <input type="hidden" name="ordner" value="<?= htmlspecialchars((string)$ordnerParam) ?>">
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

<h2>Bilder (<span id="anzahl"><?= count($bilder) ?></span>)</h2>

<div id="galerie" class="adm-galerie"
     data-uploads="<?= htmlspecialchars($uploadsBasis) ?>"
     data-filter-ordner="<?= htmlspecialchars($filterOrdnerJs) ?>">
    <?php if (empty($bilder)): ?>
        <p id="leer-hinweis" class="adm-leer">Keine Bilder in dieser Ansicht.</p>
    <?php endif; ?>
    <?php foreach ($bilder as $b): ?>
        <?php $tagText = implode(', ', $b['tags']); ?>
        <figure class="adm-bild" data-id="<?= (int)$b['id'] ?>"
                data-ordner="<?= $b['ordner_id'] !== null ? (int)$b['ordner_id'] : '' ?>"
                data-tags="<?= htmlspecialchars($tagText) ?>"
                data-dateiname="<?= htmlspecialchars((string)$b['dateiname']) ?>"
                data-name="<?= htmlspecialchars((string)($b['original_name'] ?? '')) ?>">
            <img src="<?= htmlspecialchars($uploadsBasis . rawurlencode($b['dateiname'])) ?>" alt="<?= htmlspecialchars((string)$b['original_name']) ?>" loading="lazy">
            <figcaption>
                <span class="adm-bild-name" title="<?= htmlspecialchars((string)$b['original_name']) ?>"><?= htmlspecialchars((string)($b['original_name'] ?? $b['dateiname'])) ?></span>
                <span class="adm-bild-meta"><?= (int)$b['breite'] ?>×<?= (int)$b['hoehe'] ?> px</span>
                <span class="adm-bild-tags"><?php foreach ($b['tags'] as $tg): ?><span class="adm-minitag"><?= htmlspecialchars($tg) ?></span><?php endforeach; ?></span>
            </figcaption>
            <button type="button" class="adm-bild-edit" data-id="<?= (int)$b['id'] ?>" title="Anzeigename, Ordner &amp; Tags bearbeiten">✎</button>
            <button type="button" class="adm-bild-del" data-id="<?= (int)$b['id'] ?>" title="Löschen">×</button>
        </figure>
    <?php endforeach; ?>
</div>

<!-- Bearbeiten-Dialog -->
<div id="edit-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog">
        <h3>Bild bearbeiten</h3>
        <p id="edit-datei" class="adm-dialog-name"></p>
        <label class="adm-feld">
            <span>Anzeigename</span>
            <input type="text" id="edit-anzeige" placeholder="z.B. Logo Tanzschule">
        </label>
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
    var FILTER_ORDNER = galerie.getAttribute('data-filter-ordner');
    var UPLOAD_ORDNER = dropzone.getAttribute('data-ordner');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function setStatus(t) { if (!t) { statusBox.hidden = true; statusBox.innerHTML = ''; return; } statusBox.hidden = false; statusBox.innerHTML = t; }
    function aktualisiereAnzahl(d) { anzahlEl.textContent = String(Math.max(0, parseInt(anzahlEl.textContent, 10) + d)); }
    function entferneLeerHinweis() { var h = document.getElementById('leer-hinweis'); if (h) { h.remove(); } }
    function bildEl(id) { return galerie.querySelector('.adm-bild[data-id="' + id + '"]'); }
    function tagsHtml(tags) { return (tags || []).map(function (t) { return '<span class="adm-minitag">' + escapeHtml(t) + '</span>'; }).join(''); }

    function ergaenzeKachel(e) {
        entferneLeerHinweis();
        var anzeige = e.original_name || e.dateiname;
        var fig = document.createElement('figure');
        fig.className = 'adm-bild adm-neu';
        fig.setAttribute('data-id', e.id);
        fig.setAttribute('data-ordner', e.ordner_id != null ? e.ordner_id : '');
        fig.setAttribute('data-tags', (e.tags || []).join(', '));
        fig.setAttribute('data-dateiname', e.dateiname);
        fig.setAttribute('data-name', e.original_name || '');
        fig.innerHTML =
            '<img src="' + escapeHtml(e.url) + '" alt="' + escapeHtml(anzeige) + '">' +
            '<figcaption>' +
                '<span class="adm-bild-name" title="' + escapeHtml(anzeige) + '">' + escapeHtml(anzeige) + '</span>' +
                '<span class="adm-bild-meta">' + (e.breite || '?') + '×' + (e.hoehe || '?') + ' px</span>' +
                '<span class="adm-bild-tags">' + tagsHtml(e.tags) + '</span>' +
            '</figcaption>' +
            '<button type="button" class="adm-bild-edit" data-id="' + e.id + '" title="bearbeiten">✎</button>' +
            '<button type="button" class="adm-bild-del" data-id="' + e.id + '" title="Löschen">×</button>';
        galerie.insertBefore(fig, galerie.firstChild);
    }

    function ladeHoch(files) {
        var liste = Array.prototype.slice.call(files).filter(function (f) { return f.type.indexOf('image/') === 0; });
        if (liste.length === 0) { return; }
        var neu = 0, dup = 0, fehler = 0;
        function naechste(i) {
            if (i >= liste.length) { setStatus('Fertig: ' + neu + ' neu, ' + dup + ' Duplikat(e)' + (fehler ? ', ' + fehler + ' Fehler' : '') + '.'); return; }
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
                        var v = bildEl(data.eintrag.id);
                        if (v) { v.classList.add('adm-dup-blink'); setTimeout(function () { v.classList.remove('adm-dup-blink'); }, 1500); }
                    } else { neu++; ergaenzeKachel(data.eintrag); aktualisiereAnzahl(1); }
                })
                .catch(function (err) { fehler++; console.error(err); })
                .finally(function () { naechste(i + 1); });
        }
        naechste(0);
    }

    ['dragenter', 'dragover'].forEach(function (ev) { dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('aktiv'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('aktiv'); }); });
    dropzone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) { ladeHoch(e.dataTransfer.files); } });
    dropzone.addEventListener('click', function () { dateiInput.click(); });
    dateiInput.addEventListener('change', function () { if (dateiInput.files.length) { ladeHoch(dateiInput.files); dateiInput.value = ''; } });

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

    var overlay     = document.getElementById('edit-overlay');
    var editDatei   = document.getElementById('edit-datei');
    var editAnzeige = document.getElementById('edit-anzeige');
    var editOrdner  = document.getElementById('edit-ordner');
    var editTags    = document.getElementById('edit-tags');
    var aktuelleId = null;

    function oeffneEdit(id) {
        var fig = bildEl(id); if (!fig) { return; }
        aktuelleId = id;
        editDatei.textContent = 'Datei: ' + (fig.getAttribute('data-dateiname') || '');
        editAnzeige.value = fig.getAttribute('data-name') || '';
        editOrdner.value = fig.getAttribute('data-ordner') || '';
        editTags.value = fig.getAttribute('data-tags') || '';
        overlay.hidden = false; editAnzeige.focus();
    }
    function schliesseEdit() { overlay.hidden = true; aktuelleId = null; }
    document.getElementById('edit-abbrechen').addEventListener('click', schliesseEdit);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliesseEdit(); } });

    document.getElementById('edit-speichern').addEventListener('click', function () {
        if (!aktuelleId) { return; }
        var fd = new FormData();
        fd.append('id', aktuelleId);
        fd.append('original_name', editAnzeige.value);
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
                    fig.setAttribute('data-name', data.original_name || '');
                    var anzeige = data.original_name || fig.getAttribute('data-dateiname') || '';
                    var nameSpan = fig.querySelector('.adm-bild-name');
                    if (nameSpan) { nameSpan.textContent = anzeige; nameSpan.title = anzeige; }
                    var imgEl = fig.querySelector('img'); if (imgEl) { imgEl.alt = anzeige; }
                    var tagSpan = fig.querySelector('.adm-bild-tags'); if (tagSpan) { tagSpan.innerHTML = tagsHtml(data.tags); }
                    var raus = (FILTER_ORDNER === 'none' && neuerOrdner !== '') ||
                               (FILTER_ORDNER !== '' && FILTER_ORDNER !== 'none' && neuerOrdner !== FILTER_ORDNER);
                    if (raus) { fig.remove(); aktualisiereAnzahl(-1); }
                }
                schliesseEdit();
            })
            .catch(function () { alert('Speichern fehlgeschlagen (Netzwerkfehler).'); });
    });
})();
</script>

<?php
admin_footer();
