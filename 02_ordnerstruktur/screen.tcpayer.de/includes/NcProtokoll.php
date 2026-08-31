<?php
/**
 * includes/NcProtokoll.php
 *
 * Protokoll der ECHTEN Nimbuscloud-Aufrufe.
 *
 * Warum: Im Debug-Log der Nimbuscloud stehen die Aufrufe ALLER Verbraucher
 * der Schule nebeneinander (u.a. die Musiksoftware). Ohne eigene Zählung
 * lässt sich nicht sagen, welcher Anteil davon auf das Monitor-System geht.
 * Diese Klasse beantwortet genau eine Frage: Wie oft haben WIR gefragt?
 *
 * Protokolliert wird ausschließlich der Moment, in dem tatsächlich Daten
 * über das Netz von der NC-API geholt werden. Anfragen, die aus dem Cache
 * bedient werden (der Normalfall — viele hundert am Tag), stehen bewusst
 * NICHT drin: Sie kosten kein Kontingent, und sie würden die Datei fluten.
 * Eine Protokollzeile entspricht also einer Zeile im NC-Debug-Log.
 *
 * Format: eine Zeile je Aufruf, Felder tab-getrennt (leicht zu lesen, ohne
 * Bibliothek auswertbar, anhängbar ohne die Datei zu lesen):
 *
 *   Zeit(ISO)  Endpunkt  HTTP  Dauer_ms  Ergebnis  Herkunft  Hinweis
 *
 * Ablage: eine Datei je Monat im Cache-Verzeichnis (siehe NcCache). Der
 * Monatsschnitt macht das Zählen einfach — das NC-Kontingent gilt pro
 * Monat — und begrenzt die Dateigröße. „Monitore neu laden" leert den
 * Cache, lässt das Protokoll aber stehen (anderes Dateimuster).
 */

declare(strict_types=1);

require_once __DIR__ . '/NcCache.php';

final class NcProtokoll
{
    private const PRAEFIX = 'nc_protokoll_';

    /** So viele Monatsdateien bleiben erhalten. */
    private const MONATE_AUFHEBEN = 6;

    /** Sicherheitsgrenze beim Lesen — verhindert Speicherprobleme. */
    private const MAX_ZEILEN = 5000;

    private static function pfad(string $monat): string
    {
        $sauber = preg_replace('/[^0-9-]/', '', $monat) ?? '';
        return NcCache::verzeichnis() . '/' . self::PRAEFIX . $sauber . '.log';
    }

    /** Entfernt Zeichen, die die Zeilenstruktur zerstören würden. */
    private static function feld(string $wert): string
    {
        return trim(str_replace(["\t", "\r", "\n"], ' ', $wert));
    }

    /**
     * Hält einen echten API-Aufruf fest.
     *
     * @param string $endpunkt  Kurzname, z.B. 'timetable' oder 'locations'
     * @param int    $httpCode  HTTP-Status (0 = keine Verbindung zustande)
     * @param int    $dauerMs   Dauer des Aufrufs in Millisekunden
     * @param bool   $erfolg    Konnten die Daten verwendet werden?
     * @param string $hinweis   Fehlertext o.ä. (optional)
     */
    public static function eintragen(
        string $endpunkt,
        int $httpCode,
        int $dauerMs,
        bool $erfolg,
        string $hinweis = ''
    ): void {
        $zeile = implode("\t", [
            date('c'),
            self::feld($endpunkt),
            (string)$httpCode,
            (string)$dauerMs,
            $erfolg ? 'ok' : 'fehler',
            self::feld($_SERVER['HTTP_HOST'] ?? 'cli'),
            self::feld($hinweis),
        ]) . "\n";

        $pfad = self::pfad(date('Y-m'));
        $neu  = !is_file($pfad);

        // Fehler beim Schreiben dürfen den Abruf nicht gefährden: Das
        // Protokoll ist Diagnose, nicht Betrieb.
        @file_put_contents($pfad, $zeile, FILE_APPEND | LOCK_EX);

        if ($neu) {
            self::alteEntsorgen();
        }
    }

    /**
     * Liest die Einträge eines Monats, neueste zuerst.
     *
     * @return array<int, array{zeit:string, endpunkt:string, http:int,
     *                          dauer_ms:int, ergebnis:string, herkunft:string,
     *                          hinweis:string}>
     */
    public static function lese(string $monat): array
    {
        $pfad = self::pfad($monat);
        if (!is_file($pfad)) {
            return [];
        }

        $zeilen = @file($pfad, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($zeilen === false) {
            return [];
        }

        if (count($zeilen) > self::MAX_ZEILEN) {
            $zeilen = array_slice($zeilen, -self::MAX_ZEILEN);
        }

        $eintraege = [];
        foreach ($zeilen as $zeile) {
            $f = explode("\t", $zeile);
            if (count($f) < 5) {
                continue;
            }
            $eintraege[] = [
                'zeit'     => $f[0],
                'endpunkt' => $f[1],
                'http'     => (int)$f[2],
                'dauer_ms' => (int)$f[3],
                'ergebnis' => $f[4],
                'herkunft' => $f[5] ?? '',
                'hinweis'  => $f[6] ?? '',
            ];
        }

        return array_reverse($eintraege);
    }

    /**
     * Vorhandene Monate, neuester zuerst (Format 'YYYY-MM').
     * @return string[]
     */
    public static function monate(): array
    {
        $treffer = glob(NcCache::verzeichnis() . '/' . self::PRAEFIX . '*.log');
        if ($treffer === false || $treffer === []) {
            return [];
        }

        $monate = [];
        foreach ($treffer as $datei) {
            $monate[] = substr(basename($datei, '.log'), strlen(self::PRAEFIX));
        }
        rsort($monate);
        return $monate;
    }

    /**
     * Zahlen für die Kopfzeile im Admin.
     * @return array{heute:int, monat:int, fehler_monat:int, letzter:?array}
     */
    public static function zusammenfassung(): array
    {
        $eintraege = self::lese(date('Y-m'));
        $heuteIso  = date('Y-m-d');

        $heute   = 0;
        $fehler  = 0;
        foreach ($eintraege as $e) {
            if (str_starts_with($e['zeit'], $heuteIso)) {
                $heute++;
            }
            if ($e['ergebnis'] !== 'ok') {
                $fehler++;
            }
        }

        return [
            'heute'        => $heute,
            'monat'        => count($eintraege),
            'fehler_monat' => $fehler,
            'letzter'      => $eintraege[0] ?? null,
        ];
    }

    /** Löscht Monatsdateien, die älter sind als MONATE_AUFHEBEN. */
    private static function alteEntsorgen(): void
    {
        $monate = self::monate();
        if (count($monate) <= self::MONATE_AUFHEBEN) {
            return;
        }

        foreach (array_slice($monate, self::MONATE_AUFHEBEN) as $monat) {
            @unlink(self::pfad($monat));
        }
    }
}
