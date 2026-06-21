/**
 * modules/song/frontend.js
 *
 * Adaptiert aus dem eigenständigen Vorgänger-Projekt "Tanzschule
 * Song-Anzeige" (siehe Projektzusammenfassung_Song_Anzeige.md), reduziert
 * auf die Anzeige EINES einzelnen Saals (das Multi-Raum-Grid entfällt, das
 * übernimmt jetzt das übergeordnete Playlist-Layout).
 *
 * schoolId/computerId werden NIEMALS an dieses Skript übergeben — sie
 * bleiben serverseitig in proxies/song.php (siehe Abschnitt 4 der
 * Song-Doku: die echte FRET-API hat auch schreibende Endpunkte).
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function parseDances(song) {
        if (!song || !song.dances || song.dances.length === 0) {
            return [];
        }
        const hasPrimary = song.dances.some(function (d) { return d.isPrimary; });
        const seen = {};
        const result = [];
        song.dances.forEach(function (d) {
            const name = d.longName || d.shortName;
            if (seen[name]) {
                return; // Duplikate entfernen, siehe Song-Doku Abschnitt 5b
            }
            seen[name] = true;
            result.push({ name: name, isMain: hasPrimary ? d.isPrimary : true });
        });
        return result;
    }

    function renderBadges(dances) {
        return dances.map(function (d) {
            const cls = d.isMain ? 'tm-song-badge tm-song-badge-main' : 'tm-song-badge tm-song-badge-side';
            return '<span class="' + cls + '">' + d.name + '</span>';
        }).join('');
    }

    window.TanzschuleModule.song = function (container, settings, inhalte, modulInstanzId) {
        settings = settings || {};
        container.classList.add('tm-modul-song');

        const basisUrl = window.BACKEND_BASE || '';
        if (!modulInstanzId) {
            container.innerHTML = '<div class="tm-song-fehler">modul_instanz_id fehlt</div>';
            return;
        }

        container.innerHTML =
            '<div class="tm-song-aktuell">' +
                '<div class="tm-song-titel"></div>' +
                '<div class="tm-song-artist"></div>' +
                '<div class="tm-song-badges"></div>' +
                '<div class="tm-song-progress"><div class="tm-song-progress-bar"></div></div>' +
            '</div>' +
            '<div class="tm-song-playlist"></div>';

        const titelEl = container.querySelector('.tm-song-titel');
        const artistEl = container.querySelector('.tm-song-artist');
        const badgesEl = container.querySelector('.tm-song-badges');
        const progressBarEl = container.querySelector('.tm-song-progress-bar');
        const playlistEl = container.querySelector('.tm-song-playlist');

        let aktuellerSong = null;
        let isPlaying = false;
        let fehlerZaehler = 0;

        // Lokaler 1-Sek.-Tick für einen flüssigen Fortschrittsbalken, statt
        // nur bei jedem Poll zu springen (siehe Song-Doku Abschnitt 5c).
        function tickProgress() {
            if (!aktuellerSong || !isPlaying || aktuellerSong.duration == null || aktuellerSong._verbleibend == null) {
                return; // pausiert -> Balken einfrieren statt zurücksetzen
            }
            aktuellerSong._verbleibend = Math.max(0, aktuellerSong._verbleibend - 1);
            const fortschritt = 1 - (aktuellerSong._verbleibend / aktuellerSong.duration);
            progressBarEl.style.width = (fortschritt * 100) + '%';
        }

        function laden() {
            const url = basisUrl + '/proxies/song.php?modul_instanz_id=' + encodeURIComponent(modulInstanzId);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        fehlerZaehler++;
                        // Generischer Hinweis erst nach 3 Fehlversuchen, siehe
                        // Song-Doku Abschnitt 3 ("würde Kunden verwirren").
                        if (fehlerZaehler >= 3) {
                            titelEl.textContent = 'Verbindung unterbrochen';
                            artistEl.textContent = '';
                            badgesEl.innerHTML = '';
                            playlistEl.innerHTML = '';
                        }
                        return;
                    }
                    fehlerZaehler = 0;
                    const player = data.player;
                    isPlaying = !!player.isPlaying;

                    const available = (player.songs || []).filter(function (s) { return s.position >= 0; });
                    const current = available.find(function (s) { return s.position === 0; }) || available[0] || null;
                    const upcoming = available.filter(function (s) { return s.position > 0; }).slice(0, 3);

                    if (current) {
                        titelEl.textContent = current.title || '';
                        artistEl.textContent = current.artist || '';
                        badgesEl.innerHTML = renderBadges(parseDances(current));

                        if (current.duration && current.remainingSeconds != null) {
                            current._verbleibend = current.remainingSeconds;
                            const fortschritt = 1 - (current.remainingSeconds / current.duration);
                            progressBarEl.style.width = (fortschritt * 100) + '%';
                        } else if (!current.duration) {
                            progressBarEl.style.width = '0%';
                        }
                        // sonst (pausiert, gleicher Song): Balken bleibt wie er ist
                        aktuellerSong = current;
                    } else {
                        titelEl.textContent = 'Kein Song aktiv';
                        artistEl.textContent = '';
                        badgesEl.innerHTML = '';
                        progressBarEl.style.width = '0%';
                        aktuellerSong = null;
                    }

                    playlistEl.innerHTML = upcoming.map(function (s) {
                        return '<div class="tm-song-playlist-item">' +
                            '<span class="tm-song-playlist-titel">' + (s.title || '') + '</span>' +
                            renderBadges(parseDances(s)) +
                            '</div>';
                    }).join('');
                })
                .catch(function () {
                    fehlerZaehler++;
                });
        }

        laden();

        // Wichtig (Bugfix aus Song-Doku Abschnitt 5e): alte Timer IMMER
        // aufräumen, sonst entstehen bei Re-Render dutzende parallele Timer.
        if (container._tmPollInterval) {
            clearInterval(container._tmPollInterval);
        }
        if (container._tmTickInterval) {
            clearInterval(container._tmTickInterval);
        }
        container._tmPollInterval = setInterval(laden, 7000); // 5-10 Sek. gem. Abschnitt 10 der Monitor-Doku
        container._tmTickInterval = setInterval(tickProgress, 1000);
    };
})();
