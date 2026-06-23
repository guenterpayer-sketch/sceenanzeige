<?php
/**
 * includes/Saal.php
 *
 * CRUD-Helfer für die Säle (Tabelle `saele`, Abschnitt 8 der Doku) — Grundlage
 * der Saal-Zuweisung von Playlists (Schritt 7). Ein Saal hat Name + Subdomain
 * (z.B. "saal1"); die UNIQUE-Subdomain ordnet das Saal-Frontend zu.
 *
 * NC-Key/FRET liegen schulweit in config.php — pro Saal werden hier KEINE
 * API-Keys mehr verwaltet (die per-Saal-Tabelle `einstellungen` wird dafür
 * nicht mehr gebraucht).
 */

declare(strict_types=1);

final class Saal
{
    /**
     * Normalisiert eine Subdomain-Eingabe: Kleinbuchstaben, nur a–z 0–9 und
     * Bindestrich; eine evtl. mitgetippte Domain (".tcpayer.de") fällt weg.
     */
    public static function normSubdomain(string $raw): string
    {
        $s = strtolower(trim($raw));
        // nur den ersten Label-Teil nehmen (alles ab dem ersten Punkt verwerfen)
        if (($pos = strpos($s, '.')) !== false) {
            $s = substr($s, 0, $pos);
        }
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        return trim((string)$s, '-');
    }

    /**
     * Alle Säle inkl. Anzahl zugewiesener Playlists (für die Übersicht und den
     * Löschhinweis).
     * @return array<int,array>
     */
    public static function listAll(): array
    {
        $sql = 'SELECT s.id, s.name, s.subdomain, s.erstellt_am,
                       (SELECT COUNT(*) FROM playlist_saele ps WHERE ps.saal_id = s.id) AS anzahl_playlists
                FROM saele s
                ORDER BY s.subdomain';
        return get_pdo()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM saele WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $subdomain): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('INSERT INTO saele (name, subdomain) VALUES (:name, :sub)');
        $stmt->execute([':name' => trim($name), ':sub' => $subdomain]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, string $name, string $subdomain): void
    {
        get_pdo()->prepare('UPDATE saele SET name = :name, subdomain = :sub WHERE id = :id')
            ->execute([':name' => trim($name), ':sub' => $subdomain, ':id' => $id]);
    }

    public static function delete(int $id): void
    {
        // ON DELETE CASCADE räumt playlist_saele, ticker_playlist_saele und
        // einstellungen dieses Saals automatisch mit ab.
        get_pdo()->prepare('DELETE FROM saele WHERE id = :id')->execute([':id' => $id]);
    }

    /** Prüft, ob die (normalisierte) Subdomain bereits vergeben ist. */
    public static function subdomainExistiert(string $subdomain, ?int $exceptId = null): bool
    {
        $pdo = get_pdo();
        $sql = 'SELECT COUNT(*) FROM saele WHERE subdomain = :sub';
        $params = [':sub' => $subdomain];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}
