# Konzept: Slide-Engine — Trennung von Inhalt und Präsentation

> **Status:** ✅ live — alle Etappen umgesetzt und produktiv: Slide-Engine
> vollständig, alle 7 Module portiert, Adapter entfernt, Uhr-Analog +
> Hintergrundbild.
> **Voraussetzung war:** Übergangs-Bugfix Schritt 19 (ebenfalls live ✅).

---

## 1. Motivation

### Die wiederkehrende Bug-Klasse

Drei Module (`bild`, `ankuendigung`, `veranstaltung`) implementieren dieselbe
Rotations-Maschine unabhängig voneinander: zwei A/B-Layer, Opacity-Crossfade,
Timer, Cleanup. Dadurch ist derselbe Fehler dreimal entstanden und musste
dreimal einzeln gefixt werden:

- **Multiplikative Opacity:** Modul setzt seine innere Layer-Transition vor dem
  ersten Render → kollidiert mit dem äußeren Container-Fade von `rotateModule`
  (`sichtbar = opacity_außen × opacity_innen`, bei je 0.5 nur noch 25 %
  Helligkeit → wirkt wie „Dip to Black" / harter Schnitt).
- Gefixt in `bild` und `ankuendigung` (Transition erst per rAF nach dem ersten
  Render); `veranstaltung` trägt das alte Muster noch, wird aber durch die
  Settle-Phase aus Schritt 19 maskiert.

Solange jedes Modul selbst an `opacity`/`transition` dreht, kann jeder neue
Modul-Autor den Fehler wieder einbauen. Ziel: **korrekt durch Konstruktion
statt durch Disziplin.**

### Doppelte Konzepte auf zwei Ebenen

Die Playlist-Ebene hat bereits gelöst, was der Modul-Ebene fehlte:
Pre-Render (`SETTLE_MS = 800`), sauberer Crossfade, zentrales Timer-Management.
Schritt 19 hat das auf die Modul-Ebene kopiert — die Slide-Engine führt beide
Ebenen auf **einen** Mechanismus zusammen.

---

## 2. Grundidee

**Module liefern Inhalt. Die Engine präsentiert.**

| Verantwortung | Heute | Mit Slide-Engine |
|---|---|---|
| Daten holen (inhalte[] / fetch) | Modul | Modul |
| DOM eines einzelnen Slides bauen | Modul | Modul |
| Slides rotieren (Timer) | Modul (3× dupliziert) | **Engine** |
| Überblendungen (Slide↔Slide, Modul↔Modul) | Modul + `rotateModule` (2 Systeme) | **Engine** (1 System) |
| Pre-Render / Settle | nur Playlist-Ebene | **Engine** (überall) |
| Cleanup (Timer, Player, Listener) | Modul + `cleanupModulContainer` | **Engine** + `destroy`-Hook |

Ein Modul kann die Übergänge nicht mehr kaputt machen, weil es
`opacity`/`transition` nie anfasst.

---

## 3. Der Vertrag

### Modul-Seite

```js
window.TanzschuleModule.<id> = {
    /**
     * Liefert die Slides der Instanz. Asynchron (Callback), damit die Engine
     * mit dem Fade warten kann, bis der Inhalt wirklich da ist (Settle).
     *
     * @param {object}   settings  Instanz-Einstellungen (JSON aus DB)
     * @param {array}    inhalte   modul_instanz_inhalte (falls has_inhalte)
     * @param {function} fertig    fertig(slides) — Array von Slide-Objekten
     */
    getSlides: function (settings, inhalte, fertig) { … }
};
```

### Slide-Objekt

```js
{
    el:          HTMLElement,   // fertiges DOM-Element (100% × 100%)
    dauerSek:    12,            // Anzeigedauer; von der Engine skalierbar
                                // (Spalten-Synchronisation)
    meldetEnde:  false,         // true = Slide meldet selbst, wann er fertig
                                // ist (Video: 'ended'-Event) → Engine wartet
                                // auf slide.onEnde-Callback statt auf Timer
    destroy:     function () {} // optional: Intervalle/Player/Listener abbauen
                                // (Uhr-Tick, FRET-Poll, YT-Player)
}
```

**Wichtig:** „Slide" heißt nicht statisches Bild — das Element darf innen
leben (Uhr tickt, FRET-Fortschrittsbalken animiert, Video spielt). Nur
Sichtbarkeit, Position und Übergänge gehören der Engine.

### Engine-Seite (Ablauf pro Spalte)

```
1. Für jede Modul-Instanz der Spalte: getSlides() aufrufen (parallel)
2. Slides aller Instanzen zu einer Sequenz verketten
3. Spalten-Synchronisation: Gesamtdauern vergleichen, dauerSek proportional
   skalieren (ersetzt modulAnzeigeDauer + skaliereMod + Sonderfall
   veranstaltung — die Engine kennt jede Slide-Dauer exakt)
4. Anzeige-Loop:
   a. Nächsten Slide unsichtbar einhängen (opacity:0, deckender Hintergrund)
   b. Settle: warten bis Bilder/iframes im Slide geladen sind
      (img.decode()/Timeout-Fallback) — max. SETTLE_MS
   c. Overlay-Dissolve: neuer Slide blendet über den alten (FADE_MS)
   d. Alten Slide entfernen, dessen destroy() aufrufen
   e. Weiter nach dauerSek — oder, bei meldetEnde, nach dem Ende-Signal
```

Slide↔Slide innerhalb eines Moduls und Modul↔Modul sind damit **derselbe
Codepfad** — es gibt keine zwei Übergangssysteme mehr.

---

## 4. Abbildung der bestehenden Module

| Modul | Slides | Besonderheit |
|---|---|---|
| `bild` | N Slides (je 1 `<img>`) | trivial |
| `ankuendigung` | N Slides (Text-Pill / BG-Bild) | trivial |
| `veranstaltung` | N Slides (Event-Karten) | fetch in `getSlides`; Sonderfall in `modulAnzeigeDauer` entfällt |
| `stundenplan` | 1 Slide (Kursliste) | fetch in `getSlides`; Kartenhöhen-rAF bleibt im Modul |
| `uhrzeit` | 1 Slide, tickt intern | `destroy` räumt Intervall ab |
| `fret` | 1 Slide, pollt intern | `destroy` räumt Poll + rAF ab |
| `video` | N Slides, `meldetEnde: true` | YT-/PeerTube-Logik bleibt im Modul; `destroy` zerstört Player |

---

## 5. Migrationsplan (kein Big Bang)

**Etappe 1 — Engine + Adapter: ✅ umgesetzt (Staging-Test ausstehend)**
Engine in `monitor.js` gebaut: `adapterDescriptor` (Alt-Modul → 1
selbstverwalteter Slide), `slideDescriptor` (getSlides-Slide → Descriptor),
`sammleModulSlides`/`sammleSpaltenSlides` (asynchrone Sammlung in stabiler
Reihenfolge), `spieleSlides` (Anzeige-Loop mit Settle + Overlay-Dissolve,
ersetzt `rotateModule`; Einzel-Slide-Spalten laufen durch denselben Pfad).
`destroyContainer` als zentraler Cleanup-Wrapper (Descriptor-`destroy` oder
low-level `cleanupModulContainer`). `module-loader.js`: neue Methode
`TanzschuleLoader.lade(modulId, cb)` liefert die rohe Registrierung;
`onerror` ruft den Callback trotzdem (eine defekte Modul-Datei blockiert
keine Spalte mehr). `meldetEnde`-Pfad ist implementiert (`slide.onEnde` +
15-Min-Sicherheits-Timeout), wird aber erst ab Etappe 2/3 real genutzt.
Playlist-Timer bleibt die synchrone Schätzung via `modulAnzeigeDauer`.
**Hinweis für Etappe 2:** `TanzschuleLoader.render()` (genutzt von
`playlist-preview.php`) kann nur Alt-Stil-Module rendern — beim Portieren
der ersten Module muss die Vorschau mitgezogen werden (Engine-Zugriff oder
Mini-Player im Loader).

**Etappe 2 — Die drei Rotierer portieren: ✅ umgesetzt (Staging-Test ausstehend)**
`bild`, `ankuendigung`, `veranstaltung` liefern nur noch Slides via
`getSlides` — die 3× duplizierte Rotations-/Transitions-Logik ist gelöscht
(bild 111→64, ankuendigung 141→100, veranstaltung: Fetch in `getSlides`,
A/B-Layer entfallen). Engine-Erweiterungen dabei:
- **`uebergang` je Slide** (`'fade'`/`'none'`): Instanz-Einstellung „Kein
  Übergang" → harter Schnitt nach der Settle-Phase (Entscheidung Nr. 2).
- **Zyklus-Refresh (`neuSammeln`):** nach jeder vollen Rotationsrunde
  sammelt die Engine die Slides der Spalte asynchron neu → erhält das
  bisherige Verhalten „jede Runde rendert/fetcht frisch" (veranstaltung-
  Events bleiben aktuell); bis dahin läuft die alte Sequenz weiter.
  Einzel-Slide-Spalten refreshen wie bisher nur über den Monitor-Zyklus.
