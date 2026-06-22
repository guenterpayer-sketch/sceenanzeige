/**
 * modules/stundenplan/frontend.js
 *
 * Holt die Kursdaten serverseitig über proxies/nc.php (Nimbuscloud Legacy-API)
 * und rendert eine Liste. Der API-Key bleibt im Proxy, das Frontend übergibt
 * nur die nicht-sensiblen Anzeige-Einstellungen (saal_id, nur_heute, anzahl).
 *
 * Konvention (siehe module-loader.js):
 *   window.TanzschuleModule.stundenplan = function(container, settings, inhalte)
 *
 * Globale Werte (vom Saal-Frontend gesetzt, siehe Abschnitt 10 der Doku):
 *   window.BACKEND_BASE  Basis-URL von screen.tcpayer.de
 *   window.SAAL_ID       ID des aktuellen Saals (für den API-Key im Proxy)
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

    window.TanzschuleModule.stundenplan = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-stundenplan');
        container.innerHTML = '<div class="tm-sp-status">Lade Stundenplan…</div>';

        var basis = window.BACKEND_BASE || '';
        var saalId = window.SAAL_ID != null ? window.SAAL_ID : '';
        var nurHeute = settings.nur_heute === false ? '0' : '1';
        var anzahl = (settings.anzahl_kurse != null) ? settings.anzahl_kurse : 0;

        var url = basis + '/proxies/nc.php'
            + '?saal_id=' + encodeURIComponent(saalId)
            + '&nur_heute=' + nurHeute
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
                var html = '<ul class="tm-sp-liste">';
                kurse.forEach(function (k) {
                    html += '<li class="tm-sp-eintrag">'
                        + '<span class="tm-sp-zeit">' + escapeHtml(k.start_date) + '</span>'
                        + '<span class="tm-sp-name">' + escapeHtml(k.displayName || k.course_key) + '</span>'
                        + (k.room ? '<span class="tm-sp-raum">' + escapeHtml(k.room) + '</span>' : '')
                        + (k.teacher ? '<span class="tm-sp-lehrer">' + escapeHtml(k.teacher) + '</span>' : '')
                        + '</li>';
                });
                html += '</ul>';
                container.innerHTML = html;
            })
            .catch(function () {
                container.innerHTML = '<div class="tm-sp-status tm-sp-fehler">'
                    + 'Stundenplan konnte nicht geladen werden.</div>';
            });
    };
})();
