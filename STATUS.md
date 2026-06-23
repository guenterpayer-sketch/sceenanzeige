# STATUS — Tanzschule Monitor-System

> **Branch:** `claude/nifty-johnson-3q6u7g`  
> Eine neue Session liest `CLAUDE.md` (Konzept) + diese Datei (Stand) und kann sofort weiterarbeiten.

_Letzte Aktualisierung: Schritt 9 (Monitor-Frontend) implementiert — bereit zum Deployment + Live-Test._

---

## Bauplan-Fortschritt

| Schritt | Inhalt | Stand |
|---|---|---|
| 1 | DB-Schema | ✅ live |
| 2 | Ordnerstruktur + .htaccess | ✅ live |
| 3 | Modul-Registry + `uhrzeit`, `bild` | ✅ live |
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy | ✅ live getestet |
| 5 | Backend-Bibliothek + Mediathek | ✅ live getestet |
| 6 | Playlist-Editor | ✅ live getestet |
| 7 | Zeitplanung monitor-zentrisch | ✅ live getestet |
| 8 | Ticker-Verwaltung (monitor-zentrisch) | ✅ live getestet |
| 9 | Monitor-Frontend | ✅ implementiert |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Aktueller Fokus: Schritt 10 — Live-Vorschau (iFrame)

Schritt 9 ist implementiert, bereit für Deployment + Live-Test.

**Schritt 9 — Neue Dateien (auf Server hochladen):**
- `09_migration_monitor_header_text.sql` — zuerst einspielen
- `proxies/monitor.php` — öffentlicher API-Endpunkt
- `assets/css/monitor.css` — Vollbild-Kiosk-CSS
- `assets/js/monitor.js` — Kern-Frontend-Logik
- `saalN.tcpayer.de/index.html` — identisch für alle Säle (aktualisieren)

**Schritt 9 — Geänderte Dateien:**
- `includes/Monitor.php` — `create()`/`update()` um `header_text` erweitert
- `admin/monitore.php` — Formular um Header-Text-Feld erweitert

---

## Wichtige Architektur-Entscheidungen

- **Monitor-zentrische Zeitplanung:** `monitor_zeitplan` (Playlist, mit Priorität) + `ticker_zeitplan` (Ticker, ohne Priorität → Mischung)
- Uhrzeit optional: leer = dauerhaft/Fallback, wird von Einträgen mit Uhrzeit überschrieben
- **FRET vs. NC = getrennte Systeme:** `FRET_SCHOOL_ID`/`FRET_API_BASE` + `NC_API_KEY`/`NC_API_BASE` — alles in `config.php`, niemals in Modul-Instanz-Einstellungen
- Stundenplan über NC Legacy-API `/timetable/data` (POST-Parameter `apikey`)
- Bilder: zentral in `mediathek` (SHA-256-Dup-Erkennung), `mediathek_id` in Inhalten
- Modul `song` → `fret`; `community` zurückgestellt

---

## Code-Stand je Schritt (Kurzfassung)

**Schritt 5 (Bibliothek + Mediathek) ✅**  
Mediathek mit SHA-256-Dup-Erkennung, Ordner, Tags. Bibliothek: Kachel-Übersicht
nach Typ, Instanz-Editor (generisch aus `module.json`), Inhalte-Editor mit
Mediathek-Bild-Picker. FRET-Geräte-Whitelist (`fret_geraete`-Tabelle, Dropdown im
FRET-Editor). Migrationen 03/04/05 live.

**Schritt 6 (Playlist-Editor) ✅**  
Layout-Konfigurator (1–3 Spalten, Breitenregler), schematische Vorschau,
Spalten-Editor mit Instanz-Picker, Drag & Drop zwischen Spalten. Dateien:
`admin/playlists.php`, `admin/playlist-editor.php`, `includes/Playlist.php`,
`includes/LayoutRegistry.php`, Layouts in `layouts/`.

**Schritt 7 (Monitore + Zeitplan) ✅**  
Monitor-Kachel-Übersicht (`admin/monitore.php`), Zeitplan-Editor
(`admin/monitor-zeitplan.php`): Kachel-Picker, Wochentag-Toggles, optionale
Uhrzeit, Priorität. Migrationen 06+07 live. `admin/saele.php` + `includes/Saal.php`
auf Server entfernt.

**Schritt 9 (Monitor-Frontend) ✅**
`proxies/monitor.php` (API-Endpunkt: Subdomain → aktive Playlist + Ticker).
`assets/css/monitor.css` + `assets/js/monitor.js` (Vollbild-Kiosk-Logik).
`saalN.tcpayer.de/index.html` identisch für alle Säle; Subdomain-Selbsterkennung.
Header: Logo links, `header_text` mittig (pro Monitor konfigurierbar), Uhrzeit rechts.
Ticker: Laufschrift wenn zu lang, statisch+überblenden wenn kürzer.
Migration `09_migration_monitor_header_text.sql` (neues Feld `header_text` in `monitore`).
`includes/Monitor.php` + `admin/monitore.php` aktualisiert.

**Schritt 8 (Ticker) ✅**  
Ticker-Kachel-Übersicht (`admin/ticker.php`), Ticker-Editor (`admin/ticker-edit.php`:
Textzeilen, Drag & Drop). Kachel-Picker für Playlist + Ticker in
`admin/monitor-zeitplan.php` (zweiter Abschnitt „Ticker-Zeitplan", ohne Priorität).
Monitor-Übersichtskachel zeigt Playlist- UND Ticker-Anzahl. Migration 08 live.  
⚠️ **Auf Server löschen:** `admin/monitor.php` + `admin/playlist.php`
(umbenannt zu `monitor-zeitplan.php` / `playlist-editor.php`).

---

## Zugriffsschutz

- `admin/` via all-inkl Verzeichnisschutz (Basic-Auth, KAS → Tools → Verzeichnisschutz)
- `proxies/`, `uploads/`, `modules/` offen (Monitore brauchen Zugriff)
- Geplanter Schritt „Benutzerkonten": PHP-Login + `benutzer`-Tabelle, Guard in `admin/includes/bootstrap.php`

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Branch `claude/nifty-johnson-3q6u7g`; nach jedem Abschnitt committen + pushen + `STATUS.md` aktualisieren.
- `Live-Abgleich/` = nur Server-Referenz (nicht bearbeiten).
