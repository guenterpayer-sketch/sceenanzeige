<?php
/**
 * admin/playlists.php
 *
 * Playlists in zwei Ebenen — bewusst wie admin/mediathek.php (Schritt 36):
 *   1. Ordner-Übersicht: Kacheln „Alle Playlists", „Ohne Ordner", je Ordner
 *      eine Kachel (✎ umbenennen / × löschen), „+ Neuer Ordner", „Archiv".
 *   2. Playlist-Ansicht (?ordner=alle|none|archiv|<id>): Kacheln wie bisher
 *      (Bearbeiten, Vorschau, Pausieren, Archivieren, Löschen) plus
 *      Verwendungs-Filter (?f=monitor|termin|frei|pausiert).
 *
 * Archivieren ist nur möglich, wenn die Playlist weder im Wochenplan noch in
 * einem kommenden Kalender-Termin steht (Playlist::archivieren()).
 * Ordner-Verwaltung läuft per fetch über admin/api/playlist-ordner.php.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$hinweis = null;
$fehler  = null;

// Ansicht: null = Ordner-Übersicht, sonst alle|none|archiv|<ordner-id>.
// Kommt man mit einem Flash/Highlight ohne Ordner (z.B. aus dem Editor oder
// von einem Bibliotheks-Badge), zeigt die Seite direkt „Alle Playlists" —
// sonst wären die gemeinten Kacheln hinter der Ordner-Übersicht versteckt.
$ordnerParam = $_GET['ordner'] ?? null;
if ($ordnerParam !== null && !in_array($ordnerParam, ['alle', 'none', 'archiv'], true) && !ctype_digit((string)$ordnerParam)) {
    $ordnerParam = null;
}
if ($ordnerParam === null && (isset($_GET['gespeichert']) || isset($_GET['hl_instanz']))) {
    $ordnerParam = 'alle';
}
$filterParam = (string)($_GET['f'] ?? '');
if (!in_array($filterParam, ['monitor', 'termin', 'frei', 'pausiert'], true)) { $filterParam = ''; }

/** URL dieser Seite mit geänderten Parametern (Ordner + Filter bleiben). */
function pl_url(array $aenderung = []): string
{
    global $ordnerParam, $filterParam;
    $p = ['ordner' => $ordnerParam, 'f' => $filterParam];
    foreach ($aenderung as $k => $v) { $p[$k] = $v; }
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'playlists.php' . ($p ? '?' . http_build_query($p) : '');
}

// --- Aktionen (Toggle aktiv / Archivieren / Wiederherstellen / Löschen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $aktion === 'toggle') {
        $pl = Playlist::find($id);
        if ($pl) { Playlist::setAktiv($id, !$pl['aktiv']); }
        header('Location: ' . pl_url());
        exit;
    }
    if ($id > 0 && $aktion === 'archivieren') {
        $res = Playlist::archivieren($id);
        header('Location: ' . pl_url($res['ok'] ? ['archiviert' => 1] : ['archiv_gesperrt' => $id]));
        exit;
    }
    if ($id > 0 && $aktion === 'wiederherstellen') {
        Playlist::wiederherstellen($id);
        header('Location: ' . pl_url(['wiederhergestellt' => 1]));
        exit;
    }
    if ($id > 0 && $aktion === 'loeschen') {
        Playlist::delete($id);
        header('Location: ' . pl_url(['geloescht' => 1]));
        exit;
    }
}
$hinweisAktion = null; // [href, label] — geführter nächster Schritt im Flash
if (isset($_GET['geloescht']))        { $hinweis = 'Playlist gelöscht.'; }
if (isset($_GET['archiviert']))       { $hinweis = 'Playlist archiviert. Du findest sie in der Ordnerübersicht unter „Archiv".'; }
if (isset($_GET['wiederhergestellt'])) { $hinweis = 'Playlist wiederhergestellt.'; }
$gesperrtId = (int)($_GET['archiv_gesperrt'] ?? 0);
if ($gesperrtId > 0 && ($gesperrt = Playlist::find($gesperrtId))) {
    $orte = Playlist::einplanungen($gesperrtId);
    if (!empty($orte)) {
        $fehler = '„' . $gesperrt['name'] . '" kann nicht archiviert werden, solange sie eingeplant ist: '
                . implode(' · ', $orte) . '. Erst dort entfernen, dann archivieren.';
    }
}
if (isset($_GET['gespeichert'])) {
    $hinweis       = 'Playlist gespeichert. Eingeplante Monitore übernehmen Änderungen '
                   . 'innerhalb von ca. 1 Minute — oder sofort über „↺ Monitore neu laden" oben.';
    // Kommt die gespeicherte Playlist-ID mit, hebt der Aktions-Link drüben
    // gleich die Monitore hervor, auf denen sie schon eingeplant ist.
    $gespId        = (int)($_GET['id'] ?? 0);
    $hinweisAktion = [
        $gespId > 0 ? 'monitore.php?hl_playlist=' . $gespId : 'monitore.php',
        '→ Jetzt auf einem Monitor einplanen',
    ];
}

