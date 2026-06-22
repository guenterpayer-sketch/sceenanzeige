# Chat-Zusammenfassung: Tanzschule Monitor-System — Schritt 4 (Claude-Code)

> Diese Datei zusätzlich zu `CLAUDE.md` in einen neuen Chat hochladen, um
> nahtlos weiterzuarbeiten. Stand: Ende der Claude-Code-Session zu Schritt 4.

---

## 0. Wichtigster Punkt zuerst (offen / zu erledigen!)

Es gibt **zwei noch nicht umgesetzte Code-Änderungen**, für die zuletzt das
GO ausstand. **Punkt A ist dringend**, weil der Live-Server sonst beim
Anlegen von Bild-/Ankündigungs-Inhalten einen SQL-Fehler wirft:

**A) PFLICHT-FIX — `includes/ModulInstanz.php`, Funktion `addInhalt`:**
Die bereits eingespielte Migration hat die Spalte `ablaufdatum` in
**`gueltig_bis`** umbenannt. `addInhalt` schreibt aber noch in `ablaufdatum`
→ muss auf `gueltig_bis` geändert werden. (Die Frontends lesen `gueltig_bis`
schon korrekt; nur das Schreiben fehlt.)

**B) NC-Key schulweit (Design-Korrektur des Nutzers):**
Es gibt **je Software genau einen Key/Identifier, schulweit** — nicht pro Saal.
- `config.php`: Konstante **`NC_API_KEY`** ergänzen.
- `proxies/nc.php`: Key aus `NC_API_KEY` (config) lesen statt aus der
  DB-Tabelle `einstellungen`; `saal_id` wird für den Key nicht mehr gebraucht.
- Die Spalten `nc_api_key_stundenplan`/`nc_api_key_stammdaten` in
  `einstellungen` bleiben als ungenutzte Altlast stehen (kein DB-Eingriff).

→ Im neuen Chat als Erstes: **GO einholen und A + B umsetzen.**

---

## 1. Wo wir stehen

- **Branch:** `claude/nifty-johnson-3q6u7g` (alle Arbeit liegt hier, **nicht**
  auf `main`). Letzter relevanter Commit: `test-module4.php` + Vorschau-CSS.
- **Bauplan-Schritt:** Schritt 4 ist **codeseitig fertig** (Module
  `stundenplan`, `ankuendigung`, `fret` + Proxys + Testseite), aber der
  Pflicht-Fix A fehlt und der Live-Test steht noch aus.
- **Live-Server:** Der Nutzer hat **den aktuellen Branch-Stand inkl. der
  SQL-Migration** bereits hochgeladen und in phpMyAdmin ausgeführt. Das
  Live-Schema (DB `d04768bb`) ist damit auf dem Abschnitt-16-Stand
  (`gueltig_bis`, `mediathek`-Tabelle, `nc_api_key_stundenplan`, `aktiv`).
  **Achtung:** dadurch ist Fix A jetzt zwingend nötig.

---

## 2. Was in dieser Session passiert ist

1. **Schritt 1–3 konsolidiert:** Der nur als Text-Dump vorliegende Schritt-3-
   Code wurde in echte Dateien unter `02_ordnerstruktur/screen.tcpayer.de/`
   ausgepackt (`includes/ModuleRegistry.php`, `includes/ModulInstanz.php`,
   Module `uhrzeit`+`bild`, `module-loader.js`, `module-test.css`,
   `test-module3.php`). `CLAUDE.md.md` → `CLAUDE.md` umbenannt, `.gitignore`
   und `uploads/.gitkeep` ergänzt.
2. **Migration erstellt:** `03_migration_abschnitt16.sql` (mediathek-Tabelle,
   zwei NC-Key-Spalten, `mediathek_id`/`aktiv`, `ablaufdatum`→`gueltig_bis`).
   **Wurde vom Nutzer bereits live eingespielt.**
3. **Schritt 4 gebaut:** Module `stundenplan`, `ankuendigung`, `fret` +
   Proxys `nc.php` (Legacy-API `/timetable/data`), `fret.php` (FRET-API),
   `_cors.php`. Registry um die drei ergänzt (community NICHT).
4. **`song` → `fret` umbenannt** (Modul, Proxy, Registry) — nach der
   Musiksoftware FRET.
5. **CLAUDE.md aktualisiert** (mit GO): Zieldefinition (Abschnitt 1.1),
   FRET/NC-Klarstellung, `song`→`fret`, schoolId serverseitig, Full-HD +
   Layout-Katalog (Abschnitt 6), Änderungsprotokoll 16b.
6. **`test-module4.php`** als Meilenstein-Testseite gebaut + CSS für die
   neuen Module.
7. **Live-Abgleich** durchgeführt: Der vom Nutzer hochgeladene Server-Stand
   liegt zur Referenz im Ordner `Live-Abgleich/` (nur lesend).

---

## 3. Wichtige geklärte Entscheidungen

- **Maßgeblich ist die `CLAUDE.md`** (Ziel-Stand). `Live-Abgleich/` ist nur
  Referenz dessen, was schon auf dem Server liegt.
