# STATUS — Tanzschule Monitor-System

> **Branch:** `claude/nifty-johnson-3q6u7g`  
> Eine neue Session liest `CLAUDE.md` (Konzept) + diese Datei (Stand) und kann sofort weiterarbeiten.

_Letzte Aktualisierung: Schritt 9 live getestet + diverse Fixes/Erweiterungen — nächster Fokus: Layout-Anpassungen Stundenplan & Song._

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
| 9b | Monitor-Frontend: Layout `stundenplan` + `fret` | **offen → nächster Chat** |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Aktueller Fokus: Schritt 9b — Layout-Anpassungen

### Stundenplan-Modul
Datei: `modules/stundenplan/frontend.js` + CSS-Klassen `.tm-modul-stundenplan`, `.tm-sp-*`

Ziel: Card-Layout mit 4 fixen Spalten: **Uhrzeit · Saal · Kurs · Lehrer**
- Feste Box-Höhe, 6–8 px Gap zwischen Boxen
- Alle Felder vertikal zentriert; Kurs darf zweizeilig werden
- Felder aus Proxy: `start_date` (→ "HH:MM"), `room`, `displayName`, `teacher`

### Song/FRET-Modul
Datei: `modules/fret/frontend.js` + CSS-Klassen `.tm-modul-fret`, `.tm-song-*`

Ziel: Layout aus `display.txt` (Standalone-Referenz im Repo-Root) übernehmen
- **Modulname als Überschrift** (rot, uppercase, 32px)
- Schriftgrößen gemäß `Projektzusammenfassung_Song_Anzeige.md` Abschnitt 6
- Playlist-Items als Cards; Badge-Farben: Haupttanz = `#ad2121` gefüllt, Nebentanz = Rand + Schrift `#ad2121`

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
