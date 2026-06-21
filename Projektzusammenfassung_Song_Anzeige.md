# Projektzusammenfassung: Tanzschule Song-Anzeige (für Integration als `song`-Modul)

> Dieses Dokument fasst das bisherige eigenständige Projekt "Tanzschule
> Song-Anzeige" zusammen, damit es als Vorlage für das `song`-Modul im
> übergeordneten Monitor-System (siehe Projektdokumentation Monitor-System)
> dienen kann.

---

## 1. Zweck

Zeigt den aktuell laufenden Song inkl. Tanz-Art und eine Vorschau der
nächsten Titel an. Datenquelle ist die Musiksoftware **FRET**, die über
eine REST-API (Azure-Hosting) den Song-Status pro PC/Saal bereitstellt.

---

## 2. Bisherige Architektur (eigenständig, vor Integration)

```
index.html    → Konfigurationsseite (Saal-Auswahl, 1-3 Säle, Anzeigename)
display.html  → Live-Anzeige (Song + Playlist), Polling alle 5 Sek.
proxy.php     → Server-seitiger Proxy zur FRET-API (versteckt schoolId + Mapping)
```

Da die echte FRET-API auch **schreibende** Endpunkte besitzt, darf die
`schoolId` **niemals im Frontend/Browser sichtbar** sein. Das ist im neuen
System bereits durch das Konzept "API-Key bleibt serverseitig im Proxy"
(Abschnitt 9 der Monitor-Doku) abgedeckt — exakt dieselbe Anforderung wie
beim Nimbuscloud-API-Key.

---

## 3. FRET-API (Kurzreferenz)

**Basis-URL:** `https://fret-api.azurewebsites.net/api/v1`

| Endpunkt | Zweck |
|---|---|
| `GET /schools/{schoolId}/Computers` | Liste aller Computer/Säle: `[{id, name}]` |
| `GET /schools/{schoolId}/computers/{computerId}/Players` | Aktueller Song-Status für einen Computer |

### Response-Struktur `/Players`
```json
{
  "player1": {
    "isPlaying": true,
    "songs": [ { ... }, { ... } ],
    "isLineDanceMode": false
  },
  "player2": { "isPlaying": true, "songs": [], "isLineDanceMode": false }
}
```
**Wichtig:** Nur `player1` ist für die Anzeige relevant. `player2` wird
von der Musiksoftware intern genutzt, ist aber für die Monitor-Anzeige
irrelevant und meist leer.

### Song-Objekt (innerhalb `songs[]`)
```json
{
  "position": 0,                       // 0 = aktueller Song
                                        // >0 = kommende Songs (Reihenfolge)
                                        // <0 = bereits gespielte Songs (Verlauf)
  "songId": "uuid",
  "startTime": "2026-06-13T12:15:55+00:00",  // null wenn Player pausiert/gestoppt
  "endTime": null,
  "title": "Cuidado con los cincuenta",
  "artist": "SARLI, C. DI",
  "dancesShort": "AT",
  "dancesLong": "Tango Argentino",
  "dances": [
    {
      "longName": "Tango Argentino",
      "shortName": "AT",
      "isPrimary": true,                // true = Haupttanz, false = Nebentanz
      "isOverride": false
    }
  ],
  "duration": 201.99,                  // Sekunden, Songlänge gesamt
  "year": 1954,
  "comment": "salon",
  "coverImageUrl": "https://...",      // kann null sein
  "isWish": false,
  "remainingSeconds": 119.93,          // null wenn pausiert/gestoppt!
  "estimatedSecondsUntilStart": null   // nur bei position > 0 gesetzt, ebenfalls null bei Pause
}
```

**Wichtiges API-Verhalten (per Live-Test verifiziert):**
- Bei `isPlaying: false` (Pause/Stop) werden `remainingSeconds`,
  `estimatedSecondsUntilStart` und `startTime` **alle auf `null`** gesetzt
  — auch beim Song mit `position: 0`. Das muss im Frontend abgefangen
  werden (Fortschrittsbalken einfrieren statt zurücksetzen, siehe unten).
- Songs mit mehreren Tänzen: `isPrimary: true` markiert den/die Haupttanz/
  -tänze, `isPrimary: false` die Nebentänze. Es kann mehrere `isPrimary:
  true`-Einträge geben (gleichwertige Tänze).
- API liefert teils mehr als 3 kommende Songs (in Tests bis zu 15) —
  bisher wurden nur die ersten 3 (`position 1-3`) für die Playlist-Anzeige
  genutzt.
- **Bekanntes Infrastruktur-Problem:** Gelegentlich aktualisiert der
  Musikserver die Daten nicht (vermutlich Netzwerkproblem der Tanzschule,
  nicht API-seitig). Bisher keine Fehleranzeige dafür vorgesehen (würde
  Kunden verwirren) — nur generischer "Verbindung unterbrochen"-Hinweis
  nach 3 aufeinanderfolgenden Fetch-Fehlern.

---

## 4. Proxy-Logik (`proxy.php`) — Vorlage für das neue Modul

Aktuelle Implementierung: `schoolId` und ein Computer-Mapping (UUID →
Anzeigename, inkl. Ein-/Ausblenden über `active`-Flag) liegen direkt als
PHP-Array im Proxy-Skript (keine separate JSON-Datei, um zusätzlichen
`.htaccess`-Schutz zu vermeiden).

