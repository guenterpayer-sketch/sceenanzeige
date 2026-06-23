# Projektdokumentation: Tanzschule Monitor-System

> Diese Datei ist die vollständige Konzeptgrundlage für die Programmierung.
> Bitte zu Beginn eines neuen Chats hochladen mit dem Hinweis, an welchem
> Schritt aus dem Bauplan (siehe unten) gerade gearbeitet werden soll.

> **🛑 Arbeitsanweisung an Claude:** Erst nachdenken/vorschlagen/Rückfragen
> stellen — **keine Datei erstellen, ändern oder Code schreiben, bevor der
> Nutzer explizit "GO" sagt.** Das gilt auch für diese Dokumentation selbst.
> Reine Lese-/Prüf-Schritte (Dateien ansehen, Rückfragen stellen) sind ohne
> GO erlaubt; jedes Schreiben/Ausführen braucht vorher ein GO.

> **⚠️ Status-Hinweis:** Diese Datei beschreibt das **Konzept**, nicht den
> aktuellen Umsetzungsstand. Schritte 1–3 aus dem Bauplan (Abschnitt 13)
> sind bereits live umgesetzt und getestet. Für den genauen Stand
> zusätzlich hochladen:
> - `Chat_Zusammenfassung_Schritt1-2.md` (DB-Schema, Hosting, CORS-Details)
> - `Schritt3_FINAL_fuer_Projektdateien.txt` (Modul-Registry, Module
>   `uhrzeit` + `bild`, Code-Stand)
>
> Diese Hauptdoku wird in diesem Überarbeitungsschritt um mehrere
> Konzept-Korrekturen ergänzt (siehe Abschnitt 16 "Änderungsprotokoll"),
> die in den bisherigen Schritt-1–3-Dateien **noch nicht** berücksichtigt
> sind — das betrifft v.a. das `ankuendigung`-Modul, das generische
> `gueltig_bis`/`aktiv`-Feld und die Mediathek-Konzeption, die erst in
> Schritt 4/5 umgesetzt werden.

---

## 1. Übersicht

Digitales Monitor-System für eine Tanzschule mit zentralem Backend und
saalspezifischen Monitor-Frontends. Ersetzt die bisherige Lösung (Redirect
auf eine Nimbuscloud-eigene Monitor-URL), die zu unflexibel war (kein
Zeitmanagement, keine eigenen Bilder, keine Songanzeige/Ticker).

**Hosting:** all-inkl (PHP 8 + MySQL). Kein Node.js, kein Docker, keine
serverseitigen Laufzeitumgebungen außer PHP.

### 1.1 Was dieses Projekt baut (Zieldefinition)

Gebaut wird ein **digitales Monitor-/Signage-System für die Tanzschule**, das
die bisherige unflexible Lösung (Redirect auf eine fremde Monitor-URL)
ersetzt. Konkret:

- **Zentrales Backend** (`screen.tcpayer.de`): Oberfläche, in der das Personal
  die Inhalte pflegt (Bilder, Ankündigungen, Stundenplan-/FRET-Einstellungen,
  Playlists, Ticker, Saal-Zuweisung).
- **Schlanke Saal-Monitore** (`saal1/2/3.tcpayer.de`): laufen im
  Vollbild-Kiosk-Modus auf je einem Bildschirm, holen ihre Inhalte vom
  Backend und kennen nur ihre eigene `SAAL_ID`.
- **Inhalte als wiederverwendbare Module**: `uhrzeit`, `bild`, `ankuendigung`,
  `stundenplan`, `fret` — angelegt als benannte „Modul-Instanzen" in einer
  Bibliothek und beliebig in Playlists einsetzbar.
- **Playlists** ordnen Module in **Spalten-Layouts** an, mit **Zeitregeln**
  (welche Playlist läuft wann), **Saal-Zuweisung** und Priorität bei
  Überschneidung. Ein **Ticker-Footer** läuft als eigenständiges System
  parallel.
- **Zielnutzen**: eigenes Zeitmanagement, eigene Bilder/Ankündigungen,
  Live-Songanzeige je Saal (FRET) und Stundenplan (Nimbuscloud) — alles
  flexibel pro Saal und Uhrzeit steuerbar.

**Zwei klar getrennte Datenquellen** (siehe auch Abschnitt 9 und 14):

| System | Was | Modul | Braucht |
|---|---|---|---|
| **FRET** | Musiksoftware; je Saal ein PC (eigene Computer-UUID) mit je 2 Playern. Zeigt, welche Musik gerade in welchem Saal läuft (z. B. Tanzabend). | `fret` | `schoolId` (Account-Zuordnung) + Computer-UUID |
| **Nimbuscloud (NC)** | Tanzschulverwaltung; liefert den Stundenplan (welcher Unterricht in welchem Saal). | `stundenplan` | nur den Stundenplan-API-Key, **keine** schoolId |

Es sind **vollständig voneinander unabhängige Systeme** — daher auch im Code
getrennte Proxys (`proxies/fret.php`, `proxies/nc.php`) und getrennte
Zugangsdaten.

