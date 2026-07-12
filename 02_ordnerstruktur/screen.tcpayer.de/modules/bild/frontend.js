/**
 * modules/bild/frontend.js
 *
 * Slide-Engine-Modul (Etappe 2, siehe KONZEPT_SLIDE_ENGINE.md):
 * liefert nur noch Inhalt — ein Slide pro Bild. Rotation, Überblendung,
 * Timer und Cleanup besitzt die Engine in monitor.js.
 *
 * "inhalte" kommt vom Backend als Array von Objekten:
 *   { id, dateiname, reihenfolge, dauer_sek }
 * Die Bild-URL wird aus UPLOADS_URL (global, vom Saal-Frontend gesetzt)
 * + dateiname zusammengesetzt.
 *
 * Alle <img> werden beim Sammeln erzeugt → Bilder laden sofort im
 * Hintergrund; die Settle-Phase der Engine überdeckt die Ladezeit.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    window.TanzschuleModule.bild = {
        getSlides: function (settings, inhalte, fertig) {
            settings = settings || {};
            inhalte  = (inhalte || []).filter(function (i) { return !!i.dateiname; });

            var uploadsBase = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';
            var objectFit   = settings.bildmodus === 'contain' ? 'contain' : 'cover';
            var uebergang   = settings.uebergang === 'none' ? 'none' : 'fade';

            if (inhalte.length === 0) {
                var leer = document.createElement('div');
                leer.className = 'tm-modul-bild';
                leer.style.cssText = 'width:100%;height:100%;';
                leer.innerHTML = '<div class="tm-bild-leer">Keine Bilder vorhanden</div>';
                fertig([{ el: leer, dauerSek: 30 }]);
                return;
            }

            fertig(inhalte.map(function (item) {
                var el = document.createElement('div');
                el.className = 'tm-modul-bild';
                el.style.cssText = 'position:relative;width:100%;height:100%;overflow:hidden;';

                var img = document.createElement('img');
                img.alt = '';
                img.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;'
                    + 'display:block;object-fit:' + objectFit + ';object-position:center;';
                img.onerror = function () {
                    console.error('[bild-modul] Bild konnte nicht geladen werden:', img.src);
                };
                img.src = uploadsBase + encodeURIComponent(item.dateiname);
                el.appendChild(img);

                return {
                    el:        el,
                    dauerSek:  (item.dauer_sek && item.dauer_sek > 0)
                        ? item.dauer_sek
                        : (settings.intervall_sek || 10),
                    uebergang: uebergang
                };
            }));
        }
    };
})();
