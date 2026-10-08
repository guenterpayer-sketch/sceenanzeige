<?php
/**
 * includes/VeranstaltungKategorien.php
 *
 * Kategorien des Veranstaltungskalenders auf tcpayer.de (WP "The Events
 * Calendar", öffentliche REST-API, kein Key).
 *
 * Gebraucht an zwei Stellen:
 *   - proxies/veranstaltung-kategorien.php → Checkboxen im Instanz-Editor
 *   - proxies/veranstaltungen.php          → gespeicherte IDs abgleichen
 *
 * WARUM DER ABGLEICH: Die Events-API beantwortet eine Abfrage mit einer
 * unbekannten Kategorie (z.B. in WordPress gelöscht, in einer Instanz aber
 * noch gespeichert) mit HTTP 400 für die GANZE Abfrage. Ein vergessener Haken
 * würde den Monitor also komplett leer machen. Deshalb werden vor jeder
 * Abfrage nur die IDs durchgelassen, die es noch gibt.
 *
 * Kurzer Datei-Cache (1 h) im cache/-Verzeichnis, damit nicht jeder
 * Monitor-Abruf zusätzlich die Kategorienliste holt.
 */

declare(strict_types=1);

require_once __DIR__ . '/NcCache.php';

final class VeranstaltungKategorien
{
    private const API_URL   = 'https://tcpayer.de/wp-json/tribe/events/v1/categories?per_page=100';
    private const CACHE_SEK = 3600;
    private const DATEI     = 'va_kategorien.json';

    /**
     * Alle Kategorien als [{id, name, anzahl}], alphabetisch.
     * null = API nicht erreichbar und auch kein (abgelaufener) Cache vorhanden.
     */
    public static function laden(): ?array
    {
        $pfad = NcCache::verzeichnis() . '/' . self::DATEI;

        $alt = null;
        if (is_file($pfad)) {
            $alt = json_decode((string)@file_get_contents($pfad), true);
            if (is_array($alt) && (time() - (int)@filemtime($pfad)) < self::CACHE_SEK) {
                return $alt;
            }
        }

        $frisch = self::holen();
        if ($frisch === null) {
            // Lieber eine veraltete Liste als gar keine.
            return is_array($alt) ? $alt : null;
        }
        @file_put_contents($pfad, json_encode($frisch, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $frisch;
    }

    /**
     * Schnittmenge aus gewünschten und noch existierenden IDs.
     * Ist die Liste nicht ladbar, gehen die Wünsche ungeprüft durch.
     *
     * @param int[] $ids
     * @return int[]
     */
    public static function gueltigeIds(array $ids): array
    {
        $liste = self::laden();
        if ($liste === null) {
            return $ids;
        }
        $vorhanden = array_map(static fn($k) => (int)$k['id'], $liste);
        return array_values(array_intersect($ids, $vorhanden));
    }

    private static function holen(): ?array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'TanzschuleMonitor/1.0',
        ]);
        $antwort  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($antwort === false || $httpCode >= 400) {
            return null;
        }
        $json = json_decode((string)$antwort, true);
        if (!is_array($json) || !isset($json['categories']) || !is_array($json['categories'])) {
            return null;
        }

        $liste = [];
        foreach ($json['categories'] as $k) {
            if (!isset($k['id'])) {
                continue;
            }
            $liste[] = [
                'id'     => (int)$k['id'],
                'name'   => html_entity_decode((string)($k['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'anzahl' => (int)($k['count'] ?? 0),
            ];
        }
        usort($liste, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $liste;
    }
}
