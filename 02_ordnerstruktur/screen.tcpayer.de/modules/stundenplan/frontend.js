/**
 * modules/stundenplan/frontend.js
 *
 * Holt die Kursdaten serverseitig über proxies/nc.php (Nimbuscloud Legacy-API)
 * und rendert eine Liste. Der API-Key bleibt im Proxy, das Frontend übergibt
 * nur die nicht-sensiblen Anzeige-Einstellungen (nur_heute, anzahl).
 *
 * Hinweis: Es gibt genau EINEN schulweiten NC-API-Key (config.php → NC_API_KEY),
 * KEINEN Key pro Saal. Der Stundenplan braucht daher KEINE SAAL_ID.
 *
 * Konvention (siehe module-loader.js):
 *   window.TanzschuleModule.stundenplan = function(container, settings, inhalte)
 *
 * Globale Werte (vom Saal-Frontend gesetzt, siehe Abschnitt 10 der Doku):
 *   window.BACKEND_BASE  Basis-URL von screen.tcpayer.de
 *
 * Daten werden bei jedem Neu-Rendern einmal geholt; das Monitor-Frontend
 * rendert die Module beim ~60-Sek-Refresh ohnehin neu (Abschnitt 10).
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatZeit(start_date) {
        if (!start_date) { return ''; }
        var m = String(start_date).match(/(\d{2}):(\d{2})/);
        return m ? m[1] + ':' + m[2] : String(start_date);
    }

    window.TanzschuleModule.stundenplan = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-stundenplan');
        container.innerHTML = '<div class="tm-sp-status">Lade Stundenplan…</div>';

        var basis = window.BACKEND_BASE || '';
        var nurHeute = settings.nur_heute === false ? '0' : '1';
        var anzahl = (settings.anzahl_kurse != null) ? settings.anzahl_kurse : 0;

        var url = basis + '/proxies/nc.php'
            + '?nur_heute=' + nurHeute
            + '&anzahl=' + encodeURIComponent(anzahl);

        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    container.innerHTML = '<div class="tm-sp-status tm-sp-fehler">'
                        + escapeHtml(data.error) + '</div>';
                    return;
                }
                var kurse = data.kurse || [];
                if (kurse.length === 0) {
                    container.innerHTML = '<div class="tm-sp-status">Keine Kurse</div>';
                    return;
                }
                var html = '<div class="tm-sp-cards">';
                kurse.forEach(function (k) {
                    html += '<div class="tm-sp-card">'
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
