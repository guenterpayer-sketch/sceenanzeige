# STATUS — Tanzschule Monitor-System

> **Zentrale, lebende Stand-Datei.** Wird am Ende jedes Schritts aktualisiert.
> Eine neue Session liest `CLAUDE.md` (Konzept, maßgeblich) + diese Datei
> (aktueller Stand) und kann sofort weiterarbeiten.
>
> **Branch:** `claude/nifty-johnson-3q6u7g` (gesamter Stand liegt hier,
> **nicht** auf `main`).

_Letzte Aktualisierung: Ende Schritt-7-Arbeit (Säle-Verwaltung + Zeitregeln +
Saal-Zuweisung gebaut, lokal per `php -l` geprüft, Live-Test steht noch aus)._

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
| 7 | Zeitregeln + Saal-Zuweisung (inkl. Säle-Verwaltung) | 🧪 Code fertig, Live-Test offen |
| 8 | Ticker-Verwaltung | ▶️ als Nächstes |
| 9 | Monitor-Frontend (Anzeige-/Zeitlogik) | offen · Vormerk-Notiz: `Notiz_Schritt9_Monitor-Frontend.md` |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## Aktueller Fokus: Schritt 7 🧪 Code fertig (Live-Test offen) → Schritt 8 als Nächstes

Schritt 7 (Säle-Verwaltung + Zeitregeln + Saal-Zuweisung) ist gebaut,
committet/gepusht; Live-Test durch den Nutzer steht aus. **Keine Migration**
(`saele`, `playlist_zeitregeln`, `playlist_saele` waren bereits live, `saele`
hatte 0 Einträge). Deployment-ZIP: **`Schritt7_zeitregeln-saele.zip`**
(Struktur unter `screen.tcpayer.de/`, in den Subdomain-Ordner entpacken).

Schritt 6 (Playlist-Editor) bleibt **live getestet und bestätigt** (inkl.
Drag & Drop). Deployment-ZIP dazu: `Schritt6_playlist-editor.zip`.

> **Vorgemerkt (abgestimmt, noch NICHT gebaut): Umbau auf monitor-zentrisches
> Modell.** Statt Zeitregeln/Säle im Playlist-Editor soll die Zeitplanung pro
> **Monitor** erfolgen (Monitor wählen → „Playlist X läuft wann"). Beschlossen:
> Rename `saele`→`monitore`/`saal_id`→`monitor_id` (auch DB), neue Tabelle
> `monitor_zeitplan` ersetzt `playlist_saele` + `playlist_zeitregeln`
> (Migration `06_…`), Zeitplan-Editor in die Monitor-Verwaltung, Playlist =
> nur Inhalt/Layout. Details + Schritt-9-Auswirkung (Monitor-Selbsterkennung
> per Subdomain) in **`Notiz_Schritt9_Monitor-Frontend.md`**. Umsetzung erst
> nach ausdrücklichem „GO".

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

## Schritt 7 — Stand (Zeitregeln + Saal-Zuweisung, Code fertig, Live-Test offen)

**Keine Migration** — `saele`, `playlist_zeitregeln`, `playlist_saele` waren
bereits live (`saele` hatte 0 Einträge, daher Säle-Verwaltung mitgebaut).
Deployment-ZIP: `Schritt7_zeitregeln-saele.zip`.

Neue/aktualisierte Dateien (alle committet + gepusht):
- `includes/Saal.php` — CRUD, `normSubdomain` (klein, ohne Domain),
  `subdomainExistiert`, `listAll` mit Anzahl zugewiesener Playlists.
- `admin/saele.php` — Säle anlegen/bearbeiten (`?edit=<id>` füllt das Formular
  vor)/löschen (Rückfrage warnt bei zugewiesenen Playlists). Nav „Säle" aktiv.
- `includes/Playlist.php` — `ladeZeitregeln`/`ersetzeZeitregeln`,
  `ladeSaele`/`ersetzeSaele` (Bulk in Transaktion); `listAll` zählt zusätzlich
  Zeitregeln + zugewiesene Säle (Badges).
- `admin/playlist.php` — zwei neue Karten: **Zeitregeln** (dynamische Zeilen,
  7 Wochentag-Toggle-Buttons + Presets Alle/Mo–Fr/Wochenende, von/bis,
  Priorität; Validierung `von < bis` und ≥1 Tag, Eingaben bleiben nach Fehler
  erhalten) und **Säle** (Checkbox-Auswahl, nur existierende Säle).
- `admin/playlists.php` — Kachel-Badges: Anzahl Zeitregeln (🕒) + Säle (🏠).
- `admin/includes/{bootstrap,layout}.php`, `assets/css/admin.css` ergänzt.

**Design-Entscheidungen Schritt 7 (vom Nutzer bestätigt):**
- Säle-Verwaltung mitgebaut (sonst Saal-Zuweisung leer).
- Wochentage: Toggle-Buttons + Presets.
- Zeitregeln über Mitternacht: **nicht** in Schritt 7 — `von < bis` erzwungen
  (über Mitternacht = zwei Regeln; echte Overnight-Logik später nachrüstbar).
- Übersicht-Badges: ja.

**Datenmodell-Notiz:** `wochentage` als `"1,2,3,4,5"` (1=Montag). Auswertung
zur Laufzeit (welche Playlist *jetzt* je Saal, Prioritäts-Konflikt) bleibt
**Schritt 9** (Monitor); Schritt 7 erfasst nur die Daten.

**Live-Test 7 (To-do Nutzer):** ZIP in `screen.tcpayer.de/` entpacken. „Säle":
einen/mehrere Säle anlegen (Subdomain wird normalisiert), bearbeiten, löschen.
„Playlists" → Playlist bearbeiten: Zeitregel(n) anlegen (Tage/Presets, von/bis,
Priorität), Säle zuweisen, speichern; erneut öffnen (Vorbelegung prüfen);
Validierung testen (von ≥ bis bzw. kein Tag → Fehlermeldung, Eingaben bleiben);
Badges auf der Übersicht prüfen.

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
