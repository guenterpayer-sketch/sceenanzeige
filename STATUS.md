# STATUS — Tanzschule Monitor-System

> **Branch:** `claude/nifty-johnson-3q6u7g`  
> Eine neue Session liest `CLAUDE.md` (Konzept) + diese Datei (Stand) und kann sofort weiterarbeiten.

_Letzte Aktualisierung: Schritt 15 — Monitor-Domain, CI/CD-Deploy, Testmon-Frontend + Bugfixes._

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
| 13 | Modul `veranstaltung` (WP Events Calendar) + Vorschau-Schema-Fix | ✅ geliefert, noch nicht live getestet |
| 14 | Modul `video` (eigene Uploads + YouTube/PeerTube-Embeds) + Videothek-Admin | ✅ live getestet |
| 15 | CI/CD via GitHub Actions + Monitor-Domain + Testmon-Frontend | ✅ auf Staging getestet, bereit für Live-Merge |

---

## Offene Punkte

- **Modul `veranstaltung`:** geliefert, aber noch nicht live getestet — Feedback ausstehend
- **FRET Countdown 22px:** Schriftgröße erhöht, live-Test noch ausstehend
- **FRET Fortschrittsbalken:** FRET-API liefert `remainingSeconds` immer `null` → Balken friert ein, läuft nicht; serverseitiges FRET-Problem, kein Code-Fehler
- **SETTLE_MS = 800:** Heuristik für Off-screen-Pre-render; bei sehr langsamer NC-API ggf. auf 1000–1200ms erhöhen
- **Live-Merge ausstehend:** Branch `claude/nifty-johnson-3q6u7g` → `main` noch nicht gemergt (Schritt 15 + alle vorherigen Änderungen inkl. Bugfixes Monitor-Domain)

---

## Was in den letzten Sessions erledigt wurde

### Schritt 15 — CI/CD + Monitor-Domain + Testmon (auf Staging getestet ✅)

| Datei | Was |
|---|---|
| `.github/workflows/deploy.yml` | NEU — GitHub Actions: Push auf `claude/nifty-johnson-3q6u7g` → FTP-Deploy auf `screen.spass-am-tanzen.de` (Staging-Backend) + `testmon.spass-am-tanzen.de` (Staging-Monitor); Merge auf `main` → FTP-Deploy auf `screen.tcpayer.de` (Live) |
| `includes/Monitor.php` | `normSubdomain()` → `normDomain()`: akzeptiert vollständige Domain (z.B. `saal1.tcpayer.de`, `testmon.spass-am-tanzen.de`); `normSubdomain()` als Deprecated-Alias erhalten |
| `admin/monitore.php` | Eingabefeld-Label/Placeholder → „Domain" (vollständige Domain); Kachel-Anzeige + Vorschau-URL ohne hardcodiertes `.tcpayer.de` |
| `13_migration_monitor_domain.sql` | Migration: `UPDATE monitore SET subdomain = CONCAT(subdomain, '.tcpayer.de') WHERE subdomain NOT LIKE '%.%'` — **nicht nötig**, DB-Einträge waren bereits korrekt |
| `02_ordnerstruktur/testmon.spass-am-tanzen.de/index.html` | NEU — Monitor-Frontend für Test-Monitor; Tippfehler `screen.spass-am.tanzen.de` korrigiert; `BACKEND_BASE` zeigt auf `screen.spass-am-tanzen.de`, `UPLOADS_URL` zeigt auf `screen.tcpayer.de/uploads` (Bilder vom Live-Server, kein eigener Upload-Ordner auf Staging nötig) |
| `assets/js/monitor.js` | Bugfix: `getSubdomain()` liefert jetzt `window.location.hostname` (vollständig) statt nur ersten Teil — Monitor findet sich korrekt in der DB |
| `admin/monitor-vorschau.php` | Bugfix: hardcodiertes `.tcpayer.de` entfernt |
| `admin/monitor-zeitplan.php` | Bugfix: hardcodiertes `.tcpayer.de` im Vorschau-Button entfernt |

### Schritt 14 — Modul `video` + Videothek-Admin (live getestet ✅)

