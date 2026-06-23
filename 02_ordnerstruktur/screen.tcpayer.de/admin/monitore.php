<?php
/**
 * admin/monitore.php
 *
 * Monitor-Verwaltung (monitor-zentrisches Modell). Anlegen/Bearbeiten/Löschen
 * je Monitor: Name + Subdomain. Pro Monitor führt „Zeitplan" zum Zeitplan-
 * Editor (monitor.php), in dem festgelegt wird, welche Playlist wann läuft.
 *
 * Bearbeiten: ?edit=<id> füllt das Formular vor; Speichern aktualisiert.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$fehler = [];
$flash  = null;

$formId   = 0;
$formName = '';
$formSub  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { Monitor::delete($id); }
        header('Location: monitore.php?geloescht=1');
        exit;
    }

    if ($aktion === 'speichern') {
        $formId   = (int)($_POST['id'] ?? 0);
        $formName = trim((string)($_POST['name'] ?? ''));
        $formSub  = Monitor::normSubdomain((string)($_POST['subdomain'] ?? ''));
        $istNeu   = ($formId === 0);

        if ($formName === '') {
            $fehler[] = 'Bitte einen Namen für den Monitor angeben.';
        }
        if ($formSub === '') {
            $fehler[] = 'Bitte eine gültige Subdomain angeben (z.B. „saal1").';
        } elseif (Monitor::subdomainExistiert($formSub, $istNeu ? null : $formId)) {
            $fehler[] = 'Die Subdomain „' . htmlspecialchars($formSub) . '" ist bereits vergeben.';
        }

        if (empty($fehler)) {
            if ($istNeu) {
                Monitor::create($formName, $formSub);
            } else {
                Monitor::update($formId, $formName, $formSub);
            }
            header('Location: monitore.php?gespeichert=1');
            exit;
        }
    }
}

if (empty($fehler) && $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $monitor = Monitor::find((int)$_GET['edit']);
    if ($monitor) {
        $formId   = (int)$monitor['id'];
        $formName = $monitor['name'];
        $formSub  = $monitor['subdomain'];
    }
}

if (isset($_GET['geloescht']))   { $flash = 'Monitor gelöscht.'; }
if (isset($_GET['gespeichert'])) { $flash = 'Monitor gespeichert.'; }

$monitore     = Monitor::listAll();
$istEditieren = ($formId > 0);

admin_header('Monitore', 'monitore');
?>

<?php if ($flash): ?>
    <div class="adm-flash"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= $f ?></div>
<?php endforeach; ?>

<p class="adm-hilfe">
    Jeder Monitor läuft unter einer eigenen Subdomain (z.&nbsp;B.
    <code>saal1.tcpayer.de</code> → Subdomain <code>saal1</code>). Über „Zeitplan"
    legst du je Monitor fest, welche Playlist wann läuft.
</p>

<div class="adm-card">
    <h2><?= $istEditieren ? 'Monitor bearbeiten' : 'Neuen Monitor anlegen' ?></h2>
    <form method="post">
        <input type="hidden" name="aktion" value="speichern">
        <input type="hidden" name="id" value="<?= (int)$formId ?>">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($formName) ?>"
                   placeholder="z.B. Saal 1" required>
        </div>
        <div class="field">
            <label for="subdomain">Subdomain</label>
            <input type="text" id="subdomain" name="subdomain" value="<?= htmlspecialchars($formSub) ?>"
                   placeholder="z.B. saal1" required>
        </div>
        <div class="adm-aktionsleiste">
            <button type="submit" class="adm-btn-primary"><?= $istEditieren ? 'Speichern' : 'Anlegen' ?></button>
            <?php if ($istEditieren): ?>
                <a href="monitore.php" class="adm-btn adm-btn-grau">Abbrechen</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($monitore)): ?>
    <p class="adm-leer">Noch kein Monitor angelegt. Mit dem Formular oben anlegen.</p>
<?php else: ?>
<table class="adm-tabelle">
    <thead>
        <tr>
            <th>Name</th>
            <th>Subdomain</th>
            <th class="adm-mitte">Zeitplan-Einträge</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($monitore as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['name']) ?></td>
                <td class="adm-uuid"><?= htmlspecialchars($m['subdomain']) ?></td>
                <td class="adm-mitte"><?= (int)$m['anzahl_zeitplan'] ?></td>
                <td>
                    <a class="adm-btn adm-btn-primary" href="monitor.php?id=<?= (int)$m['id'] ?>">Zeitplan</a>
                    <a class="adm-btn" href="monitore.php?edit=<?= (int)$m['id'] ?>">Bearbeiten</a>
                    <form method="post" class="adm-inline adm-del-form"
                          data-name="<?= htmlspecialchars($m['name']) ?>"
                          data-anzahl="<?= (int)$m['anzahl_zeitplan'] ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-rot">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
document.querySelectorAll('.adm-del-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        var n = parseInt(f.dataset.anzahl || '0', 10);
        var txt = 'Monitor „' + (f.dataset.name || '') + '" wirklich löschen?';
        if (n > 0) {
            txt += '\n\nAchtung: Der Monitor hat ' + n + ' Zeitplan-Eintrag/-Einträge — '
                 + 'diese werden mit entfernt (die Playlists selbst bleiben).';
        }
        if (!confirm(txt)) { e.preventDefault(); }
    });
});
</script>

<?php
admin_footer();
