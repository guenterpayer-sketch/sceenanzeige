/**
 * modules/stundenplan/frontend.js
 *
 * Holt die Kursdaten serverseitig über proxies/nc.php (Nimbuscloud Legacy-API)
 * und rendert eine zeitgesteuerte Liste: vergangene Kurse werden ausgeblendet,
 * der aktuell laufende Kurs wird hervorgehoben (▶), danach folgen die nächsten.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatZeit(dateStr) {
        if (!dateStr) { return ''; }
        var m = String(dateStr).match(/(\d{2}):(\d{2})/);
        return m ? m[1] + ':' + m[2] : String(dateStr);
    }

    // Parst Datum/Zeit-String als Date-Objekt.
    // Unterstützt "2024-01-15 14:30:00" (vollständig) und "14:30:00" (nur Zeit → heute).
    function parseTimestamp(dateStr) {
        if (!dateStr) { return null; }
        var s = String(dateStr);
        var d = new Date(s.replace(' ', 'T'));
        if (!isNaN(d.getTime()) && d.getFullYear() > 2000) { return d; }
        var m = s.match(/(\d{2}):(\d{2})/);
        if (m) {
            var t = new Date();
            t.setHours(parseInt(m[1], 10), parseInt(m[2], 10), 0, 0);
            return t;
        }
        return null;
    }

    window.TanzschuleModule.stundenplan = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-stundenplan');
        container.innerHTML = '<div class="tm-sp-status">Lade Stundenplan…</div>';

        var basis       = window.BACKEND_BASE || '';
        var nurHeute    = settings.nur_heute === false ? '0' : '1';
        var anzahl      = (settings.anzahl_kurse != null) ? parseInt(settings.anzahl_kurse, 10) : 5;
        var locationIds = settings.location_ids || '';
        var roomId      = settings.room_id ? parseInt(settings.room_id, 10) : 0;

        var url = basis + '/proxies/nc.php?nur_heute=' + nurHeute;
        if (locationIds !== '') {
            url += '&location_ids=' + encodeURIComponent(locationIds);
        }
        if (roomId > 0) {
            url += '&room_id=' + roomId;
        }

        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    container.innerHTML = '<div class="tm-sp-status tm-sp-fehler">'
                        + escapeHtml(data.error) + '</div>';
                    return;
                }

                var kurse = data.kurse || [];
                var jetzt = new Date();

                // Sortieren nach Startzeit
                kurse.sort(function (a, b) {
                    var ta = parseTimestamp(a.start_date);
                    var tb = parseTimestamp(b.start_date);
                    return (ta ? ta.getTime() : 0) - (tb ? tb.getTime() : 0);
                });

                // Zeitfilter: vergangene ausblenden, laufende markieren
                var sichtbar = [];
                kurse.forEach(function (k) {
                    var start = parseTimestamp(k.start_date);
                    var ende  = parseTimestamp(k.end_date);
                    if (ende && ende <= jetzt) { return; } // bereits beendet
                    sichtbar.push({
                        kurs:    k,
                        aktuell: !!(start && start <= jetzt)
                    });
                });

                // Anzahl-Limit erst nach Zeitfilter anwenden
                if (anzahl > 0) { sichtbar = sichtbar.slice(0, anzahl); }

                if (sichtbar.length === 0) {
                    container.innerHTML = '<div class="tm-sp-status">Keine weiteren Kurse heute</div>';
                    return;
                }

                var html = '<div class="tm-sp-cards">';
                sichtbar.forEach(function (item) {
                    var k  = item.kurs;
                    var ak = item.aktuell;
                    var style = (ak && k.color) ? ' style="border-left-color:' + escapeHtml(k.color) + '"' : '';
                    html += '<div class="tm-sp-card' + (ak ? ' tm-sp-card--aktuell' : '') + '"' + style + '>'
                        + '<div class="tm-sp-zeit">' + escapeHtml(formatZeit(k.start_date)) + '</div>'
                        + '<div class="tm-sp-saal">' + escapeHtml(k.room || '') + '</div>'
                        + '<div class="tm-sp-kurs">' + escapeHtml(k.displayName || k.course_key) + '</div>'
                        + '<div class="tm-sp-lehrer">' + escapeHtml(k.teacher || '') + '</div>'
                        + '</div>';
                });
                html += '</div>';
                container.innerHTML = html;
            })
            .catch(function () {
                container.innerHTML = '<div class="tm-sp-status tm-sp-fehler">'
                    + 'Stundenplan konnte nicht geladen werden.</div>';
            });
    };
})();
