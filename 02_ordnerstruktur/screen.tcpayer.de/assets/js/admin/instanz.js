/**
 * assets/js/admin/instanz.js
 *
 * Gesamte Editor-Logik der Instanz-Seite (Inhalte-Editor mit Bild-/Video-
 * Picker, Dirty-Guard, Setting-Bild-Picker, Stundenplan-Standortwahl).
 * Ausgelagert aus admin/instanz.php, damit die PHP-Seite schlank bleibt.
 *
 * Daten kommen aus window.TM_INST (inline in instanz.php gesetzt):
 *   { modulTyp, stdDauer, start }
 * Eingebunden mit filemtime-Cache-Buster. Welche Abschnitte aktiv werden,
 * entscheidet das Vorhandensein der jeweiligen DOM-Elemente (die PHP-Seite
 * rendert Overlays/Editor nur für passende Modul-Typen).
 */

// ---- Inhalte-Editor (nur Module mit has_inhalte: bild/ankuendigung/video) ----
(function () {
    var liste = document.getElementById('inhalte-liste');
    if (!liste) { return; }

    var MODUL_TYP = window.TM_INST.modulTyp;
    var STD_DAUER = window.TM_INST.stdDauer;
    var START     = window.TM_INST.start;

    var _dirty = false;

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
    // Initialaufbau zählt nicht als Nutzer-Änderung.
    _dirty = false;

    document.getElementById('zeile-hinzu').addEventListener('click', function () {
        neueZeile({}); _dirty = true;
    });

    // Zeilen-Aktionen (Delegation)
    liste.addEventListener('click', function (e) {
        var zeile = e.target.closest('.adm-inhalt-zeile');
        if (!zeile) { return; }

        if (e.target.closest('.adm-bild-waehlen')) { oeffnePicker(zeile); return; }
        if (e.target.closest('.adm-bild-entfernen')) {
            zeile.querySelector('[data-feld="mediathek_id"]').value = '';
            zeile.querySelector('.adm-inhalt-vorschau').innerHTML = bildVorschau(null);
            _dirty = true;
            return;
        }
        if (e.target.closest('.adm-video-waehlen')) { oeffneVideoPicker(zeile); return; }
        var akt = e.target.getAttribute('data-akt');
        if (akt === 'weg') {
            admBestaetigen('Diesen Eintrag entfernen?', function (ok) { if (ok) { zeile.remove(); _dirty = true; } }, 'Entfernen');
            return;
        }
        if (akt === 'hoch'   && zeile.previousElementSibling) { liste.insertBefore(zeile, zeile.previousElementSibling); _dirty = true; }
        if (akt === 'runter' && zeile.nextElementSibling)     { liste.insertBefore(zeile.nextElementSibling, zeile); _dirty = true; }
    });

    // Vor dem Absenden: Feldnamen sequenziell nach DOM-Reihenfolge vergeben
    var instForm = document.getElementById('instanz-form');
    instForm.addEventListener('submit', function () {
        _dirty = false;
        var zeilen = liste.querySelectorAll('.adm-inhalt-zeile');
        zeilen.forEach(function (zeile, i) {
            zeile.querySelectorAll('[data-feld]').forEach(function (feld) {
                feld.setAttribute('name', 'inhalt[' + i + '][' + feld.getAttribute('data-feld') + ']');
            });
        });
    });
    instForm.addEventListener('input', function (e) { if (e.isTrusted) { _dirty = true; } });
    instForm.addEventListener('change', function (e) { if (e.isTrusted) { _dirty = true; } });
    window.addEventListener('beforeunload', function (e) {
        if (_dirty) { e.preventDefault(); e.returnValue = ''; }
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
        _dirty = true;
        schliessePicker();
    }

    // ---- Video-Picker ----
    var videoOverlay = document.getElementById('video-picker-overlay');
    var oeffneVideoPicker = function () {};
    if (videoOverlay) {
        var videoGalerie  = document.getElementById('video-picker-galerie');
        var videoZielZeile = null;
        var videoGeladen   = false;

        oeffneVideoPicker = function (zeile) {
            videoZielZeile = zeile;
            videoOverlay.hidden = false;
            ladeVideoPicker();
        };
        var schliesseVideoPicker = function () { videoOverlay.hidden = true; videoZielZeile = null; };

        document.getElementById('video-picker-abbrechen').addEventListener('click', schliesseVideoPicker);
        videoOverlay.addEventListener('click', function (e) { if (e.target === videoOverlay) { schliesseVideoPicker(); } });

        var ladeVideoPicker = function () {
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
        };

        var waehleVideo = function (v) {
            if (!videoZielZeile) { return; }
            videoZielZeile.querySelector('[data-feld="video_datei_id"]').value = v.id;
            videoZielZeile.querySelector('.adm-inhalt-vorschau').innerHTML = videoVorschau({ video_url: v.url });
            var embedFeld = videoZielZeile.querySelector('.adm-video-embed-feld');
            if (embedFeld) { embedFeld.value = ''; }
            schliesseVideoPicker();
        };
    }
})();

// ---- Dirty-Guard für Module OHNE Inhalte (uhrzeit, stundenplan, fret, …) ----
(function () {
    if (document.getElementById('inhalte-liste')) { return; } // hat schon einen Guard oben
    var _dirty = false;
    var instForm = document.getElementById('instanz-form');
    instForm.addEventListener('input', function (e) { if (e.isTrusted) { _dirty = true; } });
    instForm.addEventListener('change', function (e) { if (e.isTrusted) { _dirty = true; } });
    instForm.addEventListener('submit', function () { _dirty = false; });
    window.addEventListener('beforeunload', function (e) {
        if (_dirty) { e.preventDefault(); e.returnValue = ''; }
    });
})();

// ---- Bild-Picker für mediathek_bild-Einstellungsfelder (z.B. Uhr-Hintergrund) ----
// Unabhängig vom Inhalte-Picker; greift auch bei Modulen ohne Inhalte.
(function () {
    var overlay = document.getElementById('setting-bild-overlay');
    if (!overlay) { return; }
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

// ---- Stundenplan: Standort-Checkboxen + abhängiges Saal-Dropdown ----
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