| Datei | Was |
|---|---|
| `12_migration_video.sql` | NEU — Tabelle `video_dateien` + Spalten `video_datei_id`/`video_embed_url` in `modul_instanz_inhalte` |
| `includes/Videothek.php` | NEU — CRUD für `video_dateien`, MIME-Prüfung via `finfo` (mp4/webm), Upload + Bearbeiten + Löschen |
| `admin/videothek.php` | NEU — eigener Admin-Menüpunkt „Videos"; Drag&Drop-Upload, Galerie, Bearbeiten (Name/Laufzeit via `.adm-bild-edit`-Button wie Mediathek), Löschen |
| `admin/api/video-upload.php` | NEU — POST-Endpoint; nimmt `datei` + optionale `dauer_sek`, gibt `{ok, duplikat, eintrag}` zurück |
| `admin/api/video-delete.php` | NEU — POST-Endpoint; ruft `Videothek::delete()` auf |
| `admin/api/video-list.php` | NEU — GET-Endpoint; liefert `{ok, videos:[{id,url,original_name,dateiname,dauer_sek}]}` |
| `admin/api/video-update.php` | NEU — POST-Endpoint; aktualisiert `original_name` + `dauer_sek` |
| `admin/includes/bootstrap.php` | `Videothek.php` eingebunden |
| `admin/includes/layout.php` | Nav-Eintrag `videos → videothek.php` nach `mediathek` |
| `modules/video/module.json` | NEU — `has_inhalte: true`, Einstellung `intervall_sek` |
| `modules/video/frontend.js` | NEU — event-getrieben (`ended`), YouTube IFrame API (`controls=0, modestbranding=1, rel=0, showinfo=0, iv_load_policy=3`), PeerTube postMessage, 15-Min-Timeout |
| `modules/registry.php` | `video` eingetragen |
| `includes/ModulInstanz.php` | `listInhalte`: JOIN `video_dateien`; `ersetzeInhalte`: `video_datei_id`/`video_embed_url` |
| `proxies/monitor.php` | `$stmtInhalte`: LEFT JOIN `video_dateien` + Felder `video_dateiname`/`video_embed_url` (Bugfix: ohne diese fehlten die Daten im Frontend) |
| `admin/instanz.php` | Video-Editor-Zeile mit Radio-Toggle Datei/Embed, Video-Picker-Dialog, POST-Verarbeitung |
| `admin/bibliothek.php` | `modul_icon`: `video → 🎬`; `instanz_vorschau`: Video-Vorschau-Branch |
| `assets/css/admin.css` | Styles für Video-Vorschau in Zeilen + Kacheln, Embed-URL-Feld, Picker-Video; Bearbeiten-Button `.adm-bild-edit` (wie Mediathek) |
| `assets/js/monitor.js` | `cleanupModulContainer`: `_tmYtPlayer.destroy()` + `_tmPeertubeListener` entfernen |

### Bugfixes — Crossfade, Pre-render, Ticker-Vorschau

| Datei | Was |
|---|---|
| `assets/js/monitor.js` | `rotateModule`: Crossfade 1500ms zwischen Modul-Instanzen (statt hartem Schnitt) |
| `assets/js/monitor.js` | `doRender`: Neues Layout 800ms (`SETTLE_MS`) unsichtbar vorrendern bevor Crossfade startet |
| `assets/js/monitor.js` | `doRender`: `_rotationTimeouts.forEach(clearTimeout)` — altes Layout friert sofort ein während SETTLE_MS |
| `admin/playlist-preview.php` | Ticker anzeigen wenn `footer_ticker` aktiv |

### Schritt 13 — Modul `veranstaltung` + Fixes

| Datei | Was |
|---|---|
| `proxies/veranstaltungen.php` | NEU — Proxy für WP Events Calendar REST-API |
| `modules/veranstaltung/module.json` | NEU — Einstellungen: `anzahl`, `anzeige_dauer_sek`, `uebergang` |
| `modules/veranstaltung/frontend.js` | NEU — A/B-Crossfade, deutsche Datums-/Uhrzeitformatierung |
| `modules/registry.php` | `veranstaltung` eingetragen |
| `assets/css/monitor.css` | Styles für `veranstaltung`-Modul + `.tm-sp-heading` + FRET-Countdown 22px |
| `admin/includes/layout.php` | Globale Admin-Dialoge `admBestaetigen`/`admMeldung`/`admEingabe` in `admin_footer()` |
| `modules/stundenplan/module.json` | Setting `titel` hinzugefügt |
| `modules/stundenplan/frontend.js` | `.tm-sp-heading` rendern wenn `titel` gesetzt |

### Schritt 12 — Livebetrieb-Feedback

- **Ticker:** Schriftgröße 30px, Footer-Höhe 70px
- **Pixel-Größen-Panel** im Playlist-Editor neben der Vorschau
- **Zeitplan-Sortierung:** ↑/↓-Buttons, Reihenfolge als Tiebreaker
- **Stundenplan Standort-/Saal-Filter:** `location_ids`, `room_id`; `proxies/nc-locations.php`
- **Stundenplan feste Kartenhöhe:** `requestAnimationFrame` berechnet Höhe nach Render
- **Stundenplan responsive Schrift:** Container Queries, `@container (max-width: 700px)` → 22px

---

## CI/CD-Workflow

```
Push auf claude/nifty-johnson-3q6u7g
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
- **video:** `proxies/monitor.php` braucht expliziten LEFT JOIN auf `video_dateien` — `ModulInstanz::listInhalte` allein reicht nicht
- **video YT:** YouTube-UI maximal reduzierbar per API: `controls=0, modestbranding=1, rel=0, showinfo=0, iv_load_policy=3`; CSS-Overlay wäre ToS-Verstoß
- **Monitor-Domain:** `monitore.subdomain` enthält seit Schritt 15 die vollständige Domain (z.B. `saal1.tcpayer.de`), nicht nur den Subdomain-Teil

---

## Zugriffsschutz

- `admin/` via all-inkl Basic-Auth (KAS → Tools → Verzeichnisschutz)
- `config.php` via `.htaccess` `Require all denied`
- `proxies/`, `uploads/`, `modules/` offen (Monitore brauchen Zugriff)

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Branch `claude/nifty-johnson-3q6u7g`; nach jedem Abschnitt committen + pushen + `STATUS.md` aktualisieren.
