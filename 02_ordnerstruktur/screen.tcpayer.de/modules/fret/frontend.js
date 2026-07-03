/**
 * modules/fret/frontend.js
 *
 * Fortschrittsbalken-Logik (neu):
 *   - remainingSeconds + lokale Empfangszeit (Date.now()) → requestAnimationFrame-Loop
 *   - isPlaying=false oder remainingSeconds=null → Balken einfrieren (kein Reset)
 *   - Songwechsel (songId) → Balken auf 0 zurücksetzen
 *   - Kein setInterval-Tick mehr (kein Drift)
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

        // Alte Timer/Frames dieses Containers aufräumen (Leak-Schutz).
        if (container._tmPoll)      { clearInterval(container._tmPoll); }
        if (container._tmTick)      { clearInterval(container._tmTick); } // Altlast
        if (container._tmRaf)       { cancelAnimationFrame(container._tmRaf); }
        if (container._tmCountdowns) {
            container._tmCountdowns.forEach(function (id) { clearInterval(id); });
        }
        container._tmCountdowns = [];

        var basis          = window.BACKEND_BASE || '';
        var titel          = settings.titel || 'FRET';
        var computerId     = settings.computer_id || '';
        var zeigePlaylist  = settings.zeige_playlist !== false;
        var anzahlKommende = (settings.anzahl_kommende != null) ? settings.anzahl_kommende : 3;
        var pollSek        = (settings.poll_sek && settings.poll_sek >= 3) ? settings.poll_sek : 7;

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

        var aktuellEl  = container.querySelector('.tm-song-aktuell');
        var barEl      = container.querySelector('.tm-song-progress-bar');
        var kommendeEl = container.querySelector('.tm-song-kommende');

        var fehlerZaehler = 0;

        // Fortschritts-Zustand
        var bar = {
            songId:          null,  // aktuell angezeigte Song-UUID
            dauer:           null,  // Gesamtlänge in Sek.
            restBeiEmpfang:  null,  // remainingSeconds zum Zeitpunkt des Poll-Eingangs
            empfangenAm:     null,  // Date.now() beim Poll-Eingang
            letzterWert:     0,     // letzter gezeichneter Fortschritt (0–1), für Einfrieren
            laeuft:          false  // true nur wenn isPlaying + remainingSeconds vorhanden
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
            container._tmRaf = requestAnimationFrame(rafLoop);
        }

        function starteAnimation() {
            if (container._tmRaf) { cancelAnimationFrame(container._tmRaf); }
            container._tmRaf = requestAnimationFrame(rafLoop);
        }

        function renderAktuell(data) {
            var s = data.aktuell;
            if (!s) {
                aktuellEl.innerHTML = '<div class="tm-song-status">Kein Song</div>';
                bar.laeuft = false;
                if (container._tmRaf) { cancelAnimationFrame(container._tmRaf); }
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

            if (data.isPlaying && s.remainingSeconds != null && s.duration) {
                // Spielend + Positionsdaten vorhanden → Animation starten/resync
                bar.dauer          = s.duration;
                bar.restBeiEmpfang = s.remainingSeconds;
                bar.empfangenAm    = Date.now();
                bar.laeuft         = true;
                starteAnimation();
            } else {
                // Pause/Stop oder keine Positionsdaten → einfrieren, kein Reset
                bar.laeuft = false;
                if (container._tmRaf) { cancelAnimationFrame(container._tmRaf); }
                zeichneBalken(bar.letzterWert);
            }
        }

        function renderKommende(data) {
            if (!zeigePlaylist || !kommendeEl) { return; }

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
                        var empfangenAm = Date.now();
                        var startSek = sek;
                        var id = setInterval(function () {
                            var vergangen = (Date.now() - empfangenAm) / 1000;
                            var rest = Math.round(startSek - vergangen);
                            countdown.textContent = formatCountdown(rest);
                            if (rest <= 0) { clearInterval(id); }
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

        holeDaten();
        container._tmPoll = setInterval(holeDaten, pollSek * 1000);
    };
})();
