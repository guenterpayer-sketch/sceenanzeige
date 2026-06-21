/**
 * assets/js/module-loader.js
 *
 * Generischer Lader für Modul-Frontends (siehe Abschnitt 4 der Doku).
 * Lädt modules/<id>/frontend.js per <script>-Tag (einmalig pro Modul-ID,
 * dann gecacht) und ruft anschließend die registrierte Render-Funktion auf.
 *
 * Aufruf z.B. aus dem Monitor-Frontend (Schritt 9) oder von dieser
 * Testseite (Schritt 3):
 *   TanzschuleLoader.render('bild', container, settings, inhalte);
 */
(function () {
    const geladeneModule = {};

    function ladeSkript(modulId, basisUrl, callback) {
        if (geladeneModule[modulId] === 'fertig') {
            callback();
            return;
        }
        if (geladeneModule[modulId] === 'laedt') {
            // einfacher Polling-Wartemechanismus, falls parallel mehrfach angefordert
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
         * @param {string} [basisUrl] Standard: window.BACKEND_BASE oder aktueller Origin
         */
        render: function (modulId, container, settings, inhalte, basisUrl) {
            basisUrl = basisUrl || window.BACKEND_BASE || '';
            ladeSkript(modulId, basisUrl, function () {
                const fn = window.TanzschuleModule && window.TanzschuleModule[modulId];
                if (typeof fn !== 'function') {
                    console.error('Modul "' + modulId + '" hat keine Render-Funktion registriert.');
                    return;
                }
                fn(container, settings || {}, inhalte || []);
            });
        }
    };
})();
