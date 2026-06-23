# Notiz für Schritt 9 — Monitor-Frontend (Kiosk-Client)

> Vormerk-Notiz, **noch nichts umgesetzt.** Beim Start von Schritt 9 zusammen
> mit `CLAUDE.md` (Abschnitt 10) und `STATUS.md` lesen. Branch:
> `claude/nifty-johnson-3q6u7g`.

## Worum es geht
Der schlanke Vollbild-/Kiosk-Client, der je Monitor in einer eigenen Subdomain
läuft (z. B. `saal4.tcpayer.de`), die aktive Playlist holt und rendert. Er
enthält **selbst keine Inhalte** — alles kommt live vom Backend
`screen.tcpayer.de`.

## Kernidee: Monitor identifiziert sich über seine Subdomain (empfohlen)
Statt pro Monitor eine ID in die `index.html` zu schreiben, liest der Client
seine **Subdomain** selbst aus `window.location.hostname` (z. B. `saal4` aus
`saal4.tcpayer.de`) und schickt sie ans Backend. Das Backend findet den Monitor
über `monitore.subdomain`.

**Vorteil:** Der hochgeladene Code ist für **jeden** Monitor **identisch** —
kein Editieren pro Monitor.

**Neuen Monitor aufnehmen wird damit zu:**
1. Backend → „Monitore": Monitor anlegen (Name + Subdomain) und im Zeitplan
   Playlists zuweisen.
2. Beim Hoster: Subdomain einrichten + Monitor-Ordner unverändert
   draufkopieren. Fertig.

**Fallback/Alternative:** Eine optionale fest hinterlegte Konstante in der
`index.html` (z. B. `const MONITOR_SUBDOMAIN = 'saal4';`), die die
Auto-Erkennung übersteuert — nützlich für lokale Tests oder Sonderfälle. Default
bleibt die Subdomain-Selbsterkennung. (Endgültig in Schritt 9 mit dem Nutzer
bestätigen — Tendenz klar zur Selbsterkennung.)

## Was das Frontend tun muss (Kurzfassung, Details CLAUDE.md Abschnitt 10)
- Eigene Subdomain ermitteln → Monitor bestimmen.
- Aktive Playlist nach Zeitplan + Priorität ermitteln (siehe unten,
  Backend-Endpunkt), Layout (`layouts/<id>/template.html`,
  `{{spalteN_breite}}`-Platzhalter) rendern, Spalten mit Modul-Instanzen füllen
  und je Instanz `modules/<typ>/frontend.js` aufrufen.
- Header (Uhrzeit) optional, Ticker-Footer unabhängig.
- Auto-Refresh der Hauptdaten ~60 s, FRET-Polling 5–10 s.

## Backend-Endpunkt, der dafür gebraucht wird (vormerken)
Ein öffentlicher Endpunkt à la „Was läuft **jetzt** für Subdomain X?", der
serverseitig den aktiven `monitor_zeitplan`-Eintrag nach Wochentag/Uhrzeit +
höchster Priorität auflöst und die zugehörige Playlist (Layout + Spalten-
Inhalte) liefert. Liegt unter `proxies/` o. ä. (öffentlich, da die Monitore
ohne Login zugreifen).

## Datenbasis (bereits gebaut)
Das **monitor-zentrische Modell** ist umgesetzt (Stand Ende Schritt 7):
Tabelle `monitore` + `monitor_zeitplan` (`monitor_id`, `playlist_id`,
`wochentage`, `von_uhrzeit`, `bis_uhrzeit`, `prioritaet`), gepflegt über
Backend → „Monitore" → „Zeitplan". Migration `06_migration_monitor_zeitplan.sql`.

Schritt 9 liest also: Monitor per Subdomain bestimmen → in `monitor_zeitplan`
den **jetzt** gültigen Eintrag (Wochentag/Uhrzeit, höchste `prioritaet`) finden
→ zugehörige Playlist (Layout + Spalten-Inhalte) rendern. Konzept-Details in
CLAUDE.md Abschnitt 16c.
