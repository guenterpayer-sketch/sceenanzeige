/**
 * assets/js/module-loader.js
 *
 * Generischer Lader für Modul-Frontends (siehe Abschnitt 4 der Doku).
 *
 * GEÄNDERT in Schritt 4: render() bekommt jetzt zusätzlich modulInstanzId,
 * die an die Modul-Render-Funktion als 4. Parameter durchgereicht wird.
 * Nötig, weil die neuen Proxy-Module (stundenplan, community, song) wissen
 * müssen, zu welcher Instanz sie Daten abrufen sollen.
 *
 * Aufruf:
 *   TanzschuleLoader.render('song', container, settings, inhalte, modulInstanzId);
 */
(function () {
    const geladeneModule = {};

    function ladeSkript(modulId, basisUrl, callback) {
        if (geladeneModule[modulId] === 'fertig') {
            callback();
            return;
        }
        if (geladeneModule[modulId] === 'laedt') {
            const warte = setInterval(function () {
                if (geladeneModule[modulId] === 'fertig') {
                    clearInterval(warte);
                    callback();
                }
            }, 30);
            return;
        }
        geladeneModule[modulId] = 'laedt';
        const script = document.createElement('script');
        script.src = basisUrl + '/modules/' + encodeURIComponent(modulId) + '/frontend.js';
        script.onload = function () {
            geladeneModule[modulId] = 'fertig';
            callback();
        };
        script.onerror = function () {
            geladeneModule[modulId] = null;
            console.error('Konnte frontend.js für Modul "' + modulId + '" nicht laden.');
        };
        document.head.appendChild(script);
    }

    window.TanzschuleLoader = {
        /**
         * @param {string} modulId
         * @param {HTMLElement} container
         * @param {object} settings
         * @param {array} [inhalte]
         * @param {number|string} [modulInstanzId] NEU seit Schritt 4
         * @param {string} [basisUrl] Standard: window.BACKEND_BASE oder aktueller Origin
         */
        render: function (modulId, container, settings, inhalte, modulInstanzId, basisUrl) {
            basisUrl = basisUrl || window.BACKEND_BASE || '';
            ladeSkript(modulId, basisUrl, function () {
                const fn = window.TanzschuleModule && window.TanzschuleModule[modulId];
                if (typeof fn !== 'function') {
                    console.error('Modul "' + modulId + '" hat keine Render-Funktion registriert.');
                    return;
                }
                fn(container, settings || {}, inhalte || [], modulInstanzId || null);
            });
        }
    };
})();
