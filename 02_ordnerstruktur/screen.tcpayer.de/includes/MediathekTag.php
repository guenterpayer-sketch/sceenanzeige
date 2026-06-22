<?php
/**
 * includes/MediathekTag.php
 *
 * Verwaltung der Mediathek-Tags (n:m, Tabellen `mediathek_tags` +
 * `mediathek_tag`). Ein Bild kann mehrere frei vergebbare Schlagworte haben;
 * dasselbe Tag wird über alle Bilder hinweg wiederverwendet (keine Dubletten,
 * Name ist UNIQUE). Verwaiste Tags (ohne Zuordnung) werden beim Speichern
 * automatisch aufgeräumt.
 */

declare(strict_types=1);

final class MediathekTag
{
    /** @return array<int,array> Alle Tags inkl. Nutzungsanzahl, alphabetisch */
    public static function listAllMitAnzahl(): array
    {
        return get_pdo()->query(
            'SELECT t.id, t.name, COUNT(mt.mediathek_id) AS anzahl
             FROM mediathek_tags t
             LEFT JOIN mediathek_tag mt ON mt.tag_id = t.id
             GROUP BY t.id, t.name
             ORDER BY t.name'
        )->fetchAll();
    }

    /**
     * Liefert die Tag-Namen zu mehreren Bildern auf einmal.
     *
     * @param int[] $mediathekIds
     * @return array<int,string[]> mediathek_id => [tagname, ...]
     */
    public static function tagsFuerBilder(array $mediathekIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediathekIds)));
        if (empty($ids)) {
            return [];
        }
        $platzhalter = implode(',', array_fill(0, count($ids), '?'));
        $stmt = get_pdo()->prepare(
            "SELECT mt.mediathek_id, t.name
             FROM mediathek_tag mt
             JOIN mediathek_tags t ON t.id = mt.tag_id
             WHERE mt.mediathek_id IN ($platzhalter)
             ORDER BY t.name"
        );
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['mediathek_id']][] = $row['name'];
        }
        return $map;
    }

    /** Stellt sicher, dass ein Tag existiert, und gibt seine id zurück. */
    public static function ensureTag(string $name): int
    {
        $name = mb_substr(trim($name), 0, 80);
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT id FROM mediathek_tags WHERE name = :n');
        $stmt->execute([':n' => $name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $pdo->prepare('INSERT INTO mediathek_tags (name) VALUES (:n)')->execute([':n' => $name]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Setzt die komplette Tag-Liste eines Bilds neu (ersetzt vorhandene
     * Zuordnungen). Leere Namen werden ignoriert, Duplikate entfernt.
     *
     * @param string[] $namen
     * @return string[] die tatsächlich gesetzten Tag-Namen (normalisiert)
     */
    public static function setzeTagsFuerBild(int $mediathekId, array $namen): array
    {
        $pdo = get_pdo();

        // Normalisieren: trimmen, leere raus, case-insensitive deduplizieren
        $sauber = [];
        foreach ($namen as $n) {
            $n = mb_substr(trim((string)$n), 0, 80);
            if ($n === '') {
                continue;
            }
            $sauber[mb_strtolower($n)] = $n;
        }

        // Alte Zuordnungen entfernen, neue setzen
        $pdo->prepare('DELETE FROM mediathek_tag WHERE mediathek_id = :id')->execute([':id' => $mediathekId]);

        $gesetzt = [];
        $ins = $pdo->prepare('INSERT IGNORE INTO mediathek_tag (mediathek_id, tag_id) VALUES (:m, :t)');
        foreach ($sauber as $name) {
            $tagId = self::ensureTag($name);
            $ins->execute([':m' => $mediathekId, ':t' => $tagId]);
            $gesetzt[] = $name;
        }

        self::raeumeVerwaisteAuf();
        sort($gesetzt, SORT_NATURAL | SORT_FLAG_CASE);
        return $gesetzt;
    }

    /** Entfernt Tags, die keinem Bild mehr zugeordnet sind. */
    public static function raeumeVerwaisteAuf(): void
    {
        get_pdo()->exec(
            'DELETE t FROM mediathek_tags t
             LEFT JOIN mediathek_tag mt ON mt.tag_id = t.id
             WHERE mt.tag_id IS NULL'
        );
    }
}
