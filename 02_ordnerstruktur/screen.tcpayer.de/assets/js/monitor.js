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
    var _lastReloadAt      = null;
    var _tickerJson        = null; // Serialisierte Ticker-Einträge für Änderungserkennung

    function cleanupModulContainer(container) {
        if (container._tmTimeout)  { clearTimeout(container._tmTimeout);   container._tmTimeout  = null; }
        if (container._tmInterval) { clearInterval(container._tmInterval); container._tmInterval = null; }
        if (container._tmPoll)     { clearInterval(container._tmPoll);     container._tmPoll     = null; }
        if (container._tmTick)     { clearInterval(container._tmTick);     container._tmTick     = null; }
        if (container._tmYtPlayer) {
            try { container._tmYtPlayer.destroy(); } catch (e) { /* ignore */ }
            container._tmYtPlayer = null;
        }
        if (container._tmPeertubeListener) {
            window.removeEventListener('message', container._tmPeertubeListener);
            container._tmPeertubeListener = null;
        }
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
            return; // Sichtbarkeit des Footers steuert doRender/render, nicht startTicker
        }

        var einziger = eintraege.length === 1;
        var index    = 0;

        function zeigeNaechsten() {
            var eintrag  = eintraege[index];
            var dauerMs  = ((eintrag.dauer_sek > 0) ? eintrag.dauer_sek : 8) * 1000;
            index = (index + 1) % eintraege.length;

            // Reset
            textEl.style.transition  = 'none';
            textEl.style.transform   = 'none';
            textEl.style.opacity     = '0';
            textEl.textContent       = eintrag.text;
            footerEl.classList.remove('tm-ticker-zentriert');

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
                // Statisch: Text passt in den Footer → zentrieren
                footerEl.classList.add('tm-ticker-zentriert');

                if (einziger) {
                    // Nur ein Eintrag → direkt sichtbar, kein Überblenden, kein Loop
                    textEl.style.opacity = '1';
                } else {
                    // Mehrere Einträge → einblenden, warten, ausblenden
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

    /**
     * Gibt eine skalierte Kopie eines Moduls zurück.
     * Skaliert werden: jedes inhalte[].dauer_sek sowie einstellungen.anzeige_dauer_sek.
     * Die Originaldaten werden nicht verändert.
     */
    function skaliereMod(mod, factor) {
        if (factor === 1) { return mod; }
        var result = { modul_typ: mod.modul_typ, einstellungen: mod.einstellungen, inhalte: mod.inhalte };
        if (mod.inhalte && mod.inhalte.length > 0) {
            result.inhalte = mod.inhalte.map(function (item) {
                var d = item.dauer_sek > 0 ? item.dauer_sek : 10;
                return Object.assign({}, item, { dauer_sek: d * factor });
            });
        }
        if (mod.einstellungen && mod.einstellungen.anzeige_dauer_sek > 0) {
            result.einstellungen = Object.assign({}, mod.einstellungen, {
                anzeige_dauer_sek: mod.einstellungen.anzeige_dauer_sek * factor
            });
        }
        return result;
    }

    function rotateModule(spalteEl, module, scaleFactor) {
        scaleFactor = scaleFactor || 1;
        var index = 0;
        var FADE_MS = 1500;

        spalteEl.style.position = 'relative';

        function zeigeNaechstes() {
            if (!spalteEl.isConnected) { return; }
            var mod      = module[index];
            var dauerSek = modulAnzeigeDauer(mod) * scaleFactor;
            index = (index + 1) % module.length;

            var oldContainer = spalteEl.querySelector('.tm-modul-container');

            var newContainer = document.createElement('div');
            newContainer.className = 'tm-modul-container';
            newContainer.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;opacity:0;';
            spalteEl.appendChild(newContainer);
            renderModulInContainer(newContainer, skaliereMod(mod, scaleFactor));

            if (oldContainer) {
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        newContainer.style.transition = 'opacity ' + FADE_MS + 'ms ease';
                        newContainer.style.opacity = '1';
                        oldContainer.style.transition = 'opacity ' + FADE_MS + 'ms ease';
                        oldContainer.style.opacity = '0';
                        setTimeout(function () {
                            if (oldContainer.parentNode) {
                                cleanupModulContainer(oldContainer);
                                oldContainer.parentNode.removeChild(oldContainer);
                            }
                        }, FADE_MS + 50);
                    });
                });
            } else {
                // Erster Render: direkt einblenden, kein Crossfade
                newContainer.style.opacity = '1';
            }

            var t = setTimeout(zeigeNaechstes, dauerSek * 1000);
            _rotationTimeouts.push(t);
        }

        zeigeNaechstes();
    }

    /**
     * Rendert alle Spalten einer Playlist mit proportionaler Skalierung:
     * Die längste Spalte bestimmt den Zyklus; kürzere Spalten werden so
     * gestreckt, dass alle gleichzeitig enden.
     * Gibt die maximale Zyklusdauer in ms zurück (für den Playlist-Timer).
     */
    function renderSpalten(layoutEl, playlist) {
        var spalten = playlist.spalten || {};
        var anzahl  = playlist.spalten_anzahl || 1;
        var i, mods;

        // 1. Spalten-Zyklusdauern berechnen
        var zyklenMs  = {};
        var maxCycleMs = 0;
        for (i = 1; i <= anzahl; i++) {
            mods = spalten[i] || spalten[String(i)] || [];
            var zyklusMs = 0;
            mods.forEach(function (m) { zyklusMs += modulAnzeigeDauer(m) * 1000; });
            zyklenMs[i] = zyklusMs;
            if (zyklusMs > maxCycleMs) { maxCycleMs = zyklusMs; }
        }

        // 2. Spalten mit Skalierungsfaktor rendern
        for (i = 1; i <= anzahl; i++) {
            var spalteEl = layoutEl.querySelector('[data-spalte="' + i + '"]');
            if (!spalteEl) { continue; }

            mods = spalten[i] || spalten[String(i)] || [];
            if (mods.length === 0) { continue; }

            var factor = (zyklenMs[i] > 0) ? maxCycleMs / zyklenMs[i] : 1;

            if (mods.length === 1) {
                var container = document.createElement('div');
                container.className = 'tm-modul-container';
                spalteEl.appendChild(container);
                renderModulInContainer(container, skaliereMod(mods[0], factor));
            } else {
                rotateModule(spalteEl, mods, factor);
            }
        }

        return maxCycleMs;
    }

    // ── Playlist-Rotation ─────────────────────────────────────────────────────

    function startPlaylistRotation(playlists, ticker) {
        var mainEl     = document.getElementById('tm-main');
        var footerEl   = document.getElementById('tm-footer');
        var CROSSFADE_MS = 600;
        var SETTLE_MS    = 800; // Zeit für Off-screen-Pre-render bevor Crossfade startet

        mainEl.style.position = 'relative';
        mainEl.style.overflow = 'hidden';

        function doRender(pl) {
            var headerEl = document.getElementById('tm-header');

            var oldHeader = _currentPl ? !!_currentPl.header_sichtbar
                                       : (headerEl ? !headerEl.classList.contains('tm-hidden') : true);
            var oldFooter = _currentPl ? (_currentPl.footer_ticker !== false && !!(ticker && ticker.length))
                                       : !footerEl.classList.contains('tm-hidden');
            var newHeader = !!pl.header_sichtbar;
            var newFooter = pl.footer_ticker !== false && !!(ticker && ticker.length);
            _currentPl = pl;

            var oldLayout = mainEl.querySelector('.tm-layout');

            // Neues Layout unsichtbar vorrendern (opacity:0, korrekt positioniert),
            // damit Module (Stundenplan-API, Bilder) schon laden können.
            var newLayout = buildLayout(pl);
            // Einzelne Properties setzen — cssText würde gridTemplateColumns löschen
            newLayout.style.position = 'absolute';
            newLayout.style.top      = '0';
            newLayout.style.left     = '0';
            newLayout.style.right    = '0';
            newLayout.style.bottom   = '0';
            newLayout.style.opacity  = '0';

            // Alte Rotation einfrieren, damit das alte Layout während SETTLE_MS
            // nicht weiterzählt und unerwünschte Modul-Wechsel zeigt.
            _rotationTimeouts.forEach(clearTimeout);
            _rotationTimeouts = [];
            mainEl.appendChild(newLayout);
            var maxCycleMs = renderSpalten(newLayout, pl);

            // Nach SETTLE_MS Crossfade starten
            setTimeout(function () {

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

                if (oldLayout) {
                    oldLayout.style.position = 'absolute';
                    oldLayout.style.top = '0'; oldLayout.style.left = '0';
                    oldLayout.style.right = '0'; oldLayout.style.bottom = '0';
                }

                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        // Layout einblenden
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

                        // Header
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

                        // Footer
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
                            footerEl.style.height = '70px';
                            footerEl.style.opacity = '1';
                            setTimeout(function () {
                                footerEl.style.height = '';
                                footerEl.style.opacity = '';
                                footerEl.style.transition = '';
                            }, CROSSFADE_MS + 50);
                        }
                    });
                });
            }, SETTLE_MS);

            // Nächste Playlist einplanen. Timer läuft ab sofort (inkl. SETTLE_MS),
            // damit die Gesamtanzeigedauer stabil bleibt.
            if (playlists.length > 1) {
                var timerMs = Math.max(maxCycleMs, (pl.dauer_sek || 0) * 1000);
                if (timerMs <= 0) { timerMs = 300000; }
                _playlistRotTimer = setTimeout(function () {
                    _playlistIndex = (_playlistIndex + 1) % playlists.length;
                    doRender(playlists[_playlistIndex]);
                }, timerMs);
            }
        }

        doRender(playlists[_playlistIndex]);
    }

    // ── Haupt-Render ──────────────────────────────────────────────────────────

    function render(data) {
        var playlists     = data.playlists || [];
        var newIds        = playlists.map(function (p) { return String(p.id); }).join(',');
        var newTickerJson = JSON.stringify(data.ticker || []);
        var footerEl      = document.getElementById('tm-footer');

        // Gleiche Playlists laufen bereits — nur Ticker neu starten wenn Einträge geändert
        if (newIds !== '' && newIds === _activePlaylistIds) {
            if (newTickerJson !== _tickerJson) {
                _tickerJson = newTickerJson;
                startTicker(data.ticker || [], footerEl);
            }
            return;
        }

        _activePlaylistIds = newIds;
        _tickerJson        = newTickerJson;
        cleanupAlles();

        var headerTextEl = document.getElementById('tm-header-text');
        if (headerTextEl) { headerTextEl.textContent = data.header_text || ''; }

        var mainEl = document.getElementById('tm-main');

        // Ticker global starten — läuft unabhängig von Playlist-Rotation weiter.
        // Sichtbarkeit des Footers steuert doRender über tm-hidden.
        startTicker(data.ticker || [], footerEl);

        if (playlists.length === 0) {
            var headerEl = document.getElementById('tm-header');
            if (headerEl) { headerEl.classList.remove('tm-hidden'); headerEl.style.height = ''; headerEl.style.opacity = ''; }
            var fallbackEl = document.createElement('div');
            fallbackEl.className = 'tm-main-leer';
            fallbackEl.textContent = 'Kein Programm';
            mainEl.appendChild(fallbackEl);
            if (data.ticker && data.ticker.length > 0) {
                footerEl.classList.remove('tm-hidden');
            } else {
                footerEl.classList.add('tm-hidden');
            }
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
                var newReloadAt = data.reload_at || null;
                if (_lastReloadAt !== null && newReloadAt !== null && newReloadAt !== _lastReloadAt) {
                    location.reload();
                    return;
                }
                _lastReloadAt = newReloadAt;
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
