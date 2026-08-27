<?php
/**
 * admin/launcher-download.php
 *
 * Liefert die Einrichtungsdatei für den Windows-Launcher aus.
 *
 * Die Vorlage liegt als launcher/Einrichten.cmd.tpl im Repository; hier
 * werden nur die Platzhalter gefüllt — vor allem die Monitor-Liste, die
 * damit IMMER dem aktuellen Stand der Tabelle `monitore` entspricht. Ein
 * Saal, der im Backend umbenannt oder neu angelegt wird, steht ohne
 * Zutun in der nächsten heruntergeladenen Datei.
 *
 * Ausgabeformat: ZIP, wenn die PHP-Installation ZipArchive mitbringt
 * (Browser laden ZIP kommentarlos herunter, eine nackte .cmd stufen sie
 * als gefährlich ein und fragen nach). Sonst direkt die .cmd als
 * Notausgang — besser eine Rückfrage im Browser als gar kein Download.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

/** Wert für eine einfach gequotete PowerShell-Zeichenkette absichern. */
function tm_ps_wert(string $s): string
{
    return str_replace("'", "''", $s);
}

$monitore = Monitor::listAll();

$psZeilen = [];
foreach ($monitore as $m) {
    $domain = (string)$m['subdomain'];

    // Profil-Ordnername je Monitor: aus der Domain abgeleitet, damit jeder
    // Saal ein eigenes Chrome-Profil bekommt (siehe Vorlage, --user-data-dir).
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($domain)), '_');
    if ($slug === '') {
        $slug = 'monitor' . (int)$m['id'];
    }

    $psZeilen[] = '    [pscustomobject]@{ Name = \'' . tm_ps_wert((string)$m['name'])
        . '\'; Domain = \'' . tm_ps_wert($domain)
        . '\'; Url = \'https://' . tm_ps_wert($domain) . '/'
        . '\'; Slug = \'' . tm_ps_wert($slug) . '\' }';
}

// Host für Symbol-URL und Herkunftsvermerk — auf Zeichen beschränken, die in
// einem Hostnamen vorkommen dürfen (der Header ist Client-Eingabe).
$host = (string)preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host === '') {
    $host = 'screen.tcpayer.de';
}
$schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$vorlage = @file_get_contents(__DIR__ . '/launcher/Einrichten.cmd.tpl');
if ($vorlage === false) {
    http_response_code(500);
    exit('Vorlage launcher/Einrichten.cmd.tpl nicht gefunden.');
}

$cmd = strtr($vorlage, [
    '{{MONITORE}}' => implode("\n", $psZeilen),
    '{{ICON_URL}}' => tm_ps_wert($schema . '://' . $host . '/assets/img/monitor-launcher.ico'),
    '{{ERZEUGT}}'  => date('d.m.Y H:i'),
    '{{HERKUNFT}}' => $host,
]);

// Windows-Zeilenenden. Die Datei wird von cmd.exe zeilenweise gelesen —
// gemischte Zeilenenden sind eine der klassischen Fehlerquellen.
$cmd = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $cmd);

$liesmich = "Tanzschule Monitor-Launcher\r\n"
    . "===========================\r\n\r\n"
    . "1. Einrichten.cmd doppelklicken.\r\n"
    . "2. Im Fenster den Saal auswaehlen und auf \"Einrichten\" klicken.\r\n"
    . "3. Auf dem Desktop erscheint ein Symbol. Rechtsklick darauf\r\n"
    . "   -> \"An Taskleiste anheften\".\r\n\r\n"
    . "Ab jetzt genuegt ein Klick auf das Symbol in der Taskleiste:\r\n"
    . "Chrome oeffnet den Monitor im Vollbild.\r\n\r\n"
    . "Beenden: Alt + F4.\r\n"
    . "Autostart nachtraeglich an- oder abschalten: Einrichten.cmd\r\n"
    . "einfach noch einmal starten.\r\n\r\n"
    . "Voraussetzung: Google Chrome ist auf dem PC installiert.\r\n\r\n"
    . "Erzeugt am " . date('d.m.Y H:i') . " von " . $host . "\r\n";

if (class_exists('ZipArchive')) {
    $tmp = tempnam(sys_get_temp_dir(), 'tmlnch');
    if ($tmp !== false) {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('Einrichten.cmd', $cmd);
            $zip->addFromString('LIESMICH.txt', "\xEF\xBB\xBF" . $liesmich);
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="Monitor-Launcher.zip"');
            header('Content-Length: ' . (string)filesize($tmp));
            header('Cache-Control: no-store');
            readfile($tmp);
            unlink($tmp);
            exit;
        }
        unlink($tmp);
    }
}

// Notausgang ohne ZipArchive: die .cmd unverpackt.
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="Einrichten.cmd"');
header('Content-Length: ' . (string)strlen($cmd));
header('Cache-Control: no-store');
echo $cmd;