- **Engine-Export:** `window.TanzschuleEngine.renderSpalten`;
  `window.TM_ENGINE_ONLY = true` (vor monitor.js gesetzt) unterbindet den
  Monitor-Betrieb (kein Polling) — genutzt von `admin/playlist-preview.php`,
  deren eigene (duplizierte) Rotations-Logik ersatzlos entfallen ist.
**Sichtbare Vereinheitlichung:** Übergänge innerhalb einer Instanz laufen
jetzt über die Engine (Settle 800 ms + Dissolve 1500 ms) statt der alten
modul-eigenen A/B-Fades (600 ms bei ankuendigung/veranstaltung) — alle
Wechsel fühlen sich gleich an; Zykluslängen bleiben unverändert.

**Etappe 3 — Rest portieren, aufräumen: ✅ umgesetzt (Staging-Test ausstehend)**
`stundenplan`, `uhrzeit`, `fret`, `video` auf `getSlides` portiert; Adapter
(`adapterDescriptor`, `renderModulInContainer`, `skaliereMod`) entfernt —
Module ohne `getSlides` werden jetzt mit Konsolen-Fehler übersprungen.
Vertrag um **`onMount(containerEl)`** erweitert: Hook nach dem Einhängen
ins DOM — nötig für Player-Start (video, lazy: beim Sammeln darf noch kein
Video laden/spielen) und Höhenmessung (stundenplan-Karten).
**`meldetEnde`-Semantik:** ruft die Engine `slide.onEnde` (Rotation);
ist onEnde NICHT gesetzt (einziger Slide → keine Rotation), loopt das
Video-Modul selbst (Neustart) — entspricht dem Altverhalten.
**`modulAnzeigeDauer` bleibt bewusst erhalten** (inkl. veranstaltung-
Sonderfall): die synchrone Schätzung wird weiterhin für Playlist-Timer +
Spalten-Skalierungsfaktor gebraucht, weil die Slide-Sammlung asynchron ist.
Nebenbei: Uhr-Modul bekam Analog-Darstellung (SVG-Zifferblatt) + optionales
Mediathek-Hintergrundbild mit Transparenz-Pill; dafür neuer Setting-Typ
`mediathek_bild` (ModuleRegistry + eigener Picker in instanz.php).
Tote Stage-/Layer-CSS-Blöcke entfernt.

