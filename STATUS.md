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
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy + Testseite | ✅ live getestet (alle 5 Module inkl. `stundenplan`) |
| 5 | Backend-Bibliothek + Mediathek | 🟡 5a Mediathek ✅ live getestet; 5a.1 Ordner + 5a.2 Tags gebaut (Migration 04 + Live-Test offen); 5b Bibliothek-CRUD folgt |
| 6 | Playlist-Editor (Layout-Konfigurator) | offen |
| 7 | Zeitregeln + Saal-Zuweisung | offen |
| 8 | Ticker-Verwaltung | offen |
| 9 | Monitor-Frontend (Anzeige-/Zeitlogik) | offen |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Aktueller Fokus: Schritt 4 ✅ erledigt → als Nächstes Schritt 5

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

**Live-Test-Stand (22.06.2026): ✅ Schritt 4 abgeschlossen.**
- Alle 5 Module laufen live über `test-module4.php`: `uhrzeit`, `bild`,
  `ankuendigung`, `fret` und `stundenplan` (NC Legacy-API liefert Daten).
- Server-`config.php` ist mit echten Werten bestückt: `NC_API_BASE`,
  `NC_API_KEY` (schulweit), `FRET_API_BASE`, `FRET_SCHOOL_ID`.
- Layout/Schriftgrößen werden bewusst erst in Schritt 6/9 angepasst — die
  rohe Test-Darstellung war hier erwartet.

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

## Schritt 5 — Stand

**5a Mediathek — Code fertig, Live-Test offen.** Neue Dateien:
- `includes/Mediathek.php` — Upload mit SHA-256-Duplikaterkennung, `listAll`/
  `find`/`anzahlVerwendungen`/`delete` (Löschschutz, solange verwendet).
- `admin/` (neuer, separat schützbarer Backend-Ordner):
  - `includes/bootstrap.php` — zentraler Einstieg aller Admin-Seiten (lädt
    config + Klassen; **eine Stelle** für späteren Login-Guard).
  - `includes/layout.php` — gemeinsamer HTML-Rahmen + Nav.
  - `mediathek.php` — Galerie + Drag&Drop-Upload (fetch, kein Reload).
  - `api/mediathek-upload.php`, `api/mediathek-delete.php` — JSON-Endpoints.
- `assets/css/admin.css` — Backend-Styling.

**Live-Test 5a (To-do Nutzer):** `admin/` + `includes/Mediathek.php` +
`assets/css/admin.css` hochladen, dann `admin/mediathek.php` aufrufen: Bilder
per Drag&Drop hochladen (Maße/Galerie erscheinen), gleiches Bild erneut →
wird als Duplikat erkannt (kein Doppel), Löschen funktioniert.

**5a.1 Ordner + 5a.2 Tags — Code fertig, Migration + Live-Test offen.**
- Migration **`04_migration_mediathek_ordner_tags.sql`** (einmalig live einspielen):
  Tabelle `mediathek_ordner` + `mediathek.ordner_id` (FK ON DELETE SET NULL),
  Tabellen `mediathek_tags` + `mediathek_tag` (n:m).
- Neue Klassen: `includes/MediathekOrdner.php`, `includes/MediathekTag.php`;
  `Mediathek.php` erweitert (gefilterte `listAll(ordner/suche/tag)`, Upload mit
  `ordner_id`, `verschiebe`).
- Neue API: `admin/api/ordner.php` (Ordner-CRUD), `admin/api/mediathek-update.php`
  (Ordner + Tags pro Bild); `mediathek-upload.php` nimmt `ordner_id`.
- `admin/mediathek.php` überarbeitet: Ordner-Leiste (anlegen/umbenennen/löschen,
  Filter), Namens-Suche, Tag-Filter-Chips, Bearbeiten-Dialog (Ordner + Tags).
- Ordner = eine Ebene, ein Bild in genau einem Ordner; Tags = mehrere pro Bild,
  n:m, verwaiste Tags werden automatisch aufgeräumt.
- **Live-Test 5a.1/5a.2:** Migration 04 einspielen, neue `admin/`- + `includes/`-
  + `assets/`-Dateien hochladen; dann Ordner anlegen, Bilder verschieben,
  Tags setzen, nach Ordner/Tag/Name filtern.

**5b folgt:** Bibliotheks-Übersicht + Instanz-CRUD (Einstellungen aus
`module.json`) + Inhalte-Editor für `bild`/`ankuendigung` (Mediathek-Auswahl
oder Neu-Upload, `reihenfolge`/`dauer_sek`/`gueltig_bis`/`aktiv` pro Eintrag).
Dabei `ModulInstanz::listInhalte` per `LEFT JOIN mediathek` einen aufgelösten
`dateiname` liefern (hält `bild/frontend.js` abwärtskompatibel).

## Zugriffsschutz / Benutzerkonten

- Root-`.htaccess` (Referenz im Repo: `02_ordnerstruktur/screen.tcpayer.de/.htaccess`)
  schützt `config.php`/`includes/` + CORS, hat **keinen Login**.
- **Interim:** all-inkl Verzeichnisschutz (Basic-Auth) NUR auf `admin/`
  (KAS → Tools → Verzeichnisschutz). `proxies/`, `uploads/`, `modules/`
  bleiben offen für die Monitore — kein Basic-Auth auf der ganzen Subdomain!
- **Geplanter eigener Schritt „Benutzerkonten":** PHP-Login + Tabelle
  `benutzer` (inkl. `rolle`) + Verwaltung; Guard zentral in
  `admin/includes/bootstrap.php` einhängen. Vorbereitet, niedriger Aufwand.

---

## Arbeitsregeln

- **Kein Schreiben/Code ohne explizites „GO".** Lesen/Prüfen jederzeit ok.
- Entwicklung auf Branch `claude/nifty-johnson-3q6u7g`; nach jedem sinnvollen
  Abschnitt **committen + pushen** und **diese `STATUS.md` aktualisieren**.
- `Live-Abgleich/` = nur Referenz des Server-Stands (nicht bearbeiten).
