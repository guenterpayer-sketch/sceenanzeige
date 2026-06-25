<?php
/**
 * admin/fret-geraete.php
 *
 * FRET-Geräte-Whitelist (Schritt 5b-Erweiterung). "Von FRET aktualisieren"
 * holt die aktuelle Computerliste über die API (schoolId serverseitig) und
 * legt neue Geräte an. Der Admin vergibt Anzeigenamen und gibt die Geräte
 * frei, die im fret-Modul-Editor auswählbar sein sollen.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
tm_nur_admin();
require __DIR__ . '/includes/layout.php';

$flash = null;
$flashFehler = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'sync') {
        try {
            $res = FretGeraet::syncVonFret();
            $flash = "Aktualisiert: {$res['gesamt']} Geräte von FRET, davon {$res['neu']} neu.";
        } catch (Throwable $e) {
            $flash = 'FRET-Abruf fehlgeschlagen: ' . $e->getMessage();
            $flashFehler = true;
        }
    } elseif ($aktion === 'speichern') {
        $werte = $_POST['geraet'] ?? [];
        $frei  = $_POST['freigegeben'] ?? [];
        FretGeraet::speichereEinstellungen(is_array($werte) ? $werte : [], is_array($frei) ? $frei : []);
        $flash = 'Gespeichert.';
    }
}

$geraete = FretGeraet::listAll();
$fretKonfiguriert = defined('FRET_SCHOOL_ID') && FRET_SCHOOL_ID !== '';

admin_header('FRET-Geräte', 'fret-geraete');
?>

<?php if ($flash): ?>
    <div class="adm-flash <?= $flashFehler ? 'adm-flash-fehler' : '' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<p class="adm-hilfe">
    FRET meldet alle Computer mit aktiver API. Hier legst du fest, welche davon
    im FRET-Modul auswählbar sind („freigegeben") und unter welchem Anzeigenamen.
    Nur freigegebene Geräte erscheinen im Dropdown des Modul-Editors.
</p>

<?php if (!$fretKonfiguriert): ?>
    <div class="adm-flash adm-flash-fehler">
        FRET ist nicht konfiguriert (FRET_SCHOOL_ID in config.php fehlt) — „Von FRET aktualisieren" funktioniert erst danach.
    </div>
<?php endif; ?>

<div class="adm-neuzeile">
    <form method="post" class="adm-inline">
        <input type="hidden" name="aktion" value="sync">
        <button type="submit" class="adm-btn">↻ Von FRET aktualisieren</button>
    </form>
</div>

<?php if (empty($geraete)): ?>
    <p class="adm-leer">Noch keine Geräte bekannt. Auf „Von FRET aktualisieren" klicken.</p>
<?php else: ?>
<form method="post">
    <input type="hidden" name="aktion" value="speichern">
    <table class="adm-tabelle">
        <thead>
            <tr>
                <th>Freigegeben</th>
                <th>Anzeigename</th>
                <th>FRET-Name</th>
                <th>UUID</th>
                <th>Zuletzt gesehen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($geraete as $g): ?>
                <tr class="<?= $g['freigegeben'] ? '' : 'inaktiv' ?>">
                    <td class="adm-mitte">
                        <input type="checkbox" name="freigegeben[]" value="<?= (int)$g['id'] ?>" <?= $g['freigegeben'] ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <input type="text" name="geraet[<?= (int)$g['id'] ?>][anzeige_name]"
                               value="<?= htmlspecialchars((string)$g['anzeige_name']) ?>" placeholder="z.B. Saal 1">
                    </td>
                    <td><?= htmlspecialchars((string)$g['fret_name']) ?></td>
                    <td class="adm-uuid"><?= htmlspecialchars((string)$g['uuid']) ?></td>
                    <td><?= $g['gesehen_am'] ? htmlspecialchars((string)$g['gesehen_am']) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="adm-aktionsleiste">
        <button type="submit" class="adm-btn-primary">Speichern</button>
    </div>
</form>
<?php endif; ?>

<?php
admin_footer();