- **`ankuendigung` bleibt** (eigenes Modul, Text + optionales Bild je Eintrag).
- **`community` zurückgestellt** (Datenschutz/Stammdaten-Key) — nicht in der
  Registry.
- **`stundenplan`** nutzt die **Legacy-API** `POST /timetable/data`
  (API-Key als Form-Parameter `apikey`), NICHT den verworfenen v2/Direct-DB-
  Weg.
- **FRET vs. NC = zwei unabhängige Systeme:**
  - FRET (Musiksoftware): je Saal ein PC (eigene **Computer-UUID**), 2 Player;
    **schoolId** ordnet den Account zu. Modul zeigt laufende Musik je Saal.
  - NC (Nimbuscloud, Verwaltung): liefert den **Stundenplan**. Braucht
    **keine** schoolId.
- **Secrets serverseitig, je EIN Wert pro Software, schulweit:**
  - `FRET_SCHOOL_ID` (+ `FRET_API_BASE`) in `config.php`.
  - `NC_API_KEY` (+ `NC_API_BASE`) in `config.php` — **noch umzusetzen, s. 0B**.
  - Niemals in den Modul-Instanz-Einstellungen (die ans Frontend gehen).
  - Computer-UUID (nicht geheim) pro `fret`-Instanz-Einstellung.
- **Zielauflösung:** Full-HD (1920×1080, quer).
- **Layout:** Mechanik (1–3 Spalten, Breiten frei in %) ist Doku;
  konkrete Spaltenbreiten je Playlist sind Laufzeit-Konfiguration (Schritt 6).

---

## 4. Repo-Struktur (relevant)

```
02_ordnerstruktur/screen.tcpayer.de/
├── config.php                 (DB + NC_API_BASE/FRET_* ; NC_API_KEY noch zu ergänzen)
├── includes/ModuleRegistry.php (Auto-Formular aus module.json)
├── includes/ModulInstanz.php   (CRUD; addInhalt → Fix A nötig: gueltig_bis)
├── modules/registry.php        (uhrzeit, bild, stundenplan, ankuendigung, fret)
├── modules/uhrzeit|bild|stundenplan|ankuendigung|fret/ (module.json + frontend.js)
├── proxies/_cors.php           (CORS nur Saal-Subdomains)
├── proxies/nc.php              (NC Legacy-API; Fix B: NC_API_KEY aus config)
├── proxies/fret.php            (FRET; schoolId aus config)
├── assets/js/module-loader.js  (Signatur: render(modulId, container, settings, inhalte))
├── assets/css/module-test.css
├── test-module3.php / test-module4.php
03_migration_abschnitt16.sql    (bereits live eingespielt)
CLAUDE.md                       (maßgebliche Doku)
Live-Abgleich/                  (Referenz: aktueller Server-Stand, nur lesend)
```

---

## 5. Live-Test-Setup (sobald Fix A+B drin sind)

1. Geänderte Dateien neu hochladen: `includes/ModulInstanz.php`,
   `config.php` (bzw. nur die NC_API_KEY-Zeile am Server ergänzen),
   `proxies/nc.php`.
2. In `config.php` (Server) eintragen:
   - `NC_API_BASE` = echte NC-Subdomain, z. B.
     `https://tanzcenter-payer.nimbuscloud.at/api/json/v1`
   - `NC_API_KEY` = NC-Stundenplan-Key (Berechtigung „Stundenplan — Lesezugriff")
   - `FRET_API_BASE` = `https://fret-api.azurewebsites.net/api/v1`
   - `FRET_SCHOOL_ID` = echte schoolId (steht im alten Standalone-Proxy
     `FRED_prox.php` als `$schoolId`)
3. **Kein** weiterer DB-Eingriff nötig (kein Saal/Key in der DB).
4. Aufruf: `https://screen.tcpayer.de/test-module4.php`
   - `uhrzeit`/`bild`/`ankuendigung` laufen ohne API.
   - `fret`: Computer-UUID via `/proxies/fret.php?action=list` holen,
     in die fret-Instanz eintragen.
   - `stundenplan`: zeigt Kurse, sobald `NC_API_BASE` + `NC_API_KEY` stehen.

Hinweis: Reste `modules/song/`, `modules/community/`, `proxies/song.php` auf
dem Server sind Altlast (stören nicht, dürfen gelöscht werden).

---

## 6. Nächste Schritte

1. **Fix A (Pflicht) + Fix B (NC-Key)** umsetzen (GO einholen) → pushen.
2. **Live-Test** Schritt 4 (Testseite) durchführen, Fehler zurückmelden.
3. Danach **Schritt 5**: Backend-Bibliothek (Modul-Instanzen verwalten inkl.
   `aktiv`/`gueltig_bis`) + **Mediathek** (zentrale Bildtabelle mit SHA-256-
   Duplikaterkennung, Drag&Drop) — Schema dafür ist durch die Migration
   bereits vorhanden (`mediathek`, `mediathek_id`).

---

## 7. Arbeitsregel (aus CLAUDE.md)

**Kein Schreiben/Code/Datei-Änderung ohne explizites „GO" des Nutzers.**
Reine Lese-/Prüf-Schritte sind ohne GO erlaubt. Entwicklung auf Branch
`claude/nifty-johnson-3q6u7g`, committen + pushen.
