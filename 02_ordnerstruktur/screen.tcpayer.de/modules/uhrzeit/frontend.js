/**
 * modules/uhrzeit/frontend.js
 *
 * Slide-Engine-Modul (Etappe 3, siehe KONZEPT_SLIDE_ENGINE.md):
 * EIN Slide, dessen Inhalt sich intern jede Sekunde aktualisiert.
 * destroy() räumt das Intervall ab.
 *
 * Darstellungen:
 *   digital — große Zeit + Datum (wie bisher)
 *   analog  — SVG-Zifferblatt (Ziffern 12/3/6/9 + Striche, Sekundenzeiger
 *             im Markenrot), Datum darunter
 *
 * Optional Hintergrundbild aus der Mediathek (settings.hintergrund_bild =
 * Dateiname): Uhr + Datum sitzen dann auf einer abgerundeten Pill mit
 * konfigurierbarer Transparenz (settings.pill_transparenz) — gleiches
 * Muster wie das Ankündigungs-Modul.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    var WOCHENTAGE_LANG = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function formatZeit(date, format) {
        var h24 = date.getHours();
        var m = pad(date.getMinutes());
        var s = pad(date.getSeconds());
        if (format === 'H:i:s') { return pad(h24) + ':' + m + ':' + s; }
        if (format === 'g:i A') {
            var h12 = h24 % 12 === 0 ? 12 : h24 % 12;
            var ampm = h24 < 12 ? 'AM' : 'PM';
            return h12 + ':' + m + ' ' + ampm;
        }
        return pad(h24) + ':' + m; // H:i default
    }

    function formatDatum(date, format) {
        var basis = pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear();
        if (format === 'l, d.m.Y') {
            return WOCHENTAGE_LANG[date.getDay()] + ', ' + basis;
        }
        return basis; // d.m.Y default
    }

    /**
     * SVG-Zifferblatt: Ziffern bei 12/3/6/9, Striche für die übrigen
     * Stunden, drei Zeiger (Sekunde in #ad2121) + rote Nabe.
     * viewBox 200×200 → skaliert verlustfrei auf jede Spaltengröße.
     */
    function baueZifferblatt() {
        var s = '<svg class="tm-uhr-svg" viewBox="0 0 200 200">';
        s += '<circle cx="100" cy="100" r="96" fill="rgba(255,255,255,0.05)" stroke="rgba(255,255,255,0.28)" stroke-width="2"/>';

        // Striche für 1,2,4,5,7,8,10,11 Uhr
        for (var h = 0; h < 12; h++) {
            if (h % 3 === 0) { continue; } // dort stehen Ziffern
            var a  = h * 30 * Math.PI / 180;
            var x1 = 100 + Math.sin(a) * 84, y1 = 100 - Math.cos(a) * 84;
            var x2 = 100 + Math.sin(a) * 92, y2 = 100 - Math.cos(a) * 92;
            s += '<line x1="' + x1.toFixed(1) + '" y1="' + y1.toFixed(1)
                + '" x2="' + x2.toFixed(1) + '" y2="' + y2.toFixed(1)
                + '" stroke="rgba(255,255,255,0.55)" stroke-width="3" stroke-linecap="round"/>';
        }

        // Ziffern 12 / 3 / 6 / 9
        var ziffern = [ [100, 26, '12'], [174, 100, '3'], [100, 174, '6'], [26, 100, '9'] ];
        ziffern.forEach(function (z) {
            s += '<text x="' + z[0] + '" y="' + z[1] + '" text-anchor="middle" dominant-baseline="central" '
                + 'fill="#fff" font-size="27" font-weight="600" font-family="inherit">' + z[2] + '</text>';
        });

        // Zeiger + Nabe
        s += '<line class="tm-uhr-zeiger-h" x1="100" y1="100" x2="100" y2="54" stroke="#fff" stroke-width="7" stroke-linecap="round"/>';
        s += '<line class="tm-uhr-zeiger-m" x1="100" y1="100" x2="100" y2="32" stroke="#fff" stroke-width="4.5" stroke-linecap="round"/>';
        s += '<line class="tm-uhr-zeiger-s" x1="100" y1="112" x2="100" y2="28" stroke="#ad2121" stroke-width="2"/>';
        s += '<circle cx="100" cy="100" r="6" fill="#ad2121"/>';
        s += '</svg>';
        return s;
    }

    window.TanzschuleModule.uhrzeit = {
        getSlides: function (settings, inhalte, fertig) {
            settings = settings || {};

            var analog     = settings.darstellung === 'analog';
            var zeigeDatum = settings.zeige_datum !== false;
            var bgDatei    = String(settings.hintergrund_bild || '').trim();
            var pillAlpha  = parseFloat(settings.pill_transparenz) || 0.15;
            var uploadsBase = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';

            var el = document.createElement('div');
            el.className = 'tm-modul-uhrzeit' + (analog ? ' tm-uhr--analog' : '');
            el.style.cssText = 'position:relative;width:100%;height:100%;overflow:hidden;'
                + 'display:flex;align-items:center;justify-content:center;';

            var innenHtml = analog
                ? baueZifferblatt() + (zeigeDatum ? '<div class="tm-uhrzeit-datum"></div>' : '')
                : '<div class="tm-uhrzeit-zeit"></div>'
                    + (zeigeDatum ? '<div class="tm-uhrzeit-datum"></div>' : '');

            if (bgDatei) {
                el.innerHTML =
                    '<div class="tm-uhr-bg"><img alt="" src="' + uploadsBase + encodeURIComponent(bgDatei) + '"></div>'
                    + '<div class="tm-uhr-inhalt tm-uhr-pill" style="background:rgba(0,0,0,' + pillAlpha + ')">'
                    + innenHtml + '</div>';
            } else {
                el.innerHTML = '<div class="tm-uhr-inhalt">' + innenHtml + '</div>';
            }

            var zeitEl   = el.querySelector('.tm-uhrzeit-zeit');
            var datumEl  = el.querySelector('.tm-uhrzeit-datum');
            var zeigerH  = el.querySelector('.tm-uhr-zeiger-h');
            var zeigerM  = el.querySelector('.tm-uhr-zeiger-m');
            var zeigerS  = el.querySelector('.tm-uhr-zeiger-s');

            function tick() {
                var now = new Date();
                if (analog) {
                    var h = (now.getHours() % 12) * 30 + now.getMinutes() * 0.5;
                    var m = now.getMinutes() * 6 + now.getSeconds() * 0.1;
                    var s = now.getSeconds() * 6;
                    if (zeigerH) { zeigerH.setAttribute('transform', 'rotate(' + h + ' 100 100)'); }
                    if (zeigerM) { zeigerM.setAttribute('transform', 'rotate(' + m + ' 100 100)'); }
                    if (zeigerS) { zeigerS.setAttribute('transform', 'rotate(' + s + ' 100 100)'); }
                } else if (zeitEl) {
                    zeitEl.textContent = formatZeit(now, settings.format_zeit || 'H:i');
                }
                if (datumEl) {
                    datumEl.textContent = formatDatum(now, settings.format_datum || 'd.m.Y');
                }
            }

            tick();
            var interval = setInterval(tick, 1000);

            fertig([{
                el:       el,
                dauerSek: (settings.anzeige_dauer_sek > 0) ? settings.anzeige_dauer_sek : 30,
                destroy:  function () { clearInterval(interval); }
            }]);
        }
    };
})();
