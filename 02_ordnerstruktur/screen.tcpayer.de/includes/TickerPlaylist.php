<?php
/**
 * includes/TickerPlaylist.php
 *
 * CRUD-Helfer für die Ticker (eigenständiges Footer-System, Schritt 8 /
 * CLAUDE.md Abschnitt 7):
 *   - ticker_playlists  (Name, aktiv)
 *   - ticker_eintraege  (Texteinträge je Ticker: Text, Reihenfolge, Dauer)
 *
 * Der Ticker ist KEIN Modul und KEIN Bestandteil einer Playlist. WANN ein
 * Ticker auf WELCHEM Monitor läuft, steckt monitor-zentrisch in
 * ticker_zeitplan (siehe Monitor::ladeTickerZeitplan/ersetzeTickerZeitplan) —
 * analog zum Playlist-Zeitplan, aber OHNE Priorität: mehrere gleichzeitig
 * aktive Ticker werden am Monitor gemischt.
 */

declare(strict_types=1);

final class TickerPlaylist
{
    // ------------------------------------------------------------------
    // Ticker-Stammsatz
    // ------------------------------------------------------------------

    /** Legt einen Ticker an und liefert die neue ID. */
    public static function create(string $name): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('INSERT INTO ticker_playlists (name, aktiv) VALUES (:name, 1)');
        $stmt->execute([':name' => trim($name)]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, string $name): void
    {
        get_pdo()->prepare('UPDATE ticker_playlists SET name = :name WHERE id = :id')
            ->execute([':name' => trim($name), ':id' => $id]);
    }

    /** Pausiert/aktiviert den GESAMTEN Ticker ohne ihn zu löschen. */
    public static function setAktiv(int $id, bool $aktiv): void
    {
        get_pdo()->prepare('UPDATE ticker_playlists SET aktiv = :a WHERE id = :id')
            ->execute([':a' => $aktiv ? 1 : 0, ':id' => $id]);
    }

    public static function delete(int $id): void
    {
        // ON DELETE CASCADE räumt ticker_eintraege + ticker_zeitplan mit ab.
        get_pdo()->prepare('DELETE FROM ticker_playlists WHERE id = :id')->execute([':id' => $id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM ticker_playlists WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Prüft, ob bereits ein Ticker diesen Namen trägt (case-insensitiv).
     * $exceptId schließt den eigenen Ticker beim Bearbeiten aus.
     */
    public static function nameExistiert(string $name, ?int $exceptId = null): bool
    {
        $pdo = get_pdo();
        $sql = 'SELECT COUNT(*) FROM ticker_playlists WHERE LOWER(name) = LOWER(:name)';
        $params = [':name' => trim($name)];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Alle Ticker inkl. Anzahl Texteinträge und auf wie vielen Monitoren der
     * Ticker eingeplant ist (für die Kachel-Übersicht).
     * @return array<int,array>
     */
    public static function listAll(): array
    {
        $sql = 'SELECT t.id, t.name, t.aktiv, t.erstellt_am,
                       (SELECT COUNT(*) FROM ticker_eintraege e WHERE e.ticker_playlist_id = t.id) AS anzahl_eintraege,
                       (SELECT COUNT(DISTINCT z.monitor_id) FROM ticker_zeitplan z WHERE z.ticker_playlist_id = t.id) AS anzahl_monitore
                FROM ticker_playlists t
                ORDER BY t.name';
        return get_pdo()->query($sql)->fetchAll();
    }

    // ------------------------------------------------------------------
    // Texteinträge (ticker_eintraege)
    // ------------------------------------------------------------------

    /**
     * Alle Texteinträge eines Tickers, sortiert nach Reihenfolge.
     * @return array<int,array>
     */
    public static function listEintraege(int $tickerId): array
    {
        $stmt = get_pdo()->prepare(
            'SELECT id, text, reihenfolge, dauer_sek
             FROM ticker_eintraege
             WHERE ticker_playlist_id = :id
             ORDER BY reihenfolge, id'
        );
        $stmt->execute([':id' => $tickerId]);
        return $stmt->fetchAll();
    }

    /**
     * Ersetzt alle Texteinträge eines Tickers durch die übergebene Liste.
     * Die Reihenfolge ergibt sich aus der Array-Reihenfolge. Leere Texte
     * werden ignoriert.
     *
     * @param array<int,array{text:string,dauer_sek?:int}> $eintraege
     */
    public static function ersetzeEintraege(int $tickerId, array $eintraege): void
    {
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM ticker_eintraege WHERE ticker_playlist_id = :id')
                ->execute([':id' => $tickerId]);

            $stmt = $pdo->prepare(
                'INSERT INTO ticker_eintraege (ticker_playlist_id, text, reihenfolge, dauer_sek)
                 VALUES (:tid, :text, :reihenfolge, :dauer)'
            );
            $reihenfolge = 0;
            foreach ($eintraege as $e) {
                $text = trim((string)($e['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $dauer = (int)($e['dauer_sek'] ?? 8);
                if ($dauer < 1) { $dauer = 8; }
                $stmt->execute([
                    ':tid'         => $tickerId,
                    ':text'        => $text,
                    ':reihenfolge' => $reihenfolge++,
                    ':dauer'       => $dauer,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
