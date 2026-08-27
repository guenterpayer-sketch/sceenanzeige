@echo off
rem ===========================================================================
rem  Tanzschule Monitor-Launcher — Einrichtung
rem
rem  Legt eine Verknuepfung an, die Google Chrome im Vollbild (Kiosk) auf der
rem  Monitor-Seite eines Saales oeffnet. Danach genuegt ein Klick.
rem
rem  Erzeugt am: {{ERZEUGT}}  von {{HERKUNFT}}
rem
rem  Diese Datei ist zugleich ein PowerShell-Skript: der Batch-Teil oben
rem  startet den lesbaren PowerShell-Teil unterhalb der Markierung. Deshalb
rem  eine einzige Datei ohne Installation und ohne Entpacken von Zubehoer.
rem ===========================================================================

title Monitor-Launcher einrichten
echo.
echo   Das Einrichtungsfenster wird geoeffnet ...
echo.

set "TM_SELF=%~f0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$t=[IO.File]::ReadAllText($env:TM_SELF,[Text.Encoding]::UTF8); $m='#PS'+'-START'; Invoke-Expression $t.Substring($t.IndexOf($m)+$m.Length)"
exit /b

#PS-START
# ===========================================================================
#  Ab hier PowerShell. Der Batch-Teil oben hat sich bereits beendet.
# ===========================================================================

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[Windows.Forms.Application]::EnableVisualStyles()

# --- Vom Backend eingesetzte Werte ----------------------------------------
$Monitore = @(
{{MONITORE}}
)
$IconUrl  = '{{ICON_URL}}'

$Basisordner = Join-Path $env:LOCALAPPDATA 'TanzschuleMonitor'
$AutostartMuster = 'Tanzschule Monitor (*).lnk'


function Zeige-Meldung($text, $titel, $symbol) {
    [void][Windows.Forms.MessageBox]::Show($text, $titel, 'OK', $symbol)
}

# --- Chrome suchen ---------------------------------------------------------
# Reihenfolge: Registry (die von Windows registrierte Installation), danach
# die drei ueblichen Installationsorte (systemweit 64/32 Bit, benutzereigen).
function Finde-Chrome {
    $kandidaten = @()
    foreach ($schluessel in @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe',
        'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe')) {
        try {
            $wert = (Get-ItemProperty -Path $schluessel -ErrorAction Stop).'(default)'
            if ($wert) { $kandidaten += $wert }
        } catch { }
    }
    if ($env:ProgramFiles)        { $kandidaten += (Join-Path $env:ProgramFiles        'Google\Chrome\Application\chrome.exe') }
    if (${env:ProgramFiles(x86)}) { $kandidaten += (Join-Path ${env:ProgramFiles(x86)} 'Google\Chrome\Application\chrome.exe') }
    if ($env:LOCALAPPDATA)        { $kandidaten += (Join-Path $env:LOCALAPPDATA        'Google\Chrome\Application\chrome.exe') }

    foreach ($p in $kandidaten) {
        if ($p -and (Test-Path -LiteralPath $p)) { return $p }
    }
    return $null
}

# --- Symbol besorgen (optional; ohne Netz laeuft alles weiter) -------------
function Hole-Icon {
    $ziel = Join-Path $Basisordner 'monitor-launcher.ico'
    try {
        New-Item -ItemType Directory -Path $Basisordner -Force | Out-Null
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        (New-Object Net.WebClient).DownloadFile($IconUrl, $ziel)
        if ((Get-Item -LiteralPath $ziel).Length -gt 0) { return $ziel }
    } catch { }
    return $null
}

# --- Bildschirme ------------------------------------------------------------
# Ohne Positionsangabe oeffnet Chrome immer auf dem Hauptbildschirm. Haengt am
# PC ein Fernseher als erweiterter Bildschirm, ist das der falsche. Windows
# liefert die Liste der angeschlossenen Schirme samt Koordinaten; die
# ausgewaehlten wandern als --window-position/--window-size in die Verknuepfung.
#
# Achtung: Die Reihenfolge von AllScreens entspricht nicht zwingend der
# Nummerierung in den Windows-Anzeigeeinstellungen — deshalb steht die
# Position mit im Text, und am Ende laesst sich das Ergebnis zur Probe starten.
function Hole-Bildschirme {
    $liste = @()
    $alle = [Windows.Forms.Screen]::AllScreens
    for ($i = 0; $i -lt $alle.Count; $i++) {
        $b = $alle[$i].Bounds
        $text = 'Bildschirm ' + ($i + 1) + ' — ' + $b.Width + '×' + $b.Height
        if ($alle[$i].Primary) { $text += ' (Hauptbildschirm)' }
        $text += '   [Position ' + $b.X + ',' + $b.Y + ']'
        $liste += [pscustomobject]@{
            Text = $text; X = $b.X; Y = $b.Y; Breite = $b.Width; Hoehe = $b.Height
            Primaer = $alle[$i].Primary
        }
    }
    return $liste
}

