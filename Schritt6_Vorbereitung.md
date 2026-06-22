# Schritt 6 — Vorbereitung: Playlist-Editor (Layout-Konfigurator)

> Diese Datei zusammen mit `CLAUDE.md` (Konzept) und `STATUS.md` (Stand) zu
> Beginn des Schritt-6-Chats lesen. Branch: `claude/nifty-johnson-3q6u7g`.

## Ziel von Schritt 6
Backend-Editor, um **Playlists** für die Monitor-Hauptfläche anzulegen
(CLAUDE.md Abschnitt 6). Eine Playlist =
- ein **Layout** (1–3 Spalten, Spaltenbreiten in %),
- pro **Spalte** mehrere **Modul-Instanzen** aus der Bibliothek (rotieren
  später unabhängig),
- **Header (Uhrzeit)** / **Footer (Ticker)** ein/aus,
- `aktiv`-Schalter, Name.

## Abgrenzung — was NICHT in Schritt 6 gehört
- **Zeitregeln** (Wochentag/Uhrzeit/Priorität) → **Schritt 7**
  (Tabelle `playlist_zeitregeln` existiert, bleibt hier unberührt).
- **Saal-Zuweisung** → **Schritt 7** (Tabelle `playlist_saele`).
- **Monitor-Rendering** (tatsächliche Anzeige am Bildschirm, Full-HD-Optik,
  Rotation/Zeitlogik) → **Schritt 9**.
- **Live-Vorschau (iFrame)** → **Schritt 10**. (Ein schematische Spalten-
  Vorschau im Editor ist ok, aber kein echtes Modul-Rendering.)

## DB-Tabellen (alle bereits LIVE vorhanden — keine Migration nötig)
```
playlists(id, name, aktiv, erstellt_am)
playlist_layout(id, playlist_id, spalten_anzahl,
                spalte1_breite, spalte2_breite, spalte3_breite,
                header_uhrzeit, footer_ticker)
playlist_spalten_inhalte(id, playlist_id, spalte, reihenfolge,
                         modul_instanz_id, layout_override)
-- erst Schritt 7:
playlist_zeitregeln(id, playlist_id, wochentage, von_uhrzeit, bis_uhrzeit, prioritaet)
playlist_saele(playlist_id, saal_id)
```
Hinweis MariaDB / `EMULATE_PREPARES=false`: **kein benannter Platzhalter
mehrfach** im selben Statement (sonst HTTP 500 — siehe Mediathek-Suche-Fix).

## Vorhandene Bausteine zum Wiederverwenden
- **Admin-Gerüst** unter `admin/`: `includes/bootstrap.php` (zentraler
  Einstieg/Require), `includes/layout.php` (`admin_header/footer` + Nav).
  → Nav-Eintrag „Playlists" aktivieren (aktuell als „kommt später" disabled).
- **`includes/ModulInstanz.php`**: `listAll()` / `listAll($typ)` liefert die
  Modul-Instanzen für die Spalten-Zuweisung; `find()`.
- **`includes/ModuleRegistry.php`**: Labels/Icons je Modultyp (für Anzeige der
  Instanzen im Editor).
- **`layouts/registry.php`**: listet die 4 Layout-IDs. Die zugehörigen
  `layouts/<id>/layout.json` (Spaltenanzahl, Default-Breiten, Label) und
  `layouts/<id>/template.html` (CSS-Grid) **müssen in Schritt 6 gebaut**
  werden (existieren noch nicht). Eine kleine `LayoutRegistry`-Klasse analog
  `ModuleRegistry` ist sinnvoll.
- **`assets/css/admin.css`**: Muster für Kacheln, Karten (`.adm-card`),
  Felder (`.field`), Buttons (`.adm-btn`/`-primary`/`-grau`/`-rot`), Dialog
  (`.adm-overlay`/`.adm-dialog`). Akzentfarbe **#ad2121**.
- **Inhalte-Editor-Muster** aus `admin/instanz.php`: dynamische Zeilen,
  ↑/↓-Reihenfolge, Reindex der Feldnamen vor dem Submit, Picker-Dialog —
  dasselbe Muster passt 1:1 für „Modul-Instanzen je Spalte zuweisen".

## Empfohlener Aufbau Schritt 6
1. **Layout-Definitionen**: `layouts/<id>/layout.json` + `template.html` für
   `1-spaltig`, `2-spaltig-60-40`, `2-spaltig-50-50`, `3-spaltig-gleich`;
   kleine `includes/LayoutRegistry.php`.
2. **`includes/Playlist.php`**: CRUD (`create/update/find/listAll/delete/
   setAktiv`), Layout laden/speichern (`playlist_layout`), Spalten-Inhalte
   bulk ersetzen (`ersetzeSpaltenInhalte`, analog `ModulInstanz::ersetzeInhalte`).
   Doppelnamen-Prüfung wie bei Instanzen.
3. **`admin/playlists.php`**: Übersicht als Kacheln (analog Bibliothek),
   Aktiv-Toggle, Bearbeiten, Löschen (mit Rückfrage), „Neue Playlist".
4. **`admin/playlist.php`**: Editor — Name, Aktiv, Layout-Auswahl
   (→ Spaltenanzahl), Spaltenbreiten (%), Header/Footer-Schalter; pro Spalte
   Modul-Instanzen hinzufügen (Picker aus der Bibliothek) + ↑/↓-Reihenfolge;
   optional `layout_override` je Instanz (kann auch auf später vertagt werden).
   Schematische Spalten-Vorschau (nur Proportionen) ist ein nettes, billiges
   Extra.
5. Nav „Playlists" aktiv schalten; ggf. `admin/api/*` nur falls nötig
   (Picker kann wie in der Bibliothek per JSON `mediathek-list`-Muster laufen,
   hier eher `instanz-list`).

## Offene Design-Fragen (im neuen Chat klären, vor GO)
- Spaltenbreiten: frei eingebbar (%) mit Validierung Summe=100, oder
  Schieberegler? (CLAUDE.md: frei wählbar.)
- `layout_override` pro Instanz schon in 6 umsetzen oder auf später schieben?
- Spalten-Picker: Modal mit allen Instanzen (Filter nach Typ) — analog Bild-Picker.
- Schematische Layout-Vorschau im Editor: ja/nein.

## Arbeitsregeln (verbindlich)
- **Kein Code ohne explizites „GO".** Erst Konzept/Vorschlag/Rückfragen.
- Branch `claude/nifty-johnson-3q6u7g`; nach jedem sinnvollen Abschnitt
  **committen + pushen** und **`STATUS.md` aktualisieren**.
- Deployment: betroffene Dateien dem Nutzer als ZIP bündeln (Struktur unter
  `screen.tcpayer.de/`), Migrationen separat + „zuerst einspielen"-Hinweis.
  (Schritt 6 braucht **keine** Migration.)
- `admin/` liegt hinter KAS-Verzeichnisschutz; `proxies/`, `uploads/`,
  `modules/`, `layouts/` bleiben offen (Monitore).
- Zielauflösung Full-HD (1920×1080); Akzentfarbe #ad2121.
- PHP lokal nur per `php -l` lintbar (keine DB); DB-spezifisches erst live testen.
