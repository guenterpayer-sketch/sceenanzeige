<?php
/**
 * admin/launcher.php
 *
 * Ausgabeseite für den Windows-Launcher: erklärt den Ablauf und bietet die
 * Einrichtungsdatei zum Herunterladen an (gebaut von launcher-download.php).
 *
 * Bewusst NICHT auf Administratoren beschränkt — die Datei enthält nichts
 * Vertrauliches (nur die öffentlichen Monitor-Adressen) und soll von den
 * Mitarbeitern selbst geholt werden können, die den Saal-PC einrichten.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$monitore = Monitor::listAll();
$alsZip   = class_exists('ZipArchive');

admin_header('Windows-Launcher', 'launcher');
?>

<details class="adm-hilfe-klapp">
    <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
    <p class="adm-hilfe">
        Damit am Saal-PC niemand eine Adresse eintippen oder <kbd>F11</kbd> drücken
        muss, legt diese Datei eine <strong>Verknüpfung</strong> an: ein Klick darauf
        öffnet Chrome im Vollbild auf der Monitor-Seite des gewählten Saales.
        Ein echtes Installationsprogramm ist dafür nicht nötig — und wäre sogar
        hinderlich, weil Virenscanner unbekannte <code>.exe</code>-Dateien gerne
        blockieren. Die Verknüpfung startet Chrome mit einem <strong>eigenen
        Profil je Saal</strong>; damit erscheint nach einem Stromausfall kein gelbes
        Band „Wiederherstellen?" mehr, und der Monitor bleibt von den Tabs und
        Konten des normalen Chrome getrennt.
    </p>
</details>

<?php if (empty($monitore)): ?>
    <p class="adm-leer">
        Noch kein Monitor angelegt — ohne Monitore hat der Launcher nichts zum Anzeigen.
        Erst unter <a href="monitore.php">Monitore</a> einen Saal anlegen.
    </p>
<?php else: ?>

<div class="adm-card">
    <h2>1. Datei herunterladen</h2>
    <p>
        Die Datei wird beim Herunterladen frisch gebaut und enthält die
        <strong><?= count($monitore) ?> unten aufgeführten Monitore</strong> zur Auswahl.
        Wird später ein Saal umbenannt oder neu angelegt, einfach neu herunterladen.
    </p>
    <div class="adm-aktionsleiste">
        <a class="adm-btn-primary" href="launcher-download.php">
            ⬇️ <?= $alsZip ? 'Monitor-Launcher.zip' : 'Einrichten.cmd' ?> herunterladen
        </a>
    </div>
    <?php if (!$alsZip): ?>
        <p class="adm-hilfe">
            Hinweis: Der Browser stuft <code>.cmd</code>-Dateien als gefährlich ein und
            fragt beim Herunterladen nach — mit „Beibehalten" bestätigen.
        </p>
    <?php endif; ?>
</div>

<div class="adm-card">
    <h2>2. Am Saal-PC einrichten</h2>
    <p>
        <?php if ($alsZip): ?>ZIP entpacken, dann <?php endif; ?>
        <code>Einrichten.cmd</code> doppelklicken. Es öffnet sich ein Fenster mit
        drei Angaben:
    </p>
    <ul class="adm-hilfe">
        <li><strong>Welcher Monitor</strong> — der Saal, den dieser PC anzeigen soll.</li>
        <li><strong>Verknüpfung auf dem Desktop anlegen</strong> — das Symbol zum Anklicken.</li>
        <li><strong>Beim Hochfahren automatisch starten</strong> — optional. Angehakt läuft
            der Monitor nach dem Einschalten von selbst los, ohne dass jemand klickt.
            Es kann immer nur <em>ein</em> Saal automatisch starten.</li>
    </ul>
    <p class="adm-hilfe">
        Windows fragt beim ersten Start einmal nach („Möchten Sie diese Datei
        ausführen?"), weil die Datei aus dem Internet stammt — mit „Ausführen"
        bestätigen.
    </p>
    <p class="adm-hilfe">
        Umentscheiden geht jederzeit: <code>Einrichten.cmd</code> noch einmal starten.
        Die Haken zeigen den aktuellen Stand — Haken weg und „Einrichten" entfernt
        die Verknüpfung bzw. den Autostart wieder.
    </p>
</div>

<div class="adm-card">
    <h2>3. An die Taskleiste anheften</h2>
    <p>
        Rechtsklick auf das neue Desktop-Symbol → <strong>„An Taskleiste anheften"</strong>.
        Diesen einen Handgriff kann kein Programm übernehmen: Windows lässt das
        Anheften seit Version 10 nicht mehr automatisiert zu.
    </p>
    <p class="adm-hilfe">
        Beenden lässt sich der Vollbild-Monitor mit <kbd>Alt</kbd>&nbsp;+&nbsp;<kbd>F4</kbd>.
        Voraussetzung für alles: Google Chrome ist auf dem PC installiert.
    </p>
</div>

<div class="adm-card">
    <h2>Enthaltene Monitore</h2>
    <table class="adm-tabelle">
        <thead>
            <tr><th>Name</th><th>Adresse</th></tr>
        </thead>
        <tbody>
        <?php foreach ($monitore as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['name']) ?></td>
                <td><code>https://<?= htmlspecialchars($m['subdomain']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<?php
admin_footer();
