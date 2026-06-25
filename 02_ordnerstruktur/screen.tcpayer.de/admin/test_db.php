<?php
declare(strict_types=1);
echo 'schritt1: start<br>';
require dirname(__DIR__) . '/config.php';
echo 'schritt2: config ok<br>';

try {
    $pdo = get_pdo();
    echo 'schritt3: DB-Verbindung ok<br>';
} catch (Throwable $e) {
    echo 'schritt3: DB-FEHLER: ' . htmlspecialchars($e->getMessage()) . '<br>';
    exit;
}

$WURZEL = dirname(__DIR__);
echo 'schritt4: lade Klassen...<br>';

require $WURZEL . '/includes/ModuleRegistry.php';   echo 'ModuleRegistry ok<br>';
require $WURZEL . '/includes/LayoutRegistry.php';   echo 'LayoutRegistry ok<br>';
require $WURZEL . '/includes/ModulInstanz.php';     echo 'ModulInstanz ok<br>';
require $WURZEL . '/includes/Playlist.php';         echo 'Playlist ok<br>';
require $WURZEL . '/includes/TickerPlaylist.php';   echo 'TickerPlaylist ok<br>';
require $WURZEL . '/includes/Monitor.php';          echo 'Monitor ok<br>';
require $WURZEL . '/includes/MediathekOrdner.php';  echo 'MediathekOrdner ok<br>';
require $WURZEL . '/includes/MediathekTag.php';     echo 'MediathekTag ok<br>';
require $WURZEL . '/includes/Mediathek.php';        echo 'Mediathek ok<br>';
require $WURZEL . '/includes/FretApi.php';          echo 'FretApi ok<br>';
require $WURZEL . '/includes/FretGeraet.php';       echo 'FretGeraet ok<br>';

echo '<strong>ALLE KLASSEN OK</strong><br>';
