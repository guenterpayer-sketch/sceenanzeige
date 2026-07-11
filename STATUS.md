# STATUS — Tanzschule Monitor-System

> **Branch:** `claude/intelligent-cray-im1xte`  
> Eine neue Session liest `CLAUDE.md` (Konzept) + diese Datei (Stand) und kann sofort weiterarbeiten.

_Letzte Aktualisierung: Schritt 19 — Modul-Übergänge (Overlay-Dissolve + Settle-Phase); Konzept Slide-Engine dokumentiert (Schritt 20)._

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
| 11 | Deployment-Guide | ✅ ersetzt durch CI/CD (Schritt 15) |
| 12 | Livebetrieb-Feedback: Ticker 30px/70px, Pixel-Panel, Zeitplan-Sortierung | ✅ live |
| 13 | Modul `veranstaltung` (WP Events Calendar) + Vorschau-Schema-Fix | ✅ live |
| 14 | Modul `video` (eigene Uploads + YouTube/PeerTube-Embeds) + Videothek-Admin | ✅ live getestet |
| 15 | CI/CD via GitHub Actions + Monitor-Domain + Testmon-Frontend | ✅ live |
| 16 | FRET-Modul: Layout Variante D, Countdown-Fallback, rAF-Fortschrittsbalken, Admin-Versionsanzeige | ✅ live |
| 17 | Modul `veranstaltung`: adaptives Layout (Hochkant/Querformat/Kein Bild), Zyklusdauer-Fix in `monitor.js` | ✅ live |
| 18 | Playlist-Monitor-Tooltip, Veranstaltung Glow (DOM-Element), Ankündigung Vollbild-Layout + einstellbare Pill-Transparenz | ✅ live |
| 19 | Modul-Übergänge: Overlay-Dissolve (deckende Container) + Settle-Phase + Rotation-Freeze in `rotateModule`; Layer-Transition-Fixes `bild`/`ankuendigung` | 🧪 auf Staging, Test ausstehend |
| 20 | Slide-Engine: Trennung Inhalt/Präsentation (`KONZEPT_SLIDE_ENGINE.md`) | 📋 Konzept dokumentiert |

---

## Offene Punkte

- **Schritt 19 testen:** Auf `testmon.spass-am-tanzen.de` prüfen: Modul-Wechsel Bild↔Ankündigung↔Stundenplan in einer Spalte — weiche Dissolves, kein Durchscheinen, kein Pop, kein einblendender „Lade…"-Text
- **`veranstaltung/frontend.js`:** trägt noch das alte Transition-Muster (innere Layer-Transition vor erstem Render) — durch Settle-Phase maskiert; verschwindet endgültig mit der Slide-Engine (Schritt 20)
- **Slide-Engine (Schritt 20):** Konzept in `KONZEPT_SLIDE_ENGINE.md`; offene Fragen dort in Abschnitt 6 vor Etappe 1 klären
- **FRET Fortschrittsbalken:** FRET-API liefert `remainingSeconds` immer `null` → `startTime`-Fallback greift; serverseitiges FRET-Problem, kein Code-Fehler
- **SETTLE_MS = 800:** Heuristik für Off-screen-Pre-render; bei sehr langsamer NC-API ggf. auf 1000–1200ms erhöhen
- **Branch-Protection:** `main` in GitHub-Settings → Branches → Add ruleset schützen (noch nicht eingerichtet)

---

## Was in den letzten Sessions erledigt wurde

### Schritt 19 — Modul-Übergänge: Overlay-Dissolve + Settle-Phase (Staging 🧪)

