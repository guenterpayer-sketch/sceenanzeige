/**
 * assets/js/monitor.js
 *
 * Kern-Logik des Saal-Monitor-Frontends (Schritt 9).
 * Wird von saalN.tcpayer.de/index.html eingebunden; läuft im Kiosk-Vollbild.
 *
 * Ablauf:
 *   1. Subdomain ermitteln (URL-Param > Konstante MONITOR_SUBDOMAIN > hostname)
 *   2. proxies/monitor.php abfragen
 *   3. Header-Text setzen, Header-Uhrzeit starten
 *   4. Layout + Module rendern (oder Fallback anzeigen)
 *   5. Ticker starten
 *   6. Nach ~60 s erneut ab Schritt 2 (Refresh)
 *
 * Globale Variablen (aus index.html):
 *   MONITOR_SUBDOMAIN  — Override, leer = auto-detect
 *   window.BACKEND_BASE
 *   window.UPLOADS_URL
 */
(function () {
    'use strict';

    var BACKEND         = window.BACKEND_BASE || 'https://screen.tcpayer.de';
    var REFRESH_MS      = 60000;

    // ── Subdomain ────────────────────────────────────────────────────────────

    function getSubdomain() {
        var urlParam = new URLSearchParams(window.location.search).get('monitor');
        if (urlParam && urlParam.trim()) { return urlParam.trim(); }

        var konstante = (typeof window.MONITOR_SUBDOMAIN !== 'undefined')
            ? String(window.MONITOR_SUBDOMAIN).trim() : '';
        if (konstante) { return konstante; }

        var hostname = window.location.hostname;
        var teile    = hostname.split('.');
        return teile.length >= 3 ? teile[0] : hostname;
    }

    // ── Modul-Cleanup ────────────────────────────────────────────────────────

    var _rotationTimeouts  = [];
    var _playlistIndex     = 0;
    var _playlistRotTimer  = null;
    var _activePlaylistIds = '';
    var _currentPl         = null;

    function cleanupModulContainer(container) {
        if (container._tmTimeout)  { clearTimeout(container._tmTimeout);   container._tmTimeout  = null; }
        if (container._tmInterval) { clearInterval(container._tmInterval); container._tmInterval = null; }
        if (container._tmPoll)     { clearInterval(container._tmPoll);     container._tmPoll     = null; }
        if (container._tmTick)     { clearInterval(container._tmTick);     container._tmTick     = null; }
    }

    function cleanupAlles() {
        document.querySelectorAll('.tm-modul-container').forEach(cleanupModulContainer);
        _rotationTimeouts.forEach(clearTimeout);
        _rotationTimeouts = [];
        if (_playlistRotTimer) { clearTimeout(_playlistRotTimer); _playlistRotTimer = null; }
        var mainEl = document.getElementById('tm-main');
        if (mainEl) {
            mainEl.innerHTML = '';
            mainEl.style.opacity = '';
            mainEl.style.transition = '';
        }
        // In-flight header/footer inline-Styles zurücksetzen
        var headerEl = document.getElementById('tm-header');
        if (headerEl) { headerEl.style.height = ''; headerEl.style.opacity = ''; headerEl.style.transition = ''; }
        var footerEl2 = document.getElementById('tm-footer');
        if (footerEl2) { footerEl2.style.height = ''; footerEl2.style.opacity = ''; footerEl2.style.transition = ''; }
        _currentPl = null;
    }

    // ── Header-Uhrzeit ───────────────────────────────────────────────────────

    var _headerInterval = null;

    function startHeaderUhrzeit() {
        var zeitEl  = document.getElementById('tm-header-zeit');
        var datumEl = document.getElementById('tm-header-datum');
        if (!zeitEl || !datumEl) { return; }

        var WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

        function pad(n) { return String(n).padStart(2, '0'); }

        function tick() {
            var now = new Date();
            zeitEl.textContent  = pad(now.getHours()) + ':' + pad(now.getMinutes());
            datumEl.textContent = WOCHENTAGE[now.getDay()]
                + ' ' + pad(now.getDate())
                + '.' + pad(now.getMonth() + 1)
                + '.' + now.getFullYear();
        }

        if (_headerInterval) { clearInterval(_headerInterval); }
        tick();
        _headerInterval = setInterval(tick, 1000);
    }

    // ── Ticker ───────────────────────────────────────────────────────────────

    var _tickerTimeout = null;

    function stopTicker() {
        if (_tickerTimeout) { clearTimeout(_tickerTimeout); _tickerTimeout = null; }
    }

    function startTicker(eintraege, footerEl) {
        stopTicker();
        var textEl = footerEl.querySelector('.tm-ticker-text');
        if (!textEl || !eintraege || eintraege.length === 0) {
            footerEl.classList.add('tm-hidden');
            return;
        }
        footerEl.classList.remove('tm-hidden');

        var index = 0;

        function zeigeNaechsten() {
            var eintrag  = eintraege[index];
            var dauerMs  = ((eintrag.dauer_sek > 0) ? eintrag.dauer_sek : 8) * 1000;
            index = (index + 1) % eintraege.length;

            // Reset
            textEl.style.transition  = 'none';
            textEl.style.transform   = 'none';
            textEl.style.opacity     = '0';
            textEl.textContent       = eintrag.text;

            var containerWidth = footerEl.clientWidth - 56; // abzgl. padding
            var textWidth      = textEl.scrollWidth;

            if (textWidth > containerWidth * 0.95) {
                // Laufschrift: startet rechts, endet links
                var scrollPx   = textWidth + containerWidth;
                var durationMs = Math.max(6000, (scrollPx / 90) * 1000); // ~90 px/s

                textEl.style.transform  = 'translateX(' + containerWidth + 'px)';
                textEl.style.opacity    = '1';

                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        textEl.style.transition = 'transform ' + (durationMs / 1000).toFixed(2) + 's linear';
                        textEl.style.transform  = 'translateX(-' + textWidth + 'px)';
                    });
                });

                _tickerTimeout = setTimeout(function () {
                    textEl.style.transition = 'none';
                    textEl.style.transform  = 'none';
                    zeigeNaechsten();
                }, durationMs + 300);

            } else {
                // Statisch: einblenden, warten, ausblenden
                requestAnimationFrame(function () {
                    textEl.style.transition = 'opacity 600ms ease';
                    textEl.style.opacity    = '1';
                });

                _tickerTimeout = setTimeout(function () {
                    textEl.style.opacity = '0';
                    _tickerTimeout = setTimeout(zeigeNaechsten, 700);
                }, dauerMs);
            }
        }

        zeigeNaechsten();
    }

    // ── Layout bauen ─────────────────────────────────────────────────────────

    function buildLayout(playlist) {
        var anzahl = playlist.spalten_anzahl || 1;
        var b1 = playlist.spalte1_breite || 100;
        var b2 = playlist.spalte2_breite || 0;
        var b3 = playlist.spalte3_breite || 0;

        var cols = b1 + '%';
        if (anzahl >= 2) { cols += ' ' + b2 + '%'; }
        if (anzahl >= 3) { cols += ' ' + b3 + '%'; }

        var layoutEl = document.createElement('div');
        layoutEl.className = 'tm-layout tm-layout-' + anzahl + 'sp';
        layoutEl.style.gridTemplateColumns = cols;

        for (var s = 1; s <= anzahl; s++) {
            var spalteEl = document.createElement('div');
            spalteEl.className = 'tm-spalte';
            spalteEl.dataset.spalte = String(s);
            layoutEl.appendChild(spalteEl);
        }

        return layoutEl;
    }

    // ── Module in Spalten rendern ─────────────────────────────────────────────

    function renderModulInContainer(container, mod) {
        window.TanzschuleLoader.render(
            mod.modul_typ,
            container,
            mod.einstellungen || {},
            mod.inhalte || []
        );
    }

    function modulAnzeigeDauer(mod) {
        if (mod.inhalte && mod.inhalte.length > 0) {
            var summe = 0;
            mod.inhalte.forEach(function (i) { summe += (i.dauer_sek > 0 ? i.dauer_sek : 10); });
            return summe;
        }
        return (mod.einstellungen && mod.einstellungen.anzeige_dauer_sek > 0)
            ? mod.einstellungen.anzeige_dauer_sek : 30;
    }

    function rotateModule(spalteEl, module) {
        var index = 0;

        function zeigeNaechstes() {
            if (!spalteEl.isConnected) { return; } // spalteEl wurde entfernt → stoppen
            var mod      = module[index];
            var dauerSek = modulAnzeigeDauer(mod);
            index = (index + 1) % module.length;

            // Alten Container aufräumen
            var existing = spalteEl.querySelector('.tm-modul-container');
            if (existing) { cleanupModulContainer(existing); }
            spalteEl.innerHTML = '';

            var container = document.createElement('div');
            container.className = 'tm-modul-container';
            spalteEl.appendChild(container);
            renderModulInContainer(container, mod);

            var t = setTimeout(zeigeNaechstes, dauerSek * 1000);
            _rotationTimeouts.push(t);
        }

        zeigeNaechstes();
    }

    function renderSpalten(layoutEl, playlist) {
        var spalten = playlist.spalten || {};
        var anzahl  = playlist.spalten_anzahl || 1;

        for (var s = 1; s <= anzahl; s++) {
            var spalteEl = layoutEl.querySelector('[data-spalte="' + s + '"]');
            if (!spalteEl) { continue; }

            var module = spalten[s] || spalten[String(s)] || [];
            if (module.length === 0) { continue; }

            if (module.length === 1) {
                var container = document.createElement('div');
                container.className = 'tm-modul-container';
                spalteEl.appendChild(container);
                renderModulInContainer(container, module[0]);
            } else {
                rotateModule(spalteEl, module);
            }
        }
    }

    // ── Playlist-Rotation ─────────────────────────────────────────────────────

    function startPlaylistRotation(playlists, ticker) {
        var mainEl   = document.getElementById('tm-main');
        var footerEl = document.getElementById('tm-footer');
        var CROSSFADE_MS = 600;

        mainEl.style.position = 'relative';
        mainEl.style.overflow = 'hidden';

        function doRender(pl) {
            var headerEl = document.getElementById('tm-header');

            // Alte Zustände für synchronisierten Übergang
            var oldHeader = _currentPl ? !!_currentPl.header_sichtbar
                                       : (headerEl ? !headerEl.classList.contains('tm-hidden') : true);
            var oldFooter = _currentPl ? (_currentPl.footer_ticker !== false && !!(ticker && ticker.length))
                                       : !footerEl.classList.contains('tm-hidden');
            var newHeader = !!pl.header_sichtbar;
            var newFooter = pl.footer_ticker !== false && !!(ticker && ticker.length);
            _currentPl = pl;

            stopTicker();

            // Header/Footer vorab auf Startposition bringen (height:0 für Fade-In)
            if (!oldHeader && newHeader && headerEl) {
                headerEl.classList.remove('tm-hidden');
                headerEl.style.height = '0';
                headerEl.style.opacity = '0';
            }
            if (!oldFooter && newFooter) {
                footerEl.classList.remove('tm-hidden');
                footerEl.style.height = '0';
                footerEl.style.opacity = '0';
            }

            var oldLayout = mainEl.querySelector('.tm-layout');
            if (oldLayout) {
                oldLayout.style.position = 'absolute';
                oldLayout.style.top = '0'; oldLayout.style.left = '0';
                oldLayout.style.right = '0'; oldLayout.style.bottom = '0';
            }

            var newLayout = buildLayout(pl);
            newLayout.style.position = 'absolute';
            newLayout.style.top = '0'; newLayout.style.left = '0';
            newLayout.style.right = '0'; newLayout.style.bottom = '0';
            newLayout.style.opacity = '0';

            _rotationTimeouts = [];
            mainEl.appendChild(newLayout);
            renderSpalten(newLayout, pl);

            if (newFooter) { startTicker(ticker, footerEl); }

            // Alle Übergänge synchron im selben rAF-Block starten
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    // Layout
                    newLayout.style.transition = 'opacity ' + CROSSFADE_MS + 'ms ease';
                    newLayout.style.opacity = '1';
                    if (oldLayout) {
                        oldLayout.style.transition = 'opacity ' + CROSSFADE_MS + 'ms ease';
                        oldLayout.style.opacity = '0';
                        setTimeout(function () {
                            if (oldLayout.parentNode) {
                                oldLayout.querySelectorAll('.tm-modul-container')
                                    .forEach(cleanupModulContainer);
                                oldLayout.parentNode.removeChild(oldLayout);
                            }
                        }, CROSSFADE_MS + 50);
                    }

                    // Header (height + opacity synchron mit Layout)
                    if (headerEl) {
                        if (oldHeader && !newHeader) {
                            headerEl.style.transition = 'height ' + CROSSFADE_MS + 'ms ease, opacity ' + CROSSFADE_MS + 'ms ease';
                            headerEl.style.height = '0';
                            headerEl.style.opacity = '0';
                            setTimeout(function () {
                                headerEl.classList.add('tm-hidden');
                                headerEl.style.height = '';
                                headerEl.style.opacity = '';
                                headerEl.style.transition = '';
                            }, CROSSFADE_MS + 50);
                        } else if (!oldHeader && newHeader) {
                            headerEl.style.transition = 'height ' + CROSSFADE_MS + 'ms ease, opacity ' + CROSSFADE_MS + 'ms ease';
                            headerEl.style.height = '80px';
                            headerEl.style.opacity = '1';
                            setTimeout(function () {
                                headerEl.style.height = '';
                                headerEl.style.opacity = '';
                                headerEl.style.transition = '';
                            }, CROSSFADE_MS + 50);
                        }
                    }

                    // Footer (height + opacity synchron mit Layout)
                    if (oldFooter && !newFooter) {
                        footerEl.style.transition = 'height ' + CROSSFADE_MS + 'ms ease, opacity ' + CROSSFADE_MS + 'ms ease';
                        footerEl.style.height = '0';
                        footerEl.style.opacity = '0';
                        setTimeout(function () {
                            footerEl.classList.add('tm-hidden');
                            footerEl.style.height = '';
                            footerEl.style.opacity = '';
                            footerEl.style.transition = '';
                        }, CROSSFADE_MS + 50);
                    } else if (!oldFooter && newFooter) {
                        footerEl.style.transition = 'height ' + CROSSFADE_MS + 'ms ease, opacity ' + CROSSFADE_MS + 'ms ease';
                        footerEl.style.height = '58px';
                        footerEl.style.opacity = '1';
                        setTimeout(function () {
                            footerEl.style.height = '';
                            footerEl.style.opacity = '';
                            footerEl.style.transition = '';
                        }, CROSSFADE_MS + 50);
                    }
                });
            });

            // Nächste Playlist einplanen (nur bei echter Rotation)
            if (playlists.length > 1) {
                _playlistRotTimer = setTimeout(function () {
                    _playlistIndex = (_playlistIndex + 1) % playlists.length;
                    doRender(playlists[_playlistIndex]);
                }, (pl.dauer_sek || 300) * 1000);
            }
        }

        doRender(playlists[_playlistIndex]);
    }

    // ── Haupt-Render ──────────────────────────────────────────────────────────

    function render(data) {
        var playlists = data.playlists || [];
        var newIds    = playlists.map(function (p) { return String(p.id); }).join(',');

        // Gleiche Playlists laufen bereits → kein Re-Render, Rotation läuft weiter
        if (newIds !== '' && newIds === _activePlaylistIds) { return; }

        _activePlaylistIds = newIds;
        cleanupAlles();
        stopTicker();

        var headerTextEl = document.getElementById('tm-header-text');
        if (headerTextEl) { headerTextEl.textContent = data.header_text || ''; }

        var mainEl   = document.getElementById('tm-main');
        var footerEl = document.getElementById('tm-footer');

        if (playlists.length === 0) {
            var headerEl = document.getElementById('tm-header');
            if (headerEl) { headerEl.classList.remove('tm-hidden'); headerEl.style.height = ''; headerEl.style.opacity = ''; }
            var fallbackEl = document.createElement('div');
            fallbackEl.className = 'tm-main-leer';
            fallbackEl.textContent = 'Kein Programm';
            mainEl.appendChild(fallbackEl);
            footerEl.classList.add('tm-hidden');
            return;
        }

        if (_playlistIndex >= playlists.length) { _playlistIndex = 0; }
        startPlaylistRotation(playlists, data.ticker || []);
    }

    // ── Fetch & Refresh-Schleife ──────────────────────────────────────────────

    var _refreshTimer = null;

    function fetchUndRender(subdomain) {
        var url = BACKEND + '/proxies/monitor.php?subdomain=' + encodeURIComponent(subdomain);

        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                render(data);
            })
            .catch(function (err) {
                console.error('[monitor] Datenabruf fehlgeschlagen:', err);
            })
            .finally(function () {
                if (_refreshTimer) { clearTimeout(_refreshTimer); }
                _refreshTimer = setTimeout(function () {
                    fetchUndRender(subdomain);
                }, REFRESH_MS);
            });
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    function init() {
        var subdomain = getSubdomain();
        if (!subdomain) {
            document.body.innerHTML =
                '<div style="color:#fff;padding:40px;font-size:20px">'
                + 'Fehler: Monitor-Subdomain nicht erkannt.</div>';
            return;
        }
        startHeaderUhrzeit();
        fetchUndRender(subdomain);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
