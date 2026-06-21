/**
 * modules/song/frontend.js
 *
 * Zeigt den aktuell laufenden Song (Titel, Künstler, Tanz-Badges,
 * Fortschrittsbalken) sowie optional die kommenden Songs. Daten kommen vom
 * Proxy proxies/song.php (FRET-API; schoolId bleibt serverseitig).
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
 *   window.TanzschuleModule.song = function(container, settings, inhalte)
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

    window.TanzschuleModule.song = function (container, settings) {
        settings = settings || {};
        container.classList.add('tm-modul-song');

        // Alte Timer dieses Containers zwingend aufräumen (Leak-Schutz).
        if (container._tmPoll) { clearInterval(container._tmPoll); }
        if (container._tmTick) { clearInterval(container._tmTick); }

        var basis = window.BACKEND_BASE || '';
        var computerId = settings.computer_id || '';
        var zeigePlaylist = settings.zeige_playlist !== false;
        var anzahlKommende = (settings.anzahl_kommende != null) ? settings.anzahl_kommende : 3;
        var pollSek = (settings.poll_sek && settings.poll_sek >= 3) ? settings.poll_sek : 7;

        if (!computerId) {
            container.innerHTML = '<div class="tm-song-status">Kein Saal (FRET-Computer) ausgewählt.</div>';
            return;
        }

        container.innerHTML =
            '<div class="tm-song-aktuell"></div>'
            + '<div class="tm-song-progress"><div class="tm-song-progress-bar"></div></div>'
            + (zeigePlaylist ? '<div class="tm-song-kommende"></div>' : '');

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
            var liste = (data.kommende || []).slice(0, anzahlKommende);
            if (liste.length === 0) {
                kommendeEl.innerHTML = '';
                return;
            }
            var html = '<ul class="tm-song-kommende-liste">';
            liste.forEach(function (s) {
                html += '<li class="tm-song-kommende-eintrag">'
                    + '<span class="tm-song-k-titel">' + escapeHtml(s.title) + '</span>'
                    + '<span class="tm-song-k-badges">' + badges(s.taenze) + '</span>'
                    + '</li>';
            });
            html += '</ul>';
            kommendeEl.innerHTML = html;
        }

        function holeDaten() {
            var url = basis + '/proxies/song.php?computer=' + encodeURIComponent(computerId);
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
