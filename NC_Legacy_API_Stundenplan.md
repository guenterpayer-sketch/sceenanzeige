# Nimbuscloud Legacy-API — Stundenplan-Endpunkt (Lösung für `stundenplan`-Modul)

**Stand:** Ersetzt den ursprünglich geplanten Weg über
`POST /v2/system/direct-db-access/execute-query` (aktuelle API). Dieser
Direct-DB-Access-Weg wurde **verworfen**, da die dafür nötige Berechtigung
`System_ApiKey_DirectDatabaseAccess` nicht im vorhandenen API-Zugriff
enthalten ist und laut Nimbuscloud-Doku nur auf Sonderanfrage beim Support
vergeben wird (Aufruf scheiterte mit `401 core_not_authorized`).

**Lösung:** Die **Legacy-API** bietet einen fertigen, bereits strukturierten
Endpunkt für Stundenplandaten — ganz ohne SQL und ohne Sonderberechtigung.

---

## Quelle

- Dokumentation: https://nimbuscloud.at/developers/api-legacy
- Zurück zur aktuellen API-Doku: https://nimbuscloud.at/developers/api

---

## Allgemeines zur Legacy-API

- **Basis-URL:** `https://xyz.nimbuscloud.at/api/json/v1`
  (echte Subdomain der Tanzschule-Instanz einsetzen, z.B.
  `https://tanzcenter-payer.nimbuscloud.at/api/json/v1`)
- **API-Key-Übergabe:** Im **POST-Parameter** `apikey` — **nicht** im Header
  `X-API-Key` (Unterschied zur aktuellen API!)
- **Antwortformat:** JSON-Objekt mit `content` (Ergebnis) und `statuscode`
  (Status)
- **Unbekannte Methoden:** HTTP 404
- **Ungültiger API-Key:** HTTP 401
- **Interner Fehler:** HTTP 500 (wird im Systemlog protokolliert)

### Beispiel (GuzzleHTTP)

```php
$client = new Client();

$response = $client->post("https://xyz.nimbuscloud.at/api/json/v1/timetable/data", [
    RequestOptions::FORM_PARAMS => [
        "apikey" => "IHRAPIKEY",
        "days"   => 1,
        "date"   => "1676934000"
    ]
]);

$data = json_decode($response->getBody()->getContents(), true);
```

---

## Relevanter Endpunkt: `POST /timetable/data`

**Beschreibung:** Liefert die Belegungsdaten der Säle (Kurstermine +
Kalendertermine) für einen Zeitraum.

**Benötigte Berechtigung:** `Stundenplan — Lesezugriff`
(normale, frei erstellbare Berechtigung — **keine** Sonderfreischaltung
durch den Support nötig, im Gegensatz zu Direct-DB-Access)

### Parameter

| Name             | Typ     | Pflicht | Beschreibung                                                       |
|------------------|---------|---------|----------------------------------------------------------------------|
| `date`           | int     | Nein    | Datum als UNIX-Timestamp (muss Mitternacht entsprechen)             |
| `days`           | int     | Nein    | Anzahl der Tage, die ausgegeben werden (max. 7)                     |
| `programOnlyNew` | boolean | Nein    | Nur neue Wiki-Artikel anzeigen (Default: true)                      |

### Relevante Rückgabefelder (Auszug, fürs `stundenplan`-Modul wichtig)

