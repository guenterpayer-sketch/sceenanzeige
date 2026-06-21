<?php
/**
 * proxies/_cors.php
 *
 * Gemeinsamer CORS-/JSON-Header-Helfer für die Proxys (nc.php, fret.php).
 * Die Saal-Frontends (saalX.tcpayer.de) rufen diese Proxys cross-origin auf;
 * erlaubt werden ausschließlich die bekannten Saal-Subdomains (analog zur
 * .htaccess-Regel aus Schritt 2 / Chat-Zusammenfassung Schritt 1-2).
 *
 * Bei Erweiterung um weitere Säle hier UND in der screen-.htaccess ergänzen.
 */

declare(strict_types=1);

/** Setzt CORS- und JSON-Header. Beendet Preflight-OPTIONS-Anfragen direkt. */
function proxy_cors_und_json(): void
{
    $allowedPattern = '/^https:\/\/(saal1|saal2|saal3)\.tcpayer\.de$/';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && preg_match($allowedPattern, $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
    }

    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Gibt $data als JSON aus und beendet das Skript. */
function proxy_json_exit(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Gibt eine Fehlerantwort (JSON) aus und beendet das Skript. */
function proxy_fehler(string $nachricht, int $status = 400): never
{
    proxy_json_exit(['error' => $nachricht], $status);
}
