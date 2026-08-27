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

# --- Chrome-Startparameter -------------------------------------------------
# --kiosk      : Vollbild ohne Adressleiste, kein versehentliches Verlassen
# --app        : eigenes Fenster mit eigener Windows-App-Kennung; nur so
#                bekommt der Launcher ein getrenntes Taskleisten-Symbol und
#                landet nicht unter dem normalen Chrome
# --user-data-dir : eigenes Profil je Saal. Verhindert das gelbe Band
#                "Wiederherstellen?" nach einem Stromausfall und haelt den
#                Monitor von den Konten/Tabs des normalen Chrome getrennt
# --autoplay-policy : das Video-Modul darf ohne Mausklick abspielen
function Baue-Argumente($monitor) {
    $profil = Join-Path $Basisordner $monitor.Slug
    return @(
        '--kiosk',
        ('--app="' + $monitor.Url + '"'),
        ('--user-data-dir="' + $profil + '"'),
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-session-crashed-bubble',
        '--noerrdialogs',
        '--disable-features=TranslateUI',
        '--autoplay-policy=no-user-gesture-required',
        '--overscroll-history-navigation=0'
    ) -join ' '
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


# ===========================================================================
#  Einrichtungsfenster
# ===========================================================================
$form = New-Object Windows.Forms.Form
$form.Text            = 'Monitor-Launcher einrichten'
$form.ClientSize      = New-Object Drawing.Size(480, 310)
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

$chkDesktop = New-Object Windows.Forms.CheckBox
$chkDesktop.Text     = 'Verknüpfung auf dem Desktop anlegen'
$chkDesktop.Location = New-Object Drawing.Point(20, 88)
$chkDesktop.Size     = New-Object Drawing.Size(440, 24)
$form.Controls.Add($chkDesktop)

$chkAutostart = New-Object Windows.Forms.CheckBox
$chkAutostart.Text     = 'Beim Hochfahren automatisch starten'
$chkAutostart.Location = New-Object Drawing.Point(20, 114)
$chkAutostart.Size     = New-Object Drawing.Size(440, 24)
$form.Controls.Add($chkAutostart)

$lblAutostartInfo = New-Object Windows.Forms.Label
$lblAutostartInfo.Location  = New-Object Drawing.Point(40, 138)
$lblAutostartInfo.Size      = New-Object Drawing.Size(420, 18)
$lblAutostartInfo.ForeColor = [Drawing.Color]::FromArgb(173, 33, 33)
$form.Controls.Add($lblAutostartInfo)

$lblHinweis = New-Object Windows.Forms.Label
$lblHinweis.Location = New-Object Drawing.Point(20, 176)
$lblHinweis.Size     = New-Object Drawing.Size(440, 76)
$lblHinweis.ForeColor = [Drawing.Color]::FromArgb(90, 90, 90)
$lblHinweis.Text = "An die Taskleiste anheften geht nur von Hand — Windows lässt das " +
                   "seit Version 10 nicht mehr per Skript zu:`n" +
                   "Rechtsklick auf das neue Desktop-Symbol → »An Taskleiste anheften«.`n`n" +
                   "Beenden lässt sich der Vollbild-Monitor mit Alt + F4."
$form.Controls.Add($lblHinweis)

$btnOk = New-Object Windows.Forms.Button
$btnOk.Text     = 'Einrichten'
$btnOk.Location = New-Object Drawing.Point(268, 264)
$btnOk.Size     = New-Object Drawing.Size(95, 30)
$btnOk.DialogResult = [Windows.Forms.DialogResult]::OK
$form.Controls.Add($btnOk)

$btnAbbruch = New-Object Windows.Forms.Button
$btnAbbruch.Text     = 'Abbrechen'
$btnAbbruch.Location = New-Object Drawing.Point(373, 264)
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
    $desktopDa   = Test-Path -LiteralPath (Desktop-Pfad $m)
    $autostartDa = Test-Path -LiteralPath (Autostart-Pfad $m)

    $chkDesktop.Checked   = ($desktopDa -or -not $autostartDa)
    $chkAutostart.Checked = $autostartDa

    $anderer = Aktiver-Autostart
    if (-not $autostartDa -and $anderer) {
        $lblAutostartInfo.Text = 'Derzeit startet »' + $anderer + '« automatisch.'
    } else {
        $lblAutostartInfo.Text = ''
    }
}

$cbMonitor.Add_SelectedIndexChanged({ Aktualisiere-Haken })
Aktualisiere-Haken

if ($form.ShowDialog() -ne [Windows.Forms.DialogResult]::OK) { return }


# ===========================================================================
#  Anlegen / Entfernen
# ===========================================================================
$monitor    = $Monitore[$cbMonitor.SelectedIndex]
$argumente  = Baue-Argumente $monitor
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
if ($chkDesktop.Checked) {
    $text += "`n`nAn die Taskleiste anheften:`nRechtsklick auf das neue Desktop-Symbol → »An Taskleiste anheften«."
}
$text += "`n`nMonitor jetzt zur Probe starten?"

$antwort = [Windows.Forms.MessageBox]::Show($text, 'Monitor-Launcher — fertig', 'YesNo', 'Information')
if ($antwort -eq [Windows.Forms.DialogResult]::Yes) {
    Start-Process -FilePath $Chrome -ArgumentList $argumente
}
