# Projektdokumentation: Tanzschule Monitor-System

> **🛑 Arbeitsanweisung:** Erst nachdenken/vorschlagen/Rückfragen stellen —
> **keine Datei erstellen, ändern oder Code schreiben, bevor der Nutzer
> explizit „GO" sagt.** Lesen/Prüfen jederzeit ok.

> **📦 Lieferung:** Neu erstellte oder geänderte Dateien immer als ZIP ausgeben,
> in der korrekten Ordnerstruktur (entpackbar direkt im Stammordner der jeweiligen
> Domain). Jede ZIP nur die tatsächlich geänderten Dateien enthalten.

> **⚠️ config.php:** Wird `config.php` geändert, den Nutzer explizit warnen —
> die Datei enthält echte Zugangsdaten (DB, Passwörter, API-Keys) und darf
> **nie** in eine Liefer-ZIP gepackt werden.

---

## 1. Übersicht

Digitales Monitor-Signage-System für eine Tanzschule. Zentrales Backend
(`screen.tcpayer.de`) + schlanke Saal-Monitor-Frontends (`saalN.tcpayer.de`)
im Vollbild-Kiosk-Modus. Module: `uhrzeit`, `bild`, `ankuendigung`,
`stundenplan`, `fret`, `veranstaltung`, `video`. Ticker läuft als eigenständiges Footer-System.

**Hosting:** all-inkl (PHP 8 + MySQL). Kein Node.js, kein Docker.

| System | Was | Modul | Zugangsdaten |
|---|---|---|---|
| **FRET** | Musiksoftware; Songanzeige je Saal | `fret` | `FRET_SCHOOL_ID` + Computer-UUID |
| **Nimbuscloud** | Tanzschulverwaltung; Stundenplan | `stundenplan` | `NC_API_KEY` (schulweit) |
| **tcpayer.de** | Homepage; Veranstaltungskalender | `veranstaltung` | kein Key (öffentliche WP REST-API) |

Beide Systeme sind vollständig unabhängig — getrennte Proxys, getrennte Keys.

---

## 2. Hosting-Struktur

```
screen.tcpayer.de    → Backend / Admin
saal1.tcpayer.de     → Monitor Saal 1  (eigener Ordner im all-inkl KAS)
saal2.tcpayer.de     → Monitor Saal 2
saal3.tcpayer.de     → Monitor Saal 3  (beliebig erweiterbar)
```

Bilder: zentral über `screen.tcpayer.de/uploads/`. Jedes Saal-Frontend kennt
nur seine eigene `SAAL_ID`. Zielauflösung: **Full-HD (1920×1080)**.

---

## 3. Technologie-Stack

| Schicht | Technologie |
|---|---|
| Backend | PHP 8 + MySQL |
| Proxys | PHP (NC-Key + FRET-schoolId bleiben serverseitig) |
| Monitor-Frontend | HTML + Vanilla JS, Vollbild/Kiosk-Modus |
| Hosting | all-inkl, je Subdomain ein Ordner |

---

## 4. Plugin-/Modul-System

Neuer Inhaltstyp → Ordner in `/modules/` + Eintrag in `registry.php`.
Neues Layout → Ordner in `/layouts/` + Eintrag in `registry.php`.
**Kein bestehender Code muss angefasst werden.**

```
screen.tcpayer.de/
├── modules/
│   ├── registry.php
│   ├── bild/          (module.json, backend.php, frontend.js)
│   ├── stundenplan/
│   ├── ankuendigung/
│   ├── uhrzeit/
│   └── fret/
├── layouts/
│   ├── registry.php
│   ├── 1-spaltig/     (layout.json, template.html)
│   ├── 2-spaltig-60-40/
│   ├── 2-spaltig-50-50/
│   └── 3-spaltig-gleich/
├── proxies/
│   ├── nc.php              (NC Legacy-API, Key serverseitig)
│   ├── fret.php            (FRET, schoolId serverseitig)
│   └── veranstaltungen.php (WP Events Calendar REST-API, kein Key)
└── uploads/
```

