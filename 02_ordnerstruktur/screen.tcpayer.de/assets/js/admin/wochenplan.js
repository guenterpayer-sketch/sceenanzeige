/**
 * assets/js/admin/wochenplan.js
 *
 * Kalender — alle Monitore (nur lesen): rendert je Kalenderwoche zwei
 * Schichten in einem gemeinsamen Grid (CSS-Klassen adm-kal-* wie in
 * monitor-zeitplan.js):
 *   - Regelbetrieb (Wochenmuster, monitor_zeitplan)   → blasse Blöcke
 *   - Kalender-Termine (konkretes Datum, monitor_termine) → kräftige Blöcke
 *     mit goldenem Rand; mehrtägige Termine erscheinen an jedem Tag ihres
 *     Zeitraums, ganztägige in der Ganztags-Zeile.
 *
 * Daten aus window.TM_WP (inline in wochenplan.php gesetzt):
 *   { monitore, eintraege, termine, wochen_start ('YYYY-MM-DD', Montag),
 *     heute ('YYYY-MM-DD') }
 *
 * Identische Einträge (gleicher Tag + Playlist + Uhrzeit) auf mehreren
 * Monitoren werden zu EINEM Block mit Monitor-Badges zusammengefasst.
 */
(function () {
    var MONITORE  = window.TM_WP.monitore;
    var EINTRAEGE = window.TM_WP.eintraege;
    var TERMINE   = window.TM_WP.termine || [];
    var WSTART    = window.TM_WP.wochen_start;
    var HEUTE     = window.TM_WP.heute;
    var TAGE = [ [1,'Mo'], [2,'Di'], [3,'Mi'], [4,'Do'], [5,'Fr'], [6,'Sa'], [7,'So'] ];
    var KAL_START_H = 9, KAL_END_H = 24, KAL_ROW_H = 40;

    var filterEl = document.getElementById('wp-monitor-filter');
    var gridEl   = document.getElementById('wp-kalender-grid');
    if (!gridEl) { return; }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
    function playlistFarbe(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) { h = (h * 31 + name.charCodeAt(i)) >>> 0; }
        return 'hsl(' + (h % 360) + ' 55% 38%)';
    }
    function monitorName(mid) {
        for (var i = 0; i < MONITORE.length; i++) {
            if (MONITORE[i].id === mid) { return MONITORE[i].name; }
        }
        return '?';
    }
    function zeitZuMin(s) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(s || '');
        if (!m) { return null; }
        return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    }
    // 'YYYY-MM-DD' + n Tage → 'YYYY-MM-DD' (lokal, kein UTC-Versatz)
    function datumPlus(iso, tage) {
        var p = iso.split('-');
        var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10) + tage);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
            + '-' + String(d.getDate()).padStart(2, '0');
    }
    function datumKurz(iso) { // 'DD.MM.'
        var p = iso.split('-');
        return p[2] + '.' + p[1] + '.';
    }

    // Datum je Tagesspalte der angezeigten Woche (Index 0 = Montag)
    var TAG_DATEN = [];
    for (var td = 0; td < 7; td++) { TAG_DATEN.push(datumPlus(WSTART, td)); }

    // ---- Monitor-Filter (Checkboxen, alle an) ------------------------------
    var aktiveMonitore = {};
    MONITORE.forEach(function (m) { aktiveMonitore[m.id] = true; });

    filterEl.innerHTML = '<span class="adm-wp-filter-label">Monitore:</span>' +
        MONITORE.map(function (m) {
            return '<label class="adm-wp-filter-item">' +
                '<input type="checkbox" data-mid="' + m.id + '" checked> ' +
                escapeHtml(m.name) + '</label>';
        }).join('');
    filterEl.addEventListener('change', function (e) {
        var cb = e.target.closest('input[data-mid]');
        if (!cb) { return; }
        aktiveMonitore[parseInt(cb.getAttribute('data-mid'), 10)] = cb.checked;
        rendere();
    });

    // ---- Überlappende Blöcke nebeneinander (wie monitor-zeitplan.js) -------
    function verteileLanes() {
        gridEl.querySelectorAll('.adm-kal-tag').forEach(function (col) {
            var blocks = col.querySelectorAll('.adm-kal-block');
            if (blocks.length < 2) { return; }
            var infos = Array.prototype.map.call(blocks, function (b) {
                var top = parseFloat(b.style.top) || 0;
                return { el: b, top: top, bottom: top + (parseFloat(b.style.height) || 0),
                         prio: parseInt(b.getAttribute('data-prio'), 10) || 0,
                         termin: b.classList.contains('adm-kal-block--termin') };
            });
            infos.sort(function (a, b) { return a.top - b.top || b.prio - a.prio; });
            var cluster = [], aktuell = null, ende = -1;
            infos.forEach(function (i) {
                if (!aktuell || i.top >= ende) { aktuell = []; cluster.push(aktuell); }
                ende = aktuell.length ? Math.max(ende, i.bottom) : i.bottom;
                aktuell.push(i);
            });
            cluster.forEach(function (c) {
                if (c.length < 2) { return; }
                // Termine vor Regelbetrieb, dann Priorität — Termin steht links
                c.sort(function (a, b) {
                    return (b.termin - a.termin) || (b.prio - a.prio) || (a.top - b.top);
                });
                var lanes = [];
                c.forEach(function (i) {
                    var l = -1;
                    for (var k = 0; k < lanes.length; k++) {
                        var frei = lanes[k].every(function (o) {
                            return i.bottom <= o.top || i.top >= o.bottom;
                        });
                        if (frei) { l = k; break; }
                    }
                    if (l === -1) { lanes.push([]); l = lanes.length - 1; }
                    lanes[l].push(i);
                    i.lane = l;
                });
                var n = lanes.length;
                c.forEach(function (i) {
                    i.el.style.left  = 'calc(' + (i.lane * 100 / n) + '% + 2px)';
                    i.el.style.right = 'auto';
                    i.el.style.width = 'calc(' + (100 / n) + '% - 4px)';
                });
            });
        });
    }

    // ---- Block-Fabrik (beide Schichten nutzen dieselbe Form) ---------------
    // opts: { schicht: 'regel'|'termin', gz: bool, titel, meta, monsText,
    //         prio, von, bis, farbe, titleAttr }
    function baueBlock(opts) {
        var b = document.createElement('div');
        b.className = (opts.gz ? 'adm-kal-gz-block' : 'adm-kal-block')
            + (opts.gz ? ' adm-kal-gz-block--' : ' adm-kal-block--') + opts.schicht;
        b.style.background = opts.farbe;
        b.innerHTML = '<span class="adm-kal-block-titel">' + escapeHtml(opts.titel) + '</span>'
            + (opts.meta ? '<span class="adm-kal-block-meta">' + opts.meta + '</span>' : '')
            + '<span class="adm-kal-block-monitore">' + escapeHtml(opts.monsText) + '</span>';
        b.title = opts.titleAttr;
        return b;
    }

    // ---- Rendern -----------------------------------------------------------
    function rendere() {
        var eintraege = EINTRAEGE.filter(function (e) { return aktiveMonitore[e.monitor_id]; });
        var termine   = TERMINE.filter(function (t) { return aktiveMonitore[t.monitor_id]; });

        // Grid-Skelett: Kopf mit Wochentag + Datum, Heute markiert
        var std = KAL_END_H - KAL_START_H;
        var head = '<div class="adm-kal-corner"></div>';
        for (var t = 0; t < 7; t++) {
            var istHeute = TAG_DATEN[t] === HEUTE;
            head += '<div class="adm-kal-tagkopf' + (istHeute ? ' adm-kal-tagkopf--heute' : '') + '">'
                + TAGE[t][1] + ' ' + datumKurz(TAG_DATEN[t]) + '</div>';
        }
        var gzZellen = '';
        for (var gd = 1; gd <= 7; gd++) {
            gzZellen += '<div class="adm-kal-gz-tag' + (TAG_DATEN[gd - 1] === HEUTE ? ' adm-kal-gz-tag--heute' : '')
                + '" data-tag="' + gd + '"></div>';
        }
        var stunden = '';
        for (var h = 0; h < std; h++) {
            stunden += '<div class="adm-kal-std" style="top:' + (h * KAL_ROW_H) + 'px">'
                + String(KAL_START_H + h).padStart(2, '0') + ':00</div>';
        }
        var spalten = '';
        for (var d = 1; d <= 7; d++) {
            spalten += '<div class="adm-kal-tag' + (TAG_DATEN[d - 1] === HEUTE ? ' adm-kal-tag--heute' : '')
                + '" data-tag="' + d + '" style="height:' + (std * KAL_ROW_H) + 'px"></div>';
        }
        gridEl.innerHTML =
            '<div class="adm-kal-kopf">' + head + '</div>' +
            '<div class="adm-kal-ganztags">' +
                '<div class="adm-kal-gz-label">Ganztags</div>' + gzZellen +
            '</div>' +
            '<div class="adm-kal-body">' +
                '<div class="adm-kal-stundenspalte" style="height:' + (std * KAL_ROW_H) + 'px">' + stunden + '</div>' +
                '<div class="adm-kal-spalten">' + spalten + '</div>' +
            '</div>';

        // ==== Schicht 2: Kalender-Termine (kräftig) =========================
        // Je Tag der Woche prüfen, ob das Datum im Termin-Zeitraum liegt;
        // identische Termine mehrerer Monitore werden gruppiert.
        var tGz = {}, tZeit = {};
        termine.forEach(function (t) {
            TAG_DATEN.forEach(function (datum, idx) {
                if (datum < t.datum_von || datum > t.datum_bis) { return; }
                var tagNr = idx + 1;
                var ziel, key;
                if (!t.von && !t.bis) {
                    ziel = tGz;   key = tagNr + '|' + t.playlist_id;
                } else {
                    ziel = tZeit; key = tagNr + '|' + t.playlist_id + '|' + t.von + '|' + t.bis;
                }
                if (!ziel[key]) { ziel[key] = { tag: tagNr, t: t, monitore: [], prio: t.prio }; }
                if (ziel[key].monitore.indexOf(t.monitor_id) === -1) {
                    ziel[key].monitore.push(t.monitor_id);
                }
                ziel[key].prio = Math.max(ziel[key].prio, t.prio);
            });
        });

        function terminTitle(g) {
            var t = g.t;
            var zeitraum = (t.datum_von === t.datum_bis)
                ? datumKurz(t.datum_von)
                : datumKurz(t.datum_von) + '–' + datumKurz(t.datum_bis);
            return 'Termin: ' + t.playlist_name
                + ' · ' + zeitraum
                + (t.von ? ' · ' + t.von + '–' + t.bis : ' · ganztags')
                + (g.prio ? ' · Priorität ' + g.prio : '')
                + ' · ' + g.monitore.map(monitorName).join(' · ');
        }

        // Ganztags-Termine → Ganztags-Zeile (vor den Regel-Blöcken = oben)
        Object.keys(tGz).map(function (k) { return tGz[k]; })
            .sort(function (a, b) { return b.prio - a.prio; })
            .forEach(function (g) {
                var zelle = gridEl.querySelector('.adm-kal-gz-tag[data-tag="' + g.tag + '"]');
                if (!zelle) { return; }
                var t = g.t;
                zelle.appendChild(baueBlock({
                    schicht: 'termin', gz: true,
                    titel: t.playlist_name,
                    meta: '📅 Termin' + (t.datum_von !== t.datum_bis
                        ? ' ' + datumKurz(t.datum_von) + '–' + datumKurz(t.datum_bis) : ''),
                    monsText: (g.prio ? 'P' + g.prio + ' · ' : '')
                        + (t.playlist_aktiv ? '' : 'pausiert · ')
                        + g.monitore.map(monitorName).join(' · '),
                    farbe: playlistFarbe(t.playlist_name),
                    titleAttr: terminTitle(g)
                }));
            });

        // Zeitgebundene Termine → Tagesspalten
        var startMin = KAL_START_H * 60, endMin = KAL_END_H * 60;
        Object.keys(tZeit).forEach(function (k) {
            var g = tZeit[k], t = g.t;
            var vMin = zeitZuMin(t.von), bMin = zeitZuMin(t.bis);
            if (vMin == null || bMin == null || bMin <= vMin) { return; }
            var vClamp = Math.max(vMin, startMin), bClamp = Math.min(bMin, endMin);
            if (bClamp <= vClamp) { return; }
            var col = gridEl.querySelector('.adm-kal-tag[data-tag="' + g.tag + '"]');
            if (!col) { return; }
            var b = baueBlock({
                schicht: 'termin', gz: false,
                titel: t.playlist_name,
                meta: t.von + '–' + t.bis + ' · 📅'
                    + (g.prio ? ' · P' + g.prio : '')
                    + (t.playlist_aktiv ? '' : ' · pausiert'),
                monsText: g.monitore.map(monitorName).join(' · '),
                farbe: playlistFarbe(t.playlist_name),
                titleAttr: terminTitle(g)
            });
            b.style.top = ((vClamp - startMin) / 60 * KAL_ROW_H) + 'px';
            b.style.height = ((bClamp - vClamp) / 60 * KAL_ROW_H) + 'px';
            b.style.zIndex = String(50 + g.prio);
            b.setAttribute('data-prio', String(g.prio));
            col.appendChild(b);
        });

        // ==== Schicht 1: Regelbetrieb (blass) ===============================
        // Ganztags-Einträge (Fallback): pro Tag + Playlist gruppieren
        var gzGruppen = {};
        eintraege.forEach(function (e) {
            if (e.von || e.bis) { return; }
            e.tage.forEach(function (tag) {
                var key = tag + '|' + e.playlist_id;
                if (!gzGruppen[key]) { gzGruppen[key] = { tag: tag, e: e, monitore: [], prio: e.prio }; }
                if (gzGruppen[key].monitore.indexOf(e.monitor_id) === -1) {
                    gzGruppen[key].monitore.push(e.monitor_id);
                }
                gzGruppen[key].prio = Math.max(gzGruppen[key].prio, e.prio);
            });
        });
        Object.keys(gzGruppen).map(function (key) { return gzGruppen[key]; })
            .sort(function (a, b) { return b.prio - a.prio; })
            .forEach(function (g) {
                var zelle = gridEl.querySelector('.adm-kal-gz-tag[data-tag="' + g.tag + '"]');
                if (!zelle) { return; }
                var e = g.e;
                var mons = g.monitore.map(monitorName).join(' · ');
                zelle.appendChild(baueBlock({
                    schicht: 'regel', gz: true,
                    titel: e.playlist_name,
                    meta: '',
                    monsText: (g.prio ? 'P' + g.prio + ' · ' : '')
                        + (e.playlist_aktiv ? '' : 'pausiert · ') + mons,
                    farbe: playlistFarbe(e.playlist_name),
                    titleAttr: e.playlist_name + ' · ganztags (Regelbetrieb)'
                        + (g.prio ? ' · Priorität ' + g.prio : '') + ' · ' + mons
                }));
            });

        // Zeitgebundene Regelbetrieb-Einträge
        var gruppen = {};
        eintraege.forEach(function (e) {
            if (!e.von || !e.bis) { return; }
            e.tage.forEach(function (tag) {
                var key = tag + '|' + e.playlist_id + '|' + e.von + '|' + e.bis;
                if (!gruppen[key]) {
                    gruppen[key] = { tag: tag, e: e, monitore: [], prio: e.prio };
                }
                if (gruppen[key].monitore.indexOf(e.monitor_id) === -1) {
                    gruppen[key].monitore.push(e.monitor_id);
                }
                gruppen[key].prio = Math.max(gruppen[key].prio, e.prio);
            });
        });
        Object.keys(gruppen).forEach(function (key) {
            var g = gruppen[key], e = g.e;
            var vMin = zeitZuMin(e.von), bMin = zeitZuMin(e.bis);
            if (vMin == null || bMin == null || bMin <= vMin) { return; }
            var vClamp = Math.max(vMin, startMin), bClamp = Math.min(bMin, endMin);
            if (bClamp <= vClamp) { return; }
            var col = gridEl.querySelector('.adm-kal-tag[data-tag="' + g.tag + '"]');
            if (!col) { return; }
            var mons = g.monitore.map(monitorName).join(' · ');
            var b = baueBlock({
                schicht: 'regel', gz: false,
                titel: e.playlist_name,
                meta: e.von + '–' + e.bis
                    + (g.prio ? ' · P' + g.prio : '')
                    + (e.playlist_aktiv ? '' : ' · pausiert'),
                monsText: mons,
                farbe: playlistFarbe(e.playlist_name),
                titleAttr: e.playlist_name + ' · ' + e.von + '–' + e.bis + ' (Regelbetrieb)'
                    + (g.prio ? ' · Priorität ' + g.prio : '') + ' · ' + mons
            });
            b.style.top = ((vClamp - startMin) / 60 * KAL_ROW_H) + 'px';
            b.style.height = ((bClamp - vClamp) / 60 * KAL_ROW_H) + 'px';
            b.style.zIndex = String(10 + g.prio);
            b.setAttribute('data-prio', String(g.prio));
            col.appendChild(b);
        });

        verteileLanes();

        // Kurze Einträge kompakt (nur Titel)
        gridEl.querySelectorAll('.adm-kal-block').forEach(function (b) {
            if ((parseFloat(b.style.height) || 0) < 34) { b.classList.add('adm-kal-block--klein'); }
        });
    }

    rendere();
})();