$alle        = Playlist::listAll();
$ordnerListe = PlaylistOrdner::listAllMitAnzahl();

/** Verwendungs-Kategorie einer Playlist (für Filter + Badge). */
function pl_verwendung(array $p): string
{
    if ((int)$p['anzahl_monitore'] > 0)        { return 'monitor'; }
    if ((int)$p['anzahl_termine_kommend'] > 0) { return 'termin'; }
    return 'frei';
}

// --- Badge-Highlight: „in N Playlists"-Badge (Bibliothek) markiert hier die
// Playlists, die die Modul-Instanz enthalten. Die Bearbeiten-Links schleifen
// den Parameter durch den Playlist-Editor zurück.
$hlIds    = [];
$hlQuery  = '';
$hlLeiste = null;
$hlIn = (int)($_GET['hl_instanz'] ?? 0);
if ($hlIn > 0 && ($hlObj = ModulInstanz::find($hlIn))) {
    $hlIds    = Playlist::idsMitInstanz($hlIn);
    $hlQuery  = '&hl_instanz=' . $hlIn;
    $hlLeiste = 'Hervorgehoben: Playlists mit Modul-Instanz „<strong>'
              . htmlspecialchars($hlObj['name']) . '</strong>"';
    if (empty($hlIds)) { $hlLeiste .= ' — derzeit in keiner Playlist enthalten'; }
}

/** Kurzbeschreibung des Layouts aus den gespeicherten Werten. */
function pl_layout_text(array $p): string
{
    $n = (int)($p['spalten_anzahl'] ?? 1);
    if ($n <= 1) {
        return '1 Spalte';
    }
    $breiten = array_values(array_filter([
        $p['spalte1_breite'] ?? null,
        $p['spalte2_breite'] ?? null,
        $p['spalte3_breite'] ?? null,
    ], static fn($v) => $v !== null));
    return $n . ' Spalten · ' . implode(' / ', array_map('intval', $breiten)) . ' %';
}

