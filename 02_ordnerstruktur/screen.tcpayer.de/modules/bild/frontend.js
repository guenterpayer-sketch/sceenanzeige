/**
 * modules/bild/frontend.js
 *
 * Rotiert durch die Unter-Inhalte (einzelne Bilder) einer "bild"-Modul-
 * Instanz. Jedes Bild kann eine eigene Anzeigedauer (dauer_sek) haben;
 * fehlt diese, greift settings.intervall_sek als Fallback.
 *
 * "inhalte" kommt vom Backend als Array von Objekten:
 *   { id, dateiname, reihenfolge, dauer_sek }
 * Die Bild-URL wird aus UPLOADS_URL (global, vom Saal-Frontend gesetzt)
 * + dateiname zusammengesetzt.
 *
 * FIX (nach Live-Test Schritt 3): echtes Crossfade statt "ausblenden,
 * dann erst neues Bild laden". Dafür zwei übereinanderliegende <img>-Layer,
 * die sich per Opacity gegenseitig durchkreuzen — während Layer A
 * ausblendet, blendet Layer B (mit dem neuen Bild) gleichzeitig ein.
 * Crossfade-Dauer gemeinsam getestet und final auf 1500ms gesetzt.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    window.TanzschuleModule.bild = function (container, settings, inhalte) {
        settings = settings || {};
        inhalte = (inhalte || []).filter(function (i) { return !!i.dateiname; });
        container.classList.add('tm-modul-bild');

        if (container._tmTimeout) {
            clearTimeout(container._tmTimeout);
        }

        if (inhalte.length === 0) {
            container.innerHTML = '<div class="tm-bild-leer">Keine Bilder vorhanden</div>';
            return;
        }

        const uploadsBase = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';
        const objectFit = settings.bildmodus === 'contain' ? 'contain' : 'cover';
        const useFade = settings.uebergang !== 'none';
        const fadeDauerMs = 1500;

        // Zwei übereinanderliegende Bild-Layer für echtes Crossfade.
        // Positionierung/Sichtbarkeit bewusst per Inline-Style statt nur per
        // CSS-Klasse, damit das Modul auch funktioniert, falls module-test.css
        // (noch) nicht aktualisiert wurde oder gecacht ist.
        container.style.position = container.style.position || 'relative';
        container.innerHTML =
            '<div class="tm-bild-stage" style="position:relative;width:100%;height:100%;overflow:hidden;">' +
            '<img class="tm-bild-img tm-bild-layer-a" alt="" style="position:absolute;top:0;left:0;width:100%;height:100%;display:block;">' +
            '<img class="tm-bild-img tm-bild-layer-b" alt="" style="position:absolute;top:0;left:0;width:100%;height:100%;display:block;">' +
            '</div>';

        const layerA = container.querySelector('.tm-bild-layer-a');
        const layerB = container.querySelector('.tm-bild-layer-b');
        [layerA, layerB].forEach(function (img) {
            img.style.objectFit = objectFit;
            img.style.opacity = '0';
            img.onerror = function () {
                console.error('[bild-modul] Bild konnte nicht geladen werden:', img.src);
            };
            if (useFade) {
                img.style.transition = 'opacity ' + fadeDauerMs + 'ms ease';
            }
        });

        let aktiverLayer = layerA;
        let inaktiverLayer = layerB;
        let index = 0;
        let ersterDurchlauf = true;

        function zeigeNaechstesBild() {
            const item = inhalte[index];
            const dauerSek = (item.dauer_sek && item.dauer_sek > 0) ? item.dauer_sek : (settings.intervall_sek || 10);
            const url = uploadsBase + encodeURIComponent(item.dateiname);

            if (ersterDurchlauf || !useFade) {
                // Erstes Bild bzw. Übergang deaktiviert: direkt anzeigen, kein Crossfade nötig.
                aktiverLayer.src = url;
                aktiverLayer.style.opacity = '1';
                inaktiverLayer.style.opacity = '0';
                ersterDurchlauf = false;
            } else {
                // Neues Bild im (aktuell unsichtbaren) anderen Layer vorladen,
                // dann beide gleichzeitig kreuzen lassen: alt -> 0, neu -> 1.
                inaktiverLayer.src = url;
                inaktiverLayer.style.opacity = '1';
                aktiverLayer.style.opacity = '0';

                const tmp = aktiverLayer;
                aktiverLayer = inaktiverLayer;
                inaktiverLayer = tmp;
            }

            index = (index + 1) % inhalte.length;
            container._tmTimeout = setTimeout(zeigeNaechstesBild, dauerSek * 1000);
        }

        zeigeNaechstesBild();
    };
})();
