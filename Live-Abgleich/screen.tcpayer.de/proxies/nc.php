<?php
/**
 * proxies/nc.php
 *
 * Serverseitiger Proxy zur Nimbuscloud-API (Abschnitt 9 der Doku). Der
 * NC-API-Key wird AUSSCHLIESSLICH hier verwendet und niemals ans Frontend
 * übertragen — exakt wie für den Song-Proxy gefordert.
 *
 * Aufruf: /proxies/nc.php?action=stundenplan|community&saal_id=X&modul_instanz_id=Y
 *   - saal_id           -> bestimmt, welcher NC-API-Key (Tabelle "einstellungen") verwendet wird
 *   - modul_instanz_id  -> bestimmt, welche Modul-Settings (Tabelle "modul_instanzen") verwendet werden
 *
 * Bewusste Design-Entscheidung: modulspezifische Werte (anzahl_kurse,
 * customer_nr, ...) werden NICHT als GET-Parameter vom Client akzeptiert,
 * sondern immer aus der gespeicherten Modul-Instanz geladen. Das verhindert,
 * dass im Frontend frei wählbare Parameter an die NC-API durchgereicht
 * werden können.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/ModulInstanz.php';

/**
 * TODO (Schritt 4 -> vor Live-Test eintragen):
 * Platzhalter, siehe Abschnitt 9 der Projektdokumentation. Echte Subdomain
 * der Nimbuscloud-Instanz der Tanzschule eintragen.
 */
const NC_BASE = 'https://tanzcenter-payer.nimbuscloud.at/backend';

function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ncRequest(string $method, string $path, string $apiKey, ?array $body = null, array $query = []): array
{
    $url = NC_BASE . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'X-API-Key: ' . $apiKey];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['data' => $body], JSON_UNESCAPED_UNICODE));
    }
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('NC-Verbindung fehlgeschlagen: ' . $curlErr);
    }
    $decoded = json_decode($response, true);
    return ['status' => $httpCode, 'data' => $decoded['data'] ?? $decoded];
}

$action = $_GET['action'] ?? '';
$saalId = isset($_GET['saal_id']) ? (int)$_GET['saal_id'] : 0;
$modulInstanzId = isset($_GET['modul_instanz_id']) ? (int)$_GET['modul_instanz_id'] : 0;

if ($saalId <= 0) {
    fail(400, 'saal_id fehlt oder ungültig.');
}
if ($modulInstanzId <= 0) {
    fail(400, 'modul_instanz_id fehlt oder ungültig.');
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare('SELECT nc_api_key FROM einstellungen WHERE saal_id = :sid');
    $stmt->execute([':sid' => $saalId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['nc_api_key'])) {
        fail(404, 'Kein NC-API-Key für diesen Saal hinterlegt (Bereich "Säle" im Backend).');
    }
    $apiKey = $row['nc_api_key'];

    $instanz = ModulInstanz::find($modulInstanzId);
    if (!$instanz) {
        fail(404, 'Modul-Instanz nicht gefunden.');
    }
    $settings = $instanz['einstellungen'];

    if ($action === 'stundenplan') {
        // ====================================================================
        // TODO (Schritt 4 — Platzhalter, siehe Hinweis am Anfang dieser Datei
        // bzw. in Schritt4_FINAL_fuer_Projektdateien.txt):
        // Echte Tabellen-/Spaltenstruktur noch unbekannt.
        //   1. GET /v2/system/direct-db-access/schema mit demselben API-Key
        //      aufrufen -> Array von CREATE/ALTER TABLE Statements.
        //   2. Relevante Tabelle(n) für Kurse/Termine identifizieren.
        //   3. SQL unten ersetzen (anzahl_kurse/nur_heute aus $settings
        //      stammen bereits korrekt aus der Modul-Instanz).
        // Absichtlich eine garantiert fehlschlagende Platzhalter-Query, damit
        // ein fehlendes Update sofort als Fehler sichtbar wird statt leere
        // Daten zu liefern.
        // ====================================================================
        $anzahlKurse = (int)($settings['anzahl_kurse'] ?? 5);
        $nurHeute = (bool)($settings['nur_heute'] ?? true);

        $sql = "SELECT 'TODO: echtes DB-Schema eintragen, siehe Kommentar in proxies/nc.php' AS hinweis";
        $params = [];
        $arrayTypes = [];

        $result = ncRequest('POST', '/v2/system/direct-db-access/execute-query', $apiKey, [
            'sql'        => $sql,
            'params'     => $params,
            'arrayTypes' => $arrayTypes,
        ]);

        if ($result['status'] >= 400) {
            fail(502, 'NC-Stundenplan-Abfrage fehlgeschlagen (vermutlich noch Platzhalter-SQL, siehe TODO).');
        }

        echo json_encode(['kurse' => $result['data']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'community') {
        $customerNr = (int)($settings['customer_nr'] ?? 0);
        $anzahlPosts = (int)($settings['anzahl_posts'] ?? 10);

        if ($customerNr <= 0) {
            fail(400, 'customer_nr ist in den Einstellungen dieser Modul-Instanz nicht gesetzt.');
        }

        $result = ncRequest('GET', '/v2/community/api-feed/api-posts', $apiKey, null, [
            'customer'  => $customerNr,
            'id'        => 0,
            'type'      => 'main',
            'pageStart' => 0,
            'pageSize'  => $anzahlPosts,
        ]);

        if ($result['status'] >= 400) {
            fail(502, 'NC-Community-Feed-Abfrage fehlgeschlagen.');
        }

        echo json_encode(['feed' => $result['data']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    fail(400, 'Unbekannte action: ' . htmlspecialchars($action));

} catch (Throwable $e) {
    fail(500, 'Proxy-Fehler: ' . $e->getMessage());
}