# --- Chrome-Startparameter -------------------------------------------------
# --app        : eigenes Fenster mit eigener Windows-App-Kennung; nur so
#                bekommt der Launcher ein getrenntes Taskleisten-Symbol und
#                landet nicht unter dem normalen Chrome
# --user-data-dir : eigenes Profil je Saal. Verhindert das gelbe Band
#                "Wiederherstellen?" nach einem Stromausfall und haelt den
#                Monitor von den Konten/Tabs des normalen Chrome getrennt
# --window-position/-size : legt fest, auf welchem Bildschirm das Fenster
#                aufgeht, bevor es ins Vollbild wechselt
# --start-fullscreen : Vollbild, aus dem F11 wieder herausfuehrt
# --kiosk      : abgesichertes Vollbild ohne F11 — nur wenn ausdruecklich
#                gewuenscht (Mini-PC hinter dem Fernseher). Ob Chrome dabei
#                die Bildschirmwahl befolgt, muss am Geraet geprueft werden;
#                falls nicht, ist der Haken wieder weg die Loesung.
# --autoplay-policy : das Video-Modul darf ohne Mausklick abspielen
function Baue-Argumente($monitor, $bildschirm, $kiosk) {
    $profil = Join-Path $Basisordner $monitor.Slug
    $teile = @()
    if ($kiosk) { $teile += '--kiosk' } else { $teile += '--start-fullscreen' }
    $teile += ('--app="' + $monitor.Url + '"')
    $teile += ('--user-data-dir="' + $profil + '"')
    if ($bildschirm) {
        $teile += ('--window-position=' + $bildschirm.X + ',' + $bildschirm.Y)
        $teile += ('--window-size=' + $bildschirm.Breite + ',' + $bildschirm.Hoehe)
    }
    $teile += '--no-first-run'
    $teile += '--no-default-browser-check'
    $teile += '--disable-session-crashed-bubble'
    $teile += '--noerrdialogs'
    $teile += '--disable-features=TranslateUI'
    $teile += '--autoplay-policy=no-user-gesture-required'
    $teile += '--overscroll-history-navigation=0'
    return $teile -join ' '
}

# Liest die Einstellungen aus einer vorhandenen Verknuepfung zurueck, damit
# das Fenster beim erneuten Oeffnen den IST-Zustand zeigt (wie beim Autostart).
function Lies-Verknuepfung($pfad) {
    if (-not (Test-Path -LiteralPath $pfad)) { return $null }
    try {
        $shell = New-Object -ComObject WScript.Shell
        $arg = $shell.CreateShortcut($pfad).Arguments
        $x = $null; $y = $null
        if ($arg -match '--window-position=(-?\d+),(-?\d+)') {
            $x = [int]$Matches[1]; $y = [int]$Matches[2]
        }
        return [pscustomobject]@{ Kiosk = ($arg -match '--kiosk'); X = $x; Y = $y }
    } catch {
        return $null
    }
}

function Saeubere-Dateiname($text) {
    return ($text -replace '[\\/:*?"<>|]', '-').Trim()
}

function Neue-Verknuepfung($pfad, $chrome, $argumente, $icon, $beschreibung) {
    $shell = New-Object -ComObject WScript.Shell
    $lnk = $shell.CreateShortcut($pfad)
    $lnk.TargetPath       = $chrome
    $lnk.Arguments        = $argumente
    $lnk.WorkingDirectory = (Split-Path -Parent $chrome)
    $lnk.Description      = $beschreibung
    if ($icon) { $lnk.IconLocation = "$icon,0" } else { $lnk.IconLocation = "$chrome,0" }
    $lnk.Save()
}

function Desktop-Pfad($monitor) {
    return Join-Path ([Environment]::GetFolderPath('Desktop')) ((Saeubere-Dateiname ('Monitor ' + $monitor.Name)) + '.lnk')
}

