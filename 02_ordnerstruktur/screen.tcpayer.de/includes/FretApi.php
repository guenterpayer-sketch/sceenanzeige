<?php
/**
 * includes/FretApi.php
 *
 * Schlanker serverseitiger Zugriff auf die FRET-API für das Backend (z.B. zum
 * Abrufen der Computerliste im Bereich "FRET-Geräte"). Die FRET_SCHOOL_ID
 * bleibt serverseitig (config.php) und gelangt nie ans Frontend.
 *
 * Hinweis: Der Live-Datenabruf der Monitore läuft weiterhin über
 * proxies/fret.php; diese Klasse ist nur für Backend-Aktionen gedacht.
 */

declare(strict_types=1);

final class FretApi
{
    /**
     * Liefert die Liste der FRET-Computer: [['id' => uuid, 'name' => ...], ...]
     *
     * @throws RuntimeException bei fehlender Konfiguration oder API-Fehler
     */
    public static function listComputers(): array
    {
        if (!defined('FRET_SCHOOL_ID') || FRET_SCHOOL_ID === '') {
            throw new RuntimeException('FRET_SCHOOL_ID ist nicht konfiguriert (config.php).');
        }
        $url = rtrim(FRET_API_BASE, '/') . '/schools/' . rawurlencode(FRET_SCHOOL_ID) . '/Computers';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $antwort  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($antwort === false) {
            throw new RuntimeException('Verbindung zur FRET-API fehlgeschlagen: ' . $curlErr);
        }
        if ($httpCode >= 400) {
            throw new RuntimeException('FRET-API-Fehler (HTTP ' . $httpCode . ').');
        }
        $json = json_decode($antwort, true);
        if (!is_array($json)) {
            throw new RuntimeException('Unerwartete Antwort von der FRET-API.');
        }

        $liste = [];
        foreach ($json as $c) {
            $liste[] = ['id' => (string)($c['id'] ?? ''), 'name' => (string)($c['name'] ?? '')];
        }
        return $liste;
    }
}
