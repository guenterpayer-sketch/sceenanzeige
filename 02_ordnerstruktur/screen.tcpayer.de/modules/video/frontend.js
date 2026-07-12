/**
 * modules/video/frontend.js
 *
 * Slide-Engine-Modul (Etappe 3, siehe KONZEPT_SLIDE_ENGINE.md):
 * liefert einen Slide pro Video-Eintrag. Zwei Eintragstypen:
 *   - eigene Datei:  video_dateiname gesetzt -> natives <video>-Element
 *   - Embed-Link:    video_embed_url gesetzt -> YouTube IFrame API oder
 *                     PeerTube-Embed (Typ wird aus der URL erkannt)
 *
 * meldetEnde: die Weiterschaltung ist event-getrieben ("ended"-Event bzw.
 * Player-API), nicht timer-basiert — dauer_sek bleibt nur Schätzwert für
 * die Spalten-Synchronisation. Meldet der Slide sein Ende und die Engine
 * hat KEINEN onEnde gesetzt (einziger Slide der Spalte → keine Rotation),
 * startet das Video von vorn (Loop) — entspricht dem bisherigen Verhalten.
 *
 * Player-Aufbau erst im onMount-Hook (lazy): beim Sammeln der Slides darf
 * noch kein Video laden/spielen. destroy() räumt Player, Listener und
 * Sicherheits-Timeout ab.
 *
 * SICHERHEITS-TIMEOUT: 15 Minuten, falls "ended" nie kommt (defektes
 * Embed, hängender Stream o.ä.). Immer stumm (Browser-Autoplay-Pflicht).
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

    /**
     * Baut den Slide für einen Eintrag. Player entsteht erst in onMount;
     * ende() ruft slide.onEnde (Rotation) oder startet neu (Einzel-Slide).
     */
    function baueVideoSlide(eintrag, uploadsBase) {
        var el = document.createElement('div');
        el.className = 'tm-modul-video';
        el.style.cssText = 'width:100%;height:100%;';

        var slide = {
            el:         el,
            dauerSek:   (eintrag.dauer_sek && eintrag.dauer_sek > 0) ? eintrag.dauer_sek : 10,
            meldetEnde: true
        };

        // Lokaler Player-Zustand
        var videoEl     = null;
        var ytPlayer    = null;
        var ptListener  = null;
        var safety      = null;
        var beendet     = false; // onEnde nur einmal an die Engine melden

        function starteSafety() {
            if (safety) { clearTimeout(safety); }
            safety = setTimeout(ende, SICHERHEITS_TIMEOUT_MS);
        }

        function neustart() {
            // Einzel-Slide ohne Engine-Rotation → Video loopen (wie bisher:
            // das Modul rotierte intern auf denselben Eintrag zurück).
            if (videoEl) {
                try { videoEl.currentTime = 0; videoEl.play(); } catch (e) { /* ignore */ }
            } else if (ytPlayer) {
                try { ytPlayer.seekTo(0, true); ytPlayer.playVideo(); } catch (e) { /* ignore */ }
            } else {
                var iframe = el.querySelector('iframe');
                if (iframe) { iframe.src = iframe.src; } // PeerTube: neu laden
            }
            starteSafety();
        }

        function ende() {
            if (safety) { clearTimeout(safety); safety = null; }
            if (typeof slide.onEnde === 'function') {
                if (!beendet) {
                    beendet = true;
                    slide.onEnde();
                }
            } else {
                neustart();
            }
        }

        function zeigeEigeneDatei() {
            el.innerHTML =
                '<video class="tm-video-player" style="width:100%;height:100%;object-fit:contain;background:#000;" autoplay muted playsinline></video>';
            videoEl = el.querySelector('.tm-video-player');
            videoEl.src = uploadsBase + encodeURIComponent(eintrag.video_dateiname);
            videoEl.addEventListener('ended', ende);
            videoEl.addEventListener('error', function () {
                console.error('[video-modul] Datei konnte nicht abgespielt werden:', videoEl.src);
                ende();
            }, { once: true });
            videoEl.play().catch(function () { /* Autoplay-Policy: muted sollte ausreichen */ });
            starteSafety();
        }

        function zeigeYoutube(id) {
            el.innerHTML = '<div class="tm-video-yt" style="width:100%;height:100%;"></div>';
            var ziel = el.querySelector('.tm-video-yt');
            ladeYoutubeApi().then(function () {
                if (!el.isConnected) { return; }
                ytPlayer = new YT.Player(ziel, {
                    width: '100%',
                    height: '100%',
                    videoId: id,
                    playerVars: { autoplay: 1, mute: 1, controls: 0, modestbranding: 1, rel: 0, playsinline: 1, showinfo: 0, iv_load_policy: 3 },
                    events: {
                        onReady: function (e) { e.target.mute(); e.target.playVideo(); },
                        onStateChange: function (e) {
                            if (e.data === YT.PlayerState.ENDED) { ende(); }
                        },
                        onError: function () {
                            console.error('[video-modul] YouTube-Fehler bei Video', id);
                            ende();
                        }
                    }
                });
            });
            starteSafety();
        }

        function zeigePeertube(url) {
            var trenner = url.indexOf('?') === -1 ? '?' : '&';
            var src = url + trenner + 'autoplay=1&muted=1&controls=0&peertubeLink=0';
            el.innerHTML =
                '<iframe class="tm-video-pt" src="' + src.replace(/"/g, '&quot;') + '" ' +
                'style="width:100%;height:100%;border:0;" allow="autoplay" sandbox="allow-scripts allow-same-origin allow-presentation"></iframe>';

            // PeerTube-Embed-API kommuniziert per postMessage; Player meldet
            // u.a. { type: 'playbackStatusUpdate', data: { type: 'ended' } }.
            ptListener = function (e) {
                var msg = e.data;
                if (!msg || typeof msg !== 'object') { return; }
                if (msg.type === 'playbackStatusUpdate' && msg.data && msg.data.type === 'ended') {
                    ende();
                }
            };
            window.addEventListener('message', ptListener);
            starteSafety();
        }

        slide.onMount = function () {
            if (eintrag.video_dateiname) {
                zeigeEigeneDatei();
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
            ende();
        };

        slide.destroy = function () {
            if (safety) { clearTimeout(safety); safety = null; }
            if (ytPlayer) {
                try { ytPlayer.destroy(); } catch (e) { /* ignore */ }
                ytPlayer = null;
            }
            if (ptListener) {
                window.removeEventListener('message', ptListener);
                ptListener = null;
            }
            if (videoEl) {
                try { videoEl.pause(); videoEl.removeAttribute('src'); videoEl.load(); } catch (e) { /* ignore */ }
                videoEl = null;
            }
        };

        return slide;
    }

    window.TanzschuleModule.video = {
        getSlides: function (settings, inhalte, fertig) {
            inhalte = (inhalte || []).filter(istAktiv).filter(function (i) {
                return !!i.video_dateiname || !!i.video_embed_url;
            });

            if (inhalte.length === 0) {
                var leer = document.createElement('div');
                leer.className = 'tm-modul-video';
                leer.style.cssText = 'width:100%;height:100%;';
                leer.innerHTML = '<div class="tm-video-leer">Keine Videos vorhanden</div>';
                fertig([{ el: leer, dauerSek: 30 }]);
                return;
            }

            var uploadsBase = (window.UPLOADS_URL || 'https://screen.tcpayer.de/uploads') + '/';

            fertig(inhalte.map(function (eintrag) {
                return baueVideoSlide(eintrag, uploadsBase);
            }));
        }
    };
})();
