/**
 * modules/veranstaltung/frontend.js
 *
 * Slide-Engine-Modul (Etappe 2, siehe KONZEPT_SLIDE_ENGINE.md):
 * lädt kommende Veranstaltungen von proxies/veranstaltungen.php und
 * liefert einen Slide pro Event. Rotation, Überblendung, Timer und
 * Cleanup besitzt die Engine; der Fetch passiert in getSlides — die
 * Settle-Phase der Engine wartet damit automatisch auf die Daten.
 *
 * Adaptives Layout je Bildformat:
 *   Hochkant (breite/hoehe < 0.85) → Bild links 40 %, Text rechts
 *   Querformat/quadratisch          → Vollbild + Gradient-Overlay
 *   Kein Bild                       → Nur Text, zentriert
 *
 * Bildmaße kommen aus dem Proxy (bild_breite/bild_hoehe).
 * Fehlen sie, wird nach img.onload nachgemessen.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    var WOCHENTAGE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    var MONATE = [
        'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
    ];

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function parseDate(s) {
        if (!s) { return null; }
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function formatDatum(d) {
        if (!d) { return ''; }
        return WOCHENTAGE[d.getDay()]
            + ', ' + d.getDate() + '. '
            + MONATE[d.getMonth()] + ' ' + d.getFullYear();
    }

    function formatZeit(d) {
        if (!d) { return ''; }
        return String(d.getHours()).padStart(2, '0')
            + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    // Bildorientierung aus Maßen; null = unbekannt
    function orientierung(breite, hoehe) {
        if (!breite || !hoehe) { return null; }
        return (breite / hoehe < 0.85) ? 'portrait' : 'landscape';
    }

    function renderSlide(el, ev) {
        var start = parseDate(ev.start_date);
        var ende  = parseDate(ev.end_date);

        var datumStr = formatDatum(start);
        var zeitStr  = '';
        if (start) {
            zeitStr = formatZeit(start);
            if (ende && formatZeit(ende) !== '00:00') {
                zeitStr += ' – ' + formatZeit(ende) + ' Uhr';
            } else {
                zeitStr += ' Uhr';
            }
        }

        var infoHtml =
            '<div class="tm-va-datum">'   + escapeHtml(datumStr) + '</div>'
            + '<div class="tm-va-uhrzeit">' + escapeHtml(zeitStr)  + '</div>'
            + '<div class="tm-va-titel">'   + escapeHtml(ev.titel)  + '</div>';

        // Hilfsfunktion: Klassen-Variante setzen
        function setVariant(variant) {
            el.classList.remove('tm-va-slide--portrait', 'tm-va-slide--landscape', 'tm-va-slide--keinbild');
            el.classList.add('tm-va-slide--' + variant);
        }

        if (!ev.bild_url) {
            setVariant('keinbild');
            el.innerHTML = '<div class="tm-va-info">' + infoHtml + '</div>';
            return;
        }

        var glowUrl = ev.bild_url.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        var bildHtmlLandscape = '<div class="tm-va-bild">'
            + '<div class="tm-va-bild-glow" style="background-image:url(\'' + glowUrl + '\')"></div>'
            + '<img alt="" src="' + escapeHtml(ev.bild_url) + '">'
            + '</div>';
        var bildHtmlPortrait = '<div class="tm-va-bild">'
            + '<div class="tm-va-bild-glow" style="background-image:url(\''
            + ev.bild_url.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + '\')"></div>'
            + '<img alt="" src="' + escapeHtml(ev.bild_url) + '">'
            + '</div>';

        function applyLayout(orient) {
            setVariant(orient);
            if (orient === 'portrait') {
                el.innerHTML = bildHtmlPortrait + '<div class="tm-va-info">' + infoHtml + '</div>';
            } else {
                el.innerHTML = bildHtmlLandscape
                    + '<div class="tm-va-overlay"></div>'
                    + '<div class="tm-va-info">' + infoHtml + '</div>';
            }
        }

        var o = orientierung(ev.bild_breite, ev.bild_hoehe);
        if (o) {
            applyLayout(o);
            return;
        }

        // Maße unbekannt → Bild laden und nachmessen, bis dahin landscape als Fallback
        applyLayout('landscape');
        var img = el.querySelector('img');
        if (img) {
            img.onload = function () {
                if (img.naturalWidth && img.naturalHeight) {
                    applyLayout(orientierung(img.naturalWidth, img.naturalHeight) || 'landscape');
                }
            };
        }
    }

    window.TanzschuleModule.veranstaltung = {
        getSlides: function (settings, inhalte, fertig) {
            settings = settings || {};

            var basis     = window.BACKEND_BASE || '';
            var anzahl    = (settings.anzahl != null) ? parseInt(settings.anzahl, 10) : 5;
            var dauerSek  = (settings.anzeige_dauer_sek > 0) ? parseInt(settings.anzeige_dauer_sek, 10) : 10;
            var uebergang = settings.uebergang === 'none' ? 'none' : 'fade';

            // Status-/Fehler-Slide (Design-Entscheidung: Ausfälle sind am
            // Monitor sichtbar, keine stumm übersprungene Instanz)
            function statusSlide(text, istFehler) {
                var el = document.createElement('div');
                el.className = 'tm-modul-veranstaltung';
                el.style.cssText = 'width:100%;height:100%;';
                el.innerHTML = '<div class="' + (istFehler ? 'tm-va-status tm-va-fehler' : 'tm-va-leer') + '">'
                    + escapeHtml(text) + '</div>';
                return { el: el, dauerSek: dauerSek };
            }

            var url = basis + '/proxies/veranstaltungen.php?anzahl=' + anzahl;

            fetch(url, { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        fertig([statusSlide(data.error, true)]);
                        return;
                    }

                    var events = data.events || [];
                    if (events.length === 0) {
                        fertig([statusSlide('Keine kommenden Veranstaltungen', false)]);
                        return;
                    }

                    fertig(events.map(function (ev) {
                        var el = document.createElement('div');
                        el.className = 'tm-modul-veranstaltung tm-va-slide';
                        el.style.cssText = 'position:relative;width:100%;height:100%;overflow:hidden;';
                        renderSlide(el, ev);
                        return { el: el, dauerSek: dauerSek, uebergang: uebergang };
                    }));
                })
                .catch(function () {
                    fertig([statusSlide('Veranstaltungen konnten nicht geladen werden.', true)]);
                });
        }
    };
})();
