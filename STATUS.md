# STATUS — Tanzschule Monitor-System

> **Branch:** `claude/nifty-johnson-3q6u7g`  
> Eine neue Session liest `CLAUDE.md` (Konzept) + diese Datei (Stand) und kann sofort weiterarbeiten.

_Letzte Aktualisierung: Bugfixes Crossfade/Pre-render/Ticker-Vorschau._

---

## Bauplan-Fortschritt

| Schritt | Inhalt | Stand |
|---|---|---|
| 1 | DB-Schema | ✅ live |
| 2 | Ordnerstruktur + .htaccess | ✅ live |
| 3 | Modul-Registry + `uhrzeit`, `bild` | ✅ live |
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy | ✅ live getestet |
| 5 | Backend: Bibliothek + Mediathek | ✅ live getestet |
| 6 | Backend: Playlist-Editor | ✅ live getestet |
| 7 | Backend: Monitore + Zeitplan | ✅ live getestet |
| 8 | Backend: Ticker + Ticker-Zeitplan | ✅ live getestet |
| 9 | Monitor-Frontend (Kern-Logik) | ✅ live getestet |
| 9b-sp | Monitor-Frontend: Layout `stundenplan` | ✅ live getestet |
| 9b-fret | Monitor-Frontend: Layout `fret` | ✅ live getestet |
| 9c | TV-Skalierung (Google TV 720p → scale auf 1920px) | ✅ live getestet |
| 10 | Live-Vorschau (iFrame) + Playlist-Vorschau | ✅ live getestet |
| 11 | Deployment-Guide | ✅ live (manuell per FTP auf all-inkl) |
| 12 | Livebetrieb-Feedback: Ticker 30px/70px, Pixel-Panel, Zeitplan-Sortierung | ✅ live |
| 13 | Modul `veranstaltung` (WP Events Calendar) + Vorschau-Schema-Fix | ✅ geliefert, noch nicht live getestet |

---

## Offene Punkte

- **Modul `veranstaltung`:** geliefert, aber noch nicht live getestet — Feedback ausstehend
- **FRET Countdown 22px:** Schriftgröße erhöht, live-Test noch ausstehend
- **FRET Fortschrittsbalken:** FRET-API liefert `remainingSeconds` immer `null` → Balken friert ein, läuft nicht; serverseitiges FRET-Problem, kein Code-Fehler
- **SETTLE_MS = 800:** Heuristik für Off-screen-Pre-render; bei sehr langsamer NC-API ggf. auf 1000–1200ms erhöhen

---

## Was in den letzten Sessions erledigt wurde

### Bugfixes — Crossfade, Pre-render, Ticker-Vorschau

| Datei | Was |
|---|---|
| `assets/js/monitor.js` | `rotateModule`: Crossfade 1500ms zwischen Modul-Instanzen (statt hartem Schnitt); passend zu `bild/frontend.js` |
| `assets/js/monitor.js` | `doRender`: Neues Layout 800ms (`SETTLE_MS`) unsichtbar vorrendern (opacity:0, korrekt positioniert) bevor Crossfade startet — verhindert halb-fertige Module beim Layout-Wechsel |
| `assets/js/monitor.js` | `doRender`: `_rotationTimeouts.forEach(clearTimeout)` vor Reset — altes Layout friert sofort ein, rotiert nicht weiter während SETTLE_MS |
| `admin/playlist-preview.php` | Ticker anzeigen wenn `footer_ticker` aktiv: DB-Abfrage aller aktiven `ticker_eintraege` + `startTicker`-Logik eingebaut |

### Schritt 13 — Modul `veranstaltung` + Fixes

