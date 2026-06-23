<?php
/**
 * admin/includes/bootstrap.php
 *
 * Gemeinsamer Einstiegspunkt für ALLE Admin-Seiten (Bibliothek, Mediathek,
 * später Playlists/Ticker/Säle). Jede Admin-Seite bindet diese Datei ganz
 * oben ein:
 *     require __DIR__ . '/includes/bootstrap.php';   (bzw. passender Pfad)
 *
 * Lädt Konfiguration + Helper-Klassen an EINER Stelle. Damit lässt sich
 * später ein Login-/Benutzer-Schutz zentral hier ergänzen, ohne jede Seite
 * einzeln anzufassen (siehe STATUS.md, geplanter Schritt "Benutzerkonten").
 *
 * Interimsschutz bis dahin: all-inkl Verzeichnisschutz (Basic-Auth) NUR auf
 * dem Ordner admin/ — proxies/, uploads/ und modules/ bleiben offen, damit
 * die Saal-Monitore weiter laden können.
 */

declare(strict_types=1);

$WURZEL = dirname(__DIR__, 2); // .../screen.tcpayer.de

require $WURZEL . '/config.php';
require $WURZEL . '/includes/ModuleRegistry.php';
require $WURZEL . '/includes/LayoutRegistry.php';
require $WURZEL . '/includes/ModulInstanz.php';
require $WURZEL . '/includes/Playlist.php';
require $WURZEL . '/includes/TickerPlaylist.php';
require $WURZEL . '/includes/Monitor.php';
require $WURZEL . '/includes/MediathekOrdner.php';
require $WURZEL . '/includes/MediathekTag.php';
require $WURZEL . '/includes/Mediathek.php';
require $WURZEL . '/includes/FretApi.php';
require $WURZEL . '/includes/FretGeraet.php';

// ----------------------------------------------------------------------------
// PLATZHALTER für späteren Login-Schutz (eigener Schritt):
//   require __DIR__ . '/auth.php';   // startet Session, prüft Login, leitet
//                                     // sonst auf admin/login.php um
// Bis dahin schützt der KAS-Verzeichnisschutz den Ordner admin/.
// ----------------------------------------------------------------------------
