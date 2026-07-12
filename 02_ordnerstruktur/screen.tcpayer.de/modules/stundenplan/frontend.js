/**
 * modules/stundenplan/frontend.js
 *
 * Slide-Engine-Modul (Etappe 3, siehe KONZEPT_SLIDE_ENGINE.md):
 * holt die Kursdaten serverseitig über proxies/nc.php (Nimbuscloud
 * Legacy-API) und liefert EINEN Slide mit der zeitgesteuerten Liste:
 * vergangene Kurse werden ausgeblendet, der aktuell laufende Kurs wird
 * hervorgehoben (Akzentbalken), danach folgen die nächsten.
 *
 * Der Fetch passiert in getSlides — die Settle-Phase der Engine wartet
 * automatisch auf die Daten (kein sichtbarer "Lade…"-Zustand mehr).
 * Daten-Refresh: bei Spalten-Rotation sammelt die Engine nach jeder
 * Runde neu (frischer Fetch); allein in einer Spalte wie bisher über
 * den Monitor-Zyklus.
 *
 * Die feste Kartenhöhe (immer auf die konfigurierte Maximalanzahl
 * gerechnet) braucht ein lebendes DOM → Messung im onMount-Hook.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatZeit(dateStr) {
        if (!dateStr) { return ''; }
        var m = String(dateStr).match(/(\d{2}):(\d{2})/);
        return m ? m[1] + ':' + m[2] : String(dateStr);
    }

    // Parst Datum/Zeit-String als Date-Objekt.
    // Unterstützt "2024-01-15 14:30:00" (vollständig) und "14:30:00" (nur Zeit → heute).
    function parseTimestamp(dateStr) {
        if (!dateStr) { return null; }
        var s = String(dateStr);
        var d = new Date(s.replace(' ', 'T'));
        if (!isNaN(d.getTime()) && d.getFullYear() > 2000) { return d; }
        var m = s.match(/(\d{2}):(\d{2})/);
        if (m) {
            var t = new Date();
            t.setHours(parseInt(m[1], 10), parseInt(m[2], 10), 0, 0);
            return t;
        }
        return null;
    }

    window.TanzschuleModule.stundenplan = {
        getSlides: function (settings, inhalte, fertig) {
            settings = settings || {};

            var titel       = (settings.titel || '').trim();
            var basis       = window.BACKEND_BASE || '';
            var nurHeute    = settings.nur_heute === false ? '0' : '1';
            var anzahl      = (settings.anzahl_kurse != null) ? parseInt(settings.anzahl_kurse, 10) : 5;
            var locationIds = settings.location_ids || '';
            var roomId      = settings.room_id ? parseInt(settings.room_id, 10) : 0;
            var dauerSek    = (settings.anzeige_dauer_sek > 0) ? settings.anzeige_dauer_sek : 30;

            var headingHtml = titel ? '<div class="tm-sp-heading">' + escapeHtml(titel) + '</div>' : '';

            var el = document.createElement('div');
            el.className = 'tm-modul-stundenplan';
            el.style.cssText = 'width:100%;height:100%;';

            // Feste Kartenhöhe: immer auf anzahl (max) gerechnet, damit wenige
            // Kurse keine riesigen Karten erzeugen. Braucht gemessene Höhen →
            // läuft im onMount-Hook (und ist wirkungslos, solange totalH 0 ist).
            function passeKartenAn() {
                var cardsEl = el.querySelector('.tm-sp-cards');
                if (!cardsEl) { return; }
                var totalH = cardsEl.clientHeight;
                if (totalH <= 0) { return; }
                var gapPx = parseFloat(window.getComputedStyle(cardsEl).gap) || 7;
                var cardH = Math.floor((totalH - gapPx * (anzahl - 1)) / anzahl);
                if (cardH <= 0) { return; }
                cardsEl.querySelectorAll('.tm-sp-card').forEach(function (c) {
                    c.style.flex = '0 0 ' + cardH + 'px';
                    c.style.overflow = 'hidden';
                });
            }

            function liefere() {
                fertig([{
                    el:       el,
                    dauerSek: dauerSek,
                    onMount:  function () { requestAnimationFrame(passeKartenAn); }
                }]);
            }

            var url = basis + '/proxies/nc.php?nur_heute=' + nurHeute;
            if (locationIds !== '') {
                url += '&location_ids=' + encodeURIComponent(locationIds);
            }
            if (roomId > 0) {
                url += '&room_id=' + roomId;
            }

            fetch(url, { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        el.innerHTML = headingHtml + '<div class="tm-sp-status tm-sp-fehler">'
                            + escapeHtml(data.error) + '</div>';
                        liefere();
                        return;
                    }

                    var kurse = data.kurse || [];
                    var jetzt = new Date();

                    // Sortieren nach Startzeit
                    kurse.sort(function (a, b) {
                        var ta = parseTimestamp(a.start_date);
                        var tb = parseTimestamp(b.start_date);
                        return (ta ? ta.getTime() : 0) - (tb ? tb.getTime() : 0);
                    });

                    // Zeitfilter: vergangene ausblenden, laufende markieren
                    var sichtbar = [];
                    kurse.forEach(function (k) {
                        var start = parseTimestamp(k.start_date);
                        var ende  = parseTimestamp(k.end_date);
                        if (ende && ende <= jetzt) { return; } // bereits beendet
                        sichtbar.push({
                            kurs:    k,
                            aktuell: !!(start && start <= jetzt)
                        });
                    });

                    // Anzahl-Limit erst nach Zeitfilter anwenden
                    if (anzahl > 0) { sichtbar = sichtbar.slice(0, anzahl); }

                    if (sichtbar.length === 0) {
                        el.innerHTML = headingHtml + '<div class="tm-sp-status">Keine weiteren Kurse heute</div>';
                        liefere();
                        return;
                    }

                    var html = headingHtml + '<div class="tm-sp-cards">';
                    sichtbar.forEach(function (item) {
                        var k  = item.kurs;
                        var ak = item.aktuell;
                        var style = (ak && k.color) ? ' style="border-left-color:' + escapeHtml(k.color) + '"' : '';
                        html += '<div class="tm-sp-card' + (ak ? ' tm-sp-card--aktuell' : '') + '"' + style + '>'
                            + '<div class="tm-sp-zeit">' + escapeHtml(formatZeit(k.start_date)) + '</div>'
                            + '<div class="tm-sp-saal">' + escapeHtml(k.room || '') + '</div>'
                            + '<div class="tm-sp-kurs">' + escapeHtml(k.displayName || k.course_key) + '</div>'
                            + '<div class="tm-sp-lehrer">' + escapeHtml(k.teacher || '') + '</div>'
                            + '</div>';
                    });
                    html += '</div>';
                    el.innerHTML = html;
                    liefere();
                })
                .catch(function () {
                    el.innerHTML = headingHtml + '<div class="tm-sp-status tm-sp-fehler">'
                        + 'Stundenplan konnte nicht geladen werden.</div>';
                    liefere();
                });
        }
    };
})();