---

## 5. Datenmodell: Drei Ebenen

```
Modul-Typ (Code)  →  Modul-Instanz (Bibliothek)  →  Playlist-Eintrag
 z.B. „bild"          z.B. „Veranstaltung"            Playlist „Abend" → Spalte 1
                       (wiederverwendbar)               → Instanz „Veranstaltung"
```

Generische Felder auf Eintrags-/Instanz-Ebene:

| Feld | Ebene | Bedeutung |
|---|---|---|
| `aktiv` | Instanz + Eintrag | Pausieren ohne Löschen |
| `gueltig_bis` | Eintrag | Ablaufdatum (NULL = unbegrenzt); kein Wochentag/Uhrzeit-Muster |

Bilder werden zentral in `mediathek` verwaltet (SHA-256-Duplikaterkennung);
`modul_instanz_inhalte.mediathek_id` verweist darauf.

---

## 6. Playlists (Hauptfläche)

Playlist = Layout (1–3 Spalten, Breiten frei in %) + Modul-Instanzen je Spalte.
**Zeitplanung liegt beim Monitor** (Abschnitt 8/12, `monitor_zeitplan`).

**Module:**

| Modul-ID | Beschreibung |
|---|---|
| `bild` | Rotierende Bilder aus der Mediathek, je Eintrag `gueltig_bis` |
| `ankuendigung` | Text + optionales Bild, je Eintrag `gueltig_bis` |
| `stundenplan` | NC Legacy-API `/timetable/data` |
| `uhrzeit` | Live Uhrzeit + Datum |
| `fret` | Aktuell laufender Song (Polling via `proxies/fret.php`) |
| `veranstaltung` | Kommende Veranstaltungen von tcpayer.de (WP Events Calendar REST-API) |
| `video` | Rotierende Videos: eigene Uploads (mp4/webm via Videothek) oder Embed-Links (YouTube/PeerTube) |

`community` zurückgestellt (würde sensibleren Stammdaten-Key brauchen).

**Layouts:**

| Layout-ID | Spalten |
|---|---|
| `1-spaltig` | 1 × 100 % |
| `2-spaltig-60-40` | 60 / 40 % |
| `2-spaltig-50-50` | 50 / 50 % |
| `3-spaltig-gleich` | je ~33 % |

Header (Uhrzeit/Datum) + Footer (Ticker) optional ein-/ausblendbar pro Playlist.

---

## 7. Ticker (Footer) — eigenständiges System

**Kein Modul, kein Teil der Playlist.** Bei mehreren gleichzeitig aktiven
Tickern werden alle Texteinträge **gemischt** (keine Priorität — anders als
bei Playlists). Inhalt: nur manuell erfasster Text.

Verwaltung: Bereich „Ticker" (Inhalt/Textzeilen) + Bereich „Monitore →
Zeitplan" (wann/wo der Ticker läuft, ohne Priorität).

---

## 8. Datenbankstruktur

```sql
monitore                id, name, subdomain
einstellungen           monitor_id, nc_api_key_stundenplan, nc_api_key_stammdaten, song_api_url
modul_instanzen         id, modul_typ, name, einstellungen (JSON), aktiv
mediathek               id, dateiname, original_name, datei_hash (UNIQUE), breite, hoehe, hochgeladen_am
modul_instanz_inhalte   id, modul_instanz_id, mediathek_id, text, reihenfolge, dauer_sek, gueltig_bis, aktiv
playlists               id, name, aktiv
playlist_layout         id, playlist_id, spalten_anzahl, spalte1_breite, spalte2_breite, spalte3_breite,
                        header_uhrzeit, footer_ticker
playlist_spalten_inhalte  id, playlist_id, spalte, reihenfolge, modul_instanz_id, layout_override
monitor_zeitplan        id, monitor_id, playlist_id, wochentage,
                        von_uhrzeit (NULL=Fallback), bis_uhrzeit (NULL=Fallback), prioritaet
ticker_playlists        id, name, aktiv
ticker_eintraege        id, ticker_playlist_id, text, reihenfolge, dauer_sek
ticker_zeitplan         id, monitor_id, ticker_playlist_id, wochentage,
                        von_uhrzeit (NULL=Fallback), bis_uhrzeit (NULL=Fallback)
                        -- KEIN prioritaet — mehrere aktive Ticker werden gemischt
```

