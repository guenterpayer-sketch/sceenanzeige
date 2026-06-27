/**
 * modules/veranstaltung/frontend.js
 *
 * Lädt kommende Veranstaltungen von proxies/veranstaltungen.php
 * (WordPress "The Events Calendar" REST-API) und zeigt sie als
 * rotierende Slideshow mit Datum, Uhrzeit, Titel, Veranstaltungsort
 * und optionalem Bild.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    var WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    var MONATE = [
        'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
    ];

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // "2025-09-20 19:00:00" → Date-Objekt
    function parseDate(s) {
        if (!s) { return null; }
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    // Date → "Sa, 20. September 2025"
    function formatDatum(d) {
        if (!d) { return ''; }
        return WOCHENTAGE[d.getDay()]
            + ', ' + d.getDate() + '. '
            + MONATE[d.getMonth()] + ' ' + d.getFullYear();
    }

    // Date → "19:00"
    function formatZeit(d) {
        if (!d) { return ''; }
        var h = String(d.getHours()).padStart(2, '0');
        var m = String(d.getMinutes()).padStart(2, '0');
        return h + ':' + m;
    }

    function renderSlide(el, ev) {
        var start = parseDate(ev.start_date);
        var ende  = parseDate(ev.end_date);

        var bildHtml = '';
        if (ev.bild_url) {
            bildHtml = '<div class="tm-va-bild"><img alt="" src="' + escapeHtml(ev.bild_url) + '"></div>';
        }

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

        var venueHtml = ev.venue
            ? '<div class="tm-va-venue">' + escapeHtml(ev.venue) + '</div>'
            : '';

        el.innerHTML = bildHtml
            + '<div class="tm-va-info' + (ev.bild_url ? '' : ' tm-va-info--keinbild') + '">'
            + '<div class="tm-va-datum">' + escapeHtml(datumStr) + '</div>'
            + '<div class="tm-va-uhrzeit">' + escapeHtml(zeitStr) + '</div>'
            + '<div class="tm-va-titel">' + escapeHtml(ev.titel) + '</div>'
            + venueHtml
            + '</div>';
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
