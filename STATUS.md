# STATUS — Tanzschule Monitor-System

> **Zentrale, lebende Stand-Datei.** Wird am Ende jedes Schritts aktualisiert.
> Eine neue Session liest `CLAUDE.md` (Konzept, maßgeblich) + diese Datei
> (aktueller Stand) und kann sofort weiterarbeiten.
>
> **Branch:** `claude/nifty-johnson-3q6u7g` (gesamter Stand liegt hier,
> **nicht** auf `main`).

_Letzte Aktualisierung: Schritt 7 abgeschlossen — monitor-zentrische
Zeitplanung live getestet (Monitor-Verwaltung als Kacheln, Zeitplan je Monitor,
Uhrzeit optional/Fallback, Priorität). Migrationen 06 + 07 live eingespielt._

---

## Bauplan-Fortschritt

| Schritt | Inhalt | Stand |
|---|---|---|
| 1 | DB-Schema | ✅ live |
| 2 | Ordnerstruktur + .htaccess | ✅ live |
| 3 | Modul-Registry + `uhrzeit`, `bild` | ✅ live |
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy + Testseite | ✅ live getestet (alle 5 Module inkl. `stundenplan`) |
| 5 | Backend-Bibliothek + Mediathek | ✅ live getestet (Mediathek + Ordner/Tags, Bibliothek/Instanz-Editor, FRET-Geräte-Whitelist) |
| 6 | Playlist-Editor (Layout-Konfigurator) | ✅ live getestet (inkl. Drag & Drop der Spalten-Inhalte) |
| 7 | Zeitplanung (monitor-zentrisch: Monitore + Zeitplan je Monitor) | ✅ live getestet (Kachel-Übersicht, Zeitplan, Uhrzeit optional/Fallback, Priorität) |
| 8 | Ticker-Verwaltung | ▶️ als Nächstes |
| 9 | Monitor-Frontend (Anzeige-/Zeitlogik) | offen · Vormerk-Notiz: `Notiz_Schritt9_Monitor-Frontend.md` |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Aktueller Fokus: Schritt 7 ✅ abgeschlossen → Schritt 8 (Ticker) als Nächstes

Schritt 7 (monitor-zentrische Zeitplanung) ist **live getestet und bestätigt**.
Die Zeitplanung läuft **pro Monitor**: Bereich „Monitore" (Kachel-Übersicht) →
Monitor wählen → „Zeitplan" (Playlist X läuft wann, mit Priorität; Einträge
ohne Uhrzeit laufen dauerhaft als Fallback und werden von Einträgen mit Uhrzeit
überschrieben). Migrationen **06 + 07 live eingespielt**; alte `admin/saele.php`
+ `includes/Saal.php` auf dem Server entfernt.

Konzept-Doku: CLAUDE.md Abschnitt **16c** (überschreibt die playlist-zentrischen
Stellen) + aktualisierter Bauplan (Abschnitt 13). Schritt-9-Auswirkung
(Monitor-Selbsterkennung per Subdomain) in `Notiz_Schritt9_Monitor-Frontend.md`.

Schritt 8 (Ticker) soll denselben monitor-zentrischen Ansatz bekommen
(`ticker_playlist_saele.monitor_id` ist bereits umgestellt; endgültige Ticker-
Zeit-/Monitor-Logik in Schritt 8).

Schritt 5 vollständig live getestet: Mediathek (Upload/Dup-Erkennung, Ordner,
Tags, Anzeigename), Bibliothek + Instanz-Editor (alle Modultypen, Inhalte-
Editor mit Bild-Picker), FRET-Geräte-Whitelist (Dropdown im FRET-Editor).
Migrationen 03/04/05 live eingespielt. `test-module3.php`/`test-module4.php`
liegen noch auf dem Server (fachlich abgelöst, dürfen weg).

