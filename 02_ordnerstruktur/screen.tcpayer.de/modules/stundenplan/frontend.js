/**
 * modules/stundenplan/frontend.js
 *
 * Slide-Engine-Modul (Etappe 3, siehe KONZEPT_SLIDE_ENGINE.md):
 * holt die Kursdaten serverseitig über proxies/nc.php (Nimbuscloud
 * Legacy-API) und liefert EINEN Slide mit der zeitgesteuerten Liste:
 * vergangene Kurse werden ausgeblendet, der aktuell laufende Kurs wird
 * hervorgehoben (Akzentbalken), danach folgen die nächsten.
 *
 * ── Kontingent-Schonung + Selbstheilung ──────────────────────────────────
 * Anzeige-Refresh und API-Abruf sind strikt getrennt:
 *
 *   ANZEIGE  tickt jede Minute lokal aus den bereits geholten Daten neu
 *            (beendete Kurse fallen raus, „aktuell"-Balken wandert weiter)
 *            → KEIN API-Abruf.
 *   ABRUF    passiert nur beim Seitenaufbau (= „Monitor neu laden"), beim
 *            Datumswechsel um Mitternacht und als Retry nach einem Fehler.
 *
 * Der Tages-Cache (gültig bis Mitternacht, Schlüssel = Abfrage-URL) gilt
 * modulweit: Steht die Kursanzeige mit anderen Modulen in einer Spalte,
 * sammelt die Engine nach jeder Rotationsrunde neu — diese Runden lösen
 * jetzt keinen Netzabruf mehr aus. Parallele Anfragen auf dieselbe URL
 * werden zusammengefasst (In-flight-Dedup).
 *
 * ── Warum das Modul nach „Monitor neu laden" hängen blieb ────────────────
 * Der Abruf hatte kein Timeout: Blieb er nach dem Reload stehen, wurde
 * fertig() nie gerufen, die Engine wartete ewig auf diese Instanz und die
 * Spalte blieb bis zum manuellen Neuladen leer. Jetzt gilt: fertig() wird
 * GARANTIERT gerufen (Fetch-Timeout + Sicherheitstimer), und ein Fehler
 * heilt sich über Retries selbst aus, statt bis zum nächsten F5 zu stehen.
 *
 * Die feste Kartenhöhe (immer auf die konfigurierte Maximalanzahl
 * gerechnet) braucht ein lebendes DOM → Messung im onMount-Hook, mit
 * Wiederholung, bis eine Höhe > 0 messbar ist.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    // ── Konstanten ───────────────────────────────────────────────────────────

    var FETCH_TIMEOUT_MS  = 10000;                 // harte Obergrenze je Abruf
    var TICK_MS           = 60000;                 // lokales Neu-Rendern (0 API-Calls)
    var RETRY_MS          = [15000, 30000, 60000]; // schnelle Versuche nach Fehler
    var RETRY_LANGSAM_MS  = 600000;                // danach: alle 10 min, nur ohne gültige Daten
    var HOEHE_VERSUCHE    = 20;                    // Kartenhöhen-Messung (×100 ms)
    var HOEHE_PAUSE_MS    = 100;

    // ── Modulweiter Tages-Cache ──────────────────────────────────────────────
    // url -> { kurse: [], geholtAm: ms, gueltigBis: ms }   (gültig bis Mitternacht)
    var cache   = {};
    // url -> Promise   (läuft gerade ein Abruf? → nicht doppelt anfragen)
    var laufend = {};

    function mitternachtDanach() {
        var d = new Date();
        d.setHours(24, 0, 0, 0);
        return d.getTime();
    }

    /**
     * Liefert die Kursdaten zur URL — aus dem Cache, aus einem bereits
     * laufenden Abruf oder per Netzabruf mit hartem Timeout.
     * Erfolg wird gecacht, Fehler NICHT (damit der Retry greifen kann).
     */
    function holeKurse(url) {
        var vorhanden = cache[url];
        if (vorhanden && Date.now() < vorhanden.gueltigBis) {
            return Promise.resolve(vorhanden);
        }
        if (laufend[url]) {
            return laufend[url];
        }

        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var optionen = { cache: 'no-store' };
        if (ctrl) { optionen.signal = ctrl.signal; }

        var netz = fetch(url, optionen)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) { throw new Error(data.error); }
                var eintrag = {
                    kurse:      (data && data.kurse) || [],
                    geholtAm:   Date.now(),
                    gueltigBis: mitternachtDanach()
                };
                cache[url] = eintrag;
                return eintrag;
            });
        // Verhindert "unhandled rejection", falls der Timeout zuerst greift.
        netz.catch(function () { /* wird über das Race behandelt */ });

        var timeoutId = null;
        var timeout = new Promise(function (_, reject) {
            timeoutId = setTimeout(function () {
                if (ctrl) { try { ctrl.abort(); } catch (e) { /* ignore */ } }
                reject(new Error('Zeitüberschreitung beim Stundenplan-Abruf'));
            }, FETCH_TIMEOUT_MS);
        });

        var p = Promise.race([netz, timeout]).finally(function () {
            if (timeoutId) { clearTimeout(timeoutId); }
            delete laufend[url];
        });

        laufend[url] = p;
        return p;
    }

    // ── Helfer ───────────────────────────────────────────────────────────────

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

            var url = basis + '/proxies/nc.php?nur_heute=' + nurHeute;
            if (locationIds !== '') {
                url += '&location_ids=' + encodeURIComponent(locationIds);
            }
            if (roomId > 0) {
                url += '&room_id=' + roomId;
            }

            var el = document.createElement('div');
            el.className = 'tm-modul-stundenplan';
            el.style.cssText = 'width:100%;height:100%;';

            var zerstoert    = false;
            var geliefert    = false;
            var tickTimer    = null;
            var retryTimer   = null;
            var sicherTimer  = null;
            var hoeheTimer   = null;
            var retryIndex   = 0;

            // ── Kartenhöhe ───────────────────────────────────────────────────
            // Feste Kartenhöhe: immer auf anzahl (max) gerechnet, damit wenige
            // Kurse keine riesigen Karten erzeugen. Braucht eine gemessene
            // Höhe → notfalls mehrfach versuchen (nach einem Reload steht das
            // Layout nicht zwingend schon im ersten Frame).
            function passeKartenAn(versuch) {
                if (zerstoert) { return; }
                var cardsEl = el.querySelector('.tm-sp-cards');
                if (!cardsEl) { return; }

                var totalH = cardsEl.clientHeight;
                if (totalH <= 0) {
                    if (versuch < HOEHE_VERSUCHE) {
                        if (hoeheTimer) { clearTimeout(hoeheTimer); }
                        hoeheTimer = setTimeout(function () {
                            passeKartenAn(versuch + 1);
                        }, HOEHE_PAUSE_MS);
                    }
                    return;
                }

                var karten = cardsEl.querySelectorAll('.tm-sp-card');
                var n      = (anzahl > 0) ? anzahl : (karten.length || 1);
                var gapPx  = parseFloat(window.getComputedStyle(cardsEl).gap) || 7;
                var cardH  = Math.floor((totalH - gapPx * (n - 1)) / n);
                if (!isFinite(cardH) || cardH <= 0) { return; }

                karten.forEach(function (c) {
                    c.style.flex     = '0 0 ' + cardH + 'px';
                    c.style.overflow = 'hidden';
                });
            }

            function messeKarten() {
                requestAnimationFrame(function () { passeKartenAn(0); });
            }

            // ── Rendern ──────────────────────────────────────────────────────

            // Zuletzt geschriebenes HTML — verhindert, dass der Minuten-Takt
            // das DOM unnötig neu aufbaut (Flackern auf dem TV). Neu
            // geschrieben wird nur, wenn sich wirklich etwas geändert hat.
            var letztesHtml = null;

            function schreibe(html) {
                if (html === letztesHtml) { return false; }
                letztesHtml = html;
                el.innerHTML = html;
                return true;
            }

            function zeigeStatus(text, istFehler) {
                schreibe(headingHtml + '<div class="tm-sp-status'
                    + (istFehler ? ' tm-sp-fehler' : '') + '">'
                    + escapeHtml(text) + '</div>');
            }

            /**
             * Zeichnet die Liste aus den vorliegenden Cache-Daten neu.
             * Rein lokal — kein Netzabruf. Wird jede Minute gerufen, damit
             * beendete Kurse verschwinden und der „aktuell"-Balken wandert.
             * Auch abgelaufene Daten werden gezeichnet: besser der letzte
             * bekannte Stand als eine Fehlermeldung.
             */
            function zeichne() {
                if (zerstoert) { return false; }
                var eintrag = cache[url];
                if (!eintrag) { return false; }

                var jetzt = new Date();
                var kurse = eintrag.kurse.slice();

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
                    zeigeStatus('Keine weiteren Kurse heute', false);
                    return true;
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
                if (schreibe(html)) { messeKarten(); }
                return true;
            }

            // ── Lebenszyklus ─────────────────────────────────────────────────

            function aufraeumen() {
                zerstoert = true;
                if (tickTimer)   { clearInterval(tickTimer); tickTimer   = null; }
                if (retryTimer)  { clearTimeout(retryTimer);  retryTimer  = null; }
                if (sicherTimer) { clearTimeout(sicherTimer); sicherTimer = null; }
                if (hoeheTimer)  { clearTimeout(hoeheTimer);  hoeheTimer  = null; }
            }

            /**
             * Übergibt den Slide an die Engine — genau einmal, und garantiert:
             * Ohne diesen Aufruf wartet sammleSpaltenSlides() ewig und die
             * ganze Spalte bleibt leer (der Hänger nach „Monitor neu laden").
             */
            function liefere() {
                if (geliefert || zerstoert) { return; }
                geliefert = true;
                if (sicherTimer) { clearTimeout(sicherTimer); sicherTimer = null; }
                fertig([{
                    el:       el,
                    dauerSek: dauerSek,
                    onMount:  messeKarten,
                    destroy:  aufraeumen
                }]);
            }

            function planeRetry() {
                if (zerstoert) { return; }
                var ms = (retryIndex < RETRY_MS.length) ? RETRY_MS[retryIndex] : RETRY_LANGSAM_MS;
                retryIndex++;
                if (retryTimer) { clearTimeout(retryTimer); }
                retryTimer = setTimeout(function () {
                    retryTimer = null;
                    hole();
                }, ms);
            }

            function hole() {
                if (zerstoert) { return; }
                holeKurse(url).then(function () {
                    if (zerstoert) { return; }
                    retryIndex = 0;
                    zeichne();
                    liefere();
                }).catch(function (err) {
                    if (zerstoert) { return; }
                    console.error('[stundenplan] Abruf fehlgeschlagen:', err && err.message ? err.message : err);
                    // Alte Daten sind besser als eine Fehlermeldung.
                    if (!zeichne()) {
                        zeigeStatus('Stundenplan konnte nicht geladen werden.', true);
                    }
                    liefere();   // Engine darf nie auf uns warten
                    planeRetry();
                });
            }

            // Sicherheitsnetz: Selbst wenn oben etwas Unerwartetes passiert,
            // bekommt die Engine ihren Slide — lieber ein Hinweistext als
            // eine tote Spalte.
            sicherTimer = setTimeout(function () {
                if (geliefert || zerstoert) { return; }
                if (!zeichne()) {
                    zeigeStatus('Stundenplan konnte nicht geladen werden.', true);
                }
                liefere();
                planeRetry();
            }, FETCH_TIMEOUT_MS + 2000);

            // Minuten-Takt: lokal neu zeichnen; nur beim Datumswechsel
            // (Cache abgelaufen) wird tatsächlich neu geholt.
            tickTimer = setInterval(function () {
                if (zerstoert) { return; }
                var eintrag = cache[url];
                if (!eintrag) { return; }              // ohne Daten läuft der Retry
                if (Date.now() >= eintrag.gueltigBis) {
                    hole();                             // Mitternacht → 1 Abruf
                } else {
                    zeichne();                          // 0 Abrufe
                }
            }, TICK_MS);

            hole();
        }
    };
})();
