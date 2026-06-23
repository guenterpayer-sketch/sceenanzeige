# Projektdokumentation: Tanzschule Monitor-System

> **🛑 Arbeitsanweisung:** Erst nachdenken/vorschlagen/Rückfragen stellen —
> **keine Datei erstellen, ändern oder Code schreiben, bevor der Nutzer
> explizit „GO" sagt.** Lesen/Prüfen jederzeit ok.

---

## 1. Übersicht

Digitales Monitor-Signage-System für eine Tanzschule. Zentrales Backend
(`screen.tcpayer.de`) + schlanke Saal-Monitor-Frontends (`saalN.tcpayer.de`)
im Vollbild-Kiosk-Modus. Module: `uhrzeit`, `bild`, `ankuendigung`,
`stundenplan`, `fret`. Ticker läuft als eigenständiges Footer-System.

**Hosting:** all-inkl (PHP 8 + MySQL). Kein Node.js, kein Docker.

| System | Was | Modul | Zugangsdaten |
|---|---|---|---|
| **FRET** | Musiksoftware; Songanzeige je Saal | `fret` | `FRET_SCHOOL_ID` + Computer-UUID |
| **Nimbuscloud** | Tanzschulverwaltung; Stundenplan | `stundenplan` | `NC_API_KEY` (schulweit) |

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
│   ├── nc.php         (NC Legacy-API, Key serverseitig)
│   └── fret.php       (FRET, schoolId serverseitig)
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
| Playlists | Layout + Spalten-Inhalte (kein Zeitplan hier) |
| Ticker | Ticker-Playlists + Textzeilen |
| Monitore | Anlegen (Name, Subdomain) + Zeitplan je Monitor (Playlist + Ticker) |
| Live-Vorschau | iFrame-Simulation eines Monitors |

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
| 9 | Monitor-Frontend (Anzeige- + Zeitlogik) | offen |
| 10 | Live-Vorschau (iFrame) | offen |
| 11 | Deployment-Guide | offen |

---

## 14. FRET-API — Sicherheitshinweis

**⚠️ `schoolId` darf niemals im Frontend/Browser sichtbar sein** (FRET hat
schreibende Endpunkte). Zugriff nur über `proxies/fret.php`.

- `FRET_SCHOOL_ID` + `FRET_API_BASE` → serverseitig in `config.php`
- Pro `fret`-Instanz: nur Computer-UUID + Anzeigename (nicht geheim)
- `GET /schools/{schoolId}/computers/{computerId}/Players` → aktueller Song
- `player1.songs[]`, `position: 0` = aktuell; vollständige Doku: `FRET_API.json`
