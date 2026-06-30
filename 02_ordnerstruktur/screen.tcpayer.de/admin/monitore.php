<?php
/**
 * admin/monitore.php
 *
 * Monitor-Verwaltung (monitor-zentrisches Modell), Kachel-Design analog zur
 * Playlist-Übersicht:
 *   - Standardansicht: Button „+ Neuer Monitor" + Monitore als Kacheln.
 *     Klick auf eine Kachel führt in den Zeitplan-Editor (monitor-zeitplan.php).
 *   - Anlegen/Bearbeiten: Formular (Name + Subdomain) via ?neu bzw. ?edit=<id>.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
tm_nur_admin();
require __DIR__ . '/includes/layout.php';

$fehler = [];
$flash  = null;

$formId         = 0;
$formName       = '';
$formSub        = '';
$formHeaderText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { Monitor::delete($id); }
        header('Location: monitore.php?geloescht=1');
        exit;
    }

    if ($aktion === 'speichern') {
        $formId         = (int)($_POST['id'] ?? 0);
        $formName       = trim((string)($_POST['name'] ?? ''));
        $formSub        = Monitor::normDomain((string)($_POST['subdomain'] ?? ''));
        $formHeaderText = trim((string)($_POST['header_text'] ?? ''));
        $istNeu         = ($formId === 0);

        if ($formName === '') {
            $fehler[] = 'Bitte einen Namen für den Monitor angeben.';
        }
        if ($formSub === '') {
            $fehler[] = 'Bitte eine gültige Domain angeben (z.B. „saal1.tcpayer.de").';
        } elseif (Monitor::subdomainExistiert($formSub, $istNeu ? null : $formId)) {
            $fehler[] = 'Die Domain „' . htmlspecialchars($formSub) . '" ist bereits vergeben.';
        }

        if (empty($fehler)) {
            if ($istNeu) {
                Monitor::create($formName, $formSub, $formHeaderText);
            } else {
                Monitor::update($formId, $formName, $formSub, $formHeaderText);
            }
            header('Location: monitore.php?gespeichert=1');
            exit;
        }
    }
}

// Bearbeiten: Formular aus DB vorbelegen (nur ohne vorherigen POST-Fehler)
if (empty($fehler) && $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $monitor = Monitor::find((int)$_GET['edit']);
    if ($monitor) {
        $formId         = (int)$monitor['id'];
        $formName       = $monitor['name'];
        $formSub        = $monitor['subdomain'];
        $formHeaderText = $monitor['header_text'] ?? '';
    }
}

if (isset($_GET['geloescht']))   { $flash = 'Monitor gelöscht.'; }
if (isset($_GET['gespeichert'])) { $flash = 'Monitor gespeichert.'; }

$monitore     = Monitor::listAll();
$istEditieren = ($formId > 0);
$zeigeForm    = $istEditieren || isset($_GET['neu']) || !empty($fehler);

admin_header('Monitore', 'monitore');
?>

<?php if ($flash): ?>
    <div class="adm-flash"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= $f ?></div>
<?php endforeach; ?>

<p class="adm-hilfe">
    Jeder Monitor läuft unter einer eigenen Domain (z.&nbsp;B.
    <code>saal1.tcpayer.de</code>). Die vollständige Domain wird beim Anlegen
    eingetragen. Klicke eine Kachel an, um den <strong>Zeitplan</strong> dieses
    Monitors zu pflegen (welche Playlist und welcher Ticker wann laufen).
</p>

<?php if ($zeigeForm): ?>
<!-- ===== Anlegen / Bearbeiten ===== -->
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
            <label for="subdomain">Domain</label>
            <input type="text" id="subdomain" name="subdomain" value="<?= htmlspecialchars($formSub) ?>"
                   placeholder="z.B. saal1.tcpayer.de" required>
        </div>
        <div class="field">
            <label for="header_text">Header-Text (Mitte des Monitors)</label>
            <input type="text" id="header_text" name="header_text"
                   value="<?= htmlspecialchars($formHeaderText) ?>"
                   placeholder="z.B. Willkommen im Tanzcenter Payer">
        </div>
        <div class="adm-aktionsleiste">
            <button type="submit" class="adm-btn-primary"><?= $istEditieren ? 'Speichern' : 'Anlegen' ?></button>
            <a href="monitore.php" class="adm-btn adm-btn-grau">Abbrechen</a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="adm-neuzeile">
    <a class="adm-btn-primary" href="monitore.php?neu=1">+ Neuer Monitor</a>
</div>
<?php endif; ?>

<?php if (empty($monitore)): ?>
    <p class="adm-leer">Noch kein Monitor angelegt. Mit dem Button oben anlegen.</p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($monitore as $m): ?>
        <div class="adm-kachel">
            <a class="adm-kachel-vorschau info adm-kachel-link" href="monitor-zeitplan.php?id=<?= (int)$m['id'] ?>"
               title="Zeitplan von <?= htmlspecialchars($m['name']) ?> bearbeiten">
                <span class="adm-kachel-icon">🖥️</span>
                <span class="adm-kachel-info">
                    <?= htmlspecialchars($m['subdomain']) ?><br>
                    🗂️ <?= (int)$m['anzahl_zeitplan'] ?> Playlist<?= (int)$m['anzahl_zeitplan'] === 1 ? '' : 's' ?>
                    · 📰 <?= (int)$m['anzahl_ticker'] ?> Ticker
                </span>
            </a>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name"><?= htmlspecialchars($m['name']) ?></div>
                <div class="adm-kachel-aktionen">
                    <a class="adm-btn adm-btn-primary" href="monitor-zeitplan.php?id=<?= (int)$m['id'] ?>">Zeitplan</a>
                    <button class="adm-btn adm-vorschau-btn"
                            data-url="https://<?= htmlspecialchars($m['subdomain']) ?>"
                            data-name="<?= htmlspecialchars($m['name']) ?>">Vorschau</button>
                    <a class="adm-btn" href="monitore.php?edit=<?= (int)$m['id'] ?>">Bearbeiten</a>
                    <form method="post" class="adm-inline adm-del-form"
                          data-name="<?= htmlspecialchars($m['name']) ?>"
                          data-anzahl="<?= (int)$m['anzahl_zeitplan'] + (int)$m['anzahl_ticker'] ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-rot">Löschen</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.adm-del-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        var n = parseInt(f.dataset.anzahl || '0', 10);
        var txt = 'Monitor „' + (f.dataset.name || '') + '" wirklich löschen?';
        if (n > 0) {
            txt += '\n\nAchtung: Der Monitor hat ' + n + ' Zeitplan-Eintrag/-Einträge '
                 + '(Playlist + Ticker) — diese werden mit entfernt '
                 + '(die Playlists/Ticker selbst bleiben).';
        }
        admBestaetigen(txt, function (ok) {
            if (ok) { f.submit(); }
        }, 'Löschen');
    });
});
</script>

<?php
admin_footer();