// ===== Ebene 1: Ordner-Übersicht =====
if ($ordnerParam === null) {
    $anzahlAktiv  = count(array_filter($alle, fn($p) => !(int)$p['archiviert']));
    $anzahlOhne   = count(array_filter($alle, fn($p) => !(int)$p['archiviert'] && $p['ordner_id'] === null));
    $anzahlArchiv = count($alle) - $anzahlAktiv;

    admin_header('Playlists', 'playlists');
    ?>
    <?php if ($hinweis): ?><div class="adm-flash"><?= htmlspecialchars($hinweis) ?></div><?php endif; ?>

    <details class="adm-hilfe-klapp">
        <summary><span class="adm-hk-zu">ℹ️ Erklärung anzeigen</span><span class="adm-hk-auf">ℹ️ Erklärung verbergen</span></summary>
        <p class="adm-hilfe">
            Hier legst du Playlists an. Jede Playlist hat ein Layout (1–3 Spalten) mit
            Modul-Instanzen je Spalte. Wann welche Playlist läuft, steuerst du unter
            <a href="monitore.php">Monitore → Zeitplan</a>.
            Ordner dienen nur der Übersicht — sie ändern nichts daran, was auf den Monitoren läuft.
            Nicht mehr gebrauchte Playlists kannst du <strong>archivieren</strong>: Sie verschwinden
            aus der Übersicht und aus allen Auswahllisten, bleiben aber erhalten.
        </p>
    </details>

    <div class="adm-neuzeile">
        <a class="adm-btn-primary" href="playlist-editor.php">+ Neue Playlist</a>
    </div>

    <div class="adm-ordnergrid">
        <a class="adm-ordnerkachel" href="playlists.php?ordner=alle">
            <span class="adm-ordner-cover">🗂️</span>
            <span class="adm-ordner-titel">Alle Playlists</span>
            <span class="adm-ordner-zahl"><?= $anzahlAktiv ?></span>
        </a>
        <a class="adm-ordnerkachel" href="playlists.php?ordner=none">
            <span class="adm-ordner-cover">🗂️</span>
            <span class="adm-ordner-titel">Ohne Ordner</span>
            <span class="adm-ordner-zahl"><?= $anzahlOhne ?></span>
        </a>
        <?php foreach ($ordnerListe as $o): ?>
            <div class="adm-ordnerkachel-wrap">
                <a class="adm-ordnerkachel" href="playlists.php?ordner=<?= (int)$o['id'] ?>">
                    <span class="adm-ordner-cover">📁</span>
                    <span class="adm-ordner-titel"><?= htmlspecialchars($o['name']) ?></span>
                    <span class="adm-ordner-zahl"><?= (int)$o['anzahl'] ?></span>
                </a>
                <div class="adm-ordner-tools">
                    <button type="button" class="adm-ordner-edit" data-id="<?= (int)$o['id'] ?>" data-name="<?= htmlspecialchars($o['name']) ?>" title="Umbenennen">✎</button>
                    <button type="button" class="adm-ordner-del" data-id="<?= (int)$o['id'] ?>" data-name="<?= htmlspecialchars($o['name']) ?>" title="Ordner löschen">×</button>
                </div>
            </div>
        <?php endforeach; ?>
        <button type="button" id="ordner-neu" class="adm-ordnerkachel adm-ordner-neu">
            <span class="adm-ordner-cover">＋</span>
            <span class="adm-ordner-titel">Neuer Ordner</span>
        </button>
        <a class="adm-ordnerkachel adm-ordner-archiv" href="playlists.php?ordner=archiv">
            <span class="adm-ordner-cover">🗄️</span>
            <span class="adm-ordner-titel">Archiv</span>
            <span class="adm-ordner-zahl"><?= $anzahlArchiv ?></span>
        </a>
    </div>

    <script>
    (function () {
        function ordnerAktion(params, fehlertext) {
            var fd = new FormData();
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
            return fetch('api/playlist-ordner.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (!data.ok) { admMeldung(data.error || fehlertext); return false; } return true; })
                .catch(function () { admMeldung(fehlertext + ' (Netzwerkfehler).'); return false; });
        }
        document.getElementById('ordner-neu').addEventListener('click', function () {
            admEingabe('Name des neuen Ordners:', '', function (name) {
                if (!name) { return; }
                ordnerAktion({ action: 'create', name: name }, 'Ordner konnte nicht angelegt werden.')
                    .then(function (ok) { if (ok) { location.reload(); } });
            });
        });
        document.querySelector('.adm-ordnergrid').addEventListener('click', function (e) {
            var ed = e.target.closest('.adm-ordner-edit');
            if (ed) {
                e.preventDefault();
                admEingabe('Ordner umbenennen:', ed.getAttribute('data-name'), function (name) {
                    if (!name) { return; }
                    ordnerAktion({ action: 'rename', id: ed.getAttribute('data-id'), name: name }, 'Umbenennen fehlgeschlagen.')
                        .then(function (ok) { if (ok) { location.reload(); } });
                });
                return;
            }
            var dl = e.target.closest('.adm-ordner-del');
            if (dl) {
                e.preventDefault();
                admBestaetigen('Ordner „' + dl.getAttribute('data-name') + '" löschen? Die Playlists bleiben erhalten und landen in „Ohne Ordner".', function (ok) {
                    if (!ok) { return; }
                    ordnerAktion({ action: 'delete', id: dl.getAttribute('data-id') }, 'Löschen fehlgeschlagen.')
                        .then(function (ok2) { if (ok2) { location.reload(); } });
                }, 'Löschen');
            }
        });
    })();
    </script>
    <?php
    admin_footer();
    exit;
}

// ===== Ebene 2: Playlist-Ansicht =====
$istArchiv     = ($ordnerParam === 'archiv');
$ordnerNamen   = [];
foreach ($ordnerListe as $o) { $ordnerNamen[(int)$o['id']] = $o['name']; }
$aktuellerName = 'Alle Playlists';
if ($ordnerParam === 'none')  { $aktuellerName = 'Ohne Ordner'; }
if ($istArchiv)               { $aktuellerName = 'Archiv'; }
if (ctype_digit((string)$ordnerParam)) {
    $aktuellerName = $ordnerNamen[(int)$ordnerParam] ?? 'Ordner';
}

// Erst nach Ordner/Archiv eingrenzen, dann zählen (Filter-Chips), dann filtern.
$imOrdner = array_values(array_filter($alle, function ($p) use ($ordnerParam, $istArchiv) {
    if ($istArchiv) { return (bool)(int)$p['archiviert']; }
    if ((int)$p['archiviert']) { return false; }
    if ($ordnerParam === 'none') { return $p['ordner_id'] === null; }
    if (ctype_digit((string)$ordnerParam)) { return (int)$p['ordner_id'] === (int)$ordnerParam; }
    return true;
}));
$zaehler = ['' => count($imOrdner), 'monitor' => 0, 'termin' => 0, 'frei' => 0, 'pausiert' => 0];
foreach ($imOrdner as $p) {
    $zaehler[pl_verwendung($p)]++;
    if (!(int)$p['aktiv']) { $zaehler['pausiert']++; }
}
$playlists = array_values(array_filter($imOrdner, function ($p) use ($filterParam) {
    if ($filterParam === '')         { return true; }
    if ($filterParam === 'pausiert') { return !(int)$p['aktiv']; }
    return pl_verwendung($p) === $filterParam;
}));
$filterChips = [
    ''         => 'Alle',
    'monitor'  => 'Läuft auf Monitoren',
    'termin'   => 'Nur in Kalender-Terminen',
    'frei'     => 'Nicht eingeplant',
    'pausiert' => 'Pausiert',
];
// Editor-Links merken sich die Ansicht, damit Zurück/Schließen hierher führt.
$vonQuery = '&von=' . rawurlencode((string)$ordnerParam);

admin_header('Playlists – ' . $aktuellerName, 'playlists');
?>

<p><a href="playlists.php" class="adm-zurueck">← zurück zur Ordnerübersicht</a></p>

<h1 style="margin-top:0;"><?= htmlspecialchars($aktuellerName) ?></h1>

<?php if ($hlLeiste !== null) { admin_hl_leiste($hlLeiste, 'playlists.php'); } ?>

<?php if ($fehler): ?>
    <div class="adm-flash adm-flash-fehler"><?= htmlspecialchars($fehler) ?></div>
<?php endif; ?>

<?php if ($hinweis): ?>
    <div class="adm-flash<?= $hinweisAktion ? ' adm-flash--mit-aktion' : '' ?>">
        <span><?= htmlspecialchars($hinweis) ?></span>
        <?php if ($hinweisAktion): ?>
            <a class="adm-btn adm-flash-btn" href="<?= htmlspecialchars($hinweisAktion[0]) ?>"><?= htmlspecialchars($hinweisAktion[1]) ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($istArchiv): ?>
    <p class="adm-hilfe">Archivierte Playlists sind in der Übersicht und in allen Auswahllisten
        (Zeitplan, Kalender) ausgeblendet. „Wiederherstellen" holt sie zurück.</p>
<?php else: ?>
<div class="adm-neuzeile">
    <a class="adm-btn-primary" href="playlist-editor.php?von=<?= htmlspecialchars(rawurlencode((string)$ordnerParam)) ?>">+ Neue Playlist</a>
</div>

<div class="adm-filterzeile">
    <div class="adm-tagfilter">
        <span class="adm-tagfilter-label">Anzeigen:</span>
        <?php foreach ($filterChips as $fKey => $fLabel): ?>
            <a href="<?= htmlspecialchars(pl_url(['f' => $fKey])) ?>"
               class="adm-tagchip <?= $filterParam === $fKey ? 'aktiv' : '' ?>">
                <?= htmlspecialchars($fLabel) ?> <span class="adm-zahl"><?= (int)$zaehler[$fKey] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($playlists)): ?>
    <p class="adm-leer"><?= $istArchiv ? 'Das Archiv ist leer.' : ($filterParam !== '' ? 'Keine Playlist passt zu diesem Filter.' : 'In diesem Ordner liegt noch keine Playlist.') ?></p>
