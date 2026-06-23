<?php
/**
 * admin/saele.php
 *
 * Säle-Verwaltung (Schritt 7, Voraussetzung für die Saal-Zuweisung von
 * Playlists). Anlegen/Bearbeiten/Löschen je Saal: Name + Subdomain.
 * Subdomain wird normalisiert (klein, ohne Domain) und ist eindeutig.
 *
 * Bearbeiten: ?edit=<id> füllt das Formular vor; Speichern aktualisiert.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$fehler = [];
$flash  = null;

// Formular-Vorbelegung (für Anlegen bzw. nach Validierungsfehler)
$formId   = 0;
$formName = '';
$formSub  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { Saal::delete($id); }
        header('Location: saele.php?geloescht=1');
        exit;
    }

    if ($aktion === 'speichern') {
        $formId   = (int)($_POST['id'] ?? 0);
        $formName = trim((string)($_POST['name'] ?? ''));
        $formSub  = Saal::normSubdomain((string)($_POST['subdomain'] ?? ''));
        $istNeu   = ($formId === 0);

        if ($formName === '') {
            $fehler[] = 'Bitte einen Namen für den Saal angeben.';
        }
        if ($formSub === '') {
            $fehler[] = 'Bitte eine gültige Subdomain angeben (z.B. „saal1").';
        } elseif (Saal::subdomainExistiert($formSub, $istNeu ? null : $formId)) {
            $fehler[] = 'Die Subdomain „' . htmlspecialchars($formSub) . '" ist bereits vergeben.';
        }

        if (empty($fehler)) {
            if ($istNeu) {
                Saal::create($formName, $formSub);
            } else {
                Saal::update($formId, $formName, $formSub);
            }
            header('Location: saele.php?gespeichert=1');
            exit;
        }
    }
}

// Bearbeiten: Formular aus DB vorbelegen (nur ohne vorherigen POST-Fehler)
if (empty($fehler) && $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $saal = Saal::find((int)$_GET['edit']);
    if ($saal) {
        $formId   = (int)$saal['id'];
        $formName = $saal['name'];
        $formSub  = $saal['subdomain'];
    }
}

if (isset($_GET['geloescht']))   { $flash = 'Saal gelöscht.'; }
if (isset($_GET['gespeichert'])) { $flash = 'Saal gespeichert.'; }

$saele     = Saal::listAll();
$istEditieren = ($formId > 0);

admin_header('Säle', 'saele');
?>

<?php if ($flash): ?>
    <div class="adm-flash"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= $f ?></div>
<?php endforeach; ?>

<p class="adm-hilfe">
    Jeder Saal-Monitor läuft unter einer eigenen Subdomain (z.&nbsp;B.
    <code>saal1.tcpayer.de</code> → Subdomain <code>saal1</code>). Hier angelegte
    Säle stehen in der Playlist-Bearbeitung zur Zuweisung bereit.
</p>

<div class="adm-card">
    <h2><?= $istEditieren ? 'Saal bearbeiten' : 'Neuen Saal anlegen' ?></h2>
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
                <a href="saele.php" class="adm-btn adm-btn-grau">Abbrechen</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($saele)): ?>
    <p class="adm-leer">Noch kein Saal angelegt. Mit dem Formular oben anlegen.</p>
<?php else: ?>
<table class="adm-tabelle">
    <thead>
        <tr>
            <th>Name</th>
            <th>Subdomain</th>
            <th class="adm-mitte">Playlists</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($saele as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <td class="adm-uuid"><?= htmlspecialchars($s['subdomain']) ?></td>
                <td class="adm-mitte"><?= (int)$s['anzahl_playlists'] ?></td>
                <td>
                    <a class="adm-btn" href="saele.php?edit=<?= (int)$s['id'] ?>">Bearbeiten</a>
                    <form method="post" class="adm-inline adm-del-form"
                          data-name="<?= htmlspecialchars($s['name']) ?>"
                          data-anzahl="<?= (int)$s['anzahl_playlists'] ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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
        var txt = 'Saal „' + (f.dataset.name || '') + '" wirklich löschen?';
        if (n > 0) {
            txt += '\n\nAchtung: Der Saal ist ' + n + ' Playlist(s) zugewiesen — '
                 + 'diese Zuweisung(en) werden mit entfernt (die Playlists selbst bleiben).';
        }
        if (!confirm(txt)) { e.preventDefault(); }
    });
});
</script>

<?php
admin_footer();
