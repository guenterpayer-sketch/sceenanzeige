# Notiz für Schritt 5: Mediathek statt Pro-Instanz-Upload

> Diese Datei zusammen mit den anderen Projektdateien ablegen. Beim Start
> von Schritt 5 hochladen / erwähnen, damit die Backend-Bibliothek direkt
> mit diesem Konzept gebaut wird statt mit dem einfachen Upload aus
> Schritt 3.

## Ausgangslage (Stand Schritt 3)

Das Bild-Modul speichert hochgeladene Bilder direkt in
`modul_instanz_inhalte` (Spalte `dateiname`). Jeder Upload erzeugt eine
neue Datei in `uploads/`, auch wenn exakt dasselbe Bild schon einmal
hochgeladen wurde. Außerdem ist der Upload aktuell nur der Standard-
Browser-Dateidialog (Mehrfachauswahl per Strg-Klick) — kein Drag&Drop,
keine Sammel-Vorschau vor dem endgültigen Anlegen der Modul-Instanz.

## Gewünschte Verbesserung für Schritt 5

1. **Zentrale Mediathek statt Pro-Instanz-Speicherung**
   Bilder werden in einer eigenen Tabelle `mediathek` verwaltet
   (unabhängig von einzelnen Bild-Modul-Instanzen). Eine
   `modul_instanz_inhalte`-Zeile verweist dann per `mediathek_id` auf den
   Eintrag, statt selbst einen Dateinamen zu tragen. Vorteil: ein Bild
   kann ohne erneuten Upload in mehreren Bild-Modul-Instanzen verwendet
   werden (z.B. das Logo in "Begrüßung" UND "Pause").

   ```sql
   CREATE TABLE mediathek (
       id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
       dateiname   VARCHAR(255) NOT NULL,      -- tatsächlicher Dateiname in uploads/
       original_name VARCHAR(255) DEFAULT NULL, -- ursprünglicher Upload-Name, für die Anzeige in der Bibliothek
       datei_hash  CHAR(64) NOT NULL,           -- SHA-256 des Dateiinhalts, für Duplikat-Erkennung
       breite      SMALLINT UNSIGNED DEFAULT NULL,
       hoehe       SMALLINT UNSIGNED DEFAULT NULL,
       hochgeladen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       UNIQUE KEY uniq_hash (datei_hash)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```

   `modul_instanz_inhalte.dateiname` würde dann durch
   `modul_instanz_inhalte.mediathek_id` (FK auf `mediathek.id`) ersetzt
   bzw. ergänzt werden.

2. **Duplikat-Erkennung beim Upload**
   Beim Hochladen wird der SHA-256-Hash der Datei berechnet. Existiert
   bereits ein `mediathek`-Eintrag mit diesem Hash → vorhandenen Eintrag
   wiederverwenden (kein erneuter Upload, kein doppelter Speicherplatz),
   stattdessen nur einen neuen `modul_instanz_inhalte`-Verweis anlegen.

3. **Echtes Drag&Drop + Sammel-Upload im Backend**
   Statt des Standard-`<input type="file" multiple>`-Dialogs: eine
   Drop-Zone im Bibliotheks-Bereich, in die mehrere Bilder gleichzeitig
   gezogen werden können, mit Vorschau-Thumbnails *bevor* die Modul-
   Instanz endgültig gespeichert wird (Bilder können vor dem Speichern
   wieder entfernt werden). Technisch: JS `FileReader`/`drag-and-drop`-
   API, Upload per `fetch`/`FormData`, kein Page-Reload nötig.

4. **Bibliotheks-Übersicht**
   Eigener Reiter "Mediathek" im Bereich Bibliothek (siehe Abschnitt 11
   der Projektdoku), der alle hochgeladenen Bilder als Galerie zeigt —
   neue Bild-Modul-Instanz kann dann sowohl "neu hochladen" als auch
   "aus Mediathek auswählen" anbieten.

## Zusätzlich bereits erledigt (Schritt 3, Nachbesserung)

- Echtes Crossfade beim Bild-Modul behoben: zwei übereinanderliegende
  Bild-Layer kreuzen sich beim Wechsel (statt erst auszublenden und dann
  erst das neue Bild zu laden). Betrifft `modules/bild/frontend.js` +
  `assets/css/module-test.css` (Klassen `tm-bild-stage`, `tm-bild-layer-a/b`).