**Problemkette (drei Ursachen, nacheinander gefunden):**
1. Innere Layer-Transitions in `bild`/`ankuendigung` kollidierten mit dem äußeren Container-Fade → multiplikative Opacity (0.5 × 0.5 = 0.25) → wirkte wie harter Schnitt. Fix: Transition erst per rAF nach dem ersten Render.
2. Simultanes Kreuzblenden (alt 1→0, neu 0→1) ließ den dunklen Hintergrund durchscheinen („Dip to Black"). Fix: Overlay-Fade — alt bleibt sichtbar, neu blendet darüber ein.
3. Overlay allein: alter Inhalt blieb sichtbar stehen (Text) bzw. schien durch Letterbox/halbtransparente Flächen und poppte am Ende weg. Fix: deckender Container-Hintergrund + Settle-Phase.

| Datei | Was |
|---|---|
| `assets/js/monitor.js` | `rotateModule`: `MODUL_SETTLE_MS = 800` (Pre-Render unsichtbar, dann Fade — analog Playlist-`SETTLE_MS`); Overlay-Dissolve (alter Container bleibt `opacity:1`, wird nach Fade entfernt); beim Wechselstart nur `_tmTimeout` des alten Containers einfrieren (Live-Intervalle + Video-Player laufen weiter, volles Cleanup erst beim Entfernen) |
| `assets/css/monitor.css` | `.tm-modul-container { background: #0a0a0a }` — deckend (= Seitenhintergrund): kein Durchscheinen des alten Moduls durch Letterbox-Ränder oder halbtransparente Stundenplan-Karten, kein Pop beim Entfernen |
| `modules/bild/frontend.js` | Innere Layer-Transition erst per rAF nach dem ersten Render (Muster aus `ankuendigung`, Schritt 18) |
| `KONZEPT_SLIDE_ENGINE.md` | **Neu:** Architektur-Konzept Schritt 20 — Module liefern nur Slides, Engine besitzt Präsentation; Vertrag, Modul-Mapping, 3-Etappen-Migrationsplan, offene Fragen |

**Merkregel für alle Module:** Innere `opacity`-Transitions niemals vor dem ersten Render setzen — immer erst per `requestAnimationFrame` danach.

---

### Schritt 18 — Playlist-Tooltip, Veranstaltung Glow, Ankündigung Redesign (live ✅)

| Datei | Was |
|---|---|
| `includes/Playlist.php` | `listAll()`: `GROUP_CONCAT`-Subquery liefert `monitor_namen` (kommasepariert); SEPARATOR-Quotes korrekt escaped (`\'`) |
| `admin/playlists.php` | Monitor-Badge mit `data-monitore`-Attribut + Klasse `adm-monitore-badge--aktiv` bei ≥ 1 Monitor |
| `assets/css/admin.css` | CSS-Tooltip via `::after` + `content: attr(data-monitore)` auf Hover |
| `modules/veranstaltung/frontend.js` | Glow-Effekt als echtes DOM-Element `<div class="tm-va-bild-glow">` mit Inline-`background-image`; Landscape-Bild: `object-fit: contain` + Glow füllt Letterbox |
| `assets/css/monitor.css` | `.tm-va-bild-glow`: `filter: blur(24px) brightness(0.72) saturate(1.3)`; Landscape + Portrait img: `object-fit: contain; z-index: 1` |
| `modules/ankuendigung/module.json` | Neues Setting `pill_transparenz` (15/30/45 %, Standard 15 %); Setting `schrift_groesse` (36–96 px, Standard 60 px) |
| `modules/ankuendigung/frontend.js` | Vollbild-Layout: `.tm-ank-bg` (Hintergrundbild, `object-fit: cover`) + Text-Pill mit konfigurierbarer `rgba(0,0,0,α)`; ohne Bild: nur Text zentriert |
| `assets/css/monitor.css` | Ankündigung: `.tm-ank-mit-bild .tm-ank-bg` (absolut, `inset:0`), `.tm-ank-mit-bild .tm-ank-text` (Pill: `border-radius:16px`, `padding:24px 36px`, Hintergrund per Inline-Style) |

---

### Schritt 17 — Modul `veranstaltung`: Adaptives Layout + Feinschliff (live ✅)

| Datei | Was |
|---|---|
| `proxies/veranstaltungen.php` | `bild_breite`/`bild_hoehe` aus WP-API `image.width`/`image.height` hinzugefügt |
| `modules/veranstaltung/frontend.js` | Kompletter Rewrite: Orientierungs-Erkennung (`breite/hoehe < 0.85` = Hochkant), drei Layout-Varianten (`portrait`/`landscape`/`keinbild`), `img.onload`-Fallback, Wochentage ausgeschrieben, nur Datum + Uhrzeit + Titel angezeigt (kein Venue, keine Beschreibung) |
| `assets/css/monitor.css` | Neue Styles für alle drei Varianten: Portrait (Bild links 40 %, Frosted-Glass rechts), Landscape (Vollbild + Gradient-Overlay ab 55 % von oben), Kein Bild (zentriert); Datum 38 px / `#e03535`; Uhrzeit 30 px; Titel 72 px mit `padding-bottom: 12px` (verhindert Unterlängen-Clipping durch `-webkit-line-clamp`); starker Text-Schatten |
| `assets/js/monitor.js` | `modulAnzeigeDauer()`: Sonderfall `veranstaltung` — Gesamtdauer = `anzahl × anzeige_dauer_sek` (Events kommen aus externer API, nicht aus `inhalte[]`) |

**Schriftgrößen final (live getestet):**

| Element | Landscape | Portrait | Kein Bild |
|---|---|---|---|
| Datum | 38 px, `#e03535` | 34 px | 40 px |
| Uhrzeit | 30 px | 26 px | 32 px |
| Titel | 72 px, max. 2 Zeilen | 56 px, max. 3 Zeilen | 84 px, max. 2 Zeilen |

---

### Schritt 16 — FRET-Modul Verbesserungen (live ✅)

| Datei | Was |
|---|---|
| `assets/css/monitor.css` | FRET Layout Variante D: Überschrift 34 px/700, Song-Titel 42 px/700 + `text-shadow`, `.tm-song-aktuell` mit rotem Akzentbalken (`border-left: 4px solid #ad2121`), Countdown 22 px |
| `assets/js/monitor.js` | FRET Fortschrittsbalken via `requestAnimationFrame` (kein `setInterval`-Drift); `startTime`-Fallback wenn `remainingSeconds` null; Countdown-Fallback mit akkumulierter Lieddauer |
| `admin/includes/layout.php` | Admin-Versionsanzeige in Topbar: git-Hash + Datum + STAGING-Label aus `version.php` (wird von CI/CD generiert) |

**FRET CSS-Werte final (live getestet):**
- Überschrift: 34 px, weight 700
- Song-Titel: 42 px, weight 700, max. 2 Zeilen, Text-Schatten
- Künstler: 36 px
- Haupt-Badge: 40 px / Sub-Badge: 28 px
- Countdown-Liste: Titel 28 px, Artist 22 px, Countdown 22 px
- Fortschrittsbalken: 10 px Höhe
- `.tm-song-aktuell`: `border-left: 4px solid #ad2121; padding-left: 16px; flex: 0 0 33%; display: grid; align-content: center`

---

### Schritt 15 — CI/CD + Monitor-Domain + Testmon (live ✅)

| Datei | Was |
|---|---|
| `.github/workflows/deploy.yml` | GitHub Actions: Push auf Develop-Branch → FTP-Deploy auf Staging; Merge auf `main` → FTP-Deploy auf `screen.tcpayer.de` (Live) |
| `includes/Monitor.php` | `normDomain()`: akzeptiert vollständige Domain; `normSubdomain()` als Deprecated-Alias |
| `admin/monitore.php` | Eingabefeld „Domain" (vollständig); Kachel + Vorschau-URL ohne hardcodiertes `.tcpayer.de` |
| `02_ordnerstruktur/testmon.spass-am-tanzen.de/index.html` | Monitor-Frontend für Test-Monitor; `UPLOADS_URL` zeigt auf `screen.tcpayer.de/uploads` |
| `assets/js/monitor.js` | `getSubdomain()` → `window.location.hostname` (vollständig) |

---

### Schritt 14 — Modul `video` + Videothek-Admin (live getestet ✅)

| Datei | Was |
|---|---|
| `12_migration_video.sql` | Tabelle `video_dateien` + Spalten `video_datei_id`/`video_embed_url` |
| `includes/Videothek.php` | CRUD für `video_dateien`, MIME-Prüfung via `finfo` |
| `admin/videothek.php` | Admin-Menüpunkt „Videos": Drag&Drop-Upload, Galerie, Bearbeiten, Löschen |
| `modules/video/frontend.js` | Event-getrieben (`ended`), YouTube IFrame API, PeerTube postMessage, 15-Min-Timeout |
| `proxies/monitor.php` | LEFT JOIN `video_dateien` + Felder `video_dateiname`/`video_embed_url` |

---

## CI/CD-Workflow

```
Push auf Develop-Branch
  → FTP-Deploy: screen.spass-am-tanzen.de/   (Staging-Backend)
  → FTP-Deploy: testmon.spass-am-tanzen.de/  (Staging-Monitor)

Merge auf main
  → FTP-Deploy: screen.tcpayer.de/           (Live-Backend)
```

GitHub Secrets: `FTP_HOST`, `FTP_USER`, `FTP_PASS` (in Repository-Settings hinterlegt).
`config.php` ist in allen Jobs per `exclude` ausgenommen — muss einmalig manuell per FTP hochgeladen werden.

---

## Schritt 9b — Finale CSS-Werte (live getestet)

### Stundenplan
- `grid-template-columns: 110px 100px 1fr 160px` (klein: `80px 70px 1fr 115px`)
- `.tm-sp-card`: `font-size: 32px` (klein: 22px), `padding: 8px 20px`
- `.tm-sp-zeit`: `color: #ad2121`; `.tm-sp-lehrer`: `font-size: 22px`
- Überschrift `.tm-sp-heading`: 48px, zentriert, Großbuchstaben, rot

### FRET/Song (Layout Variante D — live)
- Überschrift: 34px, weight 700
- Song-Titel: 42px, weight 700, Text-Schatten, max. 2 Zeilen
- Künstler: 36px
- Haupt-Badge: 40px / Sub-Badge: 28px
- Countdown-Liste: Titel 28px, Artist 22px, Countdown 22px
- Fortschrittsbalken: 10px Höhe
- `.tm-song-aktuell`: roter Akzentbalken `border-left: 4px solid #ad2121`, feste 1/3-Höhe

---

## Wichtige Architektur-Entscheidungen (Kurzfassung)

Vollständige Liste in `CLAUDE.md` Abschnitt 12. Highlights:

- **CORS:** nur `.htaccess`, keine PHP-Header
- **Ticker:** läuft global; `startTicker()` nur in `render()`, nie in `doRender()`
- **Spalten-Sync:** `skaliereMod(mod, factor)` skaliert Dauern proportional; `veranstaltung` Sonderfall: `anzahl × anzeige_dauer_sek`
- **FRET:** `FRET_SCHOOL_ID` niemals im Frontend — nur in `config.php` + `proxies/fret.php`
- **Admin-Dialoge:** `confirm()`/`alert()`/`prompt()` → `admBestaetigen()`/`admMeldung()`/`admEingabe()` (global in `layout.php`)
- **veranstaltung:** `status=future` nicht unterstützt (free Plugin) → `start_date=heute` als Filter
- **video:** `proxies/monitor.php` braucht expliziten LEFT JOIN auf `video_dateien` — `ModulInstanz::listInhalte` allein reicht nicht
- **Monitor-Domain:** `monitore.subdomain` enthält die vollständige Domain (z.B. `saal1.tcpayer.de`)

---

## Zugriffsschutz

- `admin/` via all-inkl Basic-Auth (KAS → Tools → Verzeichnisschutz)
- `config.php` via `.htaccess` `Require all denied`
- `proxies/`, `uploads/`, `modules/` offen (Monitore brauchen Zugriff)

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Nach jedem Abschnitt committen + pushen + `STATUS.md` aktualisieren.
