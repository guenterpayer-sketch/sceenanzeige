<?php
/**
 * admin/nc-protokoll.php
 *
 * Zeigt, wie oft DIESES System die Nimbuscloud-API tatsächlich abgerufen hat.
 *
 * Hintergrund: Im Debug-Log der Nimbuscloud stehen die Aufrufe aller
 * Verbraucher der Schule nebeneinander (u.a. die Musiksoftware). Dort ist
 * nicht erkennbar, welcher Anteil auf die Monitore entfällt. Diese Seite
 * beantwortet genau das — mit eigenen Zahlen statt einer Schätzung.
 *
 * Gezählt werden ausschließlich echte Netzabrufe. Anfragen, die aus dem
 * Tages-Cache bedient werden (der Normalfall, viele hundert am Tag), kosten
 * kein Kontingent und stehen deshalb bewusst nicht drin.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
tm_nur_admin();
require dirname(__DIR__) . '/includes/NcProtokoll.php';
require __DIR__ . '/includes/layout.php';

$monate = NcProtokoll::monate();
$aktuellerMonat = date('Y-m');
if ($monate === []) {
    $monate = [$aktuellerMonat];
}

$monat = (string)($_GET['monat'] ?? $aktuellerMonat);
if (!in_array($monat, $monate, true)) {
    $monat = $monate[0];
}

$eintraege = NcProtokoll::lese($monat);
$summe     = NcProtokoll::zusammenfassung();
$fehler    = NcCache::fehlerUebersicht();

/** 'YYYY-MM' → 'August 2026' */
function nc_monat_label(string $ym): string
{
    $namen = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
              'August', 'September', 'Oktober', 'November', 'Dezember'];
    $teile = explode('-', $ym);
    $m = (int)($teile[1] ?? 0);
    return ($namen[$m] ?? $ym) . ' ' . ($teile[0] ?? '');
}

/** ISO-Zeit → 'DD.MM. HH:MM:SS' */
function nc_zeit_label(string $iso): string
{
    $ts = strtotime($iso);
    return $ts ? date('d.m. H:i:s', $ts) : $iso;
}

admin_header('API-Protokoll', 'nc-protokoll');
?>

<details class="adm-hilfe-klapp">
    <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
    <p class="adm-hilfe">
        Das Kontingent der Nimbuscloud-API ist pro Monat begrenzt. Hier steht,
        wie oft die Monitore tatsächlich dort angefragt haben.
        <strong>Im Normalfall ist das ein einziger Abruf pro Tag:</strong> Der
        Stundenplan wird einmal geholt und bis Mitternacht zwischengespeichert —
        alle Säle und alle Modul-Instanzen teilen sich diesen einen Abruf.
        Anfragen aus dem Zwischenspeicher stehen hier nicht, sie kosten nichts.
    </p>
    <p class="adm-hilfe">
        Wenn im Debug-Log der Nimbuscloud sehr viel mehr Aufrufe auftauchen als
        hier, stammen sie von einem anderen System der Schule — nicht von den
        Monitoren. Ein Abruf außer der Reihe entsteht nur durch
        „Monitore neu laden".
    </p>
</details>

<div class="adm-nc-zahlen">
    <div class="adm-nc-zahl">
        <span class="adm-nc-wert"><?= (int)$summe['heute'] ?></span>
        <span class="adm-nc-label">heute</span>
    </div>
    <div class="adm-nc-zahl">
        <span class="adm-nc-wert"><?= (int)$summe['monat'] ?></span>
        <span class="adm-nc-label">diesen Monat</span>
    </div>
    <div class="adm-nc-zahl<?= $summe['fehler_monat'] > 0 ? ' adm-nc-zahl--warn' : '' ?>">
        <span class="adm-nc-wert"><?= (int)$summe['fehler_monat'] ?></span>
        <span class="adm-nc-label">davon Fehler</span>
    </div>
    <div class="adm-nc-zahl adm-nc-zahl--breit">
        <span class="adm-nc-wert-klein">
            <?= $summe['letzter'] ? htmlspecialchars(nc_zeit_label($summe['letzter']['zeit'])) : '—' ?>
        </span>
        <span class="adm-nc-label">letzter Abruf</span>
    </div>
</div>

<?php if ($fehler !== []): ?>
    <div class="adm-flash adm-flash-fehler">
        <strong>Gerade gesperrt:</strong>
        Nach einem Fehler wird die Nimbuscloud kurz in Ruhe gelassen, damit die
        Monitore nicht im Minutentakt dagegenhalten.
        <ul class="adm-nc-sperrliste">
            <?php foreach ($fehler as $f): ?>
                <li>
                    <?= htmlspecialchars($f['meldung']) ?>
                    — wieder frei ab <?= htmlspecialchars(date('H:i:s', $f['bis'])) ?> Uhr
                </li>
            <?php endforeach; ?>
        </ul>
        Mit „Monitore neu laden" (oben rechts) lässt sich das sofort aufheben.
    </div>
<?php endif; ?>

<?php if (count($monate) > 1): ?>
    <form method="get" class="adm-nc-monatswahl">
        <label for="monat">Monat:</label>
        <select name="monat" id="monat" onchange="this.form.submit()">
            <?php foreach ($monate as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"<?= $m === $monat ? ' selected' : '' ?>>
                    <?= htmlspecialchars(nc_monat_label($m)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="adm-btn">Anzeigen</button></noscript>
    </form>
<?php endif; ?>

<?php if ($eintraege === []): ?>
    <p class="adm-leer">
        Für <?= htmlspecialchars(nc_monat_label($monat)) ?> ist noch kein Abruf verzeichnet.
        Das ist ein gutes Zeichen — solange die Monitore Kurse anzeigen, arbeiten
        sie aus dem Zwischenspeicher.
    </p>
<?php else: ?>
    <table class="adm-tabelle">
        <thead>
            <tr>
                <th>Zeitpunkt</th>
                <th>Abfrage</th>
                <th>Ergebnis</th>
                <th class="adm-mitte">HTTP</th>
                <th class="adm-mitte">Dauer</th>
                <th>Anmerkung</th>
                <th>Server</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($eintraege as $e): ?>
                <tr<?= $e['ergebnis'] !== 'ok' ? ' class="adm-nc-fehlerzeile"' : '' ?>>
                    <td><?= htmlspecialchars(nc_zeit_label($e['zeit'])) ?></td>
                    <td><?= $e['endpunkt'] === 'locations' ? 'Standorte' : 'Stundenplan' ?></td>
                    <td><?= $e['ergebnis'] === 'ok' ? '✅ OK' : '⚠️ Fehler' ?></td>
                    <td class="adm-mitte"><?= (int)$e['http'] ?: '—' ?></td>
                    <td class="adm-mitte"><?= (int)$e['dauer_ms'] ?> ms</td>
                    <td><?= htmlspecialchars($e['hinweis']) ?></td>
                    <td class="adm-uuid"><?= htmlspecialchars($e['herkunft']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php admin_footer(); ?>