---

## 9. Nimbuscloud-Anbindung

Aktiv: **`POST /timetable/data`** (Legacy-API).
- Basis-URL: `https://tanzcenter-payer.nimbuscloud.at/api/json/v1`
- Auth: POST-Parameter `apikey` (**nicht** Header `X-API-Key`)
- Key: `NC_API_KEY` in `config.php` (schulweit, serverseitig)
- Vollständige Doku (Parameter, Felder, Codebeispiel): `NC_Legacy_API_Stundenplan.md`

Zwei getrennte Keys: `nc_api_key_stundenplan` (aktiv) + `nc_api_key_stammdaten`
(zurückgestellt, community-Modul). Niemals im selben Proxy verwenden.

---

## 10. Monitor-Frontend

Kiosk-Vollbild, kennt nur eigene `SAAL_ID`. Refresh alle ~60 Sek.,
FRET-Polling 5–10 Sek., Ticker unabhängig.

```
1. monitor_zeitplan → welche Playlist gilt jetzt? (Wochentag + Uhrzeit + Priorität)
2. Layout rendern (Spaltenanzahl + Breiten)
3. Pro Spalte: Modul-Instanzen laden + unabhängig rotieren
4. Header (Uhrzeit) falls aktiviert
5. ticker_zeitplan → alle aktiven Ticker sammeln (KEINE Priorität)
   → Texteinträge zusammenführen + gemischt im Footer durchlaufen
```

---

## 11. Backend-Bereiche

| Bereich | Funktion |
|---|---|
| Bibliothek | Modul-Instanzen anlegen/verwalten (Mediathek-Bild-Picker) |
| Playlists | Layout + Spalten-Inhalte + Vorschau-Button je Kachel |
| Ticker | Ticker-Playlists + Textzeilen |
| Monitore | Anlegen (Name, Subdomain) + Zeitplan je Monitor (Playlist + Ticker, mit ↑/↓-Sortierung) |
| Live-Vorschau | iFrame-Simulation eines Monitors (live, via saalN.tcpayer.de) |
| Playlist-Vorschau | `playlist-preview.php` — Monitor-Rendering einer einzelnen Playlist (ohne Zeitplan) |

---

## 12. Architektur-Entscheidungen