UI-Nachbesserungen (Kacheln-Phase): Bibliothek 2-stufig als Kacheln (Modulart
→ Instanzen) mit statischer Mini-Vorschau; Mediathek 2-stufig als
Ordner-Kacheln (Cover + Anzahl) → Bild-Ansicht je Ordner; Lösch-Rückfrage in
der Bibliothek gefixt (HTML-Attribut-Quote-Bug) + Rückfrage beim Entfernen
einer Editor-Eintragszeile; Doppelnamen-Prüfung pro Modultyp
(`ModulInstanz::nameExistiert`). Akzentfarbe #ad2121.

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

**5b Bibliothek/Instanz-CRUD — Code fertig, Live-Test offen.** (Keine Migration nötig.)
- `includes/ModulInstanz.php`: `setAktiv`, `ersetzeInhalte` (bulk), `listInhalte`
  jetzt per `LEFT JOIN mediathek` mit aufgelöstem `dateiname` (COALESCE) →
  `bild/frontend.js` bleibt abwärtskompatibel.
- `admin/bibliothek.php`: Übersicht aller Instanzen nach Typ, Aktiv-Schalter,
  Bearbeiten/Löschen, „Neue Instanz".
- `admin/instanz.php`: Editor — Name + Aktiv + Einstellungen (generisch aus
  `module.json`) + Inhalte-Editor für `bild`/`ankuendigung` (Mediathek-Bild-
  Picker mit Ordner/Tag/Suche-Filter, Dauer, Gültig-bis, Aktiv, ↑/↓-Reihenfolge).
- `admin/api/mediathek-list.php`: JSON-Quelle für den Picker.
- Nav: Bibliothek aktiviert.
- `test-module4.php` ist damit fachlich abgelöst (kann später entfernt werden).

**Live-Test 5b:** neue/aktualisierte `admin/`- + `includes/ModulInstanz.php` +
`assets/css/admin.css` hochladen; in der Bibliothek eine Bild- und eine
Ankündigungs-Instanz anlegen (Bilder aus Mediathek wählen), Reihenfolge/Aktiv/
Gültig-bis testen, pausieren/löschen.

**5b-Erweiterung FRET-Geräte-Whitelist — gebaut, Migration + Live-Test offen.**
- Migration **`05_migration_fret_geraete.sql`**: Tabelle `fret_geraete`
  (uuid unique, fret_name, anzeige_name, freigegeben, gesehen_am).
- `includes/FretApi.php` (`listComputers()` serverseitig, schoolId bleibt in
  config), `includes/FretGeraet.php` (Sync via INSERT…ON DUP KEY, CRUD).
