/**
 * modules/uhrzeit/frontend.js
 *
 * Konvention für alle Module (siehe module-loader.js):
 *   window.TanzschuleModule[<id>] = function(container, settings, inhalte) { ... }
 *
 * "uhrzeit" hat keine Unter-Inhalte, daher wird "inhalte" hier ignoriert.
 * Live-Uhrzeit, lokal jede Sekunde aktualisiert (kein erneuter Datenabruf
 * nötig, siehe Abschnitt 10 der Doku: rein clientseitig).
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    const WOCHENTAGE_LANG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function formatZeit(date, format) {
        const h24 = date.getHours();
        const m = pad(date.getMinutes());
        const s = pad(date.getSeconds());
        if (format === 'H:i:s') return `${pad(h24)}:${m}:${s}`;
        if (format === 'g:i A') {
            const h12 = h24 % 12 === 0 ? 12 : h24 % 12;
            const ampm = h24 < 12 ? 'AM' : 'PM';
            return `${h12}:${m} ${ampm}`;
        }
        return `${pad(h24)}:${m}`; // H:i default
    }

    function formatDatum(date, format) {
        const tag = pad(date.getDate());
        const monat = pad(date.getMonth() + 1);
        const jahr = date.getFullYear();
        const basis = `${tag}.${monat}.${jahr}`;
        if (format === 'l, d.m.Y') {
            return `${WOCHENTAGE_LANG[date.getDay()]}, ${basis}`;
        }
        return basis; // d.m.Y default
    }

    window.TanzschuleModule.uhrzeit = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-uhrzeit');
        container.innerHTML = '<div class="tm-uhrzeit-zeit"></div><div class="tm-uhrzeit-datum"></div>';
        const zeitEl = container.querySelector('.tm-uhrzeit-zeit');
        const datumEl = container.querySelector('.tm-uhrzeit-datum');

        if (settings.zeige_datum === false) {
            datumEl.style.display = 'none';
        }

        function tick() {
            const now = new Date();
            zeitEl.textContent = formatZeit(now, settings.format_zeit || 'H:i');
            if (settings.zeige_datum !== false) {
                datumEl.textContent = formatDatum(now, settings.format_datum || 'd.m.Y');
            }
        }

        tick();
        // Wichtig (siehe Song-Anzeige Bugfix, Abschnitt 5e der Song-Doku):
        // bei jedem Neu-Rendern der Modul-Instanz muss der alte Interval-Timer
        // aufgeräumt werden, sonst entstehen Leaks. Daher Referenz am Container
        // speichern und vor dem Setzen eines neuen Intervals löschen.
        if (container._tmInterval) {
            clearInterval(container._tmInterval);
        }
        container._tmInterval = setInterval(tick, 1000);
    };
})();
