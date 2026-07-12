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

        return window.location.hostname;
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

    /**
     * Räumt einen Modul-Container vollständig ab — über den Slide-Descriptor
     * der Engine (falls vorhanden), sonst direkt low-level.
     * _tmDescriptor wird vor dem destroy-Aufruf genullt, damit der Adapter
     * (dessen destroy cleanupModulContainer ruft) keine Rekursion erzeugt.
     */
    function destroyContainer(container) {
        var desc = container._tmDescriptor;
        container._tmDescriptor = null;
        if (desc && typeof desc.destroy === 'function') {
            try { desc.destroy(container); } catch (e) { /* ignore */ }
        } else {
            cleanupModulContainer(container);
        }
    }

    function cleanupAlles() {
        document.querySelectorAll('.tm-modul-container').forEach(destroyContainer);
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

    // ── Slide-Engine ──────────────────────────────────────────────────────────
    //
    // Trennung von Inhalt und Präsentation (siehe KONZEPT_SLIDE_ENGINE.md):
    // Die Engine besitzt alle Übergänge, Timer und das Cleanup — Module
    // liefern nur Inhalt:
    //
    //   window.TanzschuleModule.<id> = { getSlides: fn }
    //     getSlides(settings, inhalte, fertig) → fertig([slide, …]) mit
    //     slide = { el, dauerSek, uebergang, meldetEnde, onMount, destroy }
    //
    //   el         fertiges DOM-Element (100% × 100%); darf innen leben
    //   dauerSek   Anzeigedauer (Schätzwert bei meldetEnde)
    //   uebergang  'fade' (Standard) | 'none' (harter Schnitt)
    //   meldetEnde true → Slide ruft slide.onEnde() wenn fertig (Video);
    //              ist onEnde nicht gesetzt (Einzel-Slide), loopt das Modul selbst
    //   onMount    optional: wird nach dem Einhängen ins DOM gerufen —
    //              für Setup, das ein lebendes DOM braucht (Player-Start,
    //              Höhen messen)
    //   destroy    optional: Intervalle/Player/Listener abbauen
    //
    // Interner Slide-Descriptor der Engine ergänzt mount(el)/freeze(el).

    var FADE_MS                = 1500;          // Slide-/Modul-Überblendung
    var MODUL_SETTLE_MS        = 800;           // Pre-render unsichtbar, analog Playlist-SETTLE_MS
    var MELDET_ENDE_TIMEOUT_MS = 15 * 60 * 1000; // Sicherheits-Timeout für meldetEnde-Slides

    function modulAnzeigeDauer(mod) {
        if (mod.inhalte && mod.inhalte.length > 0) {
            var summe = 0;
            mod.inhalte.forEach(function (i) { summe += (i.dauer_sek > 0 ? i.dauer_sek : 10); });
            return summe;
        }
        var dauerSek = (mod.einstellungen && mod.einstellungen.anzeige_dauer_sek > 0)
            ? mod.einstellungen.anzeige_dauer_sek : 30;
        // veranstaltung: Events kommen aus externer API, nicht aus inhalte[] →
        // Gesamtdauer = anzahl × anzeige_dauer_sek
        if (mod.modul_typ === 'veranstaltung'
                && mod.einstellungen && mod.einstellungen.anzahl > 0) {
            return dauerSek * mod.einstellungen.anzahl;
        }
        return dauerSek;
    }

    /**
     * Wrappt einen Modul-Slide aus getSlides in einen Engine-Descriptor.
     */
    function slideDescriptor(slide, factor) {
        return {
            dauerSek:   (slide.dauerSek > 0 ? slide.dauerSek : 10) * factor,
            meldetEnde: !!slide.meldetEnde,
            uebergang:  slide.uebergang === 'none' ? 'none' : 'fade',
            slide:      slide,
            mount: function (el) {
                if (slide.el) { el.appendChild(slide.el); }
                if (typeof slide.onMount === 'function') {
                    try { slide.onMount(el); } catch (e) { console.error('[engine] onMount-Fehler:', e); }
                }
            },
            freeze: function () { /* Engine besitzt alle Timer — nichts zu tun */ },
            destroy: function () {
                if (typeof slide.destroy === 'function') {
                    try { slide.destroy(); } catch (e) { /* ignore */ }
                }
            }
        };
    }

    /**
     * Sammelt die Slide-Descriptors einer Modul-Instanz (asynchron wegen
     * Script-Laden bzw. getSlides-Fetches). fertig(descriptors[]).
     */
    function sammleModulSlides(mod, factor, fertig) {
        window.TanzschuleLoader.lade(mod.modul_typ, function (def) {
            if (def && typeof def.getSlides === 'function') {
                def.getSlides(mod.einstellungen || {}, mod.inhalte || [], function (slides) {
                    fertig((slides || []).map(function (s) {
                        return slideDescriptor(s, factor);
                    }));
                });
            } else {
                // Ladefehler oder Modul ohne getSlides → Instanz überspringen,
                // die restlichen Module der Spalte laufen weiter.
                console.error('[engine] Modul "' + mod.modul_typ + '" liefert keine Slides — übersprungen.');
                fertig([]);
            }
        });
    }

    /**
     * Sammelt die Slides aller Modul-Instanzen einer Spalte in stabiler
     * Reihenfolge und liefert die verkettete Sequenz.
     */
    function sammleSpaltenSlides(mods, factor, fertig) {
        var ergebnisse = new Array(mods.length);
        var offen = mods.length;
        mods.forEach(function (mod, i) {
            sammleModulSlides(mod, factor, function (descs) {
                ergebnisse[i] = descs;
                offen--;
                if (offen === 0) {
                    fertig(Array.prototype.concat.apply([], ergebnisse));
                }
            });
        });
    }

    /**
     * Anzeige-Loop einer Spalte: mountet die Slides nacheinander mit
     * Settle-Phase + Overlay-Dissolve (bzw. hartem Schnitt bei
     * uebergang:'none'). Bei nur einem Slide: einmal mounten, keine
     * Rotation (Slide darf innen leben — Uhr, FRET, Stundenplan).
     *
     * neuSammeln (optional): wird nach jeder vollen Rotationsrunde
     * aufgerufen und liefert frische Descriptors — erhält das bisherige
     * Verhalten, dass Module pro Runde neu rendern/fetchen (z.B.
     * veranstaltung). Bis die neuen Slides da sind, läuft die alte
     * Sequenz weiter.
     */
    function spieleSlides(spalteEl, descriptors, neuSammeln) {
        var index = 0;
        spalteEl.style.position = 'relative';

        function zeigeNaechsten() {
            if (!spalteEl.isConnected) { return; }
            var desc = descriptors[index];
            index = (index + 1) % descriptors.length;

            // Runde abgeschlossen → Slides asynchron neu sammeln (Daten-Refresh)
            if (index === 0 && descriptors.length > 1 && typeof neuSammeln === 'function') {
                neuSammeln(function (neue) {
                    if (neue && neue.length > 0 && spalteEl.isConnected) {
                        descriptors = neue;
                        if (index >= descriptors.length) { index = 0; }
                    }
                });
            }

            var oldContainer = spalteEl.querySelector('.tm-modul-container');
            var oldDesc      = oldContainer ? oldContainer._tmDescriptor : null;

            var newContainer = document.createElement('div');
            newContainer.className = 'tm-modul-container';
            newContainer._tmDescriptor = desc;
            newContainer.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;opacity:0;';

            // Weiterschaltung planen (nur bei mehreren Slides)
            if (descriptors.length > 1) {
                if (desc.meldetEnde && desc.slide) {
                    var weiterGerufen = false;
                    var weiter = function () {
                        if (weiterGerufen) { return; }
                        weiterGerufen = true;
                        zeigeNaechsten();
                    };
                    desc.slide.onEnde = weiter;
                    var tSafety = setTimeout(weiter, MELDET_ENDE_TIMEOUT_MS);
                    _rotationTimeouts.push(tSafety);
                } else {
                    var t = setTimeout(zeigeNaechsten, desc.dauerSek * 1000);
                    _rotationTimeouts.push(t);
                }
            }

            spalteEl.appendChild(newContainer);
            desc.mount(newContainer);

            if (oldContainer) {
                // Wechselstart: interne Weiterschaltung des alten Slides stoppen.
                if (oldDesc) { oldDesc.freeze(oldContainer); }
                else if (oldContainer._tmTimeout) {
                    clearTimeout(oldContainer._tmTimeout);
                    oldContainer._tmTimeout = null;
                }

                // Settle-Phase: neuer Container rendert unsichtbar vor (Fetch,
                // Bilder laden), erst danach startet der Wechsel — analog zum
                // Playlist-Wechsel. Verhindert einblendende "Lade…"-Texte und
                // mitten im Fade reinploppende Bilder.
                setTimeout(function () {
                    if (!newContainer.isConnected) { return; }

                    var entferneAlt = function () {
                        if (oldContainer.parentNode) {
                            destroyContainer(oldContainer);
                            oldContainer.parentNode.removeChild(oldContainer);
                        }
                    };

                    if (desc.uebergang === 'none') {
                        // Instanz-Einstellung "Kein Übergang": harter Schnitt
                        newContainer.style.opacity = '1';
                        entferneAlt();
                        return;
                    }

                    // Overlay-Dissolve: neuer Container (deckender Hintergrund via
                    // CSS .tm-modul-container) blendet ÜBER den alten ein; der alte
                    // bleibt bei opacity:1 und wird nach dem Fade unsichtbar entfernt.
                    newContainer.style.transition = 'opacity ' + FADE_MS + 'ms ease';
                    requestAnimationFrame(function () {
                        requestAnimationFrame(function () {
                            newContainer.style.opacity = '1';
                        });
                    });
                    setTimeout(entferneAlt, FADE_MS + 100);
                }, MODUL_SETTLE_MS);
            } else {
                // Erster Render: direkt einblenden, kein Crossfade
                newContainer.style.opacity = '1';
            }
        }

        zeigeNaechsten();
    }

    /**
     * Rendert alle Spalten einer Playlist mit proportionaler Skalierung:
     * Die längste Spalte bestimmt den Zyklus; kürzere Spalten werden so
     * gestreckt, dass alle gleichzeitig enden.
     * Gibt die maximale Zyklusdauer in ms zurück (für den Playlist-Timer) —
     * synchron geschätzt via modulAnzeigeDauer; die tatsächlichen
     * Slide-Dauern werden auf dieselbe Zyklusdauer skaliert.
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

        // 2. Slides je Spalte sammeln und abspielen
        for (i = 1; i <= anzahl; i++) {
            (function (spalteEl, spaltenMods, factor) {
                if (!spalteEl || spaltenMods.length === 0) { return; }
                sammleSpaltenSlides(spaltenMods, factor, function (descriptors) {
                    if (!spalteEl.isConnected || descriptors.length === 0) { return; }
                    spieleSlides(spalteEl, descriptors, function (fertigNeu) {
                        sammleSpaltenSlides(spaltenMods, factor, fertigNeu);
                    });
                });
            })(
                layoutEl.querySelector('[data-spalte="' + i + '"]'),
                spalten[i] || spalten[String(i)] || [],
                (zyklenMs[i] > 0) ? maxCycleMs / zyklenMs[i] : 1
            );
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
                                        .forEach(destroyContainer);
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

    // ── Engine-Export ─────────────────────────────────────────────────────────
    // Für admin/playlist-preview.php: nutzt dieselbe Engine wie die Monitore.
    // Mit window.TM_ENGINE_ONLY = true (VOR dem Laden dieser Datei gesetzt)
    // wird nur die Engine bereitgestellt — kein Monitor-Betrieb (kein Polling).

    window.TanzschuleEngine = {
        renderSpalten: renderSpalten
    };

    if (window.TM_ENGINE_ONLY === true) {
        return;
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