<?php else: ?>
<div class="adm-kachelgrid">
    <?php foreach ($playlists as $p):
        $istHl = in_array((int)$p['id'], $hlIds, true);
    ?>
        <div class="adm-kachel <?= $p['aktiv'] ? '' : 'inaktiv' ?><?= $istHl ? ' adm-kachel--highlight' : '' ?>">
            <div class="adm-kachel-vorschau info">
                <span class="adm-kachel-icon">🗂️</span>
                <span class="adm-kachel-info">
                    <?= htmlspecialchars(pl_layout_text($p)) ?><br>
                    <?= (int)$p['anzahl_module'] ?> Modul-Instanz<?= (int)$p['anzahl_module'] === 1 ? '' : 'en' ?>
                </span>
            </div>
            <div class="adm-kachel-badges">
                <a class="adm-meta-badge adm-monitore-badge<?= (int)$p['anzahl_monitore'] > 0 ? ' adm-monitore-badge--aktiv' : '' ?>"
                   href="monitore.php<?= (int)$p['anzahl_monitore'] > 0 ? '?hl_playlist=' . (int)$p['id'] : '' ?>"
                   data-monitore="<?= htmlspecialchars($p['monitor_namen'] ?? '') ?>">🖥️ auf <?= (int)$p['anzahl_monitore'] ?> Monitor<?= (int)$p['anzahl_monitore'] === 1 ? '' : 'en' ?><?= (int)$p['anzahl_monitore'] === 0 ? ' — einplanen' : '' ?></a>
                <?php if ((int)$p['anzahl_termine_kommend'] > 0): ?>
                    <a class="adm-meta-badge" href="wochenplan.php">📅 <?= (int)$p['anzahl_termine_kommend'] ?> Termin<?= (int)$p['anzahl_termine_kommend'] === 1 ? '' : 'e' ?></a>
                <?php elseif (!$istArchiv && pl_verwendung($p) === 'frei'): ?>
                    <span class="adm-meta-badge adm-meta-badge--ungenutzt" title="Weder im Wochenplan noch in einem kommenden Kalender-Termin — Kandidat zum Archivieren">nicht eingeplant</span>
                <?php endif; ?>
            </div>
            <div class="adm-kachel-body">
                <div class="adm-kachel-name">
                    <?= htmlspecialchars($p['name']) ?>
                    <?php if (!$p['aktiv']): ?><span class="adm-badge-pause">pausiert</span><?php endif; ?>
                </div>
                <?php if ($ordnerParam === 'alle' || $istArchiv): ?>
                    <div class="adm-kachel-ordner">📁 <?= htmlspecialchars($p['ordner_id'] !== null ? ($ordnerNamen[(int)$p['ordner_id']] ?? '—') : 'Ohne Ordner') ?></div>
                <?php endif; ?>
                <div class="adm-kachel-aktionen">
                    <?php if ($istArchiv): ?>
                    <form method="post" class="adm-inline">
                        <input type="hidden" name="aktion" value="wiederherstellen">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="adm-btn">Wiederherstellen</button>
                    </form>
                    <?php else: ?>
                    <a class="adm-btn" href="playlist-editor.php?id=<?= (int)$p['id'] . htmlspecialchars($hlQuery . $vonQuery) ?>">Bearbeiten</a>
                    <?php endif; ?>
                    <button type="button" class="adm-btn adm-vorschau-btn"
                            data-url="playlist-preview.php?id=<?= (int)$p['id'] ?>"
                            data-name="<?= htmlspecialchars($p['name']) ?>">Vorschau</button>
                    <?php if (!$istArchiv): ?>
                    <form method="post" class="adm-inline">
                        <input type="hidden" name="aktion" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-grau"><?= $p['aktiv'] ? 'Pausieren' : 'Aktivieren' ?></button>
                    </form>
                    <form method="post" class="adm-inline">
                        <input type="hidden" name="aktion" value="archivieren">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="adm-btn adm-btn-grau">Archivieren</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="adm-inline adm-del-form" data-name="<?= htmlspecialchars($p['name']) ?>">
                        <input type="hidden" name="aktion" value="loeschen">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
        admBestaetigen('Playlist „' + (f.dataset.name || '') + '" wirklich löschen?', function (ok) {
            if (ok) { f.submit(); }
        }, 'Löschen');
    });
});
</script>

<?php
admin_footer();
