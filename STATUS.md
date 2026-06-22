# STATUS — Tanzschule Monitor-System

> **Zentrale, lebende Stand-Datei.** Wird am Ende jedes Schritts aktualisiert.
> Eine neue Session liest `CLAUDE.md` (Konzept, maßgeblich) + diese Datei
> (aktueller Stand) und kann sofort weiterarbeiten.
>
> **Branch:** `claude/nifty-johnson-3q6u7g` (gesamter Stand liegt hier,
> **nicht** auf `main`).

_Letzte Aktualisierung: Ende Schritt-4-Arbeit (Module + Proxys gebaut, Fixes
A/B erledigt, Live-Test steht noch aus)._

---

## Bauplan-Fortschritt

| Schritt | Inhalt | Stand |
|---|---|---|
| 1 | DB-Schema | ✅ live |
| 2 | Ordnerstruktur + .htaccess | ✅ live |
| 3 | Modul-Registry + `uhrzeit`, `bild` | ✅ live |
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy + Testseite | 🟡 Code fertig, **Live-Test offen** |
| 5 | Backend-Bibliothek + Mediathek | offen |
| 6 | Playlist-Editor (Layout-Konfigurator) | offen |
| 7 | Zeitregeln + Saal-Zuweisung | offen |
| 8 | Ticker-Verwaltung | offen |
| 9 | Monitor-Frontend (Anzeige-/Zeitlogik) | offen |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Aktueller Fokus: Schritt 4 — Live-Test

**Code-Stand (alles committet + gepusht):**
- Module in `02_ordnerstruktur/screen.tcpayer.de/modules/`: `uhrzeit`, `bild`,
  `stundenplan`, `ankuendigung`, `fret` (Registry enthält genau diese 5;
  `community` ist zurückgestellt).
- Proxys: `proxies/nc.php` (NC Legacy-API `/timetable/data`, Key-Form-Param
  `apikey`), `proxies/fret.php` (FRET, schoolId serverseitig), `_cors.php`.
- Testseite: `test-module4.php`.
- Schema-Migration `03_migration_abschnitt16.sql` — **bereits live eingespielt**
  (DB `d04768bb` ist auf Abschnitt-16-Stand: `gueltig_bis`, `mediathek`,
  `aktiv`, `nc_api_key_stundenplan`).
- Zuletzt erledigte Fixes:
  - `ModulInstanz::addInhalt` schreibt `gueltig_bis` (statt umbenanntem
    `ablaufdatum`).
  - NC-Key schulweit: `config.php` → `NC_API_KEY`; `nc.php` liest ihn von dort.

**Damit der Live-Test läuft (To-do des Nutzers):**
1. Drei geänderte Dateien neu auf `screen.tcpayer.de/` hochladen:
   `includes/ModulInstanz.php`, `config.php`, `proxies/nc.php`.
2. In `config.php` (Server) die echten Werte setzen:
   - `NC_API_BASE` (echte Nimbuscloud-Subdomain, …/api/json/v1)
   - `NC_API_KEY` (Berechtigung „Stundenplan — Lesezugriff")
   - `FRET_API_BASE` = `https://fret-api.azurewebsites.net/api/v1`
   - `FRET_SCHOOL_ID` (steht im alten Standalone-Proxy als `$schoolId`)
   - DB-Passwort ist auf dem Server bereits gesetzt.
3. Kein weiterer DB-Eingriff nötig.
4. `https://screen.tcpayer.de/test-module4.php` aufrufen:
   - `uhrzeit`/`bild`/`ankuendigung` laufen ohne API.
   - `fret`: Computer-UUID via `/proxies/fret.php?action=list` ermitteln,
     in die fret-Instanz eintragen.
   - `stundenplan`: zeigt Kurse, sobald `NC_API_BASE` + `NC_API_KEY` stehen.

Altlast auf dem Server (stört nicht, darf gelöscht werden):
`modules/song/`, `modules/community/`, `proxies/song.php`.

---

## Wichtige Architektur-Entscheidungen (Kurz; Details in CLAUDE.md)

- **FRET vs. NC = zwei unabhängige Systeme.** FRET = Musiksoftware
  (Songanzeige je Saal, braucht `schoolId` + Computer-UUID). NC = Nimbuscloud
  (Stundenplan, braucht **keine** schoolId).
- **Ein Key/Identifier pro Software, schulweit, serverseitig in `config.php`:**
  `NC_API_KEY` (+ `NC_API_BASE`), `FRET_SCHOOL_ID` (+ `FRET_API_BASE`).
  Niemals in Modul-Instanz-Einstellungen (die gehen ans Frontend).
- `ankuendigung` bleibt; `community` zurückgestellt.
- `stundenplan` über Legacy-API `/timetable/data` (nicht v2/Direct-DB).
- Modul-Umbenennung: `song` → `fret`.
- Zielauflösung Monitore: Full-HD (1920×1080). Layout-Mechanik = Doku,
  konkrete Spaltenbreiten je Playlist = Laufzeit-Konfiguration.

---

## Nächste Schritte

1. **Schritt 4 Live-Test** durchführen (siehe oben); Fehlermeldungen aus
   `nc.php`/`fret.php` zurückmelden → gezielt fixen.
2. Danach **Schritt 5**: Backend-Bibliothek (Modul-Instanzen verwalten inkl.
   `aktiv`/`gueltig_bis`) + **Mediathek** (zentrale Bildtabelle, SHA-256-
   Duplikaterkennung, Drag&Drop). Schema ist durch die Migration vorhanden.

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Entwicklung auf Branch `claude/nifty-johnson-3q6u7g`; nach jedem sinnvollen
  Abschnitt **committen + pushen** und **diese `STATUS.md` aktualisieren**.
- `Live-Abgleich/` = nur Referenz des Server-Stands (nicht bearbeiten).
