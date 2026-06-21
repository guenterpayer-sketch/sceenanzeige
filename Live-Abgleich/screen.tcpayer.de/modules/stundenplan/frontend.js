/**
 * modules/stundenplan/frontend.js
 *
 * Holt Kursdaten über proxies/nc.php (action=stundenplan). Braucht zwingend
 * eine globale SAAL_ID (vom Saal-Frontend gesetzt, siehe Abschnitt 10 der
 * Doku) sowie die modul_instanz_id (wird vom Loader durchgereicht, siehe
 * geänderte module-loader.js-Signatur in diesem Schritt).
 *
 * WICHTIG: Bis das echte DB-Schema für Kurse/Termine eingetragen ist (siehe
 * TODO in proxies/nc.php), liefert der Proxy einen Fehler statt Kursdaten —
 * das Modul zeigt diesen Fehler dann einfach im Container an. Das ist
 * beabsichtigt (sichtbarer Fehlschlag statt stiller Leerdaten).
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function renderListe(container, kurse) {
        if (!kurse || kurse.length === 0) {
            container.innerHTML = '<div class="tm-stundenplan-leer">Keine Kurse gefunden</div>';
            return;
        }
        let html = '<ul class="tm-stundenplan-liste">';
        kurse.forEach(function (k) {
            html += '<li class="tm-stundenplan-zeile">' +
                '<span class="tm-sp-zeit">' + (k.zeit || '') + '</span>' +
                '<span class="tm-sp-name">' + (k.name || '') + '</span>' +
                '</li>';
        });
        html += '</ul>';
        container.innerHTML = html;
    }

    window.TanzschuleModule.stundenplan = function (container, settings, inhalte, modulInstanzId) {
        settings = settings || {};
        container.classList.add('tm-modul-stundenplan');

        const basisUrl = window.BACKEND_BASE || '';
        const saalId = window.SAAL_ID;

        if (!saalId) {
            container.innerHTML = '<div class="tm-stundenplan-fehler">SAAL_ID fehlt (nur im Saal-Frontend bzw. Testseite mit Saal-Auswahl verfügbar)</div>';
            return;
        }
        if (!modulInstanzId) {
            container.innerHTML = '<div class="tm-stundenplan-fehler">modul_instanz_id fehlt</div>';
            return;
        }

        function laden() {
            const url = basisUrl + '/proxies/nc.php?action=stundenplan' +
                '&saal_id=' + encodeURIComponent(saalId) +
                '&modul_instanz_id=' + encodeURIComponent(modulInstanzId);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        container.innerHTML = '<div class="tm-stundenplan-fehler">' + data.error + '</div>';
                        return;
                    }
                    renderListe(container, data.kurse);
                })
                .catch(function () {
                    container.innerHTML = '<div class="tm-stundenplan-fehler">Verbindung unterbrochen</div>';
                });
        }

        laden();
        if (container._tmInterval) {
            clearInterval(container._tmInterval);
        }
        // Auto-Refresh alle ~60 Sek. gemäß Abschnitt 10 der Doku
        container._tmInterval = setInterval(laden, 60000);
    };
})();
