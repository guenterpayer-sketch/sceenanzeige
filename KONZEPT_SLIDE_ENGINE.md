# Konzept: Slide-Engine — Trennung von Inhalt und Präsentation

> **Status:** Konzept (Schritt 20, geplant). Noch nicht begonnen.
> **Voraussetzung:** Der Übergangs-Bugfix aus Schritt 19 (Overlay-Dissolve +
> Settle-Phase in `rotateModule`) ist live getestet und stabil.

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

**Etappe 1 — Engine + Adapter:**
Engine in `monitor.js` (oder eigene `slide-engine.js`) bauen. Alt-Module
(Funktions-Signatur `function(container, settings, inhalte)`) werden per
Adapter als „1 Slide, der sich selbst verwaltet" gewrappt → alles läuft
unverändert weiter. Staging-Test.

**Etappe 2 — Die drei Rotierer portieren:**
`bild`, `ankuendigung`, `veranstaltung` auf `getSlides` umstellen — größter
Gewinn, die duplizierte Rotations-Logik verschwindet. Jedes Modul einzeln
testen: allein in Spalte, kombiniert in Spalte, Playlist-Wechsel.

**Etappe 3 — Rest portieren, aufräumen:**
`stundenplan`, `uhrzeit`, `fret`, `video` umstellen; Adapter entfernen;
`modulAnzeigeDauer`/`skaliereMod`-Sonderfälle entfernen;
`playlist-preview.php` auf die Engine umstellen.

---

## 6. Offene Fragen (vor Etappe 1 klären)

1. **Daten-Refresh langlebiger Slides:** Stundenplan-Slide steht ggf. lange
   allein in einer Spalte → wann werden Kursdaten neu geholt? Heute löst das
   der ~60-Sek.-Gesamtrefresh des Monitors — bleibt das so, oder bekommt der
   Slide-Vertrag ein optionales `refreshSek`?
2. **Übergangstyp pro Instanz:** `settings.uebergang` (fade/none) muss von der
   Engine respektiert werden — pro Slide-Sequenz oder global?
3. **Fehler-Slides:** Wenn `getSlides` fehlschlägt (API down), liefert das
   Modul einen Fehler-Slide oder meldet es der Engine (die dann die Instanz
   überspringt)?
4. **Speicherort:** Engine in `monitor.js` integrieren oder als eigene Datei
   `assets/js/slide-engine.js` (muss dann in `saalN/index.html` +
   `playlist-preview.php` eingebunden werden — Cache-Verhalten beachten,
   `module-loader.js` wird ohne Cache-Buster geladen)?

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