function Autostart-Pfad($monitor) {
    return Join-Path ([Environment]::GetFolderPath('Startup')) ((Saeubere-Dateiname ('Tanzschule Monitor (' + $monitor.Name + ')')) + '.lnk')
}

# Liefert den Namen des Monitors, der derzeit automatisch startet (oder $null).
function Aktiver-Autostart {
    $treffer = Get-ChildItem -LiteralPath ([Environment]::GetFolderPath('Startup')) -Filter $AutostartMuster -ErrorAction SilentlyContinue
    if ($treffer -and $treffer.Count -ne 0) {
        $name = @($treffer)[0].BaseName
        if ($name -match '^Tanzschule Monitor \((.+)\)$') { return $Matches[1] }
        return $name
    }
    return $null
}


# ===========================================================================
#  Vorpruefungen
# ===========================================================================
if ($Monitore.Count -eq 0) {
    Zeige-Meldung "Im Backend ist noch kein Monitor angelegt.`n`nBitte zuerst unter »Monitore« einen Saal anlegen und diese Datei danach neu herunterladen." 'Monitor-Launcher' 'Warning'
    return
}

$Chrome = Finde-Chrome
if (-not $Chrome) {
    Zeige-Meldung "Google Chrome wurde auf diesem PC nicht gefunden.`n`nBitte Chrome installieren (google.com/chrome) und diese Einrichtung danach erneut starten." 'Monitor-Launcher' 'Error'
    return
}

$Icon = Hole-Icon
$Bildschirme = Hole-Bildschirme


# ===========================================================================
#  Einrichtungsfenster
# ===========================================================================
$form = New-Object Windows.Forms.Form
$form.Text            = 'Monitor-Launcher einrichten'
$form.ClientSize      = New-Object Drawing.Size(480, 400)
$form.StartPosition   = 'CenterScreen'
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox     = $false
$form.MinimizeBox     = $false
$form.Font            = New-Object Drawing.Font('Segoe UI', 9)
if ($Icon) { try { $form.Icon = New-Object Drawing.Icon($Icon) } catch { } }

$lblFrage = New-Object Windows.Forms.Label
$lblFrage.Text     = 'Welcher Monitor soll auf diesem PC angezeigt werden?'
$lblFrage.Location = New-Object Drawing.Point(20, 20)
$lblFrage.Size     = New-Object Drawing.Size(440, 20)
$form.Controls.Add($lblFrage)

$cbMonitor = New-Object Windows.Forms.ComboBox
$cbMonitor.DropDownStyle = 'DropDownList'
$cbMonitor.Location      = New-Object Drawing.Point(20, 44)
$cbMonitor.Size          = New-Object Drawing.Size(440, 24)
foreach ($m in $Monitore) {
    [void]$cbMonitor.Items.Add($m.Name + '   (' + $m.Domain + ')')
}
$cbMonitor.SelectedIndex = 0
$form.Controls.Add($cbMonitor)

$lblSchirm = New-Object Windows.Forms.Label
$lblSchirm.Text     = 'Auf welchem Bildschirm?'
$lblSchirm.Location = New-Object Drawing.Point(20, 82)
$lblSchirm.Size     = New-Object Drawing.Size(440, 20)
$form.Controls.Add($lblSchirm)

$cbSchirm = New-Object Windows.Forms.ComboBox
$cbSchirm.DropDownStyle = 'DropDownList'
$cbSchirm.Location      = New-Object Drawing.Point(20, 106)
$cbSchirm.Size          = New-Object Drawing.Size(440, 24)
foreach ($b in $Bildschirme) { [void]$cbSchirm.Items.Add($b.Text) }
$form.Controls.Add($cbSchirm)
# Bei mehreren Bildschirmen ist der erste Nicht-Hauptbildschirm vorgewählt:
# der Fernseher haengt in aller Regel als erweiterter Schirm am Arbeits-PC.
$vorwahl = 0
if ($Bildschirme.Count -gt 1) {
    for ($i = 0; $i -lt $Bildschirme.Count; $i++) {
        if (-not $Bildschirme[$i].Primaer) { $vorwahl = $i; break }
    }
}
$cbSchirm.SelectedIndex = $vorwahl
if ($Bildschirme.Count -le 1) { $cbSchirm.Enabled = $false }

$chkDesktop = New-Object Windows.Forms.CheckBox
$chkDesktop.Text     = 'Verknüpfung auf dem Desktop anlegen'
$chkDesktop.Location = New-Object Drawing.Point(20, 148)
$chkDesktop.Size     = New-Object Drawing.Size(440, 24)
$form.Controls.Add($chkDesktop)

