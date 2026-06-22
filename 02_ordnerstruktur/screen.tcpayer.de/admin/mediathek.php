<?php
/**
 * admin/mediathek.php
 *
 * Mediathek-Galerie (Schritt 5a). Zeigt alle hochgeladenen Bilder und bietet
 * einen Drag&Drop-/Sammel-Upload mit Duplikat-Erkennung. Upload und Löschen
 * laufen per fetch über admin/api/* ohne Page-Reload.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$bilder = Mediathek::listAll();
$uploadsBasis = rtrim(UPLOADS_URL, '/') . '/';

admin_header('Mediathek', 'mediathek');
?>

<p class="adm-hilfe">
    Bilder per <strong>Drag&amp;Drop</strong> in die Fläche ziehen oder anklicken zum Auswählen.
    Gleiche Bilder werden automatisch erkannt und nicht doppelt gespeichert.
    Ein Bild lässt sich nur löschen, wenn es von keiner Modul-Instanz mehr verwendet wird.
</p>

<div id="dropzone" class="adm-dropzone">
    <p>Bilder hierher ziehen oder <span class="adm-link">auswählen</span></p>
    <input type="file" id="dateiauswahl" accept="image/*" multiple hidden>
</div>

<div id="upload-status" class="adm-upload-status" hidden></div>

<h2>Alle Bilder (<span id="anzahl"><?= count($bilder) ?></span>)</h2>

<div id="galerie" class="adm-galerie">
    <?php if (empty($bilder)): ?>
        <p id="leer-hinweis" class="adm-leer">Noch keine Bilder in der Mediathek.</p>
    <?php endif; ?>
    <?php foreach ($bilder as $b): ?>
        <figure class="adm-bild" data-id="<?= (int)$b['id'] ?>">
            <img src="<?= htmlspecialchars($uploadsBasis . rawurlencode($b['dateiname'])) ?>" alt="<?= htmlspecialchars((string)$b['original_name']) ?>" loading="lazy">
            <figcaption>
                <span class="adm-bild-name" title="<?= htmlspecialchars((string)$b['original_name']) ?>"><?= htmlspecialchars((string)($b['original_name'] ?? $b['dateiname'])) ?></span>
                <span class="adm-bild-meta"><?= (int)$b['breite'] ?>×<?= (int)$b['hoehe'] ?> px</span>
            </figcaption>
            <button type="button" class="adm-bild-del" data-id="<?= (int)$b['id'] ?>" title="Löschen">×</button>
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
    var UPLOADS    = <?= json_encode($uploadsBasis) ?>;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function setStatus(text) {
        if (!text) { statusBox.hidden = true; statusBox.innerHTML = ''; return; }
        statusBox.hidden = false;
        statusBox.innerHTML = text;
    }

    function aktualisiereAnzahl(delta) {
        anzahlEl.textContent = String(Math.max(0, parseInt(anzahlEl.textContent, 10) + delta));
    }

    function entferneLeerHinweis() {
        var h = document.getElementById('leer-hinweis');
        if (h) { h.remove(); }
    }

    function ergaenzeKachel(e) {
        entferneLeerHinweis();
        var fig = document.createElement('figure');
        fig.className = 'adm-bild adm-neu';
        fig.setAttribute('data-id', e.id);
        fig.innerHTML =
            '<img src="' + escapeHtml(e.url) + '" alt="' + escapeHtml(e.original_name || '') + '">' +
            '<figcaption>' +
                '<span class="adm-bild-name" title="' + escapeHtml(e.original_name || '') + '">' + escapeHtml(e.original_name || e.dateiname) + '</span>' +
                '<span class="adm-bild-meta">' + (e.breite || '?') + '×' + (e.hoehe || '?') + ' px</span>' +
            '</figcaption>' +
            '<button type="button" class="adm-bild-del" data-id="' + e.id + '" title="Löschen">×</button>';
        galerie.insertBefore(fig, galerie.firstChild);
    }

    function bildVorhanden(id) {
        return galerie.querySelector('.adm-bild[data-id="' + id + '"]');
    }

    function ladeHoch(files) {
        var liste = Array.prototype.slice.call(files).filter(function (f) {
            return f.type.indexOf('image/') === 0;
        });
        if (liste.length === 0) { return; }

        var fertig = 0, neu = 0, dup = 0, fehler = 0;

        function naechste(i) {
            if (i >= liste.length) {
                setStatus('Fertig: ' + neu + ' neu, ' + dup + ' Duplikat(e)' + (fehler ? ', ' + fehler + ' Fehler' : '') + '.');
                return;
            }
            var fd = new FormData();
            fd.append('datei', liste[i]);
            setStatus('Lade hoch … (' + (i + 1) + '/' + liste.length + ')');

            fetch('api/mediathek-upload.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    fertig++;
                    if (!data.ok) {
                        fehler++;
                        console.error('Upload-Fehler:', data.error);
                    } else if (data.duplikat) {
                        dup++;
                        // schon vorhanden -> nur kurz hervorheben, nicht doppelt einfügen
                        var vorhanden = bildVorhanden(data.eintrag.id);
                        if (vorhanden) {
                            vorhanden.classList.add('adm-dup-blink');
                            setTimeout(function () { vorhanden.classList.remove('adm-dup-blink'); }, 1500);
                        } else {
                            ergaenzeKachel(data.eintrag);
                            aktualisiereAnzahl(1);
                        }
                    } else {
                        neu++;
                        ergaenzeKachel(data.eintrag);
                        aktualisiereAnzahl(1);
                    }
                })
                .catch(function (err) {
                    fertig++; fehler++;
                    console.error(err);
                })
                .finally(function () { naechste(i + 1); });
        }
        naechste(0);
    }

    // --- Drag & Drop ---
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

    // --- Löschen (Event-Delegation) ---
    galerie.addEventListener('click', function (e) {
        var btn = e.target.closest('.adm-bild-del');
        if (!btn) { return; }
        var id = btn.getAttribute('data-id');
        if (!confirm('Dieses Bild wirklich löschen?')) { return; }

        var fd = new FormData();
        fd.append('id', id);
        fetch('api/mediathek-delete.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    var fig = bildVorhanden(id);
                    if (fig) { fig.remove(); aktualisiereAnzahl(-1); }
                } else {
                    alert(data.error || 'Löschen nicht möglich.');
                }
            })
            .catch(function () { alert('Löschen fehlgeschlagen (Netzwerkfehler).'); });
    });
})();
</script>

<?php
admin_footer();
