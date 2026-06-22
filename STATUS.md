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
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy + Testseite | 🟡 Live-Test läuft: `uhrzeit`/`bild`/`ankuendigung`/`fret` ✅; `stundenplan` offen (NC-Werte) |
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
  - **Saal-Altlast beim Stundenplan entfernt** (22.06.): `stundenplan/frontend.js`
    schickt kein `saal_id` mehr, `test-module4.php` ohne Saal-Auswahl/`SAAL_ID`.
    Begründung: NC hat genau EINEN schulweiten Key, keinen pro Saal.

**Live-Test-Stand (22.06.2026):**
- ✅ Live laufen bereits: `uhrzeit`, `bild`, `ankuendigung`, `fret`.
- 🟡 Offen: `stundenplan` — sobald in der Server-`config.php` echte Werte stehen:
  - `NC_API_BASE` (echte Nimbuscloud-Subdomain, …/api/json/v1)
  - `NC_API_KEY` (Berechtigung „Stundenplan — Lesezugriff")
  - (FRET läuft, also `FRET_API_BASE` + `FRET_SCHOOL_ID` bereits gesetzt.)
- Nach dem Saal-Fix die zwei Dateien neu hochladen:
  `modules/stundenplan/frontend.js`, `test-module4.php`.
- Layout/Schriftgrößen werden bewusst erst in Schritt 6/9 angepasst — die
  rohe Test-Darstellung ist hier erwartet.

Altlast auf dem Server (stört nicht): `modules/song/`, `modules/community/`,
`proxies/song.php`. `index.htm` bleibt bewusst stehen (all-inkl-Default-Seite
für direkte Aufrufe der Domain).

Live-Snapshot des Server-Stands zum Abgleich: Ordner
`Live abgleich 220620261145/` (DB-Dump + screen.tcpayer.de, Referenz, nicht
bearbeiten).

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
