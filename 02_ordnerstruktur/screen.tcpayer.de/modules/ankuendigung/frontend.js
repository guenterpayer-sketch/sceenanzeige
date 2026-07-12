/**
 * modules/ankuendigung/frontend.js
 *
 * Slide-Engine-Modul (Etappe 2, siehe KONZEPT_SLIDE_ENGINE.md):
 * liefert nur noch Inhalt — ein Slide pro Ankündigung (Text + optionales
 * Bild). Rotation, Überblendung, Timer und Cleanup besitzt die Engine.
 *
 * "inhalte" kommt vom Backend als Array von Objekten (Spalten aus
 * modul_instanz_inhalte):
 *   { id, text_inhalt, dateiname, dauer_sek, gueltig_bis, aktiv }
 *
 * Generische Eintrags-Regeln (siehe Abschnitt 5 der Doku) werden defensiv
 * auch hier im Frontend angewandt:
 *   - aktiv == 0  -> Eintrag wird übersprungen
 *   - gueltig_bis < heute -> abgelaufener Eintrag wird übersprungen
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

    window.TanzschuleModule.ankuendigung = {
        getSlides: function (settings, inhalte, fertig) {
            settings = settings || {};
            inhalte  = (inhalte || []).filter(istAktiv);

            var uploadsBase = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';
            var uebergang   = settings.uebergang === 'none' ? 'none' : 'fade';
            var schriftPx   = parseInt(settings.schrift_groesse, 10) || 60;
            var pillAlpha   = parseFloat(settings.pill_transparenz) || 0.15;

            if (inhalte.length === 0) {
                var leer = document.createElement('div');
                leer.className = 'tm-modul-ankuendigung';
                leer.style.cssText = 'width:100%;height:100%;';
                leer.innerHTML = '<div class="tm-ank-leer">Keine Ankündigungen</div>';
                fertig([{ el: leer, dauerSek: 30 }]);
                return;
            }

            fertig(inhalte.map(function (eintrag) {
                var el = document.createElement('div');
                el.style.cssText = 'position:relative;width:100%;height:100%;overflow:hidden;';

                var bildUrl = eintrag.dateiname
                    ? uploadsBase + encodeURIComponent(eintrag.dateiname)
                    : null;

                if (bildUrl) {
                    el.className = 'tm-modul-ankuendigung tm-ank-slide tm-ank-mit-bild';
                    el.innerHTML =
                        '<div class="tm-ank-bg"><img alt="" src="' + bildUrl + '"></div>'
                        + (eintrag.text_inhalt
                            ? '<div class="tm-ank-text" style="font-size:' + schriftPx
                                + 'px;background:rgba(0,0,0,' + pillAlpha + ')">'
                                + escapeHtml(eintrag.text_inhalt) + '</div>'
                            : '');
                } else {
                    el.className = 'tm-modul-ankuendigung tm-ank-slide';
                    el.innerHTML = eintrag.text_inhalt
                        ? '<div class="tm-ank-text" style="font-size:' + schriftPx + 'px">'
                            + escapeHtml(eintrag.text_inhalt) + '</div>'
                        : '';
                }

                return {
                    el:        el,
                    dauerSek:  (eintrag.dauer_sek && eintrag.dauer_sek > 0)
                        ? eintrag.dauer_sek
                        : (settings.intervall_sek || 12),
                    uebergang: uebergang
                };
            }));
        }
    };
})();
