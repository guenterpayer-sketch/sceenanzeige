/**
 * modules/veranstaltung/frontend.js
 *
 * Lädt kommende Veranstaltungen von proxies/veranstaltungen.php
 * und zeigt sie als rotierende Slideshow.
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

    window.TanzschuleModule.veranstaltung = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-veranstaltung');

        if (container._tmTimeout) {
            clearTimeout(container._tmTimeout);
            container._tmTimeout = null;
        }

        var basis    = window.BACKEND_BASE || '';
        var anzahl   = (settings.anzahl != null) ? parseInt(settings.anzahl, 10) : 5;
        var dauerSek = (settings.anzeige_dauer_sek > 0) ? parseInt(settings.anzeige_dauer_sek, 10) : 10;
        var useFade  = settings.uebergang !== 'none';

        container.innerHTML = '<div class="tm-va-status">Lade Veranstaltungen…</div>';

        var url = basis + '/proxies/veranstaltungen.php?anzahl=' + anzahl;

        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    container.innerHTML = '<div class="tm-va-status tm-va-fehler">'
                        + escapeHtml(data.error) + '</div>';
                    return;
                }

                var events = data.events || [];
                if (events.length === 0) {
                    container.innerHTML = '<div class="tm-va-leer">Keine kommenden Veranstaltungen</div>';
                    return;
                }

                container.style.position = container.style.position || 'relative';
                container.innerHTML =
                    '<div class="tm-va-stage">'
                    + '<div class="tm-va-slide tm-va-layer-a" style="position:absolute;inset:0;opacity:0;"></div>'
                    + '<div class="tm-va-slide tm-va-layer-b" style="position:absolute;inset:0;opacity:0;"></div>'
                    + '</div>';

                var layerA = container.querySelector('.tm-va-layer-a');
                var layerB = container.querySelector('.tm-va-layer-b');

                if (useFade) {
                    layerA.style.transition = 'opacity 600ms ease';
                    layerB.style.transition = 'opacity 600ms ease';
                }

                var aktiver   = layerA;
                var inaktiver = layerB;
                var index     = 0;
                var erster    = true;

                function zeigeNaechste() {
                    var ev = events[index];

                    if (erster || !useFade) {
                        renderSlide(aktiver, ev);
                        aktiver.style.opacity   = '1';
                        inaktiver.style.opacity = '0';
                        erster = false;
                    } else {
                        renderSlide(inaktiver, ev);
                        inaktiver.style.opacity = '1';
                        aktiver.style.opacity   = '0';
                        var tmp = aktiver;
                        aktiver   = inaktiver;
                        inaktiver = tmp;
                    }

                    index = (index + 1) % events.length;
                    if (events.length > 1) {
                        container._tmTimeout = setTimeout(zeigeNaechste, dauerSek * 1000);
                    }
                }

                zeigeNaechste();
            })
            .catch(function () {
                container.innerHTML = '<div class="tm-va-status tm-va-fehler">'
                    + 'Veranstaltungen konnten nicht geladen werden.</div>';
            });
    };
})();
