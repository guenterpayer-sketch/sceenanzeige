<?php
/**
 * admin/videothek.php
 *
 * Eigener Menüpunkt "Videos" (bewusst getrennt von der Mediathek — siehe
 * CLAUDE.md Abschnitt "Version A"). Verwaltung der eigenen Videodateien
 * (video_dateien): Drag&Drop-Upload, Liste, Löschen. Keine Ordner/Tags
 * (anders als die Mediathek) — bei wenigen Videos nicht nötig.
 *
 * Upload/Löschen laufen per fetch über admin/api/video-*.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$uploadsBasis = rtrim(UPLOADS_URL, '/') . '/';
$videos = Videothek::listAll();

admin_header('Videos', 'videos');
?>

<p class="adm-hilfe">
    Eigene Videodateien (MP4/WebM) für das Video-Modul. Hier hochladen, danach im
    Video-Modul-Editor in der <a href="bibliothek.php">Bibliothek</a> auswählen.
    Gleiche Dateien werden erkannt und nicht doppelt gespeichert.
</p>

<div id="dropzone" class="adm-dropzone">
    <p>Videos hierher ziehen oder <span class="adm-link">auswählen</span></p>
    <input type="file" id="dateiauswahl" accept="video/mp4,video/webm" multiple hidden>
</div>
<div id="upload-status" class="adm-upload-status" hidden></div>

<h2>Videos (<span id="anzahl"><?= count($videos) ?></span>)</h2>

<div id="galerie" class="adm-galerie" data-uploads="<?= htmlspecialchars($uploadsBasis) ?>">
    <?php if (empty($videos)): ?>
        <p id="leer-hinweis" class="adm-leer">Noch keine Videos hochgeladen.</p>
    <?php endif; ?>
    <?php foreach ($videos as $v): ?>
        <figure class="adm-bild" data-id="<?= (int)$v['id'] ?>"
                data-dateiname="<?= htmlspecialchars((string)$v['dateiname']) ?>">
            <video src="<?= htmlspecialchars($uploadsBasis . rawurlencode($v['dateiname'])) ?>" muted preload="metadata"></video>
            <figcaption>
                <span class="adm-bild-name" title="<?= htmlspecialchars((string)$v['original_name']) ?>"><?= htmlspecialchars((string)($v['original_name'] ?? $v['dateiname'])) ?></span>
                <span class="adm-bild-meta"><?= $v['dauer_sek'] !== null ? (int)$v['dauer_sek'] . ' Sek.' : 'Laufzeit unbekannt' ?></span>
            </figcaption>
            <button type="button" class="adm-bild-del" data-id="<?= (int)$v['id'] ?>" title="Löschen">×</button>
        </figure>
    <?php endforeach; ?>
</div>

<script>
(function () {
    var dropzone   = document.getElementById('dropzone');
    var dateiInput = document.getElementById('dateiauswahl');
    var galerie    = document.getElementById('galerie');
    var statusBox  = document.getElementById('upload-status');
    var anzahlEl   = document.getElementById('anzahl');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function setStatus(t) { if (!t) { statusBox.hidden = true; statusBox.innerHTML = ''; return; } statusBox.hidden = false; statusBox.innerHTML = t; }
    function aktualisiereAnzahl(d) { anzahlEl.textContent = String(Math.max(0, parseInt(anzahlEl.textContent, 10) + d)); }
    function entferneLeerHinweis() { var h = document.getElementById('leer-hinweis'); if (h) { h.remove(); } }
    function videoEl(id) { return galerie.querySelector('.adm-bild[data-id="' + id + '"]'); }

    function ergaenzeKachel(e) {
        entferneLeerHinweis();
        var anzeige = e.original_name || e.dateiname;
        var fig = document.createElement('figure');
        fig.className = 'adm-bild adm-neu';
        fig.setAttribute('data-id', e.id);
        fig.setAttribute('data-dateiname', e.dateiname);
        fig.innerHTML =
            '<video src="' + escapeHtml(e.url) + '" muted preload="metadata"></video>' +
            '<figcaption>' +
                '<span class="adm-bild-name" title="' + escapeHtml(anzeige) + '">' + escapeHtml(anzeige) + '</span>' +
                '<span class="adm-bild-meta">' + (e.dauer_sek ? e.dauer_sek + ' Sek.' : 'Laufzeit unbekannt') + '</span>' +
            '</figcaption>' +
            '<button type="button" class="adm-bild-del" data-id="' + e.id + '" title="Löschen">×</button>';
        galerie.insertBefore(fig, galerie.firstChild);
    }

    // Laufzeit im Browser ermitteln (kein ffprobe auf all-inkl verfügbar);
    // dient nur als grobe Schätzung für die Spalten-Synchronisation im Monitor.
    function ermittleDauer(file) {
        return new Promise(function (resolve) {
            var v = document.createElement('video');
            v.preload = 'metadata';
            v.onloadedmetadata = function () {
                URL.revokeObjectURL(v.src);
                var d = isFinite(v.duration) ? Math.round(v.duration) : null;
                resolve(d);
            };
            v.onerror = function () { resolve(null); };
            v.src = URL.createObjectURL(file);
        });
    }

    function ladeHoch(files) {
        var liste = Array.prototype.slice.call(files).filter(function (f) {
            return f.type === 'video/mp4' || f.type === 'video/webm';
        });
        if (liste.length === 0) { return; }
        var neu = 0, dup = 0, fehler = 0;
        function naechste(i) {
            if (i >= liste.length) { setStatus('Fertig: ' + neu + ' neu, ' + dup + ' Duplikat(e)' + (fehler ? ', ' + fehler + ' Fehler' : '') + '.'); return; }
            setStatus('Lade hoch … (' + (i + 1) + '/' + liste.length + ')');
            ermittleDauer(liste[i]).then(function (dauer) {
                var fd = new FormData();
                fd.append('datei', liste[i]);
                if (dauer) { fd.append('dauer_sek', dauer); }
                return fetch('api/video-upload.php', { method: 'POST', body: fd });
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { fehler++; console.error('Upload-Fehler:', data.error); return; }
                    if (data.duplikat) {
                        dup++;
                        var v = videoEl(data.eintrag.id);
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
        if (!del) { return; }
        var id = del.getAttribute('data-id');
        admBestaetigen('Dieses Video wirklich löschen?', function (ok) {
            if (!ok) { return; }
            var fd = new FormData(); fd.append('id', id);
            fetch('api/video-delete.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) { var f = videoEl(id); if (f) { f.remove(); aktualisiereAnzahl(-1); } }
                    else { admMeldung(data.error || 'Löschen nicht möglich.'); }
                })
                .catch(function () { admMeldung('Löschen fehlgeschlagen (Netzwerkfehler).'); });
        }, 'Löschen');
    });
})();
</script>

<?php
admin_footer();
