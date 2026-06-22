<?php
/**
 * test-module4.php
 *
 * MEILENSTEIN-TESTSEITE für Schritt 4 (Teststrategie siehe Chat-
 * Zusammenfassung Schritt 1-2). Erweitert test-module3.php um:
 *   - Saal-Auswahl (wird als window.SAAL_ID gesetzt; nötig für stundenplan,
 *     da der NC-API-Key pro Saal in der Tabelle einstellungen liegt)
 *   - alle registrierten Module im Anlegen-Formular (uhrzeit, bild,
 *     stundenplan, ankuendigung, fret)
 *   - ankuendigung: bis zu 3 Einträge mit Text + optionalem Bild + Ablaufdatum
 *
 * WICHTIG: Wie test-module3.php nur ein Test-Werkzeug, kein Teil der finalen
 * Bibliotheks-Verwaltung (kommt in Schritt 5). Render erfolgt über die
 * Schritt-3-Loader-Signatur: render(modulId, container, settings, inhalte).
 * Die Proxy-Module lesen ihre Parameter clientseitig aus settings bzw.
 * window.SAAL_ID — der API-Key/schoolId bleibt serverseitig im Proxy.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/includes/ModuleRegistry.php';
require __DIR__ . '/includes/ModulInstanz.php';

$fehler = [];
$erfolg = null;

$ERLAUBTE_BILD_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

/** Verschiebt eine hochgeladene Datei nach uploads/ und gibt den neuen Namen zurück (oder null). */
function speichere_upload(array $files, int $i, int $instanzId, array $erlaubt, array &$fehler): ?string
{
    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $originalName = $files['name'][$i];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $erlaubt, true)) {
        $fehler[] = "Dateityp von '$originalName' nicht erlaubt.";
        return null;
    }
    $neuerName = 'bild_' . $instanzId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (move_uploaded_file($files['tmp_name'][$i], UPLOADS_DIR . '/' . $neuerName)) {
        return $neuerName;
    }
    $fehler[] = "Upload von '$originalName' fehlgeschlagen.";
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anlegen') {
    $modulTyp = $_POST['modul_typ'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if (!ModuleRegistry::exists($modulTyp)) {
        $fehler[] = 'Unbekannter Modul-Typ.';
    } elseif ($name === '') {
        $fehler[] = 'Bitte einen Namen für die Modul-Instanz angeben.';
    } else {
        $einstellungen = ModuleRegistry::collectSettings($modulTyp, $_POST['einstellungen'] ?? []);
        $instanzId = ModulInstanz::create($modulTyp, $name, $einstellungen);

        // bild: mehrere Bilder hochladen
        if ($modulTyp === 'bild' && !empty($_FILES['bilder']['name'][0])) {
            $reihenfolge = 0;
            foreach ($_FILES['bilder']['name'] as $i => $_) {
                $neuerName = speichere_upload($_FILES['bilder'], $i, $instanzId, $ERLAUBTE_BILD_EXT, $fehler);
                if ($neuerName !== null) {
                    ModulInstanz::addInhalt($instanzId, $neuerName, null, $reihenfolge++, 10);
                }
            }
        }

        // ankuendigung: bis zu 3 Einträge (Text + optionales Bild + Ablaufdatum)
        if ($modulTyp === 'ankuendigung') {
            $texte   = $_POST['ank_text'] ?? [];
            $ablaeufe = $_POST['ank_ablauf'] ?? [];
            $dauer   = (int)($einstellungen['intervall_sek'] ?? 12);
            $reihenfolge = 0;
            foreach ($texte as $i => $rohtext) {
                $text = trim((string)$rohtext);
                $bildName = isset($_FILES['ank_bild']) ? speichere_upload($_FILES['ank_bild'], $i, $instanzId, $ERLAUBTE_BILD_EXT, $fehler) : null;
                if ($text === '' && $bildName === null) {
                    continue; // leere Zeile überspringen
                }
                $ablauf = !empty($ablaeufe[$i]) ? $ablaeufe[$i] : null;
                ModulInstanz::addInhalt($instanzId, $bildName, $text !== '' ? $text : null, $reihenfolge++, $dauer, $ablauf);
            }
        }

        if (empty($fehler)) {
            $erfolg = "Modul-Instanz '$name' (#$instanzId) angelegt.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'loeschen') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        ModulInstanz::delete($id);
        $erfolg = "Modul-Instanz #$id gelöscht.";
    }
}

$alleModule = ModuleRegistry::getAll();
$instanzen = ModulInstanz::listAll();

// Säle laden (für die Saal-Auswahl, nötig für den stundenplan-Test)
$saele = get_pdo()->query('SELECT id, name, subdomain FROM saele ORDER BY name')->fetchAll();
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

<h1>Schritt 4 — Module stundenplan / ankuendigung / fret testen</h1>

<?php foreach ($fehler as $f): ?>
    <div class="tm-card" style="border-color:#ad2121;color:#ad2121;"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>
<?php if ($erfolg): ?>
    <div class="tm-card" style="border-color:#2e7d32;color:#2e7d32;"><?= htmlspecialchars($erfolg) ?></div>
<?php endif; ?>

<div class="tm-card">
    <h2>Saal für den Test wählen</h2>
    <p>Wird als <code>SAAL_ID</code> für den stundenplan-Test gebraucht (NC-API-Key liegt pro Saal in <code>einstellungen.nc_api_key_stundenplan</code>).</p>
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
        <p style="color:#ad2121;">Noch keine Säle/Einstellungen angelegt — uhrzeit/bild/ankuendigung/fret lassen sich trotzdem testen; stundenplan braucht einen Saal mit hinterlegtem NC-Key.</p>
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

                <?php if ($id === 'bild'): ?>
                    <div class="field">
                        <label>Bilder hochladen</label>
                        <input type="file" name="bilder[]" accept="image/*" multiple>
                    </div>
                <?php endif; ?>

                <?php if ($id === 'ankuendigung'): ?>
                    <p style="font-size:13px;color:#888;">Bis zu 3 Einträge (Text und/oder Bild). Leere Zeilen werden ignoriert. Weitere Verwaltung folgt im Schritt-5-Backend.</p>
                    <?php for ($r = 0; $r < 3; $r++): ?>
                        <fieldset style="border:1px solid #eee;border-radius:6px;margin-bottom:8px;padding:8px;">
                            <legend style="font-size:12px;color:#888;">Eintrag <?= $r + 1 ?></legend>
                            <div class="field"><label>Text</label><textarea name="ank_text[]"></textarea></div>
                            <div class="field"><label>Bild (optional)</label><input type="file" name="ank_bild[]" accept="image/*"></div>
                            <div class="field"><label>Gültig bis (optional)</label><input type="date" name="ank_ablauf[]"></div>
                        </fieldset>
                    <?php endfor; ?>
                <?php endif; ?>

                <?php if ($id === 'stundenplan'): ?>
                    <p style="font-size:13px;color:#888;">Nutzt die Legacy-API <code>POST /timetable/data</code>. Voraussetzung: <code>NC_API_BASE</code> in config.php gesetzt und für den gewählten Saal ein <code>nc_api_key_stundenplan</code> hinterlegt.</p>
                <?php endif; ?>

                <?php if ($id === 'fret'): ?>
                    <p style="font-size:13px;color:#888;">Voraussetzung: <code>FRET_SCHOOL_ID</code> in config.php gesetzt. Die Computer-UUID des Saals findest du über <code>/proxies/fret.php?action=list</code>.</p>
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
        <?= json_encode(ModulInstanz::listInhalte((int)$inst['id']), JSON_UNESCAPED_UNICODE) ?>
    );
    <?php endforeach; ?>
</script>

</body>
</html>
