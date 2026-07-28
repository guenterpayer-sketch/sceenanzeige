/**
 * assets/js/admin/playlist-editor.js
 *
 * Gesamte Editor-Logik des Playlist-Editors (Layout-Wahl, Spalten-Editor mit
 * Drag&Drop, Instanz-Picker, schematische Vorschau + Pixel-Info, Dirty-Guard).
 * Ausgelagert aus admin/playlist-editor.php, damit die PHP-Seite schlank bleibt.
 *
 * Daten kommen aus window.TM_PLED (inline in playlist-editor.php gesetzt):
 *   { start }  — bereits zugewiesene Spalten-Inhalte
 * Eingebunden mit filemtime-Cache-Buster.
 */
(function () {
    var START = window.TM_PLED.start;
    var ICONS = { clock:'🕒', image:'🖼️', calendar:'📅', megaphone:'📢', music:'🎵' };

    var _dirty = false;
    var spaltenWrap   = document.getElementById('spalten');
    var breitenBlock  = document.getElementById('breiten-block');
    var slider        = document.getElementById('spalte1_breite');
    var anzeige       = document.getElementById('breiten-anzeige');
    var breitenHinweis= document.getElementById('breiten-hinweis');
    var vorschauSp    = document.getElementById('vorschau-spalten');
    var vHeader       = document.getElementById('vorschau-header');
    var vFooter       = document.getElementById('vorschau-footer');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
    function icon(name) { return ICONS[name] || '🧩'; }

    function aktivesLayout() {
        var r = document.querySelector('input[name="layout_id"]:checked');
        if (!r) { r = document.querySelector('input[name="layout_id"]'); r.checked = true; }
        return r;
    }
    function anzSpalten() { return parseInt(aktivesLayout().getAttribute('data-spalten'), 10) || 1; }

    // ---- Breiten je Layout ----
    function breiten() {
        var n = anzSpalten();
        if (n === 1) { return [100]; }
        if (n === 2) { var b1 = parseInt(slider.value, 10) || 50; return [b1, 100 - b1]; }
        return [34, 33, 33]; // 3 Spalten immer gleich
    }

    function aktualisiereBreitenUI() {
        var n = anzSpalten();
        var frei = aktivesLayout().getAttribute('data-frei') === '1';
        breitenBlock.style.display = (n === 2 && frei) ? '' : 'none';
        if (n === 2) {
            var b = breiten();
            anzeige.textContent = b[0] + ' % / ' + b[1] + ' %';
            breitenHinweis.textContent = 'Regler verschiebt das Verhältnis der beiden Spalten.';
        } else if (n === 3) {
            breitenHinweis.textContent = '';
        }
    }

    // ---- Schematische Vorschau + Pixel-Info ----
    function aktualisiereVorschau() {
        var b = breiten();
        var hatHeader = document.getElementById('header_sichtbar').checked;
        var hatFooter = document.getElementById('footer_ticker').checked;
        vorschauSp.innerHTML = '';
        b.forEach(function (br, i) {
            var sp = document.createElement('div');
            sp.className = 'adm-vorschau-spalte';
            sp.style.flex = br;
            sp.textContent = 'Spalte ' + (i + 1) + ' · ' + br + ' %';
            vorschauSp.appendChild(sp);
        });
        vHeader.style.display = hatHeader ? '' : 'none';
        vFooter.style.display = hatFooter ? '' : 'none';

        // Pixel-Größen (für Canva / Grafik-Tools)
        var pxEl = document.getElementById('px-info');
        if (pxEl) {
            var contentH = 1080 - (hatHeader ? 80 : 0) - (hatFooter ? 70 : 0);
            var zeilen = ['<strong>Pixel-Größen (für Canva / Grafik-Tools):</strong>'];
            zeilen.push('Gesamtschirm: 1920 × 1080 px');
            if (hatHeader) { zeilen.push('Header: 1920 × 80 px'); }
            zeilen.push('Hauptfläche: 1920 × ' + contentH + ' px');
            if (hatFooter) { zeilen.push('Footer-Ticker: 1920 × 70 px'); }
            b.forEach(function (br, i) {
                var colW = Math.round(1920 * br / 100);
                zeilen.push('Spalte ' + (i + 1) + ': ' + colW + ' × ' + contentH + ' px');
            });
            pxEl.innerHTML = zeilen.join('<br>');
        }
    }

    // ---- Spalten-Editor ----
    function baueSpalten() {
        var n = anzSpalten();
        // Bestehende Einträge je Spalte einsammeln, um sie beim Umbau zu erhalten
        var vorhandene = {};
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(function (col) {
            var s = col.getAttribute('data-spalte');
            vorhandene[s] = Array.prototype.slice.call(col.querySelectorAll('.adm-spalte-eintrag'));
        });

        spaltenWrap.innerHTML = '';
        spaltenWrap.setAttribute('data-anzahl', n);
        for (var s = 1; s <= n; s++) {
            var col = document.createElement('div');
            col.className = 'adm-spalte';
            col.setAttribute('data-spalte', String(s));
            col.innerHTML =
                '<div class="adm-spalte-kopf">Spalte ' + s + '</div>' +
                '<div class="adm-spalte-liste"></div>' +
                '<button type="button" class="adm-btn adm-spalte-add">+ Instanz hinzufügen</button>';
            spaltenWrap.appendChild(col);

            var liste = col.querySelector('.adm-spalte-liste');
            if (vorhandene[s]) {
                vorhandene[s].forEach(function (e) { liste.appendChild(e); });
            }
            pruefeLeer(col);
        }
        // Einträge aus weggefallenen Spalten (n+1..3) in die letzte Spalte schieben,
        // damit nichts unbemerkt verloren geht.
        for (var k = n + 1; k <= 3; k++) {
            if (vorhandene[k] && vorhandene[k].length) {
                var ziel = spaltenWrap.querySelector('.adm-spalte[data-spalte="' + n + '"] .adm-spalte-liste');
                vorhandene[k].forEach(function (e) { ziel.appendChild(e); });
            }
        }
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(pruefeLeer);
    }

    function pruefeLeer(col) {
        var liste = col.querySelector('.adm-spalte-liste');
        var hatLeer = liste.querySelector('.adm-spalte-leer');
        var hatEintrag = liste.querySelector('.adm-spalte-eintrag');
        if (!hatEintrag && !hatLeer) {
            var p = document.createElement('p');
            p.className = 'adm-spalte-leer';
            p.textContent = 'Noch keine Instanz.';
            liste.appendChild(p);
        } else if (hatEintrag && hatLeer) {
            hatLeer.remove();
        }
    }

    function baueEintrag(data) {
        var e = document.createElement('div');
        e.className = 'adm-spalte-eintrag' + (data.aktiv === false ? ' inaktiv' : '');
        e.setAttribute('data-mid', data.modul_instanz_id);
        e.innerHTML =
            '<input type="hidden" data-feld="modul_instanz_id" value="' + escapeHtml(data.modul_instanz_id) + '">' +
            '<input type="hidden" data-feld="spalte" value="">' +
            '<span class="adm-eintrag-griff" title="Ziehen zum Verschieben (auch zwischen Spalten)">⠿</span>' +
            '<span class="adm-eintrag-icon">' + icon(data.icon) + '</span>' +
            '<span class="adm-eintrag-text">' +
                '<span class="adm-eintrag-name">' + escapeHtml(data.name) +
                    (data.aktiv === false ? ' <span class="adm-badge-pause">pausiert</span>' : '') + '</span>' +
                '<span class="adm-eintrag-typ">' + escapeHtml(data.typ_label || data.modul_typ) + '</span>' +
            '</span>' +
            '<span class="adm-eintrag-steuer">' +
                '<button type="button" class="adm-mini" data-akt="hoch" title="nach oben">↑</button>' +
                '<button type="button" class="adm-mini" data-akt="runter" title="nach unten">↓</button>' +
                '<button type="button" class="adm-mini adm-mini-rot" data-akt="weg" title="entfernen">×</button>' +
            '</span>';
        return e;
    }

    function fuegeEinSpalte(spalteNr, data) {
        var col = spaltenWrap.querySelector('.adm-spalte[data-spalte="' + spalteNr + '"]');
        if (!col) { return false; }
        col.querySelector('.adm-spalte-liste').appendChild(baueEintrag(data));
        pruefeLeer(col);
        _dirty = true;
        return true;
    }

    // Klick-Delegation im Spalten-Bereich
    spaltenWrap.addEventListener('click', function (e) {
        var add = e.target.closest('.adm-spalte-add');
        if (add) {
            var col = add.closest('.adm-spalte');
            oeffnePicker(parseInt(col.getAttribute('data-spalte'), 10));
            return;
        }
        var eintrag = e.target.closest('.adm-spalte-eintrag');
        if (!eintrag) { return; }
        var akt = e.target.getAttribute('data-akt');
        var liste = eintrag.parentElement;
        if (akt === 'weg')    { eintrag.remove(); pruefeLeer(liste.closest('.adm-spalte')); _dirty = true; }
        if (akt === 'hoch'   && eintrag.previousElementSibling && eintrag.previousElementSibling.classList.contains('adm-spalte-eintrag')) {
            liste.insertBefore(eintrag, eintrag.previousElementSibling); _dirty = true;
        }
        if (akt === 'runter' && eintrag.nextElementSibling) {
            liste.insertBefore(eintrag.nextElementSibling, eintrag); _dirty = true;
        }
    });

    // ---- Drag & Drop (Einträge innerhalb + zwischen Spalten verschieben) ----
    // Nur über den Griff (⠿) ziehbar, damit Klicks auf ↑/↓/× nicht ziehen.
    var gezogen = null, urParent = null, urNext = null, griffEintrag = null;

    spaltenWrap.addEventListener('mousedown', function (e) {
        if (e.target.closest('.adm-eintrag-griff')) {
            griffEintrag = e.target.closest('.adm-spalte-eintrag');
            if (griffEintrag) { griffEintrag.setAttribute('draggable', 'true'); }
        }
    });
    // Aufräumen: nach Klick/Drag draggable wieder zurücknehmen
    document.addEventListener('mouseup', function () {
        if (griffEintrag && griffEintrag !== gezogen) {
            griffEintrag.removeAttribute('draggable');
        }
        griffEintrag = null;
    });

    function getEintragNach(liste, y) {
        var els = Array.prototype.slice.call(
            liste.querySelectorAll('.adm-spalte-eintrag:not(.wird-gezogen)')
        );
        var naechste = { offset: Number.NEGATIVE_INFINITY, element: null };
        els.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > naechste.offset) {
                naechste = { offset: offset, element: child };
            }
        });
        return naechste.element; // null = ans Ende anhängen
    }

    spaltenWrap.addEventListener('dragstart', function (e) {
        var eintrag = e.target.closest('.adm-spalte-eintrag');
        if (!eintrag || eintrag.getAttribute('draggable') !== 'true') { return; }
        gezogen  = eintrag;
        urParent = eintrag.parentElement;
        urNext   = eintrag.nextElementSibling;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', eintrag.getAttribute('data-mid')); } catch (ex) {}
        setTimeout(function () { eintrag.classList.add('wird-gezogen'); }, 0);
    });

    spaltenWrap.addEventListener('dragover', function (e) {
        if (!gezogen) { return; }
        var liste = e.target.closest('.adm-spalte-liste');
        if (!liste) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var nach = getEintragNach(liste, e.clientY);
        if (nach) { liste.insertBefore(gezogen, nach); }
        else      { liste.appendChild(gezogen); }
        pruefeLeer(liste.closest('.adm-spalte')); // Platzhalter im Ziel entfernen
    });

    spaltenWrap.addEventListener('drop', function (e) {
        if (!gezogen) { return; }
        e.preventDefault();
        _dirty = true;
    });

    spaltenWrap.addEventListener('dragend', function () {
        if (gezogen) {
            gezogen.classList.remove('wird-gezogen');
            gezogen.removeAttribute('draggable');
        }
        gezogen = urParent = urNext = null;
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(pruefeLeer);
    });

    // Layout-Wechsel
    function markiereLayout() {
        document.querySelectorAll('.adm-layoutopt').forEach(function (lbl) {
            var inp = lbl.querySelector('input[name="layout_id"]');
            lbl.classList.toggle('aktiv', !!(inp && inp.checked));
        });
    }
    document.querySelectorAll('input[name="layout_id"]').forEach(function (r) {
        r.addEventListener('change', function () {
            // Default-Breite des gewählten Layouts in den Regler übernehmen
            var db1 = parseInt(r.getAttribute('data-default-b1'), 10);
            if (!isNaN(db1)) { slider.value = db1; }
            markiereLayout();
            aktualisiereBreitenUI();
            baueSpalten();
            aktualisiereVorschau();
        });
    });
    slider.addEventListener('input', function () { aktualisiereBreitenUI(); aktualisiereVorschau(); });
    document.getElementById('header_sichtbar').addEventListener('change', aktualisiereVorschau);
    document.getElementById('footer_ticker').addEventListener('change', aktualisiereVorschau);

    // ---- Picker ----
    var overlay = document.getElementById('picker-overlay');
    var liste   = document.getElementById('picker-liste');
    var selTyp  = document.getElementById('picker-typ');
    var zielSpalte = null;
    var typenGeladen = false;

    function oeffnePicker(spalteNr) { zielSpalte = spalteNr; overlay.hidden = false; ladePicker(); }
    function schliessePicker() { overlay.hidden = true; zielSpalte = null; }
    document.getElementById('picker-abbrechen').addEventListener('click', schliessePicker);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliessePicker(); } });
    selTyp.addEventListener('change', ladePicker);

    function ladePicker() {
        var p = new URLSearchParams();
        if (selTyp.value) { p.set('typ', selTyp.value); }
        liste.innerHTML = '<p class="adm-leer">Lade …</p>';
        fetch('api/instanz-list.php?' + p.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { liste.innerHTML = '<p class="adm-leer">Fehler beim Laden.</p>'; return; }
                if (!typenGeladen) {
                    data.typen.forEach(function (t) {
                        var opt = document.createElement('option');
                        opt.value = t.id; opt.textContent = t.label + ' (' + t.anzahl + ')';
                        selTyp.appendChild(opt);
                    });
                    typenGeladen = true;
                }
                if (data.instanzen.length === 0) {
                    liste.innerHTML = '<p class="adm-leer">Keine Instanzen. In der <a href="bibliothek.php">Bibliothek</a> anlegen.</p>';
                    return;
                }
                liste.innerHTML = '';
                data.instanzen.forEach(function (inst) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'adm-picker-instanz' + (inst.aktiv ? '' : ' inaktiv');
                    btn.innerHTML =
                        '<span class="adm-eintrag-icon">' + icon(inst.icon) + '</span>' +
                        '<span class="adm-eintrag-text">' +
                            '<span class="adm-eintrag-name">' + escapeHtml(inst.name) +
                                (inst.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span>' +
                            '<span class="adm-eintrag-typ">' + escapeHtml(inst.typ_label) + '</span>' +
                        '</span>';
                    btn.addEventListener('click', function () {
                        fuegeEinSpalte(zielSpalte, {
                            modul_instanz_id: inst.id, name: inst.name, modul_typ: inst.modul_typ,
                            typ_label: inst.typ_label, icon: inst.icon, aktiv: inst.aktiv
                        });
                        schliessePicker();
                    });
                    liste.appendChild(btn);
                });
            })
            .catch(function () { liste.innerHTML = '<p class="adm-leer">Netzwerkfehler.</p>'; });
    }

    // ---- Dirty-Guard: Warnung bei ungespeicherten Änderungen ----
    var plForm = document.getElementById('playlist-form');
    plForm.addEventListener('input', function (e) { if (e.isTrusted) { _dirty = true; } });
    plForm.addEventListener('change', function (e) { if (e.isTrusted) { _dirty = true; } });
    window.addEventListener('beforeunload', function (e) {
        if (_dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // ---- Vor dem Absenden: Spalte + Feldnamen sequenziell vergeben ----
    plForm.addEventListener('submit', function () {
        _dirty = false;
        var i = 0;
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(function (col) {
            var s = col.getAttribute('data-spalte');
            col.querySelectorAll('.adm-spalte-eintrag').forEach(function (eintrag) {
                eintrag.querySelector('[data-feld="spalte"]').value = s;
                eintrag.querySelectorAll('[data-feld]').forEach(function (feld) {
                    feld.setAttribute('name', 'inhalt[' + i + '][' + feld.getAttribute('data-feld') + ']');
                });
                i++;
            });
        });
    });

    // ---- Initialaufbau ----
    markiereLayout();
    aktualisiereBreitenUI();
    baueSpalten();
    // Startdaten in ihre Spalten einsortieren
    START.forEach(function (d) { fuegeEinSpalte(Math.min(d.spalte, anzSpalten()), d); });
    aktualisiereVorschau();
    // Der programmatische Aufbau oben (fuegeEinSpalte) hat _dirty gesetzt — das ist
    // KEINE Nutzer-Änderung. Zurücksetzen, sonst warnt der Guard schon beim Laden
    // und nach jedem Speichern (Reload). Ab hier zählen nur echte Interaktionen.
    _dirty = false;
})();
