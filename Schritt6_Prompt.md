# Prompt für den Schritt-6-Chat (Claude Code)

> Diesen Text zu Beginn des neuen Claude-Code-Chats einfügen.

---

Wir arbeiten am Projekt „Tanzschule Monitor-System" weiter. Branch:
`claude/nifty-johnson-3q6u7g` (hier entwickeln, committen, pushen).

**Bitte zuerst lesen (im Repo vorhanden):**
1. `CLAUDE.md` — Gesamtkonzept (maßgeblich; v. a. Abschnitt 6 „Playlists").
2. `STATUS.md` — aktueller Stand (Schritte 1–5 sind live).
3. `Schritt6_Vorbereitung.md` — das Briefing für genau diesen Schritt.

**Aufgabe:** Schritt 6 — der **Playlist-Editor (Layout-Konfigurator)** im
Backend. Umfang, Abgrenzung (Zeitregeln/Saal = Schritt 7, Monitor-Rendering =
Schritt 9, Live-Vorschau = Schritt 10), die vorhandenen Bausteine und der
empfohlene Aufbau stehen in `Schritt6_Vorbereitung.md`.

**Wichtige Arbeitsregeln (verbindlich):**
- **Kein Code/keine Datei schreiben, bevor ich „GO" sage.** Erst nachdenken,
  Konzept vorschlagen, Rückfragen stellen. Lesen/Prüfen ist ohne GO ok.
- Nach jedem sinnvollen Abschnitt: committen + pushen auf
  `claude/nifty-johnson-3q6u7g` **und** `STATUS.md` aktualisieren.
- Fertige Dateien als ZIP (Struktur unter `screen.tcpayer.de/`) zum Hochladen
  bündeln; Migrationen separat mit „zuerst einspielen"-Hinweis. (Schritt 6
  braucht voraussichtlich **keine** Migration — die Playlist-Tabellen sind
  bereits live.)
- DB ist MariaDB mit `EMULATE_PREPARES=false` → **kein benannter Platzhalter
  mehrfach** im selben SQL-Statement.
- `admin/` liegt hinter KAS-Verzeichnisschutz; `proxies/`, `uploads/`,
  `modules/`, `layouts/` bleiben öffentlich (Monitore). Akzentfarbe **#ad2121**,
  Zielauflösung Full-HD (1920×1080).

**Bitte zu Beginn:** die drei Dateien lesen, dann einen konkreten
Umsetzungsvorschlag für Schritt 6 machen (inkl. der offenen Design-Fragen aus
`Schritt6_Vorbereitung.md`) und auf mein **GO** warten, bevor du Code schreibst.