```php
$schoolId = '...';
$apiBase  = 'https://fret-api.azurewebsites.net/api/v1';

$mapping = [
    ['id' => 'uuid-1', 'name' => 'Großer Saal', 'active' => true],
    ['id' => 'uuid-2', 'name' => 'Saal 1',       'active' => true],
    // ...
];

// ?computer=list → gefilterte (active=true) {id, name}-Liste
// ?computer=<uuid> → Players-Daten für genau diesen Computer
```

**Für das Monitor-System:** Im neuen Datenmodell entspricht das exakt
einer Modul-Instanz-Einstellung. `song_api_url` ist laut Datenbankschema
bereits pro Saal in der Tabelle `einstellungen` vorgesehen — die
`schoolId` und ggf. die UUID-Zuordnung sollten dort ergänzt werden, statt
sie hart im Code zu pflegen. Das deckt sich mit dem generischen
Architekturprinzip (Modul-Settings statt Code-Änderung).

---

## 5. Frontend-Logik (`display.html`) — zu übernehmende Bausteine

### a) Song-Auswahl aus der API-Antwort
```js
const available = player.songs.filter(s => s.position >= 0);
const current    = available.find(s => s.position === 0) ?? available[0];
const upcoming   = available.filter(s => s.position > 0).slice(0, 3);
```

### b) Tanz-Badges (Haupt- vs. Nebentanz)
Visuelles Konzept (final abgestimmt):
- **Haupttanz-Badge:** gefüllt in der Tanzschul-Farbe `#ad2121`, weiße Schrift
- **Nebentanz-Badge:** Hintergrund `#f0f0f0`, Rahmen `#ad2121` (2px),
  Schriftfarbe `#ad2121`
- Unterscheidung über `dances[].isPrimary` aus der API (nicht über
  String-Parsing von Trennzeichen wie `,` oder `|` — die API liefert das
  bereits strukturiert)
- Duplikate (gleicher Tanzname) werden entfernt

```js
function parseDances(song) {
    if (!song.dances || song.dances.length === 0) return [];
    const hasPrimary = song.dances.some(d => d.isPrimary);
    return song.dances.map(d => ({
        name: d.longName || d.shortName,
        isMain: hasPrimary ? d.isPrimary : true // kein isPrimary gesetzt → alle gleichwertig
    }));
}
```

### c) Fortschrittsbalken — Pause-Handling (wichtiger Bugfix)
```js
if (song.duration && song.remainingSeconds != null) {
    // Song läuft aktiv → Balken positionieren + 1-Sek-Tick starten
} else if (!isPlaying) {
    // Pausiert → Tick stoppen, Balken NICHT zurücksetzen (einfrieren)
} else {
    // Kein aktiver Song → Balken auf 0%
}
```
Der Tick selbst läuft lokal jede Sekunde hoch (nicht nur bei jedem
5-Sekunden-Poll), damit die Animation flüssig wirkt statt zu springen.

### d) Playlist-Countdown
Wird über `estimatedSecondsUntilStart` befüllt und lokal heruntergezählt,
pausiert mit, wenn `isPlaying === false` (da der Wert dann `null` ist und
ohnehin nicht angezeigt wird — kein Sonderfall nötig).

### e) Wichtiger Bugfix: Interval-Leak
Bei jedem Playlist-Update müssen alte `setInterval`-Timer der Countdown-
Anzeigen **zwingend** per `clearInterval()` aufgeräumt werden, sonst
entstehen bei längerer Laufzeit dutzende parallel laufende Timer.

---

## 6. Visuelles Design (für TV-Großbildschirme optimiert)

Abgestimmte Schriftgrößen (Stand: letzter Test, ggf. weiter iteriert):

| Element | Größe |
|---|---|
| Saal-Titel (`.room-title`) | 32px |
| Song-Titel aktueller Song | 40px |
| Tanz-Badges aktueller Song | 40px |
| Playlist Song-Titel | 28px |
| Playlist Tanz-Badges | 28px |
| Fortschrittsbalken-Höhe | 10px |

Farbschema: dunkler Hintergrund (`#0e0e0e`), Tanzschul-Rot `#ad2121` als
Akzentfarbe, Inter als Schriftfamilie.

**Hinweis:** Layout wurde bisher in einem eigenständigen Multi-Spalten-
Grid (1-3 Säle nebeneinander) gebaut. Im neuen Monitor-System übernimmt
das übergeordnete Playlist-Layout (Spalten/Breiten) diese Aufgabe — das
`song`-Modul muss nur noch **eine einzelne Saal-Anzeige** rendern (Titel,
Künstler, Badges, Fortschrittsbalken, Playlist), nicht mehr das
Multi-Raum-Grid selbst.

---

## 7. Offene Punkte / nicht final geklärt

- Polling-Intervall bisher 5 Sek. — im neuen System evtl. an die generelle
  "Song-Polling alle 5–10 Sekunden" Vorgabe aus der Monitor-Doku anpassen.
- `Page Visibility API`-Pausierung (Polling stoppt wenn Tab im Hintergrund)
  war für den bisherigen Standalone-Betrieb sinnvoll; im Kiosk-Mode des
  neuen Systems vermutlich nicht relevant, da der Tab nie in den
  Hintergrund wechselt.
- Mapping/Konfiguration (schoolId, Computer-UUIDs, Anzeigenamen) sollte im
  neuen System über `modul_instanz_einstellungen` (JSON-Spalte) statt
  hartkodiertem PHP-Array gepflegt werden — entspricht dem generischen
  Architekturprinzip aus Abschnitt 4 der Monitor-Doku.
