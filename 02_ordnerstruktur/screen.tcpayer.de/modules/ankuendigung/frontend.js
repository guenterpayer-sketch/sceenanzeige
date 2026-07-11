/**
 * modules/ankuendigung/frontend.js
 *
 * Rotiert durch die Unter-Inhalte (einzelne Ankündigungen) einer
 * "ankuendigung"-Modul-Instanz. Jeder Eintrag besteht aus Text und einem
 * optionalen Bild und kann eine eigene Anzeigedauer haben.
 *
 * "inhalte" kommt vom Backend als Array von Objekten (Spalten aus
 * modul_instanz_inhalte):
 *   { id, text_inhalt, dateiname, dauer_sek, gueltig_bis, aktiv }
 *
 * Generische Eintrags-Regeln (siehe Abschnitt 5 der Doku) werden defensiv
 * auch hier im Frontend angewandt:
 *   - aktiv == 0  -> Eintrag wird übersprungen
 *   - gueltig_bis < heute -> abgelaufener Eintrag wird übersprungen
 *
 * Konvention (siehe module-loader.js):
 *   window.TanzschuleModule.ankuendigung = function(container, settings, inhalte)
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function istAktiv(eintrag) {
        if (eintrag.aktiv !== undefined && Number(eintrag.aktiv) === 0) {
            return false;
        }
        if (eintrag.gueltig_bis) {
            // gueltig_bis ist ein Datum (YYYY-MM-DD); heute noch gültig = >= heute.
            var heute = new Date();
            heute.setHours(0, 0, 0, 0);
            var bis = new Date(eintrag.gueltig_bis + 'T00:00:00');
            if (!isNaN(bis.getTime()) && bis < heute) {
                return false;
            }
        }
        return true;
    }

    window.TanzschuleModule.ankuendigung = function (container, settings, inhalte) {
        settings = settings || {};
        inhalte = (inhalte || []).filter(istAktiv);
        container.classList.add('tm-modul-ankuendigung');

        if (container._tmTimeout) {
            clearTimeout(container._tmTimeout);
        }

        if (inhalte.length === 0) {
            container.innerHTML = '<div class="tm-ank-leer">Keine Ankündigungen</div>';
            return;
        }

        var uploadsBase   = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';
        var useFade       = settings.uebergang !== 'none';
        var schriftPx     = parseInt(settings.schrift_groesse, 10) || 60;
        var pillAlpha     = parseFloat(settings.pill_transparenz) || 0.15;

        container.style.position = container.style.position || 'relative';
        container.innerHTML =
            '<div class="tm-ank-stage" style="position:relative;width:100%;height:100%;overflow:hidden;">'
            + '<div class="tm-ank-slide tm-ank-layer-a" style="position:absolute;inset:0;opacity:0;"></div>'
            + '<div class="tm-ank-slide tm-ank-layer-b" style="position:absolute;inset:0;opacity:0;"></div>'
            + '</div>';

        var layerA = container.querySelector('.tm-ank-layer-a');
        var layerB = container.querySelector('.tm-ank-layer-b');
        [layerA, layerB].forEach(function (el) {
            if (useFade) {
                el.style.transition = 'opacity 600ms ease';
            }
        });

        function renderSlide(el, eintrag) {
            var bildUrl = eintrag.dateiname
                ? uploadsBase + encodeURIComponent(eintrag.dateiname)
                : null;
            var textHtml = eintrag.text_inhalt
                ? '<div class="tm-ank-text" style="font-size:' + schriftPx + 'px">'
                    + escapeHtml(eintrag.text_inhalt) + '</div>'
                : '';
            var textMitBildHtml = eintrag.text_inhalt
                ? '<div class="tm-ank-text" style="font-size:' + schriftPx + 'px;background:rgba(0,0,0,' + pillAlpha + ')">'
                    + escapeHtml(eintrag.text_inhalt) + '</div>'
                : '';

            if (bildUrl) {
                el.classList.add('tm-ank-mit-bild');
                el.innerHTML =
                    '<div class="tm-ank-bg"><img alt="" src="' + bildUrl + '"></div>'
                    + textMitBildHtml;
            } else {
                el.classList.remove('tm-ank-mit-bild');
                el.innerHTML = textHtml;
            }
        }

        var aktiver = layerA;
        var inaktiver = layerB;
        var index = 0;
        var ersterDurchlauf = true;

        function zeigeNaechste() {
            var eintrag = inhalte[index];
            var dauerSek = (eintrag.dauer_sek && eintrag.dauer_sek > 0)
                ? eintrag.dauer_sek
                : (settings.intervall_sek || 12);

            if (ersterDurchlauf || !useFade) {
                renderSlide(aktiver, eintrag);
                aktiver.style.opacity = '1';
                inaktiver.style.opacity = '0';
                ersterDurchlauf = false;
            } else {
                renderSlide(inaktiver, eintrag);
                inaktiver.style.opacity = '1';
                aktiver.style.opacity = '0';
                var tmp = aktiver;
                aktiver = inaktiver;
                inaktiver = tmp;
            }

            index = (index + 1) % inhalte.length;
            // Bei nur einem Eintrag nicht endlos neu rendern.
            if (inhalte.length > 1) {
                container._tmTimeout = setTimeout(zeigeNaechste, dauerSek * 1000);
            }
        }

        zeigeNaechste();
    };
})();