| Datei | Was |
|---|---|
| `proxies/veranstaltungen.php` | NEU — Proxy für WP Events Calendar REST-API (öffentlich, kein Key) |
| `modules/veranstaltung/module.json` | NEU — Einstellungen: `anzahl`, `anzeige_dauer_sek`, `uebergang` |
| `modules/veranstaltung/frontend.js` | NEU — A/B-Crossfade, deutsche Datums-/Uhrzeitformatierung |
| `modules/registry.php` | `veranstaltung` eingetragen |
| `assets/css/monitor.css` | Styles für `veranstaltung`-Modul + `.tm-sp-heading` + FRET-Countdown 22px |
| `admin/includes/layout.php` | Globale Admin-Dialoge in `admin_footer()` (admBestaetigen/admMeldung/admEingabe) |
| `admin/bibliothek.php` | `confirm()` → `admBestaetigen()` |
| `admin/playlists.php` | `confirm()` → `admBestaetigen()` |
| `admin/ticker.php` | `confirm()` → `admBestaetigen()` |
| `admin/monitore.php` | `confirm()` → `admBestaetigen()` |
| `admin/instanz.php` | `confirm()` → `admBestaetigen()` |
| `admin/ticker-edit.php` | `confirm()` → `admBestaetigen()` |
| `admin/playlist-editor.php` | `alert()` → `admMeldung()`; Vorschau-Breite Fix (flex: 0 0 480px) |
| `admin/mediathek.php` | Lokale Modals entfernt; alle Dialoge auf globale Funktionen umgestellt |
| `modules/stundenplan/module.json` | Setting `titel` hinzugefügt |
| `modules/stundenplan/frontend.js` | `.tm-sp-heading` rendern wenn `titel` gesetzt |

### Schritt 12 — Livebetrieb-Feedback (ältere Session)

- **Ticker:** Schriftgröße 30px, Footer-Höhe 70px
- **Pixel-Größen-Panel** im Playlist-Editor neben der Vorschau
- **Zeitplan-Sortierung:** ↑/↓-Buttons, Reihenfolge als Tiebreaker
- **Stundenplan Standort-/Saal-Filter:** `location_ids`, `room_id` in Einstellungen; `proxies/nc-locations.php`
- **Stundenplan feste Kartenhöhe:** `requestAnimationFrame` berechnet Höhe nach Render
- **Stundenplan responsive Schrift:** Container Queries, `@container (max-width: 700px)` → 22px

---

## Schritt 9b — Finale CSS-Werte (live getestet)

### Stundenplan
- `grid-template-columns: 110px 100px 1fr 160px` (klein: `80px 70px 1fr 115px`)
- `.tm-sp-card`: `font-size: 32px` (klein: 22px), `padding: 8px 20px`
- `.tm-sp-zeit`: `color: #ad2121`; `.tm-sp-lehrer`: `font-size: 22px`
- Überschrift `.tm-sp-heading`: 48px, zentriert, Großbuchstaben, rot

### FRET/Song
- Song-Titel: 40px, Künstler: 36px
- Haupt-Badge: 40px / Sub-Badge: 28px
- Countdown-Liste: Titel 28px, Artist 22px, Countdown 22px
- Fortschrittsbalken: 10px Höhe

---

## Wichtige Architektur-Entscheidungen (Kurzfassung)

Vollständige Liste in `CLAUDE.md` Abschnitt 12. Highlights:

- **CORS:** nur `.htaccess`, keine PHP-Header
- **Ticker:** läuft global; `startTicker()` nur in `render()`, nie in `doRender()`
- **Spalten-Sync:** `skaliereMod(mod, factor)` skaliert Dauern proportional
- **FRET:** `FRET_SCHOOL_ID` niemals im Frontend — nur in `config.php` + `proxies/fret.php`
- **Admin-Dialoge:** `confirm()`/`alert()`/`prompt()` → `admBestaetigen()`/`admMeldung()`/`admEingabe()` (global in `layout.php`)
- **veranstaltung:** `status=future` nicht unterstützt (free Plugin) → `start_date=heute` als Filter

---

## Zugriffsschutz

- `admin/` via all-inkl Basic-Auth (KAS → Tools → Verzeichnisschutz)
- `config.php` via `.htaccess` `Require all denied`
- `proxies/`, `uploads/`, `modules/` offen (Monitore brauchen Zugriff)

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Branch `claude/nifty-johnson-3q6u7g`; nach jedem Abschnitt committen + pushen + `STATUS.md` aktualisieren.
