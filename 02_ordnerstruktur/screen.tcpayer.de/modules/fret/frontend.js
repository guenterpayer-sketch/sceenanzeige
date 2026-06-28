/**
 * modules/fret/frontend.js
 *
 * Modul "fret" (benannt nach der Musiksoftware FRET). Zeigt den aktuell
 * laufenden Song (Titel, Künstler, Tanz-Badges, Fortschrittsbalken) sowie
 * optional die kommenden Songs. Daten kommen vom Proxy proxies/fret.php
 * (FRET-API; schoolId bleibt serverseitig in config.php).
 *
 * Übernommene Bausteine aus dem Standalone-Projekt
 * (Projektzusammenfassung_Song_Anzeige.md):
 *   - Tanz-Badges Haupt-/Nebentanz über isPrimary (vom Proxy als isMain)
 *   - Fortschrittsbalken mit lokalem 1-Sek-Tick; Pause friert den Balken ein
 *     (remainingSeconds == null), setzt ihn NICHT zurück
 *   - sämtliche Timer (Poll + Tick) werden bei Neu-Render aufgeräumt
 *     (Interval-Leak-Bugfix)
 *
 * Konvention (siehe module-loader.js):
 *   window.TanzschuleModule.fret = function(container, settings, inhalte)
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

    window.TanzschuleModule.fret = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-fret');

        // Alte Timer dieses Containers zwingend aufräumen (Leak-Schutz).
        if (container._tmPoll) { clearInterval(container._tmPoll); }
        if (container._tmTick) { clearInterval(container._tmTick); }
        if (container._tmCountdowns) {
            container._tmCountdowns.forEach(function (id) { clearInterval(id); });
        }
        container._tmCountdowns = [];

        var basis = window.BACKEND_BASE || '';
        var titel = settings.titel || 'FRET';
        var computerId = settings.computer_id || '';
        var zeigePlaylist = settings.zeige_playlist !== false;
        var anzahlKommende = (settings.anzahl_kommende != null) ? settings.anzahl_kommende : 3;
        var pollSek = (settings.poll_sek && settings.poll_sek >= 3) ? settings.poll_sek : 7;

        if (!computerId) {
            container.innerHTML = '<div class="tm-song-heading">' + escapeHtml(titel) + '</div>'
                + '<div class="tm-song-status">Kein Saal (FRET-Computer) ausgewählt.</div>';
            return;
        }

        container.innerHTML =
            '<div class="tm-song-heading">' + escapeHtml(titel) + '</div>'
            + '<div class="tm-song-aktuell"></div>'
            + '<div class="tm-song-progress"><div class="tm-song-progress-bar"></div></div>'
            + (zeigePlaylist
                ? '<div class="tm-song-label">Nächste Titel</div><div class="tm-song-kommende"></div>'
                : '');

        var aktuellEl = container.querySelector('.tm-song-aktuell');
        var barEl = container.querySelector('.tm-song-progress-bar');
        var kommendeEl = container.querySelector('.tm-song-kommende');

        var fehlerZaehler = 0;
        // Lokaler Fortschritts-Zustand für den 1-Sek-Tick zwischen den Polls.
        var prog = { dauer: null, rest: null, laeuft: false };

        function renderAktuell(data) {
            var s = data.aktuell;
            if (!s) {
                aktuellEl.innerHTML = '<div class="tm-song-status">Kein Song</div>';
                prog.laeuft = false;
                return;
            }
            aktuellEl.innerHTML =
                '<div class="tm-song-titel">' + escapeHtml(s.title) + '</div>'
                + '<div class="tm-song-artist">' + escapeHtml(s.artist) + '</div>'
                + '<div class="tm-song-badges">' + badges(s.taenze) + '</div>';

            // Fortschritt: nur wenn aktiv laufend (remainingSeconds != null).
            if (s.duration && s.remainingSeconds != null && data.isPlaying) {
                prog.dauer = s.duration;
                prog.rest = s.remainingSeconds;
                prog.laeuft = true;
            } else {
                // Pause/Stop: einfrieren (Balken NICHT zurücksetzen).
                prog.laeuft = false;
            }
            zeichneBalken();
        }

        function zeichneBalken() {
            if (prog.dauer && prog.rest != null) {
                var anteil = Math.max(0, Math.min(1, 1 - (prog.rest / prog.dauer)));
                barEl.style.width = (anteil * 100).toFixed(1) + '%';
            }
        }

        function renderKommende(data) {
            if (!zeigePlaylist || !kommendeEl) { return; }

            // Alte Countdown-Timer aufräumen
            container._tmCountdowns.forEach(function (id) { clearInterval(id); });
            container._tmCountdowns = [];

            var liste = (data.kommende || []).slice(0, anzahlKommende);
            kommendeEl.innerHTML = '';
            if (liste.length === 0) { return; }

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

                if (s.estimatedSecondsUntilStart != null) {
                    var countdown = document.createElement('div');
                    countdown.className = 'tm-song-k-countdown';
                    var sek = s.estimatedSecondsUntilStart;
                    countdown.textContent = formatCountdown(sek);
                    li.appendChild(countdown);

                    if (data.isPlaying) {
                        var id = setInterval(function () {
                            sek--;
                            countdown.textContent = formatCountdown(sek);
                            if (sek <= 0) { clearInterval(id); }
                        }, 1000);
                        container._tmCountdowns.push(id);
                    }
                }

                ul.appendChild(li);
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

        // Lokaler 1-Sek-Tick: zählt rest herunter, damit der Balken flüssig
        // läuft statt nur bei jedem Poll zu springen.
        container._tmTick = setInterval(function () {
            if (prog.laeuft && prog.rest != null) {
                prog.rest = Math.max(0, prog.rest - 1);
                zeichneBalken();
            }
        }, 1000);

        holeDaten();
        container._tmPoll = setInterval(holeDaten, pollSek * 1000);
    };
})();
