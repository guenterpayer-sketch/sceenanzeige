# Nimbuscloud Legacy-API — Standorte & Räume

Ergänzungsdoku zu `NC_Legacy_API_Stundenplan.md`.  
Zweck: Grundlage für die Standort-/Raum-Filterung im `stundenplan`-Modul (Option B: dynamische Checkboxen im Modul-Editor).

---

## 1. Felder aus `POST /timetable/data` (bereits im Einsatz)

Pro Kursevent liefert die API u.a.:

| Feld         | Typ    | Beschreibung                                        |
|--------------|--------|------------------------------------------------------|
| `location`   | string | Name des Standorts                                  |
| `locationId` | string | ID des Standorts (**camelCase**, nicht `location_id`!) |
| `room`       | string | Name des Saals/Raums                                |
| `room_id`    | int    | ID des Saals/Raums                                  |

> ⚠️ **Verifiziert 2026-06-25:** Das Feld heißt `locationId` (camelCase String), NICHT `location_id` (snake_case int) wie in der Doku ursprünglich angenommen.

> **Wichtig:** `nc.php` streift diese Felder raus (werden nicht an das Frontend weitergegeben).
> Für den Standort-Filter wird `(int)$ev['locationId']` mit den gespeicherten IDs verglichen.

---

## 2. Dedizierter Standorte-Endpunkt: `POST /data/locations`

**Quelle:** In `NC_Legacy_API_Stundenplan.md` unter „Sonstige evtl. nützliche Legacy-Endpunkte" aufgeführt.

| Aspekt             | Wert                                                      |
|--------------------|-----------------------------------------------------------|
| Endpunkt           | `POST /data/locations`                                    |
| Berechtigung       | `Stundenplan — Lesezugriff` (gleicher Key wie Stundenplan)|
| Auth               | POST-Parameter `apikey` (wie alle Legacy-Endpunkte)       |
| Basis-URL          | `https://tanzcenter-payer.nimbuscloud.at/api/json/v1`     |
| Beschreibung (Doku)| „Liefert Standorte + Säle (Namen, IDs)"                   |

### Bekannte Rückgabefelder

> ⚠️ **Noch nicht verifiziert** — Rückgabestruktur muss gegen echte API getestet werden.
> Erwartet wird ein Array von Standorten, je mit Sälen:

```json
{
  "content": [
    {
      "id": 1,
      "name": "Hauptstandort",
      "rooms": [
        { "id": 10, "name": "Saal 1" },
        { "id": 11, "name": "Saal 2" },
        { "id": 12, "name": "Büro" }
      ]
    }
  ],
  "statuscode": 200
}
```

> Alternativ werden Standorte und Räume als flache Liste zurückgegeben — muss live geprüft werden.

### Minimaler Test-Aufruf (PHP/cURL)

```php
$ch = curl_init('https://tanzcenter-payer.nimbuscloud.at/api/json/v1/data/locations');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['apikey' => NC_API_KEY]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$result = curl_exec($ch);
curl_close($ch);
var_dump(json_decode($result, true));
```

---

## 3. Implementierungsplan: Standort-Filter (Option B)

### Ziel
Im Modul-Editor (Backend, `instanz.php`) werden alle verfügbaren Standorte als Checkboxen angezeigt.
Der Admin wählt aus welche Standorte im Stundenplan erscheinen sollen.
Die Auswahl wird als JSON-Array von `location_id`s in `modul_instanzen.einstellungen` gespeichert.
`nc.php` filtert Kurse anhand dieser IDs serverseitig.

### Neue Datei: `proxies/nc-locations.php`
- Ruft `POST /data/locations` auf (NC_API_KEY aus config.php, nie ans Frontend)
- Gibt bereinigte Standortliste zurück: `[{ id, name }]`
- Wird nur vom Backend (Admin-Editor) aufgerufen — kein CORS nötig

### Änderungen `modules/stundenplan/module.json`
- Neues Setting `location_ids` vom Typ `location_picker` (custom Widget)
- Oder: gespeichert als JSON-String, im Editor per custom HTML gerendert

### Änderungen `admin/instanz.php`
- Erkennt Typ `location_picker` für das `stundenplan`-Modul
- Fetcht `proxies/nc-locations.php` per JS beim Laden des Editors
- Rendert Checkboxen für jeden Standort
- Speichert ausgewählte IDs als JSON-Array `[1, 3]` im Einstellungsfeld

### Änderungen `proxies/nc.php`
- Liest `location_ids` aus GET-Parameter (kommt vom Frontend als nicht-sensibler Wert)
- Filtert `$events` nach `location_id` wenn das Setting gesetzt ist
- `location_id` und `room_id` werden weiterhin NICHT ans Frontend weitergegeben

### Änderungen `modules/stundenplan/frontend.js`
- Übergibt `location_ids` (aus `settings`) als URL-Parameter an `nc.php`
- Keine eigene Filterlogik nötig (Proxy filtert serverseitig)

---

## 4. Offene Fragen (vor Umsetzung zu klären)

| Frage | Status |
|-------|--------|
| Exakte Rückgabestruktur von `POST /data/locations` | ⚠️ noch zu testen — Endpunkt wird auf live verwendet |
| Sind Standorte und Räume verschachtelt oder flach? | ⚠️ noch zu testen |
| Gibt es mehrere Standorte bei Tanzcenter Payer? | ✅ ja, mind. 2: „TCPayer" (ID 1), „Gymnastikraum Fritz-Beck" (ID 2), weitere vorhanden |
| Soll nach Standort ODER Raum gefiltert werden (oder beides)? | ✅ Standort (`locationId`) |
| Feldname für Standort-ID in timetable/data | ✅ `locationId` (camelCase String), nicht `location_id` |
| API-Key für `/data/locations` | ✅ universeller Key — gleiche Konstante `NC_API_KEY`, Berechtigung Stammdaten freigegeben |
