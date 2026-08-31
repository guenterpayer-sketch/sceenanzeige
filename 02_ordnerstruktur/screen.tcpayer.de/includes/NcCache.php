<?php
/**
 * includes/NcCache.php
 *
 * Tages-Cache für die Nimbuscloud-Antworten (proxies/nc.php, nc-locations.php).
 *
 * Hintergrund: Das Kontingent der NC-Legacy-API ist pro Monat begrenzt.
 * Ohne Cache holt JEDER Monitor bei JEDEM Seitenaufbau eigene Daten —
 * bei mehreren Sälen und mehreren Modul-Instanzen summiert sich das.
 *
 * Gecacht wird die ROHE Antwort der API, geschlüsselt nur nach den
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
 * ── Drei Aufgaben, ein Dateiformat ──────────────────────────────────────
 * 1. ERFOLGE  (lese/schreibe): die eigentlichen Nutzdaten bis Mitternacht.
 * 2. FEHLER   (leseFehler/schreibeFehler): Antwortet NC mit einem Fehler,
 *    wird auch DAS kurz gemerkt. Ohne dieses Negativ-Gedächtnis geht jeder
 *    Wiederholungsversuch der Monitore (alle 10 Min., pro Instanz) als
 *    echter API-Aufruf raus — genau dann, wenn das Kontingent ohnehin am
 *    Limit ist. Mit ihm fragt der Server einmal für alle nach.
 * 3. SPERRE   (sperreHolen/sperreFreigeben): Um Mitternacht laufen alle
 *    Caches gleichzeitig ab und jeder Monitor stellt im selben Moment fest,
 *    dass er selbst holen muss. Die Sperre lässt den ersten gehen; die
 *    übrigen warten kurz und nehmen dann dessen Ergebnis.
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
    private const PRAEFIX = 'nc_';

    /** Ältere Dateien als dieses Alter werden beim Schreiben entsorgt. */
    private const MAX_ALTER_SEK = 172800; // 2 Tage

    /** Wie lange ein Fehler gemerkt wird, bevor NC erneut gefragt wird. */
    public const FEHLER_TTL_SEK = 600; // 10 Minuten

    /** Wie lange auf eine fremde Sperre gewartet wird, bevor selbst geholt wird. */
    private const SPERRE_WARTE_SEK = 12.0;

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

    // ------------------------------------------------------------------
    // 1. Erfolge
    // ------------------------------------------------------------------

    /**
     * Liefert die gecachten Daten oder null (kein Eintrag / abgelaufen /
     * unlesbar). Rückgabe: ['geholt_am' => int, 'gueltig_bis' => int,
     * 'daten' => array].
     */
    public static function lese(string $schluessel): ?array
    {
        $daten = self::leseDatei(self::pfad($schluessel));
        if ($daten === null) {
            return null;
        }

        // 'events' ist der Feldname aus der ersten Fassung (nur Stundenplan).
        // Übergangsweise mitlesen, damit ein Deploy nicht alle bestehenden
        // Cache-Dateien entwertet und unnötig Kontingent kostet.
        $nutz = $daten['daten'] ?? $daten['events'] ?? null;
        if (!is_array($nutz)) {
            return null;
        }

        return [
            'geholt_am'   => (int)($daten['geholt_am'] ?? 0),
            'gueltig_bis' => (int)$daten['gueltig_bis'],
            'daten'       => $nutz,
        ];
    }

    /** Legt die Daten bis $gueltigBis (Unix-Zeit) ab. Fehler sind unkritisch. */
    public static function schreibe(string $schluessel, array $daten, int $gueltigBis): void
    {
        self::schreibeDatei(self::pfad($schluessel), [
            'geholt_am'   => time(),
            'gueltig_bis' => $gueltigBis,
            'daten'       => $daten,
        ]);
        self::alteEntsorgen();
    }

    // ------------------------------------------------------------------
    // 2. Fehler (Negativ-Gedächtnis)
    // ------------------------------------------------------------------

    /** Schlüssel des Fehler-Eintrags zu einem Daten-Schlüssel. */
    private static function fehlerSchluessel(string $schluessel): string
    {
        return 'fehler_' . $schluessel;
    }

    /**
     * Liefert den gemerkten Fehler oder null, wenn keiner (mehr) gilt.
     * Rückgabe: ['meldung' => string, 'seit' => int, 'bis' => int].
     */
    public static function leseFehler(string $schluessel): ?array
    {
        $daten = self::leseDatei(self::pfad(self::fehlerSchluessel($schluessel)));
        if ($daten === null || !isset($daten['daten']['meldung'])) {
            return null;
        }

        return [
            'meldung' => (string)$daten['daten']['meldung'],
            'seit'    => (int)($daten['geholt_am'] ?? 0),
            'bis'     => (int)$daten['gueltig_bis'],
        ];
    }

    /** Merkt sich einen Fehler für FEHLER_TTL_SEK Sekunden. */
    public static function schreibeFehler(string $schluessel, string $meldung): void
    {
        self::schreibeDatei(self::pfad(self::fehlerSchluessel($schluessel)), [
            'geholt_am'   => time(),
            'gueltig_bis' => time() + self::FEHLER_TTL_SEK,
            'daten'       => ['meldung' => $meldung],
        ]);
    }

    /**
     * Alle aktuell geltenden Fehler-Einträge — für die Anzeige im Admin.
     * @return array<int, array{schluessel:string, meldung:string, seit:int, bis:int}>
     */
    public static function fehlerUebersicht(): array
    {
        $treffer = glob(self::verzeichnis() . '/' . self::PRAEFIX . 'fehler_*.json');
        if ($treffer === false) {
            return [];
        }

        $liste = [];
        foreach ($treffer as $datei) {
            $daten = self::leseDatei($datei);
            if ($daten === null || !isset($daten['daten']['meldung'])) {
                continue;
            }
            $name = basename($datei, '.json');
            $liste[] = [
                'schluessel' => substr($name, strlen(self::PRAEFIX . 'fehler_')),
                'meldung'    => (string)$daten['daten']['meldung'],
                'seit'       => (int)($daten['geholt_am'] ?? 0),
                'bis'        => (int)$daten['gueltig_bis'],
            ];
        }
        return $liste;
    }

    // ------------------------------------------------------------------
    // 3. Sperre gegen den gleichzeitigen Ansturm
    // ------------------------------------------------------------------

    /**
     * Versucht, die Sperre für diesen Schlüssel zu bekommen.
     *
     * Rückgabe: das Sperr-Handle (an sperreFreigeben() zurückgeben) oder
     * null, wenn sie nicht zu bekommen war. null heißt NICHT „abbrechen":
     * Der Aufrufer prüft dann erneut den Cache — meistens hat der Halter
     * der Sperre ihn inzwischen gefüllt — und holt notfalls selbst. Lieber
     * ein Abruf zu viel als ein leerer Monitor.
     *
     * @return resource|null
     */
    public static function sperreHolen(string $schluessel)
    {
        $pfad = self::pfad($schluessel) . '.lock';
        $fh   = @fopen($pfad, 'c');
        if ($fh === false) {
            return null;
        }

        $ende = microtime(true) + self::SPERRE_WARTE_SEK;
        do {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            usleep(200000); // 200 ms
        } while (microtime(true) < $ende);

        @fclose($fh);
        return null;
    }

    /** Gibt eine mit sperreHolen() geholte Sperre wieder frei. */
    public static function sperreFreigeben($sperre): void
    {
        if (is_resource($sperre)) {
            @flock($sperre, LOCK_UN);
            @fclose($sperre);
        }
    }

    // ------------------------------------------------------------------
    // Aufräumen
    // ------------------------------------------------------------------

    /**
     * Löscht alle Cache-Dateien (Erfolge UND gemerkte Fehler). Wird von
     * „Monitore neu laden" gerufen, damit der nächste Abruf frische Daten
     * holt — auch dann, wenn gerade ein Fehler gemerkt ist.
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
        $treffer = glob(self::verzeichnis() . '/' . self::PRAEFIX . '*.{json,lock}', GLOB_BRACE);
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

    // ------------------------------------------------------------------
    // Datei-Ebene
    // ------------------------------------------------------------------

    /** Liest eine Cache-Datei; null bei fehlend, unlesbar oder abgelaufen. */
    private static function leseDatei(string $pfad): ?array
    {
        if (!is_file($pfad)) {
            return null;
        }

        $roh = @file_get_contents($pfad);
        if ($roh === false || $roh === '') {
            return null;
        }

        $daten = json_decode($roh, true);
        if (!is_array($daten) || !isset($daten['gueltig_bis'])) {
            return null;
        }
        if (time() >= (int)$daten['gueltig_bis']) {
            return null;
        }

        return $daten;
    }

    /** Schreibt eine Cache-Datei atomar. Fehler sind unkritisch. */
    private static function schreibeDatei(string $pfad, array $inhalt): void
    {
        $json = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // Atomar schreiben: sonst könnte ein parallel lesender Monitor eine
        // halb geschriebene Datei erwischen.
        $tmp = $pfad . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $pfad)) {
            @unlink($tmp);
        }
    }
}
