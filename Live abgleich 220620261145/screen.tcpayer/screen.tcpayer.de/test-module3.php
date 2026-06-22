<?php
/**
 * test-module3.php
 *
 * MEILENSTEIN-TESTSEITE für Schritt 3 (siehe Teststrategie in der
 * Chat-Zusammenfassung Schritt 1-2: "Tests nach sinnvollen Zwischenständen").
 *
 * Zweck:
 *   - Modul-Instanz für "uhrzeit" oder "bild" anlegen (Formular wird
 *     automatisch aus module.json generiert -> ModuleRegistry::renderSettingsForm)
 *   - bei "bild": Bilder hochladen (landen in uploads/, Eintrag in
 *     modul_instanz_inhalte)
 *   - Live-Vorschau jeder angelegten Instanz über module-loader.js
 *
 * WICHTIG: Diese Datei ist nur ein Test-Werkzeug für Schritt 3, kein Teil
 * der finalen Bibliotheks-Verwaltung (die kommt erst in Schritt 5 mit
 * richtigem UI). Nach erfolgreichem Test ggf. einfach liegen lassen oder
 * löschen — sie hat keine Abhängigkeiten zu anderen Dateien außer den
 * neu angelegten includes/Modul*.php.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/includes/ModuleRegistry.php';
require __DIR__ . '/includes/ModulInstanz.php';

$fehler = [];
$erfolg = null;

// ----------------------------------------------------------------------
// POST: neue Modul-Instanz anlegen
// ----------------------------------------------------------------------
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

        // Bild-Uploads verarbeiten (nur relevant für has_inhalte-Module wie "bild")
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

        if (empty($fehler)) {
            $erfolg = "Modul-Instanz '$name' (#$instanzId) angelegt.";
        }
    }
}

// ----------------------------------------------------------------------
// POST: Modul-Instanz löschen
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aktion']) && $_POST['aktion'] === 'loeschen') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        ModulInstanz::delete($id);
        $erfolg = "Modul-Instanz #$id gelöscht.";
    }
}

$alleModule = ModuleRegistry::getAll();
$instanzen = ModulInstanz::listAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Schritt 3 — Modul-Test</title>
<link rel="stylesheet" href="/assets/css/module-test.css">
</head>
<body>

<h1>Schritt 3 — Modul-Registry &amp; Referenz-Module testen</h1>

<?php foreach ($fehler as $f): ?>
    <div class="tm-card" style="border-color:#ad2121;color:#ad2121;"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>
<?php if ($erfolg): ?>
    <div class="tm-card" style="border-color:#2e7d32;color:#2e7d32;"><?= htmlspecialchars($erfolg) ?></div>
<?php endif; ?>

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
            <label for="name">Name der Instanz (z.B. "Begrüßung Eingang")</label>
            <input type="text" name="name" id="name" required>
        </div>

        <?php foreach ($alleModule as $id => $meta): ?>
            <div class="modul-felder" data-modul="<?= htmlspecialchars($id) ?>" style="display:none;">
                <?= ModuleRegistry::renderSettingsForm($id) ?>
                <?php if ($meta['has_inhalte']): ?>
                    <div class="field">
                        <label>Bilder hochladen</label>
                        <input type="file" name="bilder[]" accept="image/*" multiple>
                    </div>
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
        <?= json_encode(ModulInstanz::listInhalte((int)$inst['id']), JSON_UNESCAPED_UNICODE) ?>
    );
    <?php endforeach; ?>
</script>

</body>
</html>