$chkAutostart = New-Object Windows.Forms.CheckBox
$chkAutostart.Text     = 'Beim Hochfahren automatisch starten'
$chkAutostart.Location = New-Object Drawing.Point(20, 174)
$chkAutostart.Size     = New-Object Drawing.Size(440, 24)
$form.Controls.Add($chkAutostart)

$lblAutostartInfo = New-Object Windows.Forms.Label
$lblAutostartInfo.Location  = New-Object Drawing.Point(40, 198)
$lblAutostartInfo.Size      = New-Object Drawing.Size(420, 18)
$lblAutostartInfo.ForeColor = [Drawing.Color]::FromArgb(173, 33, 33)
$form.Controls.Add($lblAutostartInfo)

$chkKiosk = New-Object Windows.Forms.CheckBox
$chkKiosk.Text     = 'Vollbild absichern (Kiosk — kein F11)'
$chkKiosk.Location = New-Object Drawing.Point(20, 220)
$chkKiosk.Size     = New-Object Drawing.Size(440, 24)
$form.Controls.Add($chkKiosk)

$lblKioskInfo = New-Object Windows.Forms.Label
$lblKioskInfo.Location  = New-Object Drawing.Point(40, 244)
$lblKioskInfo.Size      = New-Object Drawing.Size(420, 32)
$lblKioskInfo.ForeColor = [Drawing.Color]::FromArgb(90, 90, 90)
$lblKioskInfo.Text = "Nur für PCs, die nichts anderes tun. Landet der Monitor damit auf dem " +
                     "falschen Bildschirm, diesen Haken wieder entfernen."
$form.Controls.Add($lblKioskInfo)

$lblHinweis = New-Object Windows.Forms.Label
$lblHinweis.Location = New-Object Drawing.Point(20, 286)
$lblHinweis.Size     = New-Object Drawing.Size(440, 62)
$lblHinweis.ForeColor = [Drawing.Color]::FromArgb(90, 90, 90)
$lblHinweis.Text = "An die Taskleiste anheften geht nur von Hand: Rechtsklick auf das neue " +
                   "Desktop-Symbol → »An Taskleiste anheften«.`n`n" +
                   "Vollbild verlassen: F11 · Monitor schließen: Alt + F4"
$form.Controls.Add($lblHinweis)

$btnOk = New-Object Windows.Forms.Button
$btnOk.Text     = 'Einrichten'
$btnOk.Location = New-Object Drawing.Point(268, 354)
$btnOk.Size     = New-Object Drawing.Size(95, 30)
$btnOk.DialogResult = [Windows.Forms.DialogResult]::OK
$form.Controls.Add($btnOk)

$btnAbbruch = New-Object Windows.Forms.Button
$btnAbbruch.Text     = 'Abbrechen'
$btnAbbruch.Location = New-Object Drawing.Point(373, 354)
$btnAbbruch.Size     = New-Object Drawing.Size(95, 30)
$btnAbbruch.DialogResult = [Windows.Forms.DialogResult]::Cancel
$form.Controls.Add($btnAbbruch)

$form.AcceptButton = $btnOk
$form.CancelButton = $btnAbbruch

# Haken spiegeln den IST-Zustand des gewählten Monitors wider — beim Öffnen
# und bei jedem Wechsel in der Liste. So wird aus »Einrichten« zugleich das
# Werkzeug zum Wieder-Abschalten.
function Aktualisiere-Haken {
    $m = $Monitore[$cbMonitor.SelectedIndex]
    $desktopPfad = Desktop-Pfad $m
    $autoPfad    = Autostart-Pfad $m
    $desktopDa   = Test-Path -LiteralPath $desktopPfad
    $autostartDa = Test-Path -LiteralPath $autoPfad

    $chkDesktop.Checked   = ($desktopDa -or -not $autostartDa)
    $chkAutostart.Checked = $autostartDa

    $anderer = Aktiver-Autostart
    if (-not $autostartDa -and $anderer) {
        $lblAutostartInfo.Text = 'Derzeit startet »' + $anderer + '« automatisch.'
    } else {
        $lblAutostartInfo.Text = ''
    }

    # Bildschirm und Kiosk-Haken aus einer schon vorhandenen Verknüpfung
    # zurücklesen. Passt die gespeicherte Position zu keinem angeschlossenen
    # Bildschirm mehr (Fernseher abgesteckt), bleibt die Vorauswahl stehen.
    $alt = Lies-Verknuepfung $desktopPfad
    if (-not $alt) { $alt = Lies-Verknuepfung $autoPfad }
    if ($alt) {
        $chkKiosk.Checked = $alt.Kiosk
        if ($null -ne $alt.X) {
            for ($i = 0; $i -lt $Bildschirme.Count; $i++) {
                if ($Bildschirme[$i].X -eq $alt.X -and $Bildschirme[$i].Y -eq $alt.Y) {
                    $cbSchirm.SelectedIndex = $i
                    break
                }
            }
        }
    }
}

