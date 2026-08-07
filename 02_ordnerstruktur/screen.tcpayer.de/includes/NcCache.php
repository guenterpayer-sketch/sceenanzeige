<?php
/**
 * includes/NcCache.php
 *
 * Tages-Cache für die Nimbuscloud-Antwort (proxies/nc.php).
 *
 * Hintergrund: Das Kontingent der NC-Legacy-API ist pro Monat begrenzt.
 * Ohne Cache holt JEDER Monitor bei JEDEM Seitenaufbau eigene Daten —
 * bei mehreren Sälen und mehreren Modul-Instanzen summiert sich das.
 *
 * Gecacht wird die ROHE Event-Liste der API, geschlüsselt nur nach den
 * Parametern des API-Aufrufs (Datum + Tagesanzahl). Die Standort-/Saal-
 * Filter werden erst danach in nc.php angewendet — dadurch teilen sich
 * ALLE Modul-Instanzen, egal mit welchem Filter, denselben Abruf.
 *
 * Gültigkeit: bis Mitternacht. Der Schlüssel enthält das Datum, ein
 * Tageswechsel erzeugt also automatisch einen neuen Eintrag.
 *
 * Invalidierung: admin/reload_trigger.php („Monitore neu laden") ruft
 * leeren() → der nächste Abruf holt garantiert frische Daten. Das ist
 * bewusst der EINZIGE Weg, den Cache zu umgehen: einen Query-Parameter
 * wie ?frisch=1 gibt es nicht, weil proxies/nc.php ohne Login erreichbar
 * ist und sich damit von außen das Kontingent leerrufen ließe.
 *
 * Speicherort: <wurzel>/cache/ (per .htaccess gesperrt). Ist der Ordner
 * nicht beschreibbar (Rechte auf dem Hosting), wird auf das temporäre
 * Systemverzeichnis ausgewichen — der Cache funktioniert dann weiter,
 * ist nur ggf. kurzlebiger.
 */

declare(strict_types=1);

final class NcCache
{
    /** Dateipräfix — leeren() räumt gezielt nur diese Dateien weg. */
    private const PRAEFIX = 'nc_timetable_';

    /** Ältere Dateien als dieses Alter werden beim Schreiben entsorgt. */
    private const MAX_ALTER_SEK = 172800; // 2 Tage

    /** Verzeichnis für die Cache-Dateien (mit Fallback). */
    public static function verzeichnis(): string
    {
        $eigen = dirname(__DIR__) . '/cache';

        if (!is_dir($eigen)) {
            @mkdir($eigen, 0775, true);
        }
        if (is_dir($eigen) && is_writable($eigen)) {
            return $eigen;
        }

        return rtrim(sys_get_temp_dir(), '/\\');
    }

    private static function pfad(string $schluessel): string
    {
        // Schlüssel kommt aus dem Code, nicht aus Nutzereingaben — zur
        // Sicherheit trotzdem auf harmlose Zeichen reduzieren.
        $sauber = preg_replace('/[^A-Za-z0-9_-]/', '', $schluessel) ?? '';
        return self::verzeichnis() . '/' . self::PRAEFIX . $sauber . '.json';
    }

    /**
     * Liefert die gecachten Events oder null (kein Eintrag / abgelaufen /
     * unlesbar). Rückgabe: ['geholt_am' => int, 'gueltig_bis' => int,
     * 'events' => array].
     */
    public static function lese(string $schluessel): ?array
    {
        $pfad = self::pfad($schluessel);
        if (!is_file($pfad)) {
            return null;
        }

        $roh = @file_get_contents($pfad);
        if ($roh === false || $roh === '') {
            return null;
        }

        $daten = json_decode($roh, true);
        if (!is_array($daten) || !isset($daten['gueltig_bis']) || !isset($daten['events'])) {
            return null;
        }
        if (!is_array($daten['events']) || time() >= (int)$daten['gueltig_bis']) {
            return null;
        }

        return $daten;
    }

    /** Legt die Events bis $gueltigBis (Unix-Zeit) ab. Fehler sind unkritisch. */
    public static function schreibe(string $schluessel, array $events, int $gueltigBis): void
    {
        $inhalt = json_encode([
            'geholt_am'   => time(),
            'gueltig_bis' => $gueltigBis,
            'events'      => $events,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($inhalt === false) {
            return;
        }

        $pfad = self::pfad($schluessel);
        // Atomar schreiben: sonst könnte ein parallel lesender Monitor eine
        // halb geschriebene Datei erwischen.
        $tmp = $pfad . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $inhalt, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $pfad)) {
            @unlink($tmp);
        }

        self::alteEntsorgen();
    }

    /**
     * Löscht alle Cache-Dateien. Wird von „Monitore neu laden" gerufen,
     * damit der nächste Abruf frische Daten holt.
     * @return int Anzahl gelöschter Dateien
     */
    public static function leeren(): int
    {
        $treffer = glob(self::verzeichnis() . '/' . self::PRAEFIX . '*.json');
        if ($treffer === false) {
            return 0;
        }

        $anzahl = 0;
        foreach ($treffer as $datei) {
            if (@unlink($datei)) {
                $anzahl++;
            }
        }
        return $anzahl;
    }

    /** Räumt Dateien vergangener Tage weg (Schlüssel enthält das Datum). */
    private static function alteEntsorgen(): void
    {
        $treffer = glob(self::verzeichnis() . '/' . self::PRAEFIX . '*.json');
        if ($treffer === false) {
            return;
        }

        $grenze = time() - self::MAX_ALTER_SEK;
        foreach ($treffer as $datei) {
            $zeit = @filemtime($datei);
            if ($zeit !== false && $zeit < $grenze) {
                @unlink($datei);
            }
        }
    }
}