- `admin/fret-geraete.php` (Nav „FRET-Geräte"): „Von FRET aktualisieren" holt
  Computerliste, Admin vergibt Anzeigenamen + Freigabe.
- `modules/fret/module.json`: `computer_id` → `select`; `ModuleRegistry`
  unterstützt jetzt **dynamische Select-Optionen** (`renderSettingsForm` 3.
  Parameter). `admin/instanz.php` speist im FRET-Editor nur **freigegebene**
  Geräte als Dropdown ein (nicht-freigegebener Altwert bleibt sichtbar markiert).
- FRET-Proxy (`proxies/fret.php`) bewusst unverändert.
- **Live-Test:** Migration 05 einspielen, Dateien hochladen, „FRET-Geräte" →
  aktualisieren → Saal benennen + freigeben → im FRET-Modul-Editor erscheint
  das Dropdown.

## Schritt 6 — Stand (Playlist-Editor, ✅ abgeschlossen / live getestet)

**Keine Migration** — `playlists`, `playlist_layout`, `playlist_spalten_inhalte`
waren bereits live. Deployment-ZIP: `Schritt6_playlist-editor.zip`.

Neue/aktualisierte Dateien (alle committet + gepusht):
- **Layouts** (öffentlich, für Monitor-Rendering Schritt 9): je `layout.json` +
  `template.html` für `1-spaltig`, `2-spaltig-60-40`, `2-spaltig-50-50`,
  `3-spaltig-gleich`. Template = CSS-Grid-Gerüst mit `{{spalteN_breite}}`-
  Platzhaltern; `.tm-spalte[data-spalte]` füllt der Renderer später.
- `includes/LayoutRegistry.php` — analog `ModuleRegistry` (`getAll/load/exists`,
  `templateHtml`, `gleichBreiten`).
- `includes/Playlist.php` — CRUD (`create` legt Default-Layout 1-spaltig mit an,
  `update/find/listAll/delete/setAktiv`, `nameExistiert`), Layout-UPSERT
  (`speichereLayout` via `ON DUPLICATE KEY UPDATE`/`VALUES()`, MariaDB-konform),
  `ladeLayout`, `layoutIdAus` (leitet Layout-ID aus spalten_anzahl+Breiten ab,
  da das Schema keine Layout-ID-Spalte hat), `listSpaltenInhalte`,
  `ersetzeSpaltenInhalte` (Bulk in Transaktion, Reihenfolge je Spalte).
- `admin/playlists.php` — Kachel-Übersicht (Layout-Kurzinfo + Modul-Anzahl),
  Aktiv-Toggle, Bearbeiten, Löschen (Rückfrage), „+ Neue Playlist".
- `admin/playlist.php` — Editor: Name, Aktiv, Layout-Auswahl (Radio-Kacheln mit
  Mini-Schema), **Breitenregler nur 2-spaltig (gekoppelt), 3-spaltig immer
  gleich, 1-spaltig 100 %**, Header/Footer-Schalter, schematische 16:9-Vorschau,
  Spalten-Editor mit Instanz-Picker (Filter nach Modulart) + ↑/↓/Entfernen.
  **Drag & Drop:** Einträge per Griff (⠿) innerhalb einer Spalte umsortieren
  und zwischen Spalten verschieben (native HTML5-DnD, vanilla JS, keine
  Library); ↑/↓ bleiben als Fallback. Dublette in derselben Spalte wird beim
  Drop verhindert (zurück an Ursprung).
- `admin/api/instanz-list.php` — JSON-Quelle für den Picker.
- `admin/includes/layout.php` — Nav „Playlists" aktiviert.
- `admin/includes/bootstrap.php` — lädt `LayoutRegistry` + `Playlist`.
- `assets/css/admin.css` — Editor-Styles ergänzt (Akzent #ad2121).

**Design-Entscheidungen Schritt 6 (vom Nutzer bestätigt):**
- Spaltenbreiten: 2-spaltig gekoppelter Schieberegler; 3-spaltig immer
  gleichmäßig (34/33/33); 1-spaltig fix 100 %.
- `layout_override` pro Instanz auf **Schritt 9 vertagt** (DB-Spalte bleibt,
  keine UI).
- Schematische Vorschau: ja (nur Proportionen).
- Schema speichert **keine** Layout-ID-Spalte → Layout-Zuordnung wird aus
  `spalten_anzahl` + Breiten abgeleitet (`Playlist::layoutIdAus`).

**Bewusste UX-Details:** Beim Verringern der Spaltenzahl werden Einträge aus
wegfallenden Spalten in die letzte sichtbare Spalte verschoben (nichts geht
unbemerkt verloren); doppelte Instanz in derselben Spalte wird verhindert.

**Live-Test 6 (To-do Nutzer):** ZIP in `screen.tcpayer.de/` entpacken, im
Backend „Playlists" → „Neue Playlist": Name, Layout wählen (Regler bei
2-spaltig testen), Instanzen je Spalte zuweisen (Picker), Reihenfolge ↑/↓,
speichern; danach erneut öffnen (Vorbelegung prüft Layout + Spalten),
Pausieren/Löschen testen.

## Schritt 7 — Stand (monitor-zentrisch, ✅ abgeschlossen / live getestet)

Zeitplanung ist von der Playlist auf den **Monitor** verlagert. Konzept-Doku:
CLAUDE.md Abschnitt **16c** (überschreibt die playlist-zentrischen Stellen).

**Migrationen live eingespielt:**
- `06_migration_monitor_zeitplan.sql` — `saele`→`monitore`,
  `saal_id`→`monitor_id` (in `einstellungen` + `ticker_playlist_saele`, FKs
  neu), `playlist_saele` + `playlist_zeitregeln` **entfernt**, neue Tabelle
  **`monitor_zeitplan`**.
- `07_migration_zeitplan_zeit_optional.sql` — `von_uhrzeit`/`bis_uhrzeit`
  NULL-fähig (Uhrzeit optional).

**Auf dem Server bereits entfernt** (ersetzt): `admin/saele.php`,
`includes/Saal.php`.

Neue/aktualisierte Dateien (alle committet + gepusht):
- `includes/Monitor.php` (ersetzt `Saal.php`) — CRUD auf `monitore` +
  `ladeZeitplan`/`ersetzeZeitplan` (Tabelle `monitor_zeitplan`, Bulk in Transaktion).
- `admin/monitore.php` (ersetzt `saele.php`) — **Kachel-Übersicht** (Button
  „+ Neuer Monitor" oben, Monitore als Kacheln; Klick auf Kachel → Zeitplan),
  Anlegen/Bearbeiten via Formular (`?neu`/`?edit`), Löschrückfrage (warnt bei
  Zeitplan-Einträgen).
- `admin/monitor.php` — **Zeitplan-Editor je Monitor:** dynamische Zeilen mit
  Playlist-Dropdown + Wochentag-Toggles/Presets + **optionaler** Uhrzeit +
  Priorität. Uhrzeit leer = läuft **dauerhaft** (Fallback); Einträge mit
  Uhrzeit überschreiben ihn. Validierung: gültige Playlist + ≥1 Tag, Uhrzeit
  entweder beide leer oder beide mit `von < bis`. **Migration 07** macht
  `von_uhrzeit`/`bis_uhrzeit` NULL-fähig.
- `includes/Playlist.php` — Zeitregel-/Säle-Methoden entfernt; `listAll`-Badge
  jetzt `anzahl_monitore` (COUNT DISTINCT `monitor_zeitplan.monitor_id`).
- `admin/playlist.php` — Zeitregeln-/Säle-Karten entfernt → **Playlist = nur
  Inhalt/Layout** (Drag&Drop bleibt); Hinweis auf Monitore→Zeitplan.
- `admin/playlists.php` — Badge „auf N Monitoren eingeplant" (🖥️).
- `admin/includes/{bootstrap,layout}.php` — `Monitor` statt `Saal`, Nav
  „Monitore" statt „Säle". `assets/css/admin.css` — Playlist-Dropdown im Zeitplan.
- `01_schema.sql` auf das neue Modell aktualisiert.

**Design-Entscheidungen (vom Nutzer bestätigt):**
- Monitor-zentrisch: Zeitplan je Monitor statt Zeitregeln/Säle an der Playlist.
- Begriff/DB voll umbenannt (`saele`→`monitore`, `saal_id`→`monitor_id`).
- Alte Tabellen `playlist_saele` + `playlist_zeitregeln` entfernt + ersetzt.
- Wochentage Toggle-Buttons + Presets; Uhrzeit **optional** (leer = dauerhaft/
  Fallback, sonst `von < bis`; über Mitternacht später); Subdomains
  (`saal1` …) bleiben als Frontend-Ordner unverändert.

**Live-Test 7 ✅ erledigt (vom Nutzer bestätigt):** Monitore als Kacheln
anlegen/auswählen, Zeitplan je Monitor (Playlist + Tage/Presets + optionale
Uhrzeit + Priorität), Validierung und Fallback-Verhalten (Eintrag ohne Uhrzeit)
geprüft; Übersicht zeigt Einträge nach Priorität sortiert.

**Deployment-ZIPs (Historie):** `Schritt7_monitor-zentrisch.zip` (Umbau +
Kachel-Übersicht), `Schritt7c_zeitplan-zeit-optional.zip` (optionale Uhrzeit).
Migrationen separat: `06_…`, `07_…`.

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
