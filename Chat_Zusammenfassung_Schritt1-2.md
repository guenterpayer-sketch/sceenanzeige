# Chat-Zusammenfassung: Tanzschule Monitor-System — Schritt 1 + 2 abgeschlossen

> Diese Datei zusätzlich zur `Projektdokumentation_Tanzschule_Monitor_System.md`
> in neuen Chats hochladen, damit der aktuelle Stand bekannt ist.

---

## Status

✅ **Schritt 1 (SQL-Schema)** — abgeschlossen und live verifiziert
✅ **Schritt 2 (Ordnerstruktur + .htaccess)** — abgeschlossen und live verifiziert
▶️ **Nächster Schritt: Schritt 3** (Modul-Registry-Grundgerüst + Referenz-Module
   `uhrzeit` und `bild`)

---

## Was bereits live auf all-inkl eingerichtet ist

- Vier Subdomains existieren im KAS mit eigenen Ordnern: `screen.tcpayer.de`,
  `saal1.tcpayer.de`, `saal2.tcpayer.de`, `saal3.tcpayer.de`
- MySQL-Datenbank ist angelegt, **DB-Name: `d04768bb`** (Host: `localhost`)
- `01_schema.sql` wurde erfolgreich über phpMyAdmin importiert — alle 13
  Tabellen sind angelegt und per Test-Skript verifiziert (siehe unten)
- `screen.tcpayer.de/config.php` enthält bereits die **echten DB-Zugangsdaten**
  (nicht mehr die Platzhalter aus der ursprünglichen Vorlage)
- Die komplette Ordnerstruktur aus Schritt 2 ist hochgeladen (siehe
  `Schritt2_Ordnerstruktur_und_Dateien.txt` für den vollständigen Code-Stand)
- Ein temporäres `test_db.php` wurde zum Verbindungstest genutzt und danach
  wieder gelöscht (war nur für den Test gedacht, gehört nicht zum System)

---

## Wichtige Entscheidungen aus diesem Chat (über die Projektdoku hinaus)

1. **CORS-Konfiguration verschärft:** In `screen.tcpayer.de/.htaccess` wird
   `Access-Control-Allow-Origin` **nicht** als Wildcard (`*`) gesetzt, sondern
   gezielt nur für `saal1.tcpayer.de`, `saal2.tcpayer.de`, `saal3.tcpayer.de`
   erlaubt (per `SetEnvIf` + `mod_headers`, mit `Vary: Origin`).
   → **Wichtig:** Falls später ein vierter Saal dazukommt, muss diese Regel
   in der `.htaccess` manuell um die neue Subdomain ergänzt werden:
   ```apache
   SetEnvIf Origin "^https://(saal1|saal2|saal3|saal4)\.tcpayer\.de$" CORS_ALLOWED=$0
   ```

2. **Teststrategie: Meilenstein-Tests statt Tests nur am Ende oder nach jedem
   Mikroschritt.** Vereinbart wurde, nach sinnvollen Zwischenständen live auf
   all-inkl zu testen, nicht nach jedem einzelnen Schritt und nicht erst ganz
   am Schluss. Geplante Meilensteine:
   - ✅ Nach Schritt 1+2: DB-Verbindung + Schema (bereits erfolgreich getestet)
   - ⏳ Nach Schritt 5 (Backend: Bibliothek): Live-Test des Bild-Uploads
     (war der ursprüngliche Auslöser, die DB schon jetzt einzurichten)
   - ⏳ Nach Schritt 9 (Monitor-Frontend): erster sichtbarer Live-Monitor

3. **Wochentage in Zeitregeln:** Komma-Liste (`"1,2,3,4,5"`) wurde bewusst
   bestätigt und **nicht** auf Bitmaske oder eigene Tabelle umgestellt.

---

## Für den nächsten Chat relevant

- Beim Start: `Projektdokumentation_Tanzschule_Monitor_System.md` +
  diese Zusammenfassung + `01_schema.sql` + `Schritt2_Ordnerstruktur_und_Dateien.txt`
  hochladen und sagen: *"Wir sind bei Schritt 3"*
- Hinweis geben, dass DB-Zugang und Hosting bereits live funktionsfähig sind
  (nicht nochmal bei Null anfangen)
- Sobald Schritt 5 (Bild-Upload) fertig ist: an die Live-Testabsicht erinnern
  lassen — das war der ursprüngliche Wunsch, der zur frühen DB-Einrichtung
  geführt hat
