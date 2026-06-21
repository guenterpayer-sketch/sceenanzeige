<?php
/**
 * test-module4.php
 *
 * MEILENSTEIN-TESTSEITE für Schritt 4 (Teststrategie siehe Chat-
 * Zusammenfassung Schritt 1-2). Erweitert test-module3.php um:
 *   - Saal-Auswahl (wird als window.SAAL_ID gesetzt, nötig für
 *     stundenplan/community, die einen NC-API-Key pro Saal brauchen)
 *   - alle 6 Module stehen jetzt im Anlegen-Formular zur Auswahl
 *   - render() ruft jetzt mit modul_instanz_id auf (neue Loader-Signatur)
 *
 * WICHTIG: Wie test-module3.php ist auch dies nur ein Test-Werkzeug, kein
 * Teil der finalen Bibliotheks-Verwaltung (kommt in Schritt 5).
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/includes/ModuleRegistry.php';
require __DIR__ . '/includes/ModulInstanz.php';

$fehler = [];
$erfolg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion']) && $_POST['aktion'] === 'anlegen') {
    $modulTyp = $_POST['modul_typ'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if (!ModuleRegistry::exists($modulTyp)) {
        $fehler[] = 'Unbekannter Modul-Typ.';
    } elseif ($name === '') {
        $fehler[] = 'Bitte einen Namen für die Modul-Instanz angeben.';
    } else {
        $einstellungen = ModuleRegistry::collectSettings($modulTyp, $_POST['einstellungen'] ?? []);
        $instanzId = ModulInstanz::create($modulTyp, $name, $einstellungen);

        if ($modulTyp === 'bild' && !empty($_FILES['bilder']['name'][0])) {
            $erlaubt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $reihenfolge = 0;
            foreach ($_FILES['bilder']['name'] as $i => $originalName) {
                if ($_FILES['bilder']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $erlaubt, true)) {
                    $fehler[] = "Dateityp von '$originalName' nicht erlaubt.";
                    continue;
                }
                $neuerName = 'bild_' . $instanzId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $ziel = UPLOADS_DIR . '/' . $neuerName;
                if (move_uploaded_file($_FILES['bilder']['tmp_name'][$i], $ziel)) {
                    ModulInstanz::addInhalt($instanzId, $neuerName, null, $reihenfolge, 10);
                    $reihenfolge++;
                } else {
                    $fehler[] = "Upload von '$originalName' fehlgeschlagen.";
                }
            }
        }

        if ($modulTyp === 'ankuendigung' && !empty($_POST['ankuendigung_text'])) {
            $text = trim((string)$_POST['ankuendigung_text']);
            $ablauf = !empty($_POST['ankuendigung_ablauf']) ? $_POST['ankuendigung_ablauf'] : null;
            if ($text !== '') {
                ModulInstanz::addInhalt($instanzId, null, $text, 0, (int)($einstellungen['intervall_sek'] ?? 8), $ablauf);
            }
        }

        if (empty($fehler)) {
            $erfolg = "Modul-Instanz '$name' (#$instanzId) angelegt.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion']) && $_POST['aktion'] === 'loeschen') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        ModulInstanz::delete($id);
        $erfolg = "Modul-Instanz #$id gelöscht.";
    }
}

$alleModule = ModuleRegistry::getAll();
$instanzen = ModulInstanz::listAll();

// Säle laden (für die Saal-Auswahl, nötig für stundenplan/community-Tests)
$pdo = get_pdo();
$saele = $pdo->query('SELECT id, name, subdomain FROM saele ORDER BY name')->fetchAll();
$gewaehlterSaal = isset($_GET['saal_id']) ? (int)$_GET['saal_id'] : ($saele[0]['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Schritt 4 — Modul-Test</title>
<link rel="stylesheet" href="/assets/css/module-test.css">
</head>
<body>

<h1>Schritt 4 — Module stundenplan / ankuendigung / community / song testen</h1>

<?php foreach ($fehler as $f): ?>
    <div class="tm-card" style="border-color:#ad2121;color:#ad2121;"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>
<?php if ($erfolg): ?>
    <div class="tm-card" style="border-color:#2e7d32;color:#2e7d32;"><?= htmlspecialchars($erfolg) ?></div>
<?php endif; ?>

<div class="tm-card">
    <h2>Saal für den Test wählen</h2>
    <p>Wird als <code>SAAL_ID</code> für stundenplan/community gebraucht (NC-API-Key pro Saal).</p>
    <form method="get">
        <div class="field">
            <select name="saal_id" onchange="this.form.submit()">
                <?php foreach ($saele as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $gewaehlterSaal ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['subdomain']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php if (empty($saele)): ?>
        <p style="color:#ad2121;">Noch keine Säle angelegt — ohne Saal funktionieren stundenplan/community-Tests nicht (song/ankuendigung/bild/uhrzeit schon).</p>
    <?php endif; ?>
</div>

<div class="tm-card">
    <h2>Neue Modul-Instanz anlegen</h2>
    <form method="post" enctype="multipart/form-data" id="anlegen-form">
        <input type="hidden" name="aktion" value="anlegen">

        <div class="field">
            <label for="modul_typ">Modul-Typ</label>
            <select name="modul_typ" id="modul_typ" onchange="zeigeFelderFuer(this.value)">
                <?php foreach ($alleModule as $id => $meta): ?>
                    <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="name">Name der Instanz</label>
            <input type="text" name="name" id="name" required>
        </div>

        <?php foreach ($alleModule as $id => $meta): ?>
            <div class="modul-felder" data-modul="<?= htmlspecialchars($id) ?>" style="display:none;">
                <?= ModuleRegistry::renderSettingsForm($id) ?>
                <?php if ($meta['has_inhalte'] && $id === 'bild'): ?>
                    <div class="field">
                        <label>Bilder hochladen</label>
                        <input type="file" name="bilder[]" accept="image/*" multiple>
                    </div>
                <?php endif; ?>
                <?php if ($id === 'ankuendigung'): ?>
                    <div class="field">
                        <label>Erster Ankündigungstext (weitere später über Schritt-5-Backend)</label>
                        <textarea name="ankuendigung_text"></textarea>
                    </div>
                    <div class="field">
                        <label>Ablaufdatum (optional)</label>
                        <input type="date" name="ankuendigung_ablauf">
                    </div>
                <?php endif; ?>
                <?php if ($id === 'stundenplan'): ?>
                    <p style="font-size:13px;color:#888;">Hinweis: liefert aktuell noch einen Fehler, bis das echte DB-Schema in proxies/nc.php eingetragen ist (siehe TODO dort).</p>
                <?php endif; ?>
                <?php if ($id === 'community'): ?>
                    <p style="font-size:13px;color:#888;">Hinweis: braucht eine echte Kundennummer aus der Nimbuscloud-Instanz, sonst Fehler "customer_nr nicht gesetzt".</p>
                <?php endif; ?>
                <?php if ($id === 'song'): ?>
                    <p style="font-size:13px;color:#888;">schoolId/computerId per GET /api/v1/Schools bzw. /Computers der FRET-API ermitteln.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit">Anlegen</button>
    </form>
</div>

<div class="tm-card">
    <h2>Vorhandene Modul-Instanzen</h2>
    <?php if (empty($instanzen)): ?>
        <p>Noch keine Instanzen angelegt.</p>
    <?php else: ?>
        <div class="tm-instanz-liste">
            <?php foreach ($instanzen as $inst): ?>
                <div class="tm-instanz-zeile">
                    <span>#<?= $inst['id'] ?> — <?= htmlspecialchars($inst['name']) ?> (<?= htmlspecialchars($inst['modul_typ']) ?>)</span>
                    <form method="post" onsubmit="return confirm('Wirklich löschen?');">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                        <button type="submit" style="background:#888;">Löschen</button>
                    </form>
                </div>
                <div class="tm-preview-monitor" id="preview-<?= $inst['id'] ?>"></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    window.BACKEND_BASE = '';
    window.UPLOADS_URL = '<?= UPLOADS_URL ?>';
    window.SAAL_ID = <?= json_encode($gewaehlterSaal) ?>;
</script>
<script src="/assets/js/module-loader.js"></script>
<script>
    function zeigeFelderFuer(modulTyp) {
        document.querySelectorAll('.modul-felder').forEach(function (el) {
            el.style.display = (el.dataset.modul === modulTyp) ? 'block' : 'none';
        });
    }
    zeigeFelderFuer(document.getElementById('modul_typ').value);

    <?php foreach ($instanzen as $inst): ?>
    TanzschuleLoader.render(
        <?= json_encode($inst['modul_typ']) ?>,
        document.getElementById('preview-<?= $inst['id'] ?>'),
        <?= json_encode($inst['einstellungen'], JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode(ModulInstanz::listInhalte((int)$inst['id']), JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($inst['id']) ?>
    );
    <?php endforeach; ?>
</script>

</body>
</html>
