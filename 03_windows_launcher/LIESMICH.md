# Windows-Launcher (Schritt 34)

Ein Klick-Symbol in der Taskleiste des Saal-PCs, das Chrome im Vollbild auf
`saalN.tcpayer.de` öffnet. Für die Mitarbeiter, damit niemand eine Adresse
eintippen oder `F11` drücken muss.

## Wo liegt was

| Datei | Zweck |
|---|---|
| `admin/launcher.php` | Ausgabeseite im Backend (Erklärung + Download-Knopf), Nav-Punkt „Launcher" |
| `admin/launcher-download.php` | Baut die Datei beim Download, füllt die Platzhalter der Vorlage |
| `admin/launcher/Einrichten.cmd.tpl` | **Die Vorlage — hier wird der Launcher gepflegt** |
| `admin/launcher/.htaccess` | Sperrt den Direktaufruf der Vorlage |
| `assets/img/monitor-launcher.ico` | Symbol der Verknüpfung |
| `03_windows_launcher/icon-bauen.py` | Erzeugt das `.ico` neu (ohne Bildbibliothek) |

Alles unter `02_ordnerstruktur/screen.tcpayer.de/` wird per CI/CD mitdeployt.
Dieser Ordner hier enthält nur Werkzeug und Doku.

## Warum kein `.exe`

Ein kompiliertes Programm bringt hier nur Nachteile: Virenscanner schlagen bei
unbekannten `.exe`-Dateien an, die CI hat keinen Windows-Build-Schritt, und
jede geänderte Saal-Adresse erzwänge ein neues Kompilat. Was die Taskleiste
tatsächlich anpinnt, ist ohnehin eine **Windows-Verknüpfung** — das „Programm"
muss also nur diese Verknüpfung anlegen.

## Aufbau der Vorlage

`Einrichten.cmd.tpl` ist eine Datei mit zwei Hälften:

1. **Batch-Teil** (oben, bewusst reines ASCII): startet PowerShell, übergibt
   den eigenen Dateipfad und beendet sich mit `exit /b`.
2. **PowerShell-Teil** (unterhalb der Markierung `#PS-START`): das eigentliche
   Programm mit dem Einrichtungsfenster.

Damit genügt **eine** Datei ohne Installation und ohne Zubehör zum Entpacken.

Die Markierung wird im Batch-Teil als `'#PS'+'-START'` zusammengesetzt — stünde
sie dort ausgeschrieben, fände `IndexOf` sie in der eigenen Startzeile und
PowerShell begänne mitten im Batch-Teil.

### ⚠️ Keine typografischen Anführungszeichen im PowerShell-Teil

PowerShell behandelt `„`, `“` und `”` wie echte Zeichenketten-Begrenzer. Ein
`"… »Monitore" …"` beendet die Zeichenkette vorzeitig und das Skript ist
kaputt. In Zeichenketten deshalb **Guillemets** (`»…«`) verwenden. In
Kommentaren ist es egal, aber der Einheitlichkeit halber gilt es überall.

### Platzhalter

| Platzhalter | Wird ersetzt durch |
|---|---|
| `{{MONITORE}}` | PowerShell-Liste aus `Monitor::listAll()` (Name, Domain, URL, Profil-Slug) |
| `{{ICON_URL}}` | Adresse des `.ico` auf dem aufrufenden Host (funktioniert so auch auf Staging) |
| `{{ERZEUGT}}` / `{{HERKUNFT}}` | Zeitstempel und Host im Kopfkommentar |

`launcher-download.php` wandelt zum Schluss auf **CRLF** und liefert
UTF-8 **ohne BOM** — ein BOM in der ersten Zeile lässt `cmd.exe` stolpern.

## Chrome-Startparameter

```
--kiosk --app="https://saal1.tcpayer.de/"
--user-data-dir="%LOCALAPPDATA%\TanzschuleMonitor\saal1_tcpayer_de"
--no-first-run --no-default-browser-check
--disable-session-crashed-bubble --noerrdialogs
--disable-features=TranslateUI
--autoplay-policy=no-user-gesture-required
--overscroll-history-navigation=0
```

- `--app` gibt dem Fenster eine eigene Windows-App-Kennung. Ohne das rutscht
  der Monitor in der Taskleiste unter das normale Chrome-Symbol.
- `--user-data-dir` je Saal verhindert das gelbe Band „Wiederherstellen?" nach
  einem Stromausfall — der häufigste Grund, warum so ein Monitor morgens
  „kaputt" aussieht.
- `--autoplay-policy` hält dem `video`-Modul den Rücken frei.
- Der Pfad wird beim Anlegen der Verknüpfung **aufgelöst**: Umgebungsvariablen
  in den Argumenten einer `.lnk` expandiert Windows beim Start nicht.

## Autostart

Optional per Kästchen. Legt eine zweite Verknüpfung in `shell:startup` an,
benannt `Tanzschule Monitor (<Name>).lnk`. Beim Einschalten werden **alle**
Verknüpfungen dieses Musters entfernt, bevor die neue entsteht — sonst öffnen
sich nach dem Hochfahren zwei Vollbild-Fenster übereinander.

Die Haken zeigen beim Öffnen den IST-Zustand des gewählten Monitors; das
Fenster ist damit zugleich das Werkzeug zum Abschalten.

## Anpinnen bleibt Handarbeit

Windows lässt das Anheften an die Taskleiste seit Version 10 nicht mehr per
Skript zu (die alte `Verb`-Methode über die Shell wurde entfernt). Das Fenster
weist am Ende darauf hin; ein Weg daran vorbei existiert nicht.

## Icon neu bauen

```bash
python3 03_windows_launcher/icon-bauen.py
```

Schreibt `assets/img/monitor-launcher.ico` (256/48/32/16 px, PNG-Einträge).
Ohne Pillow und ohne ImageMagick — das Motiv ist geometrisch, PNG und ICO
werden direkt geschrieben. So ist das Symbol in jeder Umgebung reproduzierbar.

## Prüfen ohne Windows

Der PowerShell-Teil lässt sich unter Linux syntaktisch prüfen (PowerShell 7
als Tarball genügt, WinForms braucht es dafür nicht):

```powershell
$f = $null; $t = $null
[System.Management.Automation.Language.Parser]::ParseFile('tail.ps1', [ref]$t, [ref]$f)
$f   # leer = in Ordnung
```

`tail.ps1` ist alles hinter der Markierung `#PS-START` der **erzeugten** Datei
(nicht der Vorlage — die enthält noch Platzhalter, die kein gültiges
PowerShell sind).
