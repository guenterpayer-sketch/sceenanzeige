# STATUS — Tanzschule Monitor-System

> **Branch:** `claude/nifty-johnson-3q6u7g`  
> Eine neue Session liest `CLAUDE.md` (Konzept) + diese Datei (Stand) und kann sofort weiterarbeiten.

_Letzte Aktualisierung: TV-Skalierung (Google TV 720p) live getestet + finalisiert — nächster Fokus: Schritt 10 Live-Vorschau._

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
| 9b-sp | Monitor-Frontend: Layout `stundenplan` | **✅ live getestet** |
| 9b-fret | Monitor-Frontend: Layout `fret` | **✅ live getestet** |
| 9c | TV-Skalierung (Google TV 720p → scale auf 1920px) | **✅ live getestet** |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Schritt 9b — Layout-Anpassungen

### Stundenplan-Modul (✅ live getestet, finalisiert)

**Finale CSS-Werte (live getestet):**
- `grid-template-columns: 80px 100px 1fr 160px`
- `.tm-sp-cards`: `display: flex; flex-direction: column; gap: 7px; height: 100%`
- `.tm-sp-card`: `flex: 1; font-size: 32px; padding: 8px 20px; background: rgba(255,255,255,0.1); border-radius: 6px`
- `.tm-sp-zeit`: `color: #ad2121` (Tanzschul-Rot)
- `.tm-sp-lehrer`: `font-size: 22px` (bewusst kleiner als Basis)
- Kurs erlaubt 2 Zeilen (`-webkit-line-clamp: 2`)
- `formatZeit()` extrahiert HH:MM via Regex aus `start_date` (timezone-safe)
- Kacheln verteilen sich gleichmäßig auf die volle Höhe (`flex: 1`)
- Offener Punkt: Spaltenbreiten ggf. anpassen wenn längere NC-Kursbeschreibungen kommen

### Song/FRET-Modul (✅ live getestet, finalisiert)

**Finale CSS-Werte (live getestet):**
- Überschrift: `font-size: 32px`, rot, uppercase (`settings.titel || 'FRET'`)
- Song-Titel: 40px, Künstler: 36px
- Haupt-Badge aktueller Song: 40px / Sub-Badge: 28px, padding: 4px 12px
- Badge-Container: `align-items: center` (verhindert Streckung im Flex-Container)
- Folgeliste Badges: 26px / Sub-Badge: 20px, border-width: 1.5px
- Fortschrittsbalken: 10px Höhe; läuft wenn FRET `isPlaying: true` + `remainingSeconds != null`
- Neues `titel`-Setting in `module.json`

**Offene Punkte:**
- **Fortschrittsbalken:** FRET-API liefert `remainingSeconds` immer `null` → Balken friert bei Pause korrekt ein, läuft aber auch beim Spielen nicht; serverseitiges FRET-Problem, kein Code-Fehler
- Countdown funktioniert ✅ (nach FRET-Server-Neustart in der Tanzschule)
- Browser-Cache-Hinweis: `modules/fret/frontend.js` wird dynamisch geladen → Shift+F5 reicht nicht; vollständigen Browser-Cache leeren (Strg+Shift+Entf)

---

## Was in der letzten Session erledigt wurde

### Fixes & Erweiterungen Monitor-Frontend
- **CORS-Bug (saal3 + neue Monitore):** `.htaccess` auf `Header set Access-Control-Allow-Origin "*"` vereinfacht; PHP-seitige CORS-Header aus allen Proxys entfernt → neue Monitore funktionieren automatisch
- **Header/Footer Layout-Sprung:** `flex-basis` → `height`-Animation, synchron mit Crossfade
- **Proportionale Spalten-Synchronisation:** längste Spalte = Master; alle anderen + deren Inhalte skalieren via `skaliereMod()` proportional
- **`anzeige_dauer_sek`-Setting:** zu `uhrzeit`, `stundenplan`, `fret` in `module.json` hinzugefügt
- **Ticker: Einzelner Eintrag** → kein Überblenden, dauerhaft sichtbar
- **Ticker: Kurzer Text** → zentriert (`tm-ticker-zentriert`-Klasse, `justify-content: center`)
- **Ticker: Unabhängig von Playlist-Rotation** → läuft global weiter; `doRender()` steuert nur noch Sichtbarkeit; Neustart nur bei geänderten Einträgen
- **"Refresh Monitore"-Button:** im Admin-Menü; setzt `reload_at = NOW()` in `monitore`; Monitor erkennt Änderung beim nächsten Poll und lädt neu

### Neue/geänderte Dateien
| Datei | Was |
|---|---|
| `.htaccess` | CORS vereinfacht |
| `proxies/monitor.php` | CORS-Header entfernt; `reload_at` in Response |
| `proxies/nc.php` | CORS-Header entfernt |
| `proxies/fret.php` | CORS-Header entfernt |
| `assets/js/monitor.js` | Ticker-Unabhängigkeit, prop. Skalierung, Refresh-Erkennung, Ticker-Fixes |
| `assets/css/monitor.css` | Header/Footer height-Animation, `.tm-ticker-zentriert` |
| `includes/Monitor.php` | `triggerReloadAlle()` |
| `admin/includes/layout.php` | "Refresh Monitore"-Button in Nav |
| `admin/reload_trigger.php` | NEU — POST-Endpoint für Reload-Trigger |
| `sql/migration_reload_at.sql` | NEU — `ALTER TABLE monitore ADD COLUMN reload_at ...` |
| `modules/uhrzeit/module.json` | `anzeige_dauer_sek` hinzugefügt |
| `modules/stundenplan/module.json` | `anzeige_dauer_sek` hinzugefügt |
| `modules/fret/module.json` | `anzeige_dauer_sek` hinzugefügt |

---

## Wichtige Architektur-Entscheidungen (Kurzfassung)

Vollständige Liste in `CLAUDE.md` Abschnitt 12. Highlights:

- **CORS:** nur `.htaccess`, keine PHP-Header
- **Ticker:** läuft global; `startTicker()` nur in `render()`, nie in `doRender()`
- **Spalten-Sync:** `skaliereMod(mod, factor)` skaliert `inhalte[].dauer_sek` + `einstellungen.anzeige_dauer_sek`
- **FRET:** `FRET_SCHOOL_ID` niemals im Frontend — nur in `config.php` + `proxies/fret.php`
- **Modul-Funktion:** `TanzschuleLoader.register('typ', function(container, einstellungen, inhalte) { … })`

---

## Zugriffsschutz

- `admin/` via all-inkl Basic-Auth (KAS → Tools → Verzeichnisschutz)
- `config.php` via `.htaccess` `Require all denied`
- `proxies/`, `uploads/`, `modules/` offen (Monitore brauchen Zugriff)

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Branch `claude/nifty-johnson-3q6u7g`; nach jedem Abschnitt committen + pushen + `STATUS.md` aktualisieren.