---

## 6. Entschiedene Fragen (mit Nutzer geklärt, 07/2026)

1. **Daten-Refresh langlebiger Slides: wie heute.** Der ~60-Sek.-
   Gesamtrefresh des Monitors bleibt zuständig; der Slide-Vertrag bekommt
   **kein** `refreshSek`. Bewährtes Verhalten, keine zusätzliche
   Engine-Komplexität.
2. **Übergangstyp: pro Instanz.** `settings.uebergang` (fade/none) bleibt
   Instanz-Einstellung wie heute; die Engine respektiert es je
   Slide-Sequenz. Keine Migration bestehender Einstellungen nötig.
3. **Fehlerfall: Fehler-Slide.** Wenn `getSlides` keine Daten bekommt
   (API down), liefert das Modul einen Slide mit Hinweistext (wie heute,
   z. B. „Stundenplan konnte nicht geladen werden") — Ausfälle sind am
   Monitor sofort sichtbar.
4. **Speicherort: in `monitor.js` integrieren** (klar gegliederte
   Abschnitte), **keine** eigene Datei. Begründung: Die Live-Monitor-HTMLs
   (`saal1–3`, `bar`) liegen nicht im Repo/CI — ein neues Script-Tag
   müsste manuell per FTP in jede eingetragen werden (Fehlerrisiko).
   In `monitor.js` erreicht die Engine alle Monitore automatisch beim
   nächsten Deploy. Netto-Größe bleibt ~gleich (Engine +120 Zeilen,
   `rotateModule`/`modulAnzeigeDauer`-Sonderfälle/`skaliereMod` −100).

---

## 7. Bezug zum Bugfix Schritt 19 (bereits umgesetzt)

Der „temporäre" Fix ist bewusst so gebaut, dass er zur Engine hinführt:

- **Deckender Hintergrund auf `.tm-modul-container`** (`#0a0a0a`) → Overlay-
  Dissolve funktioniert unabhängig davon, ob ein Modul seinen Container
  flächig füllt. Die Engine übernimmt dieses Prinzip 1:1.
- **`MODUL_SETTLE_MS = 800` in `rotateModule`** → Pre-Render vor dem Fade.
  Die Engine ersetzt die feste Wartezeit später durch echtes Lade-Feedback
  (`img.decode()`), behält aber den Timeout als Obergrenze.
- **Rotation-Freeze beim Fade-Start** (`_tmTimeout` des alten Containers) →
  wird in der Engine überflüssig, weil sie alle Timer selbst besitzt.