- Monitor-zentrische Zeitplanung: `monitor_zeitplan` (Priorität) + `ticker_zeitplan` (keine Priorität)
- Uhrzeit optional: leer = dauerhaft/Fallback, wird von Einträgen mit Uhrzeit überschrieben
- Playlist-Überschneidung: höchste **Priorität** gewinnt
- Ticker-Überschneidung: **Mischung**, keine Priorität
- Stundenplan: Legacy-API `/timetable/data` (Direct-DB-Access verworfen: 401)
- FRET `schoolId` → nur `config.php` (`FRET_SCHOOL_ID`), niemals in Modul-Instanz-Einstellungen
- NC-Keys: `nc_api_key_stundenplan` (aktiv), `nc_api_key_stammdaten` (zurückgestellt)
- `community`-Modul zurückgestellt (Datenschutz/Stammdaten-Key)
- Mediathek: SHA-256-Duplikaterkennung; `mediathek_id` statt Dateiname in Inhalten
- `aktiv` auf Instanz- UND Eintrags-Ebene; `gueltig_bis` auf Eintrags-Ebene
- Layout an Playlist gebunden, nicht am Monitor/Saal
- Zielauflösung: Full-HD 1920×1080
- **CORS:** zentral per `.htaccess` (`Header set Access-Control-Allow-Origin "*"`); keine PHP-seitigen CORS-Header in Proxys
- **Ticker:** läuft global unabhängig von Playlist-Rotation; `doRender()` steuert nur Sichtbarkeit (show/hide); Neustart nur bei geänderten Einträgen
- **Proportionale Spalten-Synchronisation:** längste Spalte = Master-Zyklus; kürzere Spalten + deren Inhalte werden proportional skaliert (`skaliereMod()`)
- **"Refresh Monitore"-Button:** Feld `reload_at DATETIME` in `monitore`; `Monitor::triggerReloadAlle()` setzt `NOW()`; `monitor.js` erkennt Änderung beim nächsten Poll → `location.reload()`; Migration: `sql/migration_reload_at.sql`
- **TV-Skalierung:** Google TV rendert trotz Full-HD-Display mit 1280×720 Viewport und ignoriert `<meta name="viewport" content="width=1920">`. Fix: Inline-Script in `saalN/index.html` — misst `window.innerWidth`, setzt `#tm-wrapper` auf 1920×1080 fix und skaliert via `transform: scale(innerWidth/1920)`. Greift nur wenn Breite < 1920px, skaliert nur nach Breite (nicht Höhe) um Rand-Artefakte zu vermeiden.
- **FRET Fortschrittsbalken:** API liefert `remainingSeconds` immer `null` (serverseitiges FRET-Problem); Balken friert korrekt ein aber läuft nicht — offen bis FRET-Server-Problem gelöst.
- **Browser-Cache dynamischer Module:** `module-loader.js` hängt `?v=Date.now()` an jede `frontend.js`-URL → Cache immer umgangen, kein manuelles Leeren nötig.
- **Stundenplan Standort-Filter:** `location_ids` (JSON-String `"[1,3]"` oder `""`) in `modul_instanzen.einstellungen`; `proxies/nc-locations.php` ruft `POST /data/locations` (Stammdaten, gleicher `NC_API_KEY`) ab und liefert `[{id, name, rooms:[{id,name}]}]`; `nc.php` filtert serverseitig nach `locationId` (camelCase String! nicht `location_id`). Admin-Editor: Checkboxen je Standort, abhängiges Saal-Dropdown.
- **Stundenplan Saal-Filter:** `room_id` (int, 0 = alle) in Einstellungen; `nc.php` filtert nach `room_id`/`roomId` (camelCase-Fallback); Saal-Dropdown im Admin zeigt nur Säle der angehakten Standorte.
- **Stundenplan responsive Schrift:** `.tm-spalte { container-type: inline-size }` + `@container (max-width: 700px)` in `monitor.css` → 22px in 3-Spalten-Layout, 32px in 1/2-Spalten.
- **Stundenplan feste Kartenhöhe:** `requestAnimationFrame` in `frontend.js` misst `tm-sp-cards.clientHeight` nach dem Rendern und setzt jede Karte auf `floor((totalH - gap*(anzahl-1)) / anzahl)` px → `flex: 0 0 Xpx`. Verhindert riesige Karten wenn weniger Kurse als konfigurierte Maximalanzahl vorhanden.
- **Ticker Schriftgröße / Footer-Höhe:** 30 px Schrift (war 22 px), Footer-Höhe 70 px (war 58 px). Hardcodierter Animations-Wert in `monitor.js` (`footerEl.style.height`) muss bei Änderung mitgepflegt werden.
- **Playlist-Vorschau:** `admin/playlist-preview.php` — standalone HTML-Seite (kein admin_header/footer), lädt Playlist per `?id=X` direkt aus DB, rendert Monitor-Layout mit `TanzschuleLoader.render()`, kein Polling. Wird via `adm-vorschau-btn`-Mechanismus (iFrame-Modal in `layout.php`) aus den Playlist-Kacheln geöffnet. Ticker wird angezeigt wenn `footer_ticker` aktiv — lädt alle aktiven `ticker_eintraege` ohne Zeitplan-Filter, `startTicker`-Logik inline im Script.
- **Modul-Crossfade (`rotateModule`):** Beim Wechsel zwischen Modul-Instanzen in einer Spalte Crossfade 1500ms (= gleiche Dauer wie Bildwechsel in `bild/frontend.js`). Alter Container bleibt `position:absolute;inset:0` und blendet aus, neuer startet mit `opacity:0` und blendet ein. Erster Render direkt sichtbar.
- **Playlist-Pre-render (`SETTLE_MS = 800`):** Neues Layout rendert 800ms unsichtbar (`opacity:0`, korrekt positioniert `position:absolute;inset:0`) im DOM bevor Crossfade startet — gibt Modulen (Stundenplan-API, Bilder) Ladezeit. `style.cssText` darf nicht verwendet werden — würde `gridTemplateColumns` löschen; stattdessen einzelne Properties setzen. Alte Rotation-Timeouts per `_rotationTimeouts.forEach(clearTimeout)` sofort einfrieren, damit das alte Layout während SETTLE_MS nicht weiterzählt.
- **Pixel-Größen im Playlist-Editor:** Panel neben der schematischen Vorschau (flex-row); berechnet 1920×1080-Basis dynamisch: Header 80 px, Footer 70 px, Spaltenbreiten aus Prozent-Angaben. Wird bei jedem Layout-/Regler-/Checkbox-Wechsel aktualisiert.
- **Zeitplan-Sortierung:** ↑/↓-Buttons in jeder Playlist- und Ticker-Zeitplan-Zeile (`monitor-zeitplan.php`); verschiebt Zeilen im DOM, gespeicherte Reihenfolge gilt als Tiebreaker bei gleicher Priorität.
- **Vorschau-Schema feste Breite:** `.adm-vorschau` im Playlist-Editor hat `flex: 0 0 480px; width: 480px` (inline), damit es im flex-row neben dem Pixel-Info-Panel nicht zusammengedrückt wird.
- **Modul `video`:** Zwei Inhaltstypen pro Eintrag: eigene Datei (`video_datei_id` → `video_dateien`-Tabelle, Upload via `admin/videothek.php`) oder Embed-Link (`video_embed_url`, YouTube/PeerTube auto-erkannt). Weiterschaltung event-getrieben via `ended`-Event (kein fester Timer) — YouTube IFrame API (`YT.PlayerState.ENDED`), PeerTube via `postMessage` (`playbackStatusUpdate/ended`). Sicherheits-Timeout: 15 Minuten hardcodiert. Immer stumm (Browser-Autoplay-Pflicht). `dauer_sek` nur Schätzwert für Spalten-Synchronisation. Neue Tabelle `video_dateien` (analog `mediathek`, MIME-Prüfung via `finfo`). Neuer Admin-Menüpunkt „Videos" (`admin/videothek.php`) mit Drag&Drop-Upload, Bearbeiten (Name/Laufzeit via `.adm-bild-edit`-Button wie Mediathek) und Löschen (nur wenn nicht in Verwendung). Unabhängig von Mediathek. Migration: `12_migration_video.sql`. Embed-Typ-Erkennung: `/youtube\.com|youtu\.be/i` → YouTube, `/\/videos\/embed\//i` → PeerTube. YouTube-PlayerVars: `controls=0, modestbranding=1, rel=0, showinfo=0, iv_load_policy=3` (maximale UI-Reduktion via API; CSS-Overlay wäre ToS-Verstoß). **WICHTIG:** `proxies/monitor.php` hat eine eigene SQL-Abfrage für `modul_instanz_inhalte` — dort muss `LEFT JOIN video_dateien` + `video_dateiname`/`video_embed_url` explizit stehen; `ModulInstanz::listInhalte()` allein reicht für den Monitor-Proxy nicht.
- **Modul `veranstaltung`:** Proxy `proxies/veranstaltungen.php` ruft `https://tcpayer.de/wp-json/tribe/events/v1/events?per_page=N&start_date=heute` ab (öffentlich, kein Key). `status=future` wird von der kostenlosen Version des Plugins nicht unterstützt → stattdessen `start_date`. Felder: `titel`, `start_date`, `end_date`, `bild_url` (aus `image.url` oder null), `venue` (aus `venue.venue`-String), `beschreibung` (HTML gestrippt, max. 160 Zeichen). Frontend: A/B-Crossfade analog zu `ankuendigung`, deutsche Datums-/Uhrzeitformatierung (Wochentag + Monatsname). Einstellungen: `anzahl` (max. 20), `anzeige_dauer_sek`, `uebergang` (fade/none).
- **Globale Admin-Dialoge:** `confirm()`, `alert()`, `prompt()` auf allen Admin-Seiten durch eigene HTML-Modals ersetzt (`admBestaetigen`, `admMeldung`, `admEingabe` — definiert in `admin_footer()` in `layout.php`). Browser-Dialog-Blockierung kann den Admin-Bereich nicht mehr lahmlegen.
- **Stundenplan Überschrift:** Setting `titel` (leer = keine Überschrift); rendert `.tm-sp-heading` — 48 px, zentriert, Großbuchstaben, Rot — analog zum FRET-Modul. Kartenhöhe passt sich automatisch an.
- **FRET Countdown-Schrift:** `.tm-song-k-countdown` von 16 px auf 22 px vergrößert (live noch nicht bestätigt).