**Zielauflösung der Monitore:** **Full-HD (1920×1080)** — Designvorgabe für
alle Modul-Layouts/Schriftgrößen (siehe Abschnitt 6, „Layout-Katalog").

---

## 2. Subdomains & Hosting-Struktur

```
screen.tcpayer.de    → Backend / Admin
saal1.tcpayer.de     → Monitor Saal 1
saal2.tcpayer.de     → Monitor Saal 2
saal3.tcpayer.de     → Monitor Saal 3  (beliebig erweiterbar)
```

- Jede Subdomain bekommt im all-inkl KAS einen **eigenen Ordner**, Ordnername
  identisch zur Subdomain.
- Die Saal-Subdomains sind **eigenständige, schlanke Frontends** (kein
  Redirect) — sie holen ihre Daten per URL von `screen.`.
- Bilder werden zentral über `screen.tcpayer.de/uploads/` ausgeliefert,
  damit sie nicht in jeden Saal-Ordner kopiert werden müssen.
- Jedes Saal-Frontend kennt nur seine eigene `SAAL_ID`.

---

## 3. Technologie-Stack

| Schicht | Technologie |
|---|---|
| Backend | PHP 8 + MySQL |
| NC-Proxy | PHP (Nimbuscloud API-Key bleibt serverseitig) |
| FRET-Proxy | PHP (FRET schoolId bleibt serverseitig, CORS-Schutz) |
| Monitor-Frontend | HTML + Vanilla JS, Vollbild/Kiosk-Modus |
| Live-Vorschau | iFrame im Backend, simuliert den Monitor in Echtzeit |
| Hosting | all-inkl, je Subdomain ein Ordner |

---

## 4. Architektur-Prinzip: Plugin-/Modul-System (wichtig für Vibe-Coding)

**Zentrale Anforderung:** Das System muss so gebaut sein, dass neue
Inhaltstypen oder Layouts per "Vibe Coding" ergänzt werden können, **ohne**
bestehenden Code anfassen zu müssen. Deshalb: striktes Plugin-Pattern.

```
screen.tcpayer.de/
├── modules/
│   ├── registry.php          ← zentrale Liste aller Inhalts-Module
│   ├── bild/
│   │   ├── module.json       ← Metadaten + Einstellungsfelder
│   │   ├── backend.php       ← Formular im Backend
│   │   ├── proxy.php         ← Datenabruf serverseitig (falls nötig)
│   │   └── frontend.js       ← Darstellung am Monitor
│   ├── stundenplan/
│   ├── ankuendigung/
│   ├── uhrzeit/
│   └── fret/                  ← Songanzeige aus der Musiksoftware FRET
│   (community/ aktuell NICHT angelegt — siehe Abschnitt 9 "Community-Modul")
├── layouts/
│   ├── registry.php          ← zentrale Liste aller Layouts
│   ├── 1-spaltig/
│   │   ├── layout.json
│   │   └── template.html     ← CSS Grid Definition
│   ├── 2-spaltig-60-40/
│   ├── 2-spaltig-50-50/
│   └── 3-spaltig-gleich/
├── ticker-modules/            ← falls Ticker später auch modular wird (aktuell nicht nötig, siehe Abschnitt 7)
├── proxies/
│   ├── nc.php                 ← Nimbuscloud API-Proxy (Stundenplan)
│   └── fret.php               ← FRET-API Proxy (Songanzeige, schoolId serverseitig)
└── uploads/                    ← zentrale Bild-Bibliothek
```

**Erweiterungsregel:**

| Was | Wie erweitern |
|---|---|
| Neuer Inhaltstyp | Neuen Ordner in `/modules/` anlegen + Eintrag in `registry.php` |
| Neues Layout | Neuen Ordner in `/layouts/` anlegen + Eintrag in `registry.php` |
| Neue Moduleinstellung | Nur `module.json` anpassen |

**`module.json` Beispiel:**
```json
{
  "id": "stundenplan",
  "label": "Stundenplan",
  "icon": "calendar",
  "has_proxy": true,
  "settings": [
    { "key": "anzahl_kurse", "type": "number", "label": "Anzahl Kurse", "default": 5 },
    { "key": "nur_heute",    "type": "bool",   "label": "Nur heute",    "default": true }
  ]
}
```
Das Backend liest `module.json` und generiert das Einstellungsformular
**automatisch** — kein manuelles PHP-Formular pro Modul nötig.

---

## 5. Drei-Ebenen-Modell für Inhalte

Dies ist das zentrale Datenmodell-Konzept und unterscheidet zwischen:

```
Modul-Typ (Code/Plugin)
  z.B. "Bild-Modul" – definiert WIE etwas funktioniert (generischer Code)
  ↓
Modul-Instanz (Bibliothek)
  z.B. "Veranstaltung" (eigene Bilder: a.jpg, b.jpg)
  z.B. "Workshoptermine" (eigene Bilder: c.jpg, d.jpg)
  → wiederverwendbarer, benannter Baustein mit eigenen Inhalten/Einstellungen
  ↓
Playlist-Eintrag
  Playlist "Abend" → Spalte 1 → Modul-Instanz "Veranstaltung"
  Playlist "Vormittag" → Spalte 1 → dieselbe Instanz "Veranstaltung"
  (Änderungen an der Instanz wirken sich überall aus, wo sie verwendet wird)
```

**Wichtig:** Der Nutzer legt in der Bibliothek z.B. mehrere Bild-Modul-
Instanzen mit eigenem Namen an ("Veranstaltung", "Workshoptermine") und
diese können beliebig oft in verschiedene Playlists gesteckt werden.

### Generische Zusatzfelder auf Eintrags- und Instanz-Ebene

Zwei Felder gelten **für alle Modultypen mit mehreren Einträgen**
(`bild`, `ankuendigung`, künftige Module) und sind deshalb generisch im
Datenmodell verankert, nicht pro Modultyp einzeln:

| Feld | Ebene | Bedeutung |
|---|---|---|
| `aktiv` | Modul-Instanz **und** einzelner Eintrag | Pausiert die ganze Instanz bzw. nur einen einzelnen Eintrag, ohne zu löschen. Standard: `true` |
| `gueltig_bis` | einzelner Eintrag | Konkretes Kalenderdatum, ab dem der Eintrag automatisch nicht mehr angezeigt wird. `NULL` = läuft unbegrenzt, bis manuell deaktiviert/gelöscht |

**Wichtige Abgrenzung zu den Playlist-Zeitregeln:** `gueltig_bis` ist ein
**Datum** (z.B. "bis 15. Juli"), während `playlist_zeitregeln` nur
**wiederkehrende Wochentag/Uhrzeit-Muster** kennen (z.B. "Mo–Fr ab 18 Uhr")
und kein Kalenderdatum abbilden können. Beide Mechanismen wirken
nacheinander:

```
Playlist-Zeitregel:   Läuft diese Playlist JETZT (Wochentag/Uhrzeit)?
       ↓ (falls ja)
Eintrags-Status:      Ist der Eintrag aktiv UND nicht abgelaufen (gueltig_bis)?
       ↓ (falls ja)
→ Eintrag wird angezeigt
```

### Mediathek statt Pro-Instanz-Upload

**Ausgangslage (Stand Schritt 3):** Das Bild-Modul speicherte hochgeladene
Bilder direkt als Dateiname in `modul_instanz_inhalte`. Jeder Upload erzeugte
eine neue Datei in `uploads/`, auch wenn exakt dasselbe Bild schon einmal
hochgeladen wurde — kein Wiederverwenden möglich.

**Ab Schritt 5 gilt statt dessen:** Bilder werden zentral in einer eigenen
Tabelle `mediathek` verwaltet, unabhängig von einzelnen Bild-Modul-Instanzen.
`modul_instanz_inhalte` verweist per `mediathek_id` auf den Eintrag, statt
selbst einen Dateinamen zu tragen.

**Vorteil:** Ein Bild (z.B. das Logo) kann ohne erneuten Upload in mehreren
Bild-Modul-Instanzen verwendet werden (z.B. in "Begrüßung" UND "Pause").

**Weitere Bestandteile des Mediathek-Konzepts:**

| Punkt | Beschreibung |
|---|---|
| Duplikat-Erkennung | SHA-256-Hash des Dateiinhalts beim Upload; existiert der Hash bereits, wird der vorhandene `mediathek`-Eintrag wiederverwendet statt erneut hochzuladen |
| Drag&Drop + Sammel-Upload | Drop-Zone im Bibliotheks-Bereich statt Standard-Dateidialog, mit Vorschau-Thumbnails *vor* dem endgültigen Speichern (Bilder können vor dem Speichern wieder entfernt werden) |
| Mediathek-Übersicht | Eigener Reiter "Mediathek" in der Bibliothek (siehe Abschnitt 11); zeigt alle hochgeladenen Bilder als Galerie. Eine neue Bild-Modul-Instanz kann sowohl "neu hochladen" als auch "aus Mediathek auswählen" anbieten |

**Bereits umgesetzt (Schritt 3, Nachbesserung, unabhängig von der
Mediathek):** Echtes Crossfade beim Bild-Modul — zwei übereinanderliegende
Bild-Layer kreuzen sich beim Wechsel, statt erst auszublenden und dann das
neue Bild zu laden (`modules/bild/frontend.js` + `tm-bild-stage`,
`tm-bild-layer-a/b`).

---

## 6. Playlists (Hauptfläche)

### Konzept
Eine Playlist ist der Rahmen für die Hauptfläche eines Monitors:
- Hat ein **Layout** (1–3 Spalten, Spaltenbreiten frei wählbar, z.B. 60/40)
- Hat ein **Standard-Layout**, einzelne Modul-Instanzen können davon
  abweichen (Layout-Override)
- Jede Spalte kann **mehrere Modul-Instanzen** enthalten, die unabhängig
  voneinander rotieren
- Hat **Zeitregeln** (Wochentag + von/bis Uhrzeit), mehrere Zeitregeln pro
  Playlist möglich
- Wird **einem oder mehreren Sälen** zugewiesen (saalübergreifend
  wiederverwendbar, z.B. eine "Willkommen"-Playlist für alle Säle)
- Bei zeitlicher Überschneidung mehrerer Playlists im selben Saal:
  **höchste Priorität gewinnt** (Prioritätsfeld pro Zeitregel)

### Backend-Ablauf
```
Klick "Neue Playlist"
  ↓
Name eingeben
  ↓
Layout wählen (1/2/3-spaltig, Breiten)
  ↓
Pro Spalte: vorhandene Modul-Instanzen aus der Bibliothek hinzufügen
  (oder neue Modul-Instanz direkt anlegen)
  ↓
Zeitregeln (Wochentage + Uhrzeit + Priorität) festlegen
  ↓
Säle zuweisen
  ↓
Fertig — Live-Vorschau verfügbar
```

### Verfügbare Inhalts-Module (initial)

| Modul-ID | Beschreibung |
|---|---|
| `bild` | Manuell hochgeladene Bilder, mehrere Einträge pro Instanz, rotierend, je Eintrag optional `gueltig_bis` |
| `ankuendigung` | Mehrere Einträge pro Instanz, je Eintrag: Text + optionales Bild, eigene Anzeigedauer, eigenes `gueltig_bis` |
| `stundenplan` | Dynamisch aus Nimbuscloud per Legacy-API `/timetable/data` (kein SQL, siehe Abschnitt 9) |
| `uhrzeit` | Live Uhrzeit + Datum |
| `fret` | Aktuell laufender Song je Saal aus der Musiksoftware FRET, Polling über `proxies/fret.php` (siehe Abschnitt 14) |

> **Hinweis Umbenennung:** Das frühere `song`-Modul heißt jetzt **`fret`**
> (nach der Musiksoftware), inkl. Ordner `modules/fret/` und Proxy
> `proxies/fret.php`. Frühere Erwähnungen von „song" meinen dasselbe Modul.

**Zurückgestellt:** `community` (Community-Feed aus Nimbuscloud) ist
**nicht** im aktiven Bauplan. Grund und Details siehe Abschnitt 9
"Community-Modul (zurückgestellt)". Dank Plugin-Architektur kann es
jederzeit später als zusätzlicher Ordner in `/modules/` ergänzt werden,
ohne bestehenden Code anzufassen.

### Fixe Elemente (außerhalb der Spalten)
- **Header:** Uhrzeit/Datum — optional ein-/ausblendbar pro Playlist
- **Footer:** Ticker (siehe Abschnitt 7) — komplett unabhängig von der
  Playlist-Logik

### Layout-Katalog & Zielauflösung

**Zielauflösung:** Alle Monitore laufen in **Full-HD (1920×1080, Querformat)**.
Das ist die verbindliche Designgrundlage für Modul-Layouts und Schriftgrößen
(TV-Großbild-optimiert, große Schrift; Richtwerte aus der FRET-Vorlage:
Song-Titel ~40px, Tanz-Badges ~40px, Playlist-Einträge ~28px — bei der
konkreten Umsetzung je Modul anpassbar).

**Verfügbare Layouts** (registriert in `layouts/registry.php`; die konkreten
`layout.json` + `template.html` mit der CSS-Grid-Definition werden in
Schritt 6 gebaut):

| Layout-ID | Spalten | Standard-Breiten |
|---|---|---|
| `1-spaltig` | 1 | 100 % |
| `2-spaltig-60-40` | 2 | 60 / 40 % |
| `2-spaltig-50-50` | 2 | 50 / 50 % |
| `3-spaltig-gleich` | 3 | je ~33 % |

**Wichtig — was Doku vs. was Konfiguration ist:**
- Die **Layout-Mechanik** (1–3 Spalten, Breiten frei in % wählbar,
  Header/Footer-Schalter, `layout_override` pro Modul-Instanz) ist hier
  konzeptionell festgeschrieben.
- Die **konkrete Spaltenbreite je Playlist** (z. B. „Spalte 1 = 60 %") ist
  **Laufzeit-Konfiguration** in `playlist_layout` und wird **beim Anlegen
  jeder Playlist** im Backend gesetzt — sie steht bewusst **nicht** als feste
  Zahl in dieser Doku.

---

## 7. Ticker (Footer) — bewusst eigenständiges System

**Wichtige Architektur-Entscheidung:** Der Ticker ist **kein Modul** und
**kein Bestandteil** des Playlist-Layouts, sondern ein eigenständiges,
paralleles System. Grund: Mehrere Ticker-Playlists müssen sich bei
Zeitüberschneidung **mischen** können (siehe unten) — das würde im
Modul/Playlist-Modell unnötig komplex werden, da dort bei Überschneidung
Priorität statt Mischung gilt.

### Konzept
- Eigene **Ticker-Playlists** (analog zu Playlists, aber simpler):
  Name, Text-Einträge (mit Reihenfolge + Anzeigedauer), Zeitregeln,
  Saal-Zuweisung
- Inhalt: **nur manuell erfasster Text** (kein Song, kein Community-Feed —
  bewusst einfach gehalten)
- Läuft automatisch im Footer, sobald eine Zeitregel aktiv ist — keine
  Platzierung in einer Playlist nötig

### Verhalten bei Zeitüberschneidung
**Anders als bei Haupt-Playlists:** Wenn mehrere Ticker-Playlists zeitgleich
aktiv sind, werden **alle ihre Texteinträge zusammengeführt und gemischt
nacheinander durchlaufen** — es gewinnt nicht einer per Priorität. Ein
Prioritätsfeld ist beim Ticker daher **nicht** nötig.

### Backend-Ablauf
```
Menüpunkt "Ticker" → Klick "Neuer Ticker"
  ↓
Name eingeben
  ↓
Texteinträge hinzufügen (Reihenfolge + Anzeigedauer)
  ↓
Zeitregeln (Wochentage + Uhrzeit) festlegen
  ↓
Säle zuweisen
  ↓
Fertig
```

---

## 8. Datenbankstruktur (vollständig)

> **Hinweis (Konzept-Update, siehe Abschnitt 16c):** Das Datenmodell ist auf
> ein **monitor-zentrisches** Modell umgestellt. `saele`→`monitore`,
> `saal_id`→`monitor_id`; `playlist_zeitregeln` + `playlist_saele` sind durch
> **`monitor_zeitplan`** ersetzt (Zeitplanung je Monitor). Die folgende
> Struktur ist bereits aktualisiert.

```sql
-- Monitore (je Monitor eine Subdomain; früher "Säle")
monitore
  id, name, subdomain

-- Einstellungen pro Monitor
einstellungen
  monitor_id,
  nc_api_key_stundenplan,   -- Stundenplan-Key (Legacy-API /timetable/data, niedrigere Sensibilität)
  nc_api_key_stammdaten,    -- Stammdaten-Key (für community-Modul, NICHT aktiv genutzt, siehe Abschnitt 9)
  song_api_url              -- (optional) FRET-API Basis-URL pro Saal; die
                            -- FRET-Basis-URL + schoolId liegen primär in
                            -- config.php (FRET_API_BASE/FRET_SCHOOL_ID),
                            -- siehe Abschnitt 14

-- Modul-Instanzen (Bibliothek, wiederverwendbare Bausteine)
modul_instanzen
  id, modul_typ,        -- z.B. "bild", "stundenplan", "ankuendigung"
  name,                 -- z.B. "Veranstaltung", "Workshoptermine"
  einstellungen,        -- JSON, modul-spezifisch
  aktiv                 -- bool, Standard true; pausiert die GESAMTE Instanz

-- Mediathek (zentrale Bild-Verwaltung, siehe Abschnitt 5 "Mediathek statt Pro-Instanz-Upload")
mediathek
  id,
  dateiname,            -- tatsächlicher Dateiname in uploads/
  original_name,        -- ursprünglicher Upload-Name, für die Anzeige
  datei_hash,           -- SHA-256 des Dateiinhalts, für Duplikat-Erkennung (UNIQUE)
  breite, hoehe,         -- Bildmaße in Pixel
  hochgeladen_am

-- Unter-Inhalte einer Modul-Instanz (z.B. einzelne Bilder oder Ankündigungstexte)
modul_instanz_inhalte
  id, modul_instanz_id,
  mediathek_id,         -- FK auf mediathek.id (ersetzt direktes dateiname-Feld)
  text,                 -- optional, je nach Modultyp (z.B. Ankündigungstext)
  reihenfolge, dauer_sek,
  gueltig_bis,          -- NULL = unbegrenzt; Datum, ab dem der Eintrag ausläuft
  aktiv                 -- bool, Standard true; pausiert NUR diesen einen Eintrag
  -- nur relevant für Module mit mehreren Unter-Inhalten (Bild, Ankündigung)
  -- bei dynamischen Modulen (Stundenplan, Uhrzeit, FRET) leer/nicht nötig

-- Playlists (Hauptfläche)
playlists
  id, name, aktiv

playlist_layout
  id, playlist_id,
  spalten_anzahl,        -- 1, 2 oder 3
  spalte1_breite,        -- z.B. 60 (Prozent)
  spalte2_breite,        -- z.B. 40
  spalte3_breite,
  header_uhrzeit,        -- bool: Uhrzeit/Datum oben?
  footer_ticker          -- bool: Ticker-Footer aktiv?

-- Monitor-Zeitplan (monitor-zentrisch): welche Playlist läuft wann auf welchem
-- Monitor. Ersetzt die früheren playlist_zeitregeln + playlist_saele.
monitor_zeitplan
  id, monitor_id, playlist_id,
  wochentage,
  von_uhrzeit, bis_uhrzeit,  -- NULL/leer = keine Uhrzeitgrenze (läuft dauerhaft, Fallback)
  prioritaet            -- höherer Wert gewinnt; Einträge OHNE Uhrzeit werden von
                        -- Einträgen MIT Uhrzeit immer überschrieben (Fallback)

-- Playlist-Inhalte: Verweis auf Modul-Instanzen, gruppiert nach Spalte
playlist_spalten_inhalte
  id, playlist_id, spalte,      -- 1, 2 oder 3
  reihenfolge,
  modul_instanz_id,             -- Verweis auf modul_instanzen.id
  layout_override               -- NULL = Standard-Layout der Playlist gilt

-- Ticker (eigenständiges Footer-System, siehe Abschnitt 7)
ticker_playlists
  id, name, aktiv

ticker_eintraege
  id, ticker_playlist_id, text, reihenfolge, dauer_sek

ticker_zeitregeln
  id, ticker_playlist_id, wochentage, von_uhrzeit, bis_uhrzeit
  -- KEIN Prioritätsfeld nötig, da Ticker bei Überschneidung gemischt werden

ticker_playlist_saele
  ticker_playlist_id, monitor_id   -- (Ticker-Bereich erst Schritt 8)
```

---

## 9. Nimbuscloud-Anbindung

### Zwei getrennte API-Keys

Bei der Nimbuscloud sind **zwei separate API-Keys** mit Lesezugriff
eingerichtet, die unterschiedlich sensibel sind:

| API-Key | Zweck | Sensibilität |
|---|---|---|
| **Stundenplan-Key** | `stundenplan`-Modul, Legacy-API `/timetable/data` | Niedriger — reine Kursdaten |
| **Stammdaten-Key** | `community`-Modul (Community-Feed) | **Höher** — kann datenschutzrelevante Personendaten betreffen |

**Wichtig für die Umsetzung:** Beide Keys werden getrennt in
`einstellungen` gespeichert und **nie gemeinsam** im selben Proxy verwendet.
Aktuell wird im aktiven Bauplan **nur der Stundenplan-Key** genutzt (siehe
unten, "Community-Modul (zurückgestellt)").

### Stundenplan — aktiver Endpunkt (Legacy-API)

**Hinweis Status-Wechsel:** Der ursprünglich geplante Weg über
`POST /v2/system/direct-db-access/execute-query` (aktuelle API) ist
**verworfen**. Die dafür nötige Berechtigung
`System_ApiKey_DirectDatabaseAccess` ist nicht im vorhandenen API-Zugriff
enthalten (Aufruf scheiterte mit `401 core_not_authorized`) und wird laut
Nimbuscloud nur auf Sonderanfrage beim Support vergeben.

**Lösung:** Die **Legacy-API** bietet einen fertigen, bereits
strukturierten Endpunkt für Stundenplandaten — ganz ohne SQL und ohne
Sonderberechtigung.

```
POST /timetable/data
```

- **Basis-URL:** `https://xyz.nimbuscloud.at/api/json/v1` (Platzhalter,
  tatsächliche Subdomain einsetzen, z.B.
  `https://tanzcenter-payer.nimbuscloud.at/api/json/v1`)
- **Benötigte Berechtigung:** `Stundenplan — Lesezugriff` (normale, frei
  erstellbare Berechtigung, keine Sonderfreischaltung nötig)
- **Auth-Mechanismus (abweichend von der aktuellen API!):** Der API-Key
  wird als **POST-Formular-Parameter** `apikey` übergeben, **nicht** im
  Header `X-API-Key`
- **Antwortformat:** JSON-Objekt mit `content` (Ergebnis) und `statuscode`
- Der API-Key wird weiterhin **ausschließlich serverseitig** im PHP-Proxy
  (`proxies/nc.php`) verwendet und niemals ans Frontend übertragen

**Wichtige Parameter:** `date` (UNIX-Timestamp, Mitternacht), `days`
(Anzahl Tage). Vollständige Parameterliste, Rückgabefelder (u.a.
`events[].displayName`, `course_key`, `start_date`/`end_date`, `room`,
`teacher`, `type`, `level`) sowie ein PHP/GuzzleHTTP-Codebeispiel stehen
in `NC_Legacy_API_Stundenplan.md` — diese Datei muss beim konkreten Bau
des `stundenplan`-Moduls (Schritt 4) mit hochgeladen werden.

**Auswirkung auf `proxies/nc.php`:** Der Proxy muss als POST mit
Form-Parametern (statt JSON-Body + Header) implementiert werden — Details
ebenfalls in `NC_Legacy_API_Stundenplan.md` (Vergleichstabelle alt/neu).
Der Mechanismus zum Laden der modulspezifischen Einstellungen
(`anzahl_kurse`, `nur_heute` aus der Modul-Instanz) bleibt unverändert.

**Sonstige evtl. nützliche Legacy-Endpunkte** (gleicher Bereich, ebenfalls
in `NC_Legacy_API_Stundenplan.md` dokumentiert): `POST /data/teacher`,
`POST /data/locations`, `POST /timetable/unit-wiki-types`.

### Community-Modul (zurückgestellt)

Das `community`-Modul (Feed-Anzeige über `GET /v2/community/api-feed/api-posts`)
ist **bewusst nicht** im aktiven Bauplan enthalten:

- Es würde den **Stammdaten-Key** benötigen, der datenschutzrelevantere
  Daten zugänglich macht als der Stundenplan-Key.
- Solange der Nutzen (Anzeige des Community-Feeds auf dem Monitor) den
  zusätzlichen Datenschutz-Aufwand/das Risiko nicht eindeutig überwiegt,
  wird der Stammdaten-Key **nicht** im System hinterlegt.
- Dank der Plugin-Architektur (Abschnitt 4) ist dies **jederzeit
  nachrüstbar**: ein neuer Ordner `/modules/community/` plus Eintrag in
  `registry.php`, ohne bestehenden Code zu verändern.
- Bis zur Entscheidung: kein Stammdaten-Key im System, keine
  Datenbank-Vorbereitung für Community-Inhalte nötig.

Vollständige API-Dokumentation (inkl. Swagger/OpenAPI-Referenz) liegt vor
und kann bei Bedarf erneut bereitgestellt werden.

---

## 10. Monitor-Frontend (pro Saal)

- Läuft im **Vollbild-Kiosk-Modus**
- Kennt nur seine eigene `SAAL_ID` (hartkodiert in der jeweiligen
  `index.html` des Saal-Ordners)
- Holt aktive Playlist(s) und Daten von `screen.tcpayer.de/proxies/`
  bzw. den entsprechenden Backend-Endpunkten
- Prüft Zeitregeln **clientseitig** bei jedem Refresh
- Auto-Refresh der Haupt-Daten alle ~60 Sekunden
- FRET-Polling alle 5–10 Sekunden (falls `fret`-Modul aktiv)
- Ticker läuft unabhängig als Lauftext im Footer (eigener Datenabruf)

### Monitor-Logik (Ablauf bei jedem Refresh)
```
1. Welche Playlists sind für diesen Saal zugewiesen?
2. Zeitregeln prüfen (Wochentag + Uhrzeit) → welche Playlist gilt gerade?
3. Bei Überschneidung mehrerer Playlists → höchste Priorität gewinnt
4. Layout der aktiven Playlist rendern (Spaltenanzahl + Breiten)
5. Pro Spalte: zugewiesene Modul-Instanzen laden, unabhängig rotieren
6. Pro Modul-Instanz: Standard-Layout oder layout_override anwenden
7. Header (Uhrzeit) einblenden, falls aktiviert
8. Footer (Ticker) unabhängig prüfen:
   a. Alle aktiven Ticker-Playlists für diesen Saal sammeln
      (Zeitregel-Check, KEINE Priorität)
   b. Texteinträge aller aktiven Ticker zusammenführen
   c. Gemischt im Footer durchlaufen lassen
9. Nach Ablauf eines Eintrags/einer Playlist → Zyklus erneut prüfen
```

---

## 11. Backend-Bereiche (`screen.tcpayer.de`)

| Bereich | Funktion |
|---|---|
| **Bibliothek** | Modul-Instanzen anlegen/verwalten (Bilder hochladen, Ankündigungstexte, Stundenplan-Einstellungen, etc.) |
| **Playlists** | Anlegen, Layout konfigurieren, Spalten mit Modul-Instanzen befüllen (nur Inhalt/Layout — Zeitplanung liegt beim Monitor, siehe Abschnitt 16c) |
| **Ticker** | Eigener Bereich: Ticker-Playlists anlegen, Texte verwalten, Zeitregeln + Monitor-Zuweisung |
| **Monitore** | Monitore anlegen (Name, Subdomain) und je Monitor den **Zeitplan** pflegen (welche Playlist wann läuft, mit Priorität). FRET-schoolId liegt zentral in `config.php` |
| **Live-Vorschau** | iFrame, das einen gewählten Monitor in Echtzeit simuliert |
| **Einstellungen** | NC-Verbindung testen, allgemeine Systemeinstellungen |

---

## 12. Offene/geklärte Designentscheidungen (Kurzreferenz)

- ✅ Saal-Subdomains sind **eigenständige Frontends**, kein Redirect auf
  `screen.` (sauberere URLs im Kiosk-Modus)
- ✅ Bilder werden zentral über `screen.tcpayer.de/uploads/` ausgeliefert
- ✅ Spaltenbreiten: frei wählbar (nicht nur Gleichverteilung)
- ✅ Eine Spalte kann mehrere Modul-Instanzen enthalten (Stapel/Rotation)
- ✅ Modul-Instanzen sind wiederverwendbare, benannte Bausteine in der
  Bibliothek (nicht direkt in der Playlist erstellt)
- ✅ Ticker ist bewusst **kein Modul**, sondern eigenständiges System
  (Begründung: Misch-Verhalten bei Überschneidung wäre im Modul-Modell
  unnötig komplex)
- ✅ Ticker-Inhalt: nur Text (kein Song, kein Community-Feed)
- ✅ Bei Playlist-Überschneidung: **Priorität** entscheidet
- ✅ Bei Ticker-Überschneidung: **Mischung**, keine Priorität
- ✅ Stundenplan über Legacy-API `/timetable/data`, **keine** Kundendaten/
  Kursbuchungen über die API (Direct-DB-Access-Ansatz verworfen, siehe
  Abschnitt 9 und Änderungsprotokoll)
- ✅ Layout hängt an der **Playlist**, nicht am Saal — zeigen zwei Säle
  dieselbe Playlist, zeigen sie automatisch dasselbe Layout; wechselt eine
  Playlist (z.B. durch Zeitregel), wechselt das Layout mit
- ✅ Playlist-Wechsel ist aktuell ein **harter Wechsel** beim nächsten
  Refresh (~60 Sek.), kein sanfter Übergang — als spätere, unkritische
  Verfeinerung vorgemerkt (siehe Abschnitt 16, Punkt 7)
- ✅ `aktiv`-Flag auf Modul-Instanz- **und** Eintrags-Ebene
- ✅ `gueltig_bis` generisch auf Eintrags-Ebene (nicht nur `ankuendigung`)
- ✅ Zwei getrennte Nimbuscloud-API-Keys (Stundenplan/Stammdaten),
  `community`-Modul aktuell zurückgestellt

---

## 13. Bauplan (empfohlene Reihenfolge, ggf. je ein eigener Chat)

| Schritt | Inhalt | Status |
|---|---|---|
| 1 | SQL-Script — alle Tabellen aus Abschnitt 8 anlegen | ✅ abgeschlossen, live getestet |
| 2 | Datei- und Ordnerstruktur auf all-inkl + `.htaccess` je Subdomain | ✅ abgeschlossen, live getestet |
| 3 | Modul-Registry-Grundgerüst + erste Referenz-Module (`uhrzeit`, `bild`) | ✅ abgeschlossen |
| 4 | Module `stundenplan`, `ankuendigung`, `fret` + NC-Proxy + FRET-Proxy (`community` zurückgestellt, siehe Abschnitt 9) | ▶️ aktuell, **am Anfang** (Grundgerüst der Module/Proxys steht im Repo, noch nicht live getestet) |
| 5 | Backend: Bibliothek (Modul-Instanzen verwalten, inkl. `aktiv`/`gueltig_bis` + Mediathek mit Duplikat-Erkennung) | offen |
| 6 | Backend: Playlist-Editor (Layout-Konfigurator + Spalten-Zuweisung) | offen |
| 7 | Backend: Zeitregeln + Saal-Zuweisung (Playlists) | offen |
| 8 | Backend: Ticker-Verwaltung (eigener Bereich) | offen |
| 9 | Monitor-Frontend (HTML/JS, Anzeige- und Zeitlogik gemäß Abschnitt 10) | offen |
| 10 | Live-Vorschau im Backend (iFrame-Simulation) | offen |
| 11 | Deployment-Guide für all-inkl | offen |

**Hinweis für neue Chats:** Beim Start eines neuen Chats diese Datei
hochladen und angeben, an welchem Schritt gerade gearbeitet wird. Falls in
einem vorherigen Schritt schon Code entstanden ist, diesen Code-Stand als
Datei mitgeben (nicht nur beschreiben).

---

## 14. Quelle: FRET-API (`fret`-Modul, Kurzreferenz)

**Kontext:** FRET ist die **Musiksoftware**. Je Saal läuft auf einem eigenen
PC eine FRET-Instanz (→ eine eigene **Computer-UUID** pro Saal), mit je
**2 Playern**. Die **`schoolId`** ordnet den API-Abruf dem Schul-Account zu.
Das `fret`-Modul zeigt am Monitor, **welche Musik gerade in welchem Saal**
läuft (z. B. bei einem Tanzabend für die Kunden). FRET ist **völlig
unabhängig** von Nimbuscloud (siehe Abschnitt 9/15).

- **Basis-URL:** `https://fret-api.azurewebsites.net/api/v1`
- **Relevante Endpunkte:**
  - `GET /schools/{schoolId}/Computers` — Liste der Computer/Säle
  - `GET /schools/{schoolId}/computers/{computerId}/Players` — aktueller
    Song-Status für einen Computer
- **Sicherheitshinweis (verbindlich):** Die FRET-API besitzt auch
  **schreibende** Endpunkte — die `schoolId` darf deshalb **niemals im
  Frontend/Browser sichtbar** sein. Zugriff ausschließlich über
  `proxies/fret.php` serverseitig.
- **Wo liegen welche Werte (wichtig, weicht von der ursprünglichen
  Formulierung ab):**
  - **`schoolId` + FRET-Basis-URL → serverseitig in `config.php`**
    (`FRET_SCHOOL_ID`, `FRET_API_BASE`). **Nicht** in den Modul-Instanz-
    Einstellungen, da diese ans Frontend serialisiert werden.
  - **Computer-UUID + optionaler Anzeigename → pro `fret`-Modul-Instanz**
    in deren Einstellungen. Die UUID selbst ist nicht geheim (steht ohnehin
    in der Saal-Auswahl).
- **Response-Struktur:** `Players`-Antwort enthält `player1` (relevant)
  und `player2` (intern, für die Anzeige irrelevant). Songs in
  `player1.songs[]`, `position: 0` = aktueller Song, `position > 0` =
  kommende Songs, `position < 0` = Verlauf.
- Vollständige OpenAPI-Referenz liegt vor (`FRET_API.json`).

> **Abgrenzung zu Nimbuscloud:** Das `stundenplan`-Modul (NC) braucht
> **keine** `schoolId` — nur den per-Saal `nc_api_key_stundenplan`. FRET und
> NC sind getrennte Systeme mit getrennten Proxys und Zugangsdaten.

---

## 15. Quelle: Nimbuscloud API (Kurzreferenz)

- Schnittstellen: aktuelle API (`/v2/...`) und Legacy-API, beide JSON-basiert
- **Aktiv genutzt wird die Legacy-API für den Stundenplan** — API-Key dort
  als POST-Parameter `apikey`, **nicht** im Header (Unterschied zur
  aktuellen API!). Details siehe Abschnitt 9 und `NC_Legacy_API_Stundenplan.md`
- Bei der aktuellen API (`/v2/...`) wird der Key im Header `X-API-Key`
  übergeben — relevant nur noch für das zurückgestellte `community`-Modul
- **Zwei getrennte Keys** im Einsatz/vorgesehen: Stundenplan-Key (aktiv
  genutzt, Legacy-API) und Stammdaten-Key (zurückgestellt, siehe Abschnitt 9)
- Aktuelle API: alle Daten in JSON unter dem Schlüssel `data` (außer
  Binär-Antworten); Zeitstempel lokal, Format PHP `DATE_ATOM`
  (z.B. `2005-08-15T15:52:01+00:00`); Pagination: `items`,
  `totalItemCount`, `pageStart` (Standard 0), `pageSize` (Standard 10, max. 1000)
- Aktiv genutzter Endpunkt: `POST /timetable/data` (Legacy-API,
  Berechtigung `Stundenplan — Lesezugriff`, vollständig dokumentiert in
  `NC_Legacy_API_Stundenplan.md`)
- **Verworfen:** `POST /v2/system/direct-db-access/execute-query` (aktuelle
  API) — benötigt die Sonderberechtigung
  `System_ApiKey_DirectDatabaseAccess`, die nicht ohne Support-Anfrage
  vergeben wird (siehe Abschnitt 9)
- Zurückgestellt: `GET /v2/community/api-feed/api-posts`
  (`community`-Modul, benötigt sensibleren Stammdaten-Key)

---

## 16. Änderungsprotokoll (dieser Überarbeitungs-Chat)

Ausgelöst durch Probleme beim Bau von Schritt 4. Folgende Konzept-Korrekturen
wurden in dieser Überarbeitung vorgenommen — **die Schritt-1–3-Dateien
spiegeln diese noch nicht wider**, sie betreffen v.a. Schritt 4 und 5:

1. **Zwei Nimbuscloud-API-Keys getrennt:** Stundenplan-Key (aktiv) und
   Stammdaten-Key (zurückgestellt) — vorher fälschlich als ein Key behandelt
2. **`community`-Modul aus aktivem Bauplan entfernt** — war Auslöser für die
   API-Key-Klärung; bleibt dank Plugin-Architektur jederzeit nachrüstbar
3. ~~Stundenplan-Datenfelder als offener Punkt markiert — `GET
   /v2/system/direct-db-access/schema` muss noch abgefragt werden~~
   **— ÜBERHOLT, siehe Abschnitt 16a unten: Direct-DB-Access-Ansatz wurde
   komplett verworfen, Schema-Abfrage damit hinfällig.**
4. **`ankuendigung`-Modul präzisiert** — war Ursache der Schritt-4-Probleme
   (Verwechslungsgefahr mit `bild`/Ticker): jetzt klar als "mehrere
   Text+Bild-Einträge pro Instanz, mit eigenem `gueltig_bis`" definiert
5. **`gueltig_bis` generalisiert** — liegt jetzt auf `modul_instanz_inhalte`
   für alle Modultypen mit mehreren Einträgen, nicht nur `ankuendigung`;
   klar abgegrenzt von den wiederkehrenden `playlist_zeitregeln`
   (Datum vs. Wochentag/Uhrzeit-Muster)
6. **`aktiv`-Flag ergänzt** — auf Modul-Instanz-Ebene UND Eintrags-Ebene,
   zum Pausieren ohne Löschen
7. **FRET-Song-API als eigene Kurzreferenz (Abschnitt 14)** ergänzt, basierend
   auf `Projektzusammenfassung_Song_Anzeige.md` und `FRET_API.json`
8. **Saal/Layout-Flexibilität dokumentiert** — Layout hängt an der Playlist,
   nicht am Saal; mehrere Säle können dieselbe oder unterschiedliche
   Playlists/Layouts haben, abhängig von Zuweisung und Zeitregeln
9. **Playlist-Wechsel-Verhalten (hart vs. sanft)** als spätere,
   unkritische Verfeinerung vermerkt — keine Entscheidung jetzt nötig,
   da rein im Monitor-Frontend gekapselt
10. **Arbeitsanweisung "GO vor Schreiben"** ganz oben in die Doku
    aufgenommen — verbindlich für alle künftigen Chats zu diesem Projekt
11. **Mediathek-Konzept (aus `Notiz_Schritt5_Mediathek.md`) integriert** —
    zentrale `mediathek`-Tabelle mit Duplikat-Erkennung per SHA-256-Hash,
    `modul_instanz_inhalte.dateiname` wird durch `mediathek_id` ersetzt;
    betrifft Abschnitt 5 (Konzept), Abschnitt 8 (DB-Struktur) und
    Abschnitt 11 (Backend-Bereich Bibliothek)

### 16a. Nachtrag — Stundenplan-Anbindung umgestellt (späterer Chat)

Ausgelöst durch einen gescheiterten API-Aufruf bei der Umsetzung von
Schritt 4:

12. **Direct-DB-Access-Ansatz für den Stundenplan komplett verworfen** —
    `POST /v2/system/direct-db-access/execute-query` (aktuelle API)
    scheiterte mit `401 core_not_authorized`, da die Berechtigung
    `System_ApiKey_DirectDatabaseAccess` nur auf Sonderanfrage beim
    Nimbuscloud-Support vergeben wird und nicht im vorhandenen API-Zugriff
    enthalten ist
13. **Ersetzt durch Legacy-API-Endpunkt `POST /timetable/data`** —
    benötigt nur die normale, frei erstellbare Berechtigung
    `Stundenplan — Lesezugriff`; liefert bereits strukturierte
    Kurs-/Kalendertermin-Daten, ganz ohne SQL
14. **Auth-Mechanismus weicht ab:** Legacy-API erwartet den API-Key als
    POST-Formular-Parameter `apikey`, nicht im Header `X-API-Key` wie die
    aktuelle API — wichtig für die Proxy-Implementierung
    (`proxies/nc.php`)
15. **Der "offene Punkt" zur Tabellenstruktur (Punkt 3 oben) ist hinfällig**
    — die Legacy-API liefert bereits dokumentierte, benannte Felder
    (`displayName`, `course_key`, `start_date`/`end_date`, `room`,
    `teacher`, `type`, `level`, u.a.), keine SQL-Schema-Abfrage mehr nötig
16. Vollständige Endpunkt-Doku (Parameter, Rückgabefelder, Codebeispiel,
    Vergleichstabelle alt/neu) ausgelagert in eigene Datei
    `NC_Legacy_API_Stundenplan.md` — beim Bau von Schritt 4 mit hochladen

### 16b. Nachtrag — Zieldefinition, FRET/NC-Klarstellung, `song`→`fret` (Claude-Code-Chat)

Erstellt im Zuge der Weiterarbeit in Claude Code (größeres Projekt besser
geeignet) und nach Abgleich mit dem bereits live liegenden Stand:

17. **Zieldefinition ergänzt** (Abschnitt 1.1) — knappe Beschreibung, *was*
    das Projekt baut und für wen, inkl. der Trennung FRET vs. Nimbuscloud.
18. **`song`-Modul in `fret` umbenannt** — Modul nach der Musiksoftware FRET
    benannt (`modules/fret/`, `proxies/fret.php`, Registry-Eintrag `fret`).
    Betrifft Abschnitte 3, 4, 6, 13, 14.
19. **`schoolId`-Ablage korrigiert** — entgegen der ursprünglichen
    Formulierung in Abschnitt 14 liegt die `schoolId` (sicherheitsrelevant)
    **serverseitig in `config.php`** (`FRET_SCHOOL_ID`), **nicht** in den
    Modul-Instanz-Einstellungen (die ans Frontend serialisiert werden). Pro
    Instanz nur Computer-UUID + Anzeigename. Nimbuscloud braucht **keine**
    schoolId.
20. **Zielauflösung festgelegt** — Monitore laufen in **Full-HD
    (1920×1080)**; Layout-Katalog + TV-Schriftgrößen-Richtwerte in
    Abschnitt 6 ergänzt. Klarstellung: konkrete Spaltenbreiten je Playlist
    sind Laufzeit-Konfiguration, keine Doku-Festlegung.
21. **Bauplan-Status präzisiert** — Schritt 4 steht **am Anfang** (Modul-/
    Proxy-Grundgerüst im Repo, noch nicht live getestet).

### 16c. Nachtrag — Umbau auf monitor-zentrisches Modell (Claude-Code-Chat)

Nach dem Live-Test von Schritt 7 entschieden: Die Zeitplanung war im
playlist-zentrischen Modell (Zeitregeln + Saal-Zuweisung **an der Playlist**)
umständlich und konnte „Playlist A werktags in Saal 1, am Wochenende in Saal 2"
gar nicht abbilden. Daher Umstellung auf ein **monitor-zentrisches** Modell.
Dieser Nachtrag **überschreibt** die playlist-zentrischen Formulierungen in
Abschnitt 6 (Zeitregeln/Saal-Zuweisung), 8, 10, 11 und 12.

22. **Begriff „Saal" → „Monitor"** — Tabelle `saele`→`monitore`, Spalte
    `saal_id`→`monitor_id` (auch in `einstellungen`, `ticker_playlist_saele`).
    Die Subdomains (`saal1` …) bleiben unverändert (nur Frontend-Ordner).
23. **Zeitplanung wandert von der Playlist auf den Monitor** — neue Tabelle
    **`monitor_zeitplan`** (`monitor_id`, `playlist_id`, `wochentage`,
    `von_uhrzeit`, `bis_uhrzeit`, `prioritaet`) **ersetzt** `playlist_zeitregeln`
    **und** `playlist_saele`. Bedienung: Bereich **Monitore** → Monitor wählen →
    „Zeitplan" (Playlist X läuft Mo–Fr 18–23 Uhr, Priorität N). Priorität löst
    Überschneidungen **je Monitor**.
24. **Playlist = nur Inhalt + Layout** — der Playlist-Editor enthält keine
    Zeitregeln/Saal-Zuweisung mehr.
25. **Migration** `06_migration_monitor_zeitplan.sql` (Rename + Drop der zwei
    alten Tabellen + neue `monitor_zeitplan`). `01_schema.sql` entsprechend
    aktualisiert.
26. **Auswirkung auf Schritt 9** — der Monitor ermittelt seine aktive Playlist
    künftig aus `monitor_zeitplan` (Wochentag/Uhrzeit + höchste Priorität).
    Empfehlung zur Monitor-Identifikation per Subdomain siehe
    `Notiz_Schritt9_Monitor-Frontend.md`.
27. **Ticker (Schritt 8)** soll denselben monitor-zentrischen Ansatz bekommen;
    `ticker_playlist_saele.saal_id` ist bereits auf `monitor_id` umgestellt,
    die endgültige Ticker-Zeit-/Monitor-Logik wird in Schritt 8 gebaut.

---

## 17. Hinweis für den nächsten Chat

Beim Fortsetzen von Schritt 4 zusätzlich zu dieser Datei hochladen:

- `Chat_Zusammenfassung_Schritt1-2.md`
- `Schritt3_FINAL_fuer_Projektdateien.txt`
- `01_schema.sql` (aktueller DB-Stand — **muss noch um die neuen Felder
  aus Abschnitt 16 ergänzt werden:** `aktiv` in `modul_instanzen`,
  `text`/`gueltig_bis`/`aktiv`/`mediathek_id` in `modul_instanz_inhalte`
  (statt `dateiname`), neue Tabelle `mediathek`,
  `nc_api_key_stundenplan`/`nc_api_key_stammdaten` statt `nc_api_key`
  in `einstellungen`)
- `Projektzusammenfassung_Song_Anzeige.md` (Vorlage für `song`-Modul)
- `FRET_API.json` (vollständige FRET-OpenAPI-Referenz)
- `Notiz_Schritt5_Mediathek.md` (Detail-Spezifikation Mediathek, bereits
  in Abschnitt 5 dieser Doku zusammengefasst)
- `NC_Legacy_API_Stundenplan.md` (vollständige Endpunkt-Doku für
  `POST /timetable/data` — Parameter, Rückgabefelder, Codebeispiel;
  zwingend nötig für den Bau des `stundenplan`-Moduls)
