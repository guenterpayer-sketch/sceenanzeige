/**
 * assets/js/admin/wochenplan.js
 *
 * Globaler Wochenplan (nur lesen): rendert die Playlist-Zeitpläne aller
 * Monitore in einem gemeinsamen Wochenkalender. Nutzt dieselben
 * CSS-Klassen (adm-kal-*) wie der Kalender in monitor-zeitplan.js.
 *
 * Daten aus window.TM_WP (inline in wochenplan.php gesetzt):
 *   { monitore: [{id,name,subdomain}], eintraege: [{monitor_id, playlist_id,
 *     playlist_name, playlist_aktiv, tage, von, bis, prio}] }
 *
 * Identische Einträge (gleicher Tag + Playlist + Uhrzeit) auf mehreren
 * Monitoren werden zu EINEM Block mit Monitor-Badges zusammengefasst.
 */
(function () {
    var MONITORE  = window.TM_WP.monitore;
    var EINTRAEGE = window.TM_WP.eintraege;
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
                         prio: parseInt(b.getAttribute('data-prio'), 10) || 0 };
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
                c.sort(function (a, b) { return b.prio - a.prio || a.top - b.top; });
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

    // ---- Rendern -----------------------------------------------------------
    function rendere() {
        var eintraege = EINTRAEGE.filter(function (e) { return aktiveMonitore[e.monitor_id]; });

        // Ganztags-Einträge (Fallback): pro Tag + Playlist gruppieren,
        // Monitore der Gruppe sammeln — landen in der Ganztags-Zeile oben
        // in der jeweiligen Tagesspalte.
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
        var gzZellen = '';
        for (var gd = 1; gd <= 7; gd++) { gzZellen += '<div class="adm-kal-gz-tag" data-tag="' + gd + '"></div>'; }

        // Grid-Skelett
        var std = KAL_END_H - KAL_START_H;
        var head = '<div class="adm-kal-corner"></div>';
        for (var t = 0; t < 7; t++) { head += '<div class="adm-kal-tagkopf">' + TAGE[t][1] + '</div>'; }
        var stunden = '';
        for (var h = 0; h < std; h++) {
            stunden += '<div class="adm-kal-std" style="top:' + (h * KAL_ROW_H) + 'px">'
                + String(KAL_START_H + h).padStart(2, '0') + ':00</div>';
        }
        var spalten = '';
        for (var d = 1; d <= 7; d++) {
            spalten += '<div class="adm-kal-tag" data-tag="' + d + '" style="height:' + (std * KAL_ROW_H) + 'px"></div>';
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

        // Ganztags-Blöcke einsetzen (höhere Prio zuerst)
        Object.keys(gzGruppen)
            .map(function (key) { return gzGruppen[key]; })
            .sort(function (a, b) { return b.prio - a.prio; })
            .forEach(function (g) {
                var zelle = gridEl.querySelector('.adm-kal-gz-tag[data-tag="' + g.tag + '"]');
                if (!zelle) { return; }
                var e = g.e;
                var mons = g.monitore.map(monitorName).join(' · ');
                var b = document.createElement('div');
                b.className = 'adm-kal-gz-block';
                b.style.background = playlistFarbe(e.playlist_name);
                b.innerHTML = '<span class="adm-kal-block-titel">' + escapeHtml(e.playlist_name) + '</span>'
                    + '<span class="adm-kal-block-monitore">'
                    + (g.prio ? 'P' + g.prio + ' · ' : '')
                    + (e.playlist_aktiv ? '' : 'pausiert · ')
                    + escapeHtml(mons) + '</span>';
                b.title = e.playlist_name + ' · ganztags (Fallback)'
                    + (g.prio ? ' · Priorität ' + g.prio : '') + ' · ' + mons;
                zelle.appendChild(b);
            });

        // Zeitgebundene Einträge: pro Tag gruppieren nach Playlist + Uhrzeit —
        // gleiche Einträge auf mehreren Monitoren werden ein Block.
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

        var startMin = KAL_START_H * 60, endMin = KAL_END_H * 60;
        Object.keys(gruppen).forEach(function (key) {
            var g = gruppen[key], e = g.e;
            var vMin = zeitZuMin(e.von), bMin = zeitZuMin(e.bis);
            if (vMin == null || bMin == null || bMin <= vMin) { return; }
            var vClamp = Math.max(vMin, startMin), bClamp = Math.min(bMin, endMin);
            if (bClamp <= vClamp) { return; }
            var col = gridEl.querySelector('.adm-kal-tag[data-tag="' + g.tag + '"]');
            if (!col) { return; }
            var mons = g.monitore.map(monitorName).join(' · ');
            var b = document.createElement('div');
            b.className = 'adm-kal-block';
            b.style.top = ((vClamp - startMin) / 60 * KAL_ROW_H) + 'px';
            b.style.height = ((bClamp - vClamp) / 60 * KAL_ROW_H) + 'px';
            b.style.background = playlistFarbe(e.playlist_name);
            b.style.zIndex = String(10 + g.prio);
            b.setAttribute('data-prio', String(g.prio));
            b.innerHTML = '<span class="adm-kal-block-titel">' + escapeHtml(e.playlist_name) + '</span>'
                + '<span class="adm-kal-block-meta">' + e.von + '–' + e.bis
                + (g.prio ? ' · P' + g.prio : '')
                + (e.playlist_aktiv ? '' : ' · pausiert') + '</span>'
                + '<span class="adm-kal-block-monitore">' + escapeHtml(mons) + '</span>';
            b.title = e.playlist_name + ' · ' + e.von + '–' + e.bis
                + (g.prio ? ' · Priorität ' + g.prio : '') + ' · ' + mons;
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
