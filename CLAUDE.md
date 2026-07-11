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
- **Proportionale Spalten-Synchronisation:** längste Spalte = Master-Zyklus; kürzere Spalten + deren Inhalte werden proportional skaliert (`skaliereMod()`). `modulAnzeigeDauer()` summiert `inhalte[].dauer_sek` wenn vorhanden; sonst `einstellungen.anzeige_dauer_sek`. Sonderfall `veranstaltung`: keine DB-Inhalte, Events aus externer API → Gesamtdauer = `anzahl × anzeige_dauer_sek`.
- **"Refresh Monitore"-Button:** Feld `reload_at DATETIME` in `monitore`; `Monitor::triggerReloadAlle()` setzt `NOW()`; `monitor.js` erkennt Änderung beim nächsten Poll → `location.reload()`; Migration: `sql/migration_reload_at.sql`
- **TV-Skalierung:** Google TV rendert trotz Full-HD-Display mit 1280×720 Viewport und ignoriert `<meta name="viewport" content="width=1920">`. Fix: Inline-Script in `saalN/index.html` — misst `window.innerWidth`, setzt `#tm-wrapper` auf 1920×1080 fix und skaliert via `transform: scale(innerWidth/1920)`. Greift nur wenn Breite < 1920px, skaliert nur nach Breite (nicht Höhe) um Rand-Artefakte zu vermeiden.
- **FRET Fortschrittsbalken:** Komplett neu mit `requestAnimationFrame` (kein `setInterval`-Drift). Zustandsobjekt `bar` mit `{songId, dauer, restBeiEmpfang, empfangenAm, letzterWert, laeuft}`. Drei Zustände: `isPlaying + remainingSeconds` → Animation; `isPlaying + remainingSeconds null + startTime` → Fallback via `elapsed = (Date.now() - Date.parse(startTime)) / 1000; remaining = duration - elapsed`; Pause/kein Song → Einfrieren (kein Reset). Songwechsel via `songId` (Fallback: `title|artist`) → Balken auf 0. `transition: width` in CSS entfernt (würde mit rAF kollidieren). FRET-Server liefert `remainingSeconds` unter bestimmten Umständen null — `startTime`-Fallback greift dann automatisch.
- **Browser-Cache dynamischer Module:** `module-loader.js` hängt `?v=Date.now()` an jede `frontend.js`-URL → Cache immer umgangen, kein manuelles Leeren nötig.
- **Stundenplan Standort-Filter:** `location_ids` (JSON-String `"[1,3]"` oder `""`) in `modul_instanzen.einstellungen`; `proxies/nc-locations.php` ruft `POST /data/locations` (Stammdaten, gleicher `NC_API_KEY`) ab und liefert `[{id, name, rooms:[{id,name}]}]`; `nc.php` filtert serverseitig nach `locationId` (camelCase String! nicht `location_id`). Admin-Editor: Checkboxen je Standort, abhängiges Saal-Dropdown.
- **Stundenplan Saal-Filter:** `room_id` (int, 0 = alle) in Einstellungen; `nc.php` filtert nach `room_id`/`roomId` (camelCase-Fallback); Saal-Dropdown im Admin zeigt nur Säle der angehakten Standorte.
- **Stundenplan responsive Schrift:** `.tm-spalte { container-type: inline-size }` + `@container (max-width: 700px)` in `monitor.css` → 22px in 3-Spalten-Layout, 32px in 1/2-Spalten.
- **Stundenplan feste Kartenhöhe:** `requestAnimationFrame` in `frontend.js` misst `tm-sp-cards.clientHeight` nach dem Rendern und setzt jede Karte auf `floor((totalH - gap*(anzahl-1)) / anzahl)` px → `flex: 0 0 Xpx`. Verhindert riesige Karten wenn weniger Kurse als konfigurierte Maximalanzahl vorhanden.
- **Ticker Schriftgröße / Footer-Höhe:** 30 px Schrift (war 22 px), Footer-Höhe 70 px (war 58 px). Hardcodierter Animations-Wert in `monitor.js` (`footerEl.style.height`) muss bei Änderung mitgepflegt werden.
- **Playlist-Vorschau:** `admin/playlist-preview.php` — standalone HTML-Seite (kein admin_header/footer), lädt Playlist per `?id=X` direkt aus DB, rendert Monitor-Layout mit `TanzschuleLoader.render()`, kein Polling. Wird via `adm-vorschau-btn`-Mechanismus (iFrame-Modal in `layout.php`) aus den Playlist-Kacheln geöffnet. Ticker wird angezeigt wenn `footer_ticker` aktiv — lädt alle aktiven `ticker_eintraege` ohne Zeitplan-Filter, `startTicker`-Logik inline im Script.
- **Modul-Crossfade (`rotateModule`) — Overlay-Dissolve + Settle (Schritt 19):** Neuer Container rendert `MODUL_SETTLE_MS` (800 ms) unsichtbar vor (analog Playlist-`SETTLE_MS`; verhindert einblendende „Lade…"-Texte und mitten im Fade reinploppende Bilder), dann Overlay-Fade 1500 ms: neuer Container blendet **über** den alten ein, alter bleibt bei `opacity:1` und wird erst nach dem Fade entfernt (kein simultanes Kreuzblenden — das erzeugte „Dip to Black"). `.tm-modul-container` hat deckenden Hintergrund `#0a0a0a` (= Seitenhintergrund) → echter Dissolve, kein Durchscheinen durch Letterbox-Ränder/halbtransparente Flächen (Stundenplan-Karten sind nur `rgba(255,255,255,0.1)`), kein Pop beim Entfernen. Beim Wechselstart wird nur `_tmTimeout` des alten Containers eingefroren (Slide-Rotation stoppt); Live-Intervalle (Uhr, FRET-Poll, Video-Player) laufen bis zum Entfernen weiter — volles `cleanupModulContainer` erst dort (würde sonst den YT-Player sofort zerstören). **Regel für Module:** innere Opacity-Transitions erst per rAF NACH dem ersten Render setzen (sonst multiplikative Opacity mit dem äußeren Fade: 0.5 × 0.5 = 0.25 → wirkt wie harter Schnitt); umgesetzt in `bild` + `ankuendigung`; `veranstaltung` trägt noch das alte Muster, wird durch die Settle-Phase maskiert.
- **Slide-Engine (Konzept, Schritt 20):** Trennung Inhalt/Präsentation — Module liefern nur noch Slides (`getSlides(settings, inhalte, fertig)` → `[{el, dauerSek, meldetEnde, destroy}]`), die Engine besitzt alle Übergänge/Timer/Cleanup. Beseitigt die 3× duplizierte Rotations-Logik (`bild`/`ankuendigung`/`veranstaltung`) und macht die Opacity-Bug-Klasse konstruktiv unmöglich. Vollständiges Konzept + Migrationsplan: `KONZEPT_SLIDE_ENGINE.md`.
- **Playlist-Pre-render (`SETTLE_MS = 800`):** Neues Layout rendert 800ms unsichtbar (`opacity:0`, korrekt positioniert `position:absolute;inset:0`) im DOM bevor Crossfade startet — gibt Modulen (Stundenplan-API, Bilder) Ladezeit. `style.cssText` darf nicht verwendet werden — würde `gridTemplateColumns` löschen; stattdessen einzelne Properties setzen. Alte Rotation-Timeouts per `_rotationTimeouts.forEach(clearTimeout)` sofort einfrieren, damit das alte Layout während SETTLE_MS nicht weiterzählt.
- **Pixel-Größen im Playlist-Editor:** Panel neben der schematischen Vorschau (flex-row); berechnet 1920×1080-Basis dynamisch: Header 80 px, Footer 70 px, Spaltenbreiten aus Prozent-Angaben. Wird bei jedem Layout-/Regler-/Checkbox-Wechsel aktualisiert.
- **Zeitplan-Sortierung:** ↑/↓-Buttons in jeder Playlist- und Ticker-Zeitplan-Zeile (`monitor-zeitplan.php`); verschiebt Zeilen im DOM, gespeicherte Reihenfolge gilt als Tiebreaker bei gleicher Priorität.
- **Vorschau-Schema feste Breite:** `.adm-vorschau` im Playlist-Editor hat `flex: 0 0 480px; width: 480px` (inline), damit es im flex-row neben dem Pixel-Info-Panel nicht zusammengedrückt wird.
- **Modul `video`:** Zwei Inhaltstypen pro Eintrag: eigene Datei (`video_datei_id` → `video_dateien`-Tabelle, Upload via `admin/videothek.php`) oder Embed-Link (`video_embed_url`, YouTube/PeerTube auto-erkannt). Weiterschaltung event-getrieben via `ended`-Event (kein fester Timer) — YouTube IFrame API (`YT.PlayerState.ENDED`), PeerTube via `postMessage` (`playbackStatusUpdate/ended`). Sicherheits-Timeout: 15 Minuten hardcodiert. Immer stumm (Browser-Autoplay-Pflicht). `dauer_sek` nur Schätzwert für Spalten-Synchronisation. Neue Tabelle `video_dateien` (analog `mediathek`, MIME-Prüfung via `finfo`). Neuer Admin-Menüpunkt „Videos" (`admin/videothek.php`) mit Drag&Drop-Upload, Bearbeiten (Name/Laufzeit via `.adm-bild-edit`-Button wie Mediathek) und Löschen (nur wenn nicht in Verwendung). Unabhängig von Mediathek. Migration: `12_migration_video.sql`. Embed-Typ-Erkennung: `/youtube\.com|youtu\.be/i` → YouTube, `/\/videos\/embed\//i` → PeerTube. YouTube-PlayerVars: `controls=0, modestbranding=1, rel=0, showinfo=0, iv_load_policy=3` (maximale UI-Reduktion via API; CSS-Overlay wäre ToS-Verstoß). **WICHTIG:** `proxies/monitor.php` hat eine eigene SQL-Abfrage für `modul_instanz_inhalte` — dort muss `LEFT JOIN video_dateien` + `video_dateiname`/`video_embed_url` explizit stehen; `ModulInstanz::listInhalte()` allein reicht für den Monitor-Proxy nicht.
- **Modul `veranstaltung`:** Proxy `proxies/veranstaltungen.php` ruft `https://tcpayer.de/wp-json/tribe/events/v1/events?per_page=N&start_date=heute` ab (öffentlich, kein Key). `status=future` wird von der kostenlosen Version des Plugins nicht unterstützt → stattdessen `start_date`. Felder: `titel`, `start_date`, `end_date`, `bild_url` (aus `image.url` oder null), `bild_breite`/`bild_hoehe` (aus `image.width`/`image.height`, nullable), `venue` (aus `venue.venue`-String), `beschreibung` (HTML gestrippt, max. 160 Zeichen). Angezeigt werden nur Datum, Uhrzeit und Titel (kein Venue, keine Beschreibung). Wochentage ausgeschrieben (`Montag` statt `Mo`). **Adaptives Layout:** `bild_breite/bild_hoehe < 0.85` (Hochkant) → `tm-va-slide--portrait` (Bild links 40 %, Text rechts 60 % mit `backdrop-filter: blur(8px)` + `rgba(0,0,0,0.65)`); sonst → `tm-va-slide--landscape` (Vollbild + Gradient-Overlay von unten); kein Bild → `tm-va-slide--keinbild` (Text zentriert). Bildmaße fehlen → Fallback landscape, nach `img.onload` nachmessen und Layout ggf. korrigieren. Schriftgrößen: Querformat Datum 38 px, Uhrzeit 30 px, Titel 72 px; Hochkant 34/26/56 px; Kein Bild 40/32/84 px. Datum-Farbe `#e03535` (heller als Markenrot für bessere Lesbarkeit auf dunklem Hintergrund). Starker Text-Schatten `0 2px 14px rgba(0,0,0,0.95)` auf allen Textelementen außer keinbild. `.tm-va-titel` hat `padding-bottom: 12px` damit Unterlängen (g, j, y …) nicht von `-webkit-line-clamp`-Clipping abgeschnitten werden. Landscape Info-Box: `top: 55%; bottom: 0` (Text beginnt bei 55 % der Höhe, wächst nach unten). Gradient: transparent 0–55 %, dann `rgba(0,0,0,0.88)` am unteren Rand. A/B-Crossfade (600 ms) analog zu `ankuendigung`. Einstellungen: `anzahl` (max. 20), `anzeige_dauer_sek`, `uebergang` (fade/none).
- **Globale Admin-Dialoge:** `confirm()`, `alert()`, `prompt()` auf allen Admin-Seiten durch eigene HTML-Modals ersetzt (`admBestaetigen`, `admMeldung`, `admEingabe` — definiert in `admin_footer()` in `layout.php`). Browser-Dialog-Blockierung kann den Admin-Bereich nicht mehr lahmlegen.
- **Stundenplan Überschrift:** Setting `titel` (leer = keine Überschrift); rendert `.tm-sp-heading` — 48 px, zentriert, Großbuchstaben, Rot — analog zum FRET-Modul. Kartenhöhe passt sich automatisch an.
- **FRET Countdown-Schrift:** `.tm-song-k-countdown` von 16 px auf 22 px vergrößert.
- **FRET Countdown-Fallback:** Wenn `estimatedSecondsUntilStart` null ist, wird die Wartezeit akkumuliert berechnet: Song 1 = Restzeit aktueller Song; Song 2 = Restzeit + duration[Song 1]; usw. Bricht ab sobald eine `duration` fehlt. API-Wert hat immer Vorrang. Berechnung in `renderKommende()` mit laufendem `akkumuliertSek`-Akkumulator.
- **FRET Layout (Variante D):** Überschrift: 32→34 px, weight 500→700. Song-Titel: 40→42 px, weight 600→700, `text-shadow: 0 1px 8px rgba(0,0,0,0.7)`, `display:-webkit-box; -webkit-line-clamp:2` (max. 2 Zeilen, Ellipsis). `.tm-song-aktuell`: `border-left: 4px solid #ad2121; padding-left: 16px` (roter Akzentbalken). `.tm-song-badge-sub` bleibt `background: #f0f0f0` (weiß).
- **FRET Aktuell-Block Höhe + Zentrierung:** `.tm-song-aktuell` hat `flex: 0 0 33%; min-height: 0; overflow: hidden; display: grid; align-content: center` — feste 1/3-Höhe des Moduls, Inhalt vertikal mittig. Grid (`align-content`) statt Flex (`justify-content`) weil letzteres bei prozentualer `flex-basis` nicht zuverlässig auflöst.
- **FRET Warteliste-Kacheln:** `flex: 1` von `.tm-song-kommende-eintrag` entfernt → Kacheln behalten natürliche Höhe und strecken sich nicht wenn weniger Songs als konfiguriert vorhanden. `.tm-song-kommende` hat `flex: 1; display: flex; flex-direction: column; overflow: hidden`; `.tm-song-kommende-liste` hat `flex: 1; display: flex; flex-direction: column; gap: 8px`.
- **Monitor-Domain:** `monitore.subdomain` enthält die vollständige Domain (z.B. `saal1.tcpayer.de`, `testmon.spass-am-tanzen.de`). `Monitor::normDomain()` validiert und normalisiert die Eingabe (Kleinbuchstaben, `[a-z0-9.-]`, mind. ein Punkt). Früher war nur der Subdomain-Teil gespeichert und `.tcpayer.de` hardcodiert angehängt — das ist abgelöst. `normSubdomain()` existiert noch als Deprecated-Alias. Migration `13_migration_monitor_domain.sql` war für bestehende Installationen vorgesehen, war aber nicht nötig da die DB-Einträge bereits korrekt waren. `monitor.js` sendet jetzt `window.location.hostname` (vollständig) statt nur den ersten Teil — alle hardcodierten `.tcpayer.de`-Anhänge in Admin-Seiten ebenfalls entfernt.
- **Testmon UPLOADS_URL:** `testmon.spass-am-tanzen.de/index.html` zeigt `UPLOADS_URL` auf `screen.tcpayer.de/uploads` (Live-Server) — Bilder müssen nicht auf den Staging-Server kopiert werden. Wird nur auf Staging deployed, nie auf Live.
- **CI/CD:** `.github/workflows/deploy.yml` — Push auf `claude/nifty-johnson-3q6u7g` deployt via FTP auf `screen.spass-am-tanzen.de/` (Staging-Backend) und `testmon.spass-am-tanzen.de/` (Staging-Monitor). Merge auf `main` deployt auf `screen.tcpayer.de/` (Live). Secrets `FTP_HOST`/`FTP_USER`/`FTP_PASS` in GitHub-Repository-Settings hinterlegt. `config.php` ist per `exclude` in allen Jobs ausgenommen — muss einmalig manuell per FTP hochgeladen/gepflegt werden.
- **Admin Versionsanzeige:** `version.php` (in `.gitignore`, wird von CI/CD generiert) mit `APP_VERSION` (git short-hash), `APP_VERSION_DATE` (UTC Datum/Zeit), `APP_ENV` (`staging`/`live`). `admin/includes/layout.php` bindet sie ein und zeigt rechts in der Topbar: `hash · DD.MM.YYYY HH:MM [· STAGING]`. Staging: gelb (`.adm-nav-version--staging`), Live: grau (`.adm-nav-version`). Nicht vorhanden = kein Anzeige-Element.
- **Playlist Monitor-Tooltip:** `Playlist::listAll()` liefert `monitor_namen` via `GROUP_CONCAT(m.name … SEPARATOR \', \')` (SEPARATOR-Quotes mit `\'` escapen — PHP-String ist single-quoted). Badge `adm-monitore-badge--aktiv` trägt `data-monitore`-Attribut; reines CSS-Tooltip via `::after { content: attr(data-monitore) }` auf `:hover`.
- **Veranstaltung Glow:** Ambient-Hintergrund als echtes DOM-Element `<div class="tm-va-bild-glow" style="background-image:url('…')">` in `.tm-va-bild` — zuverlässiger als CSS `::before` + Custom Property. `filter: blur(24px) brightness(0.72) saturate(1.3)`. Landscape + Portrait: `img { object-fit: contain; z-index: 1 }` → Glow füllt Letterbox-Bereiche.
- **Ankündigung Vollbild-Layout:** Mit Bild: `.tm-ank-bg` (`position:absolute;inset:0`, `img object-fit:cover`) als Hintergrund; Text-Pill direkt über dem Bild (`border-radius:16px`, Hintergrund `rgba(0,0,0,α)` per Inline-Style). Kein Gradient-Overlay mehr. Ohne Bild: nur Text zentriert. Settings: `schrift_groesse` (36–96 px, Standard 60) + `pill_transparenz` (0.15/0.30/0.45, Standard 0.15). `pillAlpha = parseFloat(settings.pill_transparenz) || 0.15` im JS.

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
| 9b | Monitor-Frontend: `stundenplan` ✅ live (Standort-/Saal-Filter, responsive Schrift, feste Kartenhöhe); `fret` ✅ Fortschrittsbalken (rAF + startTime-Fallback), Layout Variante D, Countdown-Fallback | ✅ live |
| 10 | Live-Vorschau (iFrame) + Playlist-Vorschau (`playlist-preview.php`) | ✅ live getestet |
| 11 | Deployment-Guide | ✅ ersetzt durch CI/CD (Schritt 15) |
| 12 | Livebetrieb-Feedback: Ticker 30 px/70 px, Pixel-Größen-Panel, Zeitplan-Sortierung, Endnutzer-Texte | ✅ live |
| 13 | Modul `veranstaltung` (WP Events Calendar) + Vorschau-Schema-Fix | ✅ geliefert, noch nicht live getestet |
| 17 | Modul `veranstaltung` — adaptives Layout: Hochkant / Querformat / Kein Bild; Proxy liefert `bild_breite`/`bild_hoehe`; Text ab 55 % von oben; Gradient 0–55 % transparent; Datum #e03535 38 px; Wochentage ausgeschrieben; padding-bottom auf Titel gegen Unterlängen-Clipping | ✅ live |
| 18 | Playlist-Monitor-Tooltip (CSS + GROUP_CONCAT); Veranstaltung Glow via DOM-Element + `object-fit:contain`; Ankündigung Vollbild-Layout (Bild als BG + Text-Pill); Settings `schrift_groesse` + `pill_transparenz` | ✅ live |
| 14 | Modul `video` (eigene Uploads + YouTube/PeerTube-Embeds, event-getrieben) + Videothek-Admin | ✅ live getestet |
| 15 | CI/CD via GitHub Actions + Monitor-Domain (vollständig statt Subdomain) + Testmon-Frontend | ✅ live |
| 16 | FRET-Modul Verbesserungen: Fortschrittsbalken (rAF + startTime-Fallback), Countdown-Fallback (akkumulierte Lieddauer), Layout Variante D, Admin-Versionsanzeige | ✅ Staging getestet, bereit für Live-Merge |
| 19 | Modul-Übergänge: Overlay-Dissolve (deckende `.tm-modul-container`) + `MODUL_SETTLE_MS` in `rotateModule` + Rotation-Freeze; innere Layer-Transition-Fixes in `bild` + `ankuendigung` | 🧪 geliefert, Staging-Test ausstehend |
| 20 | Slide-Engine: Module liefern nur Inhalt, Engine besitzt Präsentation (Konzept: `KONZEPT_SLIDE_ENGINE.md`) | 📋 Konzept dokumentiert |

---

## 14. FRET-API — Sicherheitshinweis

**⚠️ `schoolId` darf niemals im Frontend/Browser sichtbar sein** (FRET hat
schreibende Endpunkte). Zugriff nur über `proxies/fret.php`.

- `FRET_SCHOOL_ID` + `FRET_API_BASE` → serverseitig in `config.php`
- Pro `fret`-Instanz: nur Computer-UUID + Anzeigename (nicht geheim)
- `GET /schools/{schoolId}/computers/{computerId}/Players` → aktueller Song
- `player1.songs[]`, `position: 0` = aktuell; vollständige Doku: `FRET_API.json`
