/**
 * modules/fret/frontend.js
 *
 * Slide-Engine-Modul (Etappe 3, siehe KONZEPT_SLIDE_ENGINE.md):
 * EIN Slide, der intern lebt — pollt proxies/fret.php und aktualisiert
 * Song, Fortschrittsbalken und Warteliste. destroy() räumt Poll-Intervall,
 * requestAnimationFrame und Countdown-Intervalle ab.
 *
 * Fortschrittsbalken:
 *   - remainingSeconds + lokale Empfangszeit (Date.now()) → requestAnimationFrame-Loop
 *   - isPlaying=false oder remainingSeconds=null → Balken einfrieren (kein Reset)
 *   - startTime-Fallback wenn remainingSeconds null
 *   - Songwechsel (songId) → Balken auf 0 zurücksetzen
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function badges(taenze) {
        return (taenze || []).map(function (d) {
            var cls = d.isMain ? 'tm-song-badge tm-song-badge-main' : 'tm-song-badge tm-song-badge-sub';
            return '<span class="' + cls + '">' + escapeHtml(d.name) + '</span>';
        }).join('');
    }

    function formatCountdown(sek) {
        if (sek <= 0) { return 'Jetzt'; }
        if (sek < 60) { return 'in weniger als einer Minute'; }
        var m = Math.floor(sek / 60);
        return 'in ' + m + ' Minute' + (m !== 1 ? 'n' : '');
    }

    window.TanzschuleModule.fret = {
        getSlides: function (settings, inhalte, fertig) {
            settings = settings || {};

            var basis          = window.BACKEND_BASE || '';
            var titel          = settings.titel || 'FRET';
            var computerId     = settings.computer_id || '';
            var zeigePlaylist  = settings.zeige_playlist !== false;
            var anzahlKommende = (settings.anzahl_kommende != null) ? settings.anzahl_kommende : 3;
            var pollSek        = (settings.poll_sek && settings.poll_sek >= 3) ? settings.poll_sek : 7;
            var dauerSek       = (settings.anzeige_dauer_sek > 0) ? settings.anzeige_dauer_sek : 30;

            var el = document.createElement('div');
            el.className = 'tm-modul-fret';
            el.style.cssText = 'width:100%;height:100%;';

            if (!computerId) {
                el.innerHTML = '<div class="tm-song-heading">' + escapeHtml(titel) + '</div>'
                    + '<div class="tm-song-status">Kein Saal (FRET-Computer) ausgewählt.</div>';
                fertig([{ el: el, dauerSek: dauerSek }]);
                return;
            }

            el.innerHTML =
                '<div class="tm-song-heading">' + escapeHtml(titel) + '</div>'
                + '<div class="tm-song-aktuell"></div>'
                + '<div class="tm-song-progress"><div class="tm-song-progress-bar"></div></div>'
                + (zeigePlaylist
                    ? '<div class="tm-song-label">Nächste Titel</div><div class="tm-song-kommende"></div>'
                    : '');

            var aktuellEl  = el.querySelector('.tm-song-aktuell');
            var barEl      = el.querySelector('.tm-song-progress-bar');
            var kommendeEl = el.querySelector('.tm-song-kommende');

            var fehlerZaehler = 0;

            // Lokaler Zustand (früher container._tm*) — destroy räumt alles ab
            var poll       = null;
            var raf        = null;
            var countdowns = [];

            // Fortschritts-Zustand
            var bar = {
                songId:          null,  // aktuell angezeigte Song-UUID
                dauer:           null,  // Gesamtlänge in Sek.
                restBeiEmpfang:  null,  // remainingSeconds zum Zeitpunkt des Poll-Eingangs
                empfangenAm:     null,  // Date.now() beim Poll-Eingang
                letzterWert:     0,     // letzter gezeichneter Fortschritt (0–1), für Einfrieren
                laeuft:          false  // true nur wenn isPlaying + Positionsdaten vorhanden
            };

            function zeichneBalken(anteil) {
                barEl.style.width = (Math.max(0, Math.min(1, anteil)) * 100).toFixed(2) + '%';
            }

            function rafLoop() {
                if (!bar.laeuft) { return; }
                var vergangenSek = (Date.now() - bar.empfangenAm) / 1000;
                var restSek      = bar.restBeiEmpfang - vergangenSek;
                if (restSek <= 0) {
                    zeichneBalken(1);
                    bar.letzterWert = 1;
                    bar.laeuft = false;
                    return; // Song abgelaufen — nächster Poll bringt neuen Song
                }
                var anteil = 1 - restSek / bar.dauer;
                bar.letzterWert = anteil;
                zeichneBalken(anteil);
                raf = requestAnimationFrame(rafLoop);
            }

            function starteAnimation() {
                if (raf) { cancelAnimationFrame(raf); }
                raf = requestAnimationFrame(rafLoop);
            }

            function renderAktuell(data) {
                var s = data.aktuell;
                if (!s) {
                    aktuellEl.innerHTML = '<div class="tm-song-status">Kein Song</div>';
                    bar.laeuft = false;
                    if (raf) { cancelAnimationFrame(raf); }
                    return;
                }

                aktuellEl.innerHTML =
                    '<div class="tm-song-titel">' + escapeHtml(s.title) + '</div>'
                    + '<div class="tm-song-artist">' + escapeHtml(s.artist) + '</div>'
                    + '<div class="tm-song-badges">' + badges(s.taenze) + '</div>';

                // Songwechsel erkennen → Balken auf 0 zurücksetzen
                var neueSongId = s.songId || (s.title + '|' + s.artist);
                if (neueSongId !== bar.songId) {
                    bar.songId      = neueSongId;
                    bar.letzterWert = 0;
                    zeichneBalken(0);
                }

                if (data.isPlaying && s.duration) {
                    bar.dauer = s.duration;
                    if (s.remainingSeconds != null) {
                        // Primär: remainingSeconds + lokale Empfangszeit (exakt)
                        bar.restBeiEmpfang = s.remainingSeconds;
                        bar.empfangenAm    = Date.now();
                    } else if (s.startTime) {
                        // Fallback: startTime + duration (UTC-Differenz)
                        var startMs        = Date.parse(s.startTime);
                        var elapsedSek     = (Date.now() - startMs) / 1000;
                        bar.restBeiEmpfang = Math.max(0, s.duration - elapsedSek);
                        bar.empfangenAm    = Date.now();
                    } else {
                        // Keine Positionsdaten → einfrieren
                        bar.laeuft = false;
                        if (raf) { cancelAnimationFrame(raf); }
                        zeichneBalken(bar.letzterWert);
                        return;
                    }
                    bar.laeuft = true;
                    starteAnimation();
                } else {
                    // Pause/Stop → einfrieren, kein Reset
                    bar.laeuft = false;
                    if (raf) { cancelAnimationFrame(raf); }
                    zeichneBalken(bar.letzterWert);
                }
            }

            function renderKommende(data) {
                if (!zeigePlaylist || !kommendeEl) { return; }

                countdowns.forEach(function (id) { clearInterval(id); });
                countdowns = [];

                var liste = (data.kommende || []).slice(0, anzahlKommende);
                kommendeEl.innerHTML = '';
                if (liste.length === 0) { return; }

                // Restzeit des aktuellen Songs als Basis für Countdown-Fallback
                var restSekBasis = null;
                if (data.aktuell) {
                    var akt = data.aktuell;
                    if (akt.remainingSeconds != null) {
                        restSekBasis = akt.remainingSeconds;
                    } else if (akt.startTime && akt.duration) {
                        var startMs    = Date.parse(akt.startTime);
                        var elapsedSek = (Date.now() - startMs) / 1000;
                        restSekBasis   = Math.max(0, akt.duration - elapsedSek);
                    }
                }
                // Akkumulator: wächst je Song um dessen Dauer
                var akkumuliertSek = restSekBasis;

                var ul = document.createElement('ul');
                ul.className = 'tm-song-kommende-liste';

                liste.forEach(function (s) {
                    var li = document.createElement('li');
                    li.className = 'tm-song-kommende-eintrag';

                    var info = document.createElement('div');
                    info.className = 'tm-song-k-info';

                    var titelEl = document.createElement('div');
                    titelEl.className = 'tm-song-k-titel';
                    titelEl.textContent = s.title || '';
                    info.appendChild(titelEl);

                    if (s.artist) {
                        var artistEl = document.createElement('div');
                        artistEl.className = 'tm-song-k-artist';
                        artistEl.textContent = s.artist;
                        info.appendChild(artistEl);
                    }

                    var badgesEl = document.createElement('div');
                    badgesEl.className = 'tm-song-k-badges';
                    badgesEl.innerHTML = badges(s.taenze);
                    info.appendChild(badgesEl);

                    li.appendChild(info);

                    // API-Wert bevorzugen; sonst akkumulierter Fallback
                    var sek = s.estimatedSecondsUntilStart;
                    if (sek == null && akkumuliertSek != null) {
                        sek = Math.round(akkumuliertSek);
                    }

                    if (sek != null) {
                        var countdown = document.createElement('div');
                        countdown.className = 'tm-song-k-countdown';
                        countdown.textContent = formatCountdown(sek);
                        li.appendChild(countdown);

                        if (data.isPlaying) {
                            var empfangenAm = Date.now();
                            var startSek = sek;
                            var id = setInterval(function () {
                                var vergangen = (Date.now() - empfangenAm) / 1000;
                                var rest = Math.round(startSek - vergangen);
                                countdown.textContent = formatCountdown(rest);
                                if (rest <= 0) { clearInterval(id); }
                            }, 1000);
                            countdowns.push(id);
                        }
                    }

                    ul.appendChild(li);

                    // Akkumulator für nächsten Eintrag weiterschieben
                    if (akkumuliertSek != null && s.duration) {
                        akkumuliertSek += s.duration;
                    } else {
                        akkumuliertSek = null; // Dauer unbekannt → kein weiterer Fallback
                    }
                });

                kommendeEl.appendChild(ul);
            }

            function holeDaten() {
                var url = basis + '/proxies/fret.php?computer=' + encodeURIComponent(computerId);
                fetch(url, { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) {
                            fehlerZaehler++;
                            if (fehlerZaehler >= 3) {
                                aktuellEl.innerHTML = '<div class="tm-song-status">Verbindung unterbrochen</div>';
                            }
                            return;
                        }
                        fehlerZaehler = 0;
                        renderAktuell(data);
                        renderKommende(data);
                    })
                    .catch(function () {
                        fehlerZaehler++;
                        if (fehlerZaehler >= 3) {
                            aktuellEl.innerHTML = '<div class="tm-song-status">Verbindung unterbrochen</div>';
                        }
                    });
            }

            holeDaten();
            poll = setInterval(holeDaten, pollSek * 1000);

            fertig([{
                el:       el,
                dauerSek: dauerSek,
                destroy:  function () {
                    if (poll) { clearInterval(poll); poll = null; }
                    if (raf)  { cancelAnimationFrame(raf); raf = null; }
                    countdowns.forEach(function (id) { clearInterval(id); });
                    countdowns = [];
                }
            }]);
        }
    };
})();