---

## 13. Bauplan

| Schritt | Inhalt | Status |
|---|---|---|
| 1 | SQL-Schema | ✅ live |
| 2 | Ordnerstruktur + .htaccess | ✅ live |
| 3 | Modul-Registry + `uhrzeit`, `bild` | ✅ live |
| 4 | `stundenplan`, `ankuendigung`, `fret` + NC-/FRET-Proxy | ✅ live getestet |
| 5 | Backend: Bibliothek + Mediathek | ✅ live getestet |
| 6 | Backend: Playlist-Editor | ✅ live getestet |
| 7 | Backend: Monitore + Zeitplan (monitor-zentrisch) | ✅ live getestet |
| 8 | Backend: Ticker + Ticker-Zeitplan | ✅ live getestet |
| 9 | Monitor-Frontend (Anzeige- + Zeitlogik) | ✅ live getestet |
| 9b | Monitor-Frontend: `stundenplan` ✅ live (Standort-/Saal-Filter, responsive Schrift, feste Kartenhöhe); `fret` offen (Fortschrittsbalken wartet auf FRET-Server-Fix) | teilweise |
| 10 | Live-Vorschau (iFrame) + Playlist-Vorschau (`playlist-preview.php`) | ✅ live getestet |
| 11 | Deployment-Guide | ✅ live (manuell per FTP auf all-inkl, läuft produktiv) |
| 12 | Livebetrieb-Feedback: Ticker 30 px/70 px, Pixel-Größen-Panel, Zeitplan-Sortierung, Endnutzer-Texte | ✅ live |
| 13 | Modul `veranstaltung` (WP Events Calendar) + Vorschau-Schema-Fix | ✅ geliefert, noch nicht live getestet |
| 14 | Modul `video` (eigene Uploads + YouTube/PeerTube-Embeds, event-getrieben) + Videothek-Admin | ✅ live getestet |

---

## 14. FRET-API — Sicherheitshinweis

**⚠️ `schoolId` darf niemals im Frontend/Browser sichtbar sein** (FRET hat
schreibende Endpunkte). Zugriff nur über `proxies/fret.php`.

- `FRET_SCHOOL_ID` + `FRET_API_BASE` → serverseitig in `config.php`
- Pro `fret`-Instanz: nur Computer-UUID + Anzeigename (nicht geheim)
- `GET /schools/{schoolId}/computers/{computerId}/Players` → aktueller Song
- `player1.songs[]`, `position: 0` = aktuell; vollständige Doku: `FRET_API.json`