$cbMonitor.Add_SelectedIndexChanged({ Aktualisiere-Haken })
Aktualisiere-Haken

if ($form.ShowDialog() -ne [Windows.Forms.DialogResult]::OK) { return }


# ===========================================================================
#  Anlegen / Entfernen
# ===========================================================================
$monitor    = $Monitore[$cbMonitor.SelectedIndex]
$bildschirm = $null
if ($cbSchirm.SelectedIndex -ge 0) { $bildschirm = $Bildschirme[$cbSchirm.SelectedIndex] }
$argumente  = Baue-Argumente $monitor $bildschirm $chkKiosk.Checked
$desktopLnk = Desktop-Pfad $monitor
$autoLnk    = Autostart-Pfad $monitor
$getan      = @()

try {
    New-Item -ItemType Directory -Path (Join-Path $Basisordner $monitor.Slug) -Force | Out-Null

    if ($chkDesktop.Checked) {
        Neue-Verknuepfung $desktopLnk $Chrome $argumente $Icon ($monitor.Name + ' im Vollbild anzeigen')
        $getan += '• Desktop-Verknüpfung »' + (Split-Path -Leaf $desktopLnk).Replace('.lnk','') + '« angelegt'
    } elseif (Test-Path -LiteralPath $desktopLnk) {
        Remove-Item -LiteralPath $desktopLnk -Force
        $getan += '• Desktop-Verknüpfung entfernt'
    }

    if ($chkAutostart.Checked) {
        # Immer nur EIN Monitor im Autostart — sonst öffnen sich nach dem
        # Hochfahren zwei Vollbild-Fenster übereinander.
        Get-ChildItem -LiteralPath ([Environment]::GetFolderPath('Startup')) -Filter $AutostartMuster -ErrorAction SilentlyContinue |
            ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
        Neue-Verknuepfung $autoLnk $Chrome $argumente $Icon ($monitor.Name + ' beim Hochfahren starten')
        $getan += '• Autostart eingerichtet'
    } elseif (Test-Path -LiteralPath $autoLnk) {
        Remove-Item -LiteralPath $autoLnk -Force
        $getan += '• Autostart entfernt'
    }
} catch {
    Zeige-Meldung ("Die Einrichtung ist fehlgeschlagen:`n`n" + $_.Exception.Message) 'Monitor-Launcher' 'Error'
    return
}

if ($getan.Count -eq 0) {
    Zeige-Meldung 'Es war nichts zu tun — beide Kästchen waren leer und es gab nichts zu entfernen.' 'Monitor-Launcher' 'Information'
    return
}

$text = ($monitor.Name + ' — ' + $monitor.Domain + "`n`n" + ($getan -join "`n"))
if ($bildschirm) {
    $text += "`n• " + $bildschirm.Text
}
if ($chkKiosk.Checked) {
    $text += "`n• Vollbild abgesichert (kein F11)"
} else {
    $text += "`n• Vollbild, F11 führt heraus"
}
if ($chkDesktop.Checked) {
    $text += "`n`nAn die Taskleiste anheften:`nRechtsklick auf das neue Desktop-Symbol → »An Taskleiste anheften«."
}
# Die Probe ist der einzige verlässliche Test, ob der richtige Bildschirm
# getroffen wird — die Nummerierung von Windows und die Reihenfolge von
# AllScreens müssen nicht übereinstimmen.
$text += "`n`nMonitor jetzt zur Probe starten? (Prüft, ob der Bildschirm stimmt.)"

$antwort = [Windows.Forms.MessageBox]::Show($text, 'Monitor-Launcher — fertig', 'YesNo', 'Information')
if ($antwort -eq [Windows.Forms.DialogResult]::Yes) {
    Start-Process -FilePath $Chrome -ArgumentList $argumente
}
