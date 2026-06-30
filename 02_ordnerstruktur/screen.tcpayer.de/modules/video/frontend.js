/**
 * modules/video/frontend.js
 *
 * Rotiert durch die Unter-Inhalte (einzelne Videos) einer "video"-Modul-
 * Instanz. Zwei Eintragstypen pro Eintrag (siehe admin/instanz.php):
 *   - eigene Datei:  video_dateiname gesetzt -> normales <video>-Element
 *   - Embed-Link:    video_embed_url gesetzt -> YouTube IFrame API oder
 *                     PeerTube-Embed (Typ wird aus der URL erkannt)
 *
 * "inhalte" kommt vom Backend als Array von Objekten:
 *   { id, video_dateiname, video_embed_url, dauer_sek, gueltig_bis, aktiv }
 *
 * Weiterschaltung NICHT per festem Timer, sondern event-getrieben über das
 * "ended"-Event (native <video> bzw. YouTube IFrame API / PeerTube Embed
 * API) - das vermeidet die Unvorhersehbarkeit durch YouTube-Pre-Roll-Werbung
 * (das Video schaltet erst weiter, wenn wirklich zu Ende). dauer_sek dient
 * nur als grober Schätzwert für die Spalten-Synchronisation in monitor.js
 * (skaliereMod/modulAnzeigeDauer), NICHT für die tatsächliche Weiterschaltung.
 *
 * SICHERHEITS-TIMEOUT: hardcodiert 15 Minuten, falls "ended" nie kommt
 * (defektes Embed, hängender Stream o.ä.).
 *
 * Immer stumm (Browser-Autoplay-Pflicht) - kein Nutzer-Schalter.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    var SICHERHEITS_TIMEOUT_MS = 15 * 60 * 1000;

    function istAktiv(eintrag) {
        if (eintrag.aktiv !== undefined && Number(eintrag.aktiv) === 0) {
            return false;
        }
        if (eintrag.gueltig_bis) {
            var heute = new Date();
            heute.setHours(0, 0, 0, 0);
            var bis = new Date(eintrag.gueltig_bis + 'T00:00:00');
            if (!isNaN(bis.getTime()) && bis < heute) {
                return false;
            }
        }
        return true;
    }

    function erkenneEmbedTyp(url) {
        if (/youtube\.com|youtu\.be/i.test(url)) { return 'youtube'; }
        if (/\/videos\/embed\//i.test(url)) { return 'peertube'; }
        return null;
    }

    function youtubeId(url) {
        var m = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_-]{6,})/);
        return m ? m[1] : null;
    }

    // ---- YouTube IFrame API: einmalig global laden ----
    var ytApiPromise = null;
    function ladeYoutubeApi() {
        if (window.YT && window.YT.Player) { return Promise.resolve(); }
        if (ytApiPromise) { return ytApiPromise; }
        ytApiPromise = new Promise(function (resolve) {
            var bisherigerCallback = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function () {
                if (typeof bisherigerCallback === 'function') { bisherigerCallback(); }
                resolve();
            };
            var script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(script);
        });
        return ytApiPromise;
    }

    window.TanzschuleModule.video = function (container, settings, inhalte) {
        settings = settings || {};
        inhalte = (inhalte || []).filter(istAktiv).filter(function (i) {
            return !!i.video_dateiname || !!i.video_embed_url;
        });
        container.classList.add('tm-modul-video');

        function aufraeumen() {
            if (container._tmTimeout) { clearTimeout(container._tmTimeout); container._tmTimeout = null; }
            if (container._tmYtPlayer) {
                try { container._tmYtPlayer.destroy(); } catch (e) { /* ignore */ }
                container._tmYtPlayer = null;
            }
            if (container._tmPeertubeListener) {
                window.removeEventListener('message', container._tmPeertubeListener);
                container._tmPeertubeListener = null;
            }
        }
        aufraeumen();

        if (inhalte.length === 0) {
            container.innerHTML = '<div class="tm-video-leer">Keine Videos vorhanden</div>';
            return;
        }

        var uploadsBase = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';
        var index = 0;

        function weiter() {
            index = (index + 1) % inhalte.length;
            zeigeNaechstes();
        }

        function starteSicherheitsTimeout() {
            container._tmTimeout = setTimeout(weiter, SICHERHEITS_TIMEOUT_MS);
        }

        function zeigeEigeneDatei(eintrag) {
            container.innerHTML =
                '<video class="tm-video-player" style="width:100%;height:100%;object-fit:contain;background:#000;" autoplay muted playsinline></video>';
            var v = container.querySelector('.tm-video-player');
            v.src = uploadsBase + encodeURIComponent(eintrag.video_dateiname);
            v.addEventListener('ended', weiter, { once: true });
            v.addEventListener('error', function () {
                console.error('[video-modul] Datei konnte nicht abgespielt werden:', v.src);
                weiter();
            }, { once: true });
            v.play().catch(function () { /* Autoplay-Policy: muted sollte ausreichen */ });
            starteSicherheitsTimeout();
        }

        function zeigeYoutube(id) {
            container.innerHTML = '<div class="tm-video-yt" style="width:100%;height:100%;"></div>';
            var ziel = container.querySelector('.tm-video-yt');
            ladeYoutubeApi().then(function () {
                if (!container.isConnected) { return; }
                container._tmYtPlayer = new YT.Player(ziel, {
                    width: '100%',
                    height: '100%',
                    videoId: id,
                    playerVars: { autoplay: 1, mute: 1, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, showinfo: 0, iv_load_policy: 3 },
                    events: {
                        onReady: function (e) { e.target.mute(); e.target.playVideo(); },
                        onStateChange: function (e) {
                            if (e.data === YT.PlayerState.ENDED) { weiter(); }
                        },
                        onError: function () {
                            console.error('[video-modul] YouTube-Fehler bei Video', id);
                            weiter();
                        }
                    }
                });
            });
            starteSicherheitsTimeout();
        }

        function zeigePeertube(url) {
            var trenner = url.indexOf('?') === -1 ? '?' : '&';
            var src = url + trenner + 'autoplay=1&muted=1&controls=0&peertubeLink=0';
            container.innerHTML =
                '<iframe class="tm-video-pt" src="' + src.replace(/"/g, '&quot;') + '" ' +
                'style="width:100%;height:100%;border:0;" allow="autoplay" sandbox="allow-scripts allow-same-origin allow-presentation"></iframe>';

            // PeerTube-Embed-API kommuniziert per postMessage; Player meldet
            // u.a. { type: 'playbackStatusUpdate', data: { type: 'ended' } }.
            container._tmPeertubeListener = function (e) {
                var msg = e.data;
                if (!msg || typeof msg !== 'object') { return; }
                if (msg.type === 'playbackStatusUpdate' && msg.data && msg.data.type === 'ended') {
                    weiter();
                }
            };
            window.addEventListener('message', container._tmPeertubeListener);
            starteSicherheitsTimeout();
        }

        function zeigeNaechstes() {
            aufraeumen();
            if (!container.isConnected) { return; }
            var eintrag = inhalte[index];

            if (eintrag.video_dateiname) {
                zeigeEigeneDatei(eintrag);
                return;
            }

            var typ = erkenneEmbedTyp(eintrag.video_embed_url);
            if (typ === 'youtube') {
                var id = youtubeId(eintrag.video_embed_url);
                if (id) { zeigeYoutube(id); return; }
            } else if (typ === 'peertube') {
                zeigePeertube(eintrag.video_embed_url);
                return;
            }

            console.error('[video-modul] Unbekannter/ungültiger Embed-Link:', eintrag.video_embed_url);
            weiter();
        }

        zeigeNaechstes();
    };
})();