| Feld                          | Typ     | Beschreibung                                                  |
|-------------------------------|---------|------------------------------------------------------------------|
| `events[].id`                 | string  | ID des Termins (Kalendertermine mit Präfix `e-`)                |
| `events[].event`              | int     | ID des Termins                                                  |
| `events[].isCourseEvent`      | boolean | `true` = Kurstermin, `false` = Kalendereintrag (kein Kurs)       |
| `events[].text`               | string  | Bezeichnung des Termins                                        |
| `events[].displayName`        | string  | Anzeigename des Kurses (nur Kurstermin)                         |
| `events[].course`             | int     | ID des Kurses (nur Kurstermin)                                  |
| `events[].course_key`         | string  | Name des Kurses (nur Kurstermin)                                |
| `events[].start_date`         | string  | Formatierter Beginnzeitpunkt                                    |
| `events[].end_date`           | string  | Formatierter Endzeitpunkt                                       |
| `events[].location`           | string  | Name des Standorts                                              |
| `events[].location_id`        | int     | ID des Standorts                                                |
| `events[].room`               | string  | Name des Saals                                                  |
| `events[].room_id`            | int     | ID des Saals                                                    |
| `events[].teacher`            | string  | Name des Hauptlehrers (nur Kurstermin)                          |
| `events[].allTeachers[]`      | object[]| Alle Lehrer (firstname, surname, shortName, isPrimary, teacher) |
| `events[].color`              | string  | HEX-Farbe des Termins                                          |
| `events[].textColor`          | string  | HEX-Textfarbe des Termins                                      |
| `events[].type`               | string  | Name des Kurstyps (nur Kurstermin)                               |
| `events[].level`              | string  | Name der Stufe (nur Kurstermin)                                  |
| `events[].isFree`             | boolean | Termin übersprungen (nur Kurstermin)                              |
| `events[].showInTimetable`    | boolean | Soll im Stundenplan angezeigt werden (nur Kurstermin)             |
| `events[].notes`              | string  | Terminnotiz (nur Kurstermin)                                     |

> Vollständige Feldliste (inkl. Pausenzeiten, Auslastung, Programm/Unterrichtselemente)
> siehe Original-Doku unter "Stundenplan — Lesezugriff" → `/timetable/data`.

### Fehlercodes

| Code | Beschreibung                           |
|------|-----------------------------------------|
| 400  | Die angegebene Zeitspanne ist ungültig  |

---

## Sonstige evtl. nützliche Legacy-Endpunkte (gleiche Berechtigung/Bereich)

| Endpunkt                         | Berechtigung              | Nutzen                                           |
|-----------------------------------|----------------------------|---------------------------------------------------|
| `POST /data/teacher`              | Stammdaten — Lesezugriff   | Liefert alle Lehrer inkl. Bild — evtl. für Anzeige nützlich |
| `POST /data/locations`            | Stammdaten — Lesezugriff   | Liefert Standorte + Säle (Namen, IDs)            |
| `POST /timetable/unit-wiki-types` | Stundenplan — Lesezugriff  | Mögliche Unterrichtstypen (Icons/Namen)          |

---

## Auswirkung auf `proxies/nc.php`

Im Vergleich zur ursprünglich geplanten Variante (Direct-DB-Access über die
**aktuelle** API) ändert sich für den Stundenplan-Proxy:

| Aspekt              | Alt (verworfen)                                  | Neu (Legacy-API)                              |
|---------------------|---------------------------------------------------|------------------------------------------------|
| Endpunkt            | `POST /v2/system/direct-db-access/execute-query`  | `POST /timetable/data`                        |
| Basis-URL           | `https://xyz.nimbuscloud.at/backend`              | `https://xyz.nimbuscloud.at/api/json/v1`      |
| Key-Übergabe        | Header `X-API-Key`                                | POST-Parameter `apikey`                       |
| Benötigte Key-Berechtigung | `System_ApiKey_DirectDatabaseAccess` (Sonderfreischaltung nötig) | `Stundenplan — Lesezugriff` (normal erstellbar) |
| Datenstruktur       | unbekannt, musste per Schema-Dump ermittelt werden | bereits dokumentiert (siehe Felder oben)      |
| SQL nötig?          | Ja                                                 | Nein                                          |

`proxies/nc.php` muss entsprechend umgebaut werden (POST mit
Form-Parametern statt JSON-Body + Header). Der bisherige Mechanismus zum
Laden der modulspezifischen Einstellungen (`anzahl_kurse`, `nur_heute` aus
der Modul-Instanz) bleibt unverändert gültig — nur der eigentliche
NC-API-Call ändert sich.
