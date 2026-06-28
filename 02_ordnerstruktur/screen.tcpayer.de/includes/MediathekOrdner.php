<?php
/**
 * includes/MediathekOrdner.php
 *
 * Verwaltung der Mediathek-Ordner (eine Ebene, Tabelle `mediathek_ordner`).
 * Jedes Bild liegt in genau einem Ordner oder in keinem ("Ohne Ordner",
 * mediathek.ordner_id = NULL). Wird ein Ordner gelöscht, rutschen seine
 * Bilder per ON DELETE SET NULL automatisch nach "Ohne Ordner".
 */

declare(strict_types=1);

final class MediathekOrdner
{
    /** @return array<int,array> Ordner inkl. Bildanzahl, alphabetisch */
    public static function listAllMitAnzahl(): array
    {
        return get_pdo()->query(
            'SELECT o.id, o.name, COUNT(m.id) AS anzahl
             FROM mediathek_ordner o
             LEFT JOIN mediathek m ON m.ordner_id = o.id
             GROUP BY o.id, o.name
             ORDER BY o.name'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM mediathek_ordner WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Legt einen Ordner an. Existiert der Name schon, wird dessen id
     * zurückgegeben (idempotent bzgl. Name).
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function create(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Ordnername darf nicht leer sein.'];
        }
        $name = mb_substr($name, 0, 150);
        $pdo = get_pdo();

        $stmt = $pdo->prepare('SELECT id FROM mediathek_ordner WHERE name = :n');
        $stmt->execute([':n' => $name]);
        $vorhanden = $stmt->fetchColumn();
        if ($vorhanden) {
            return ['ok' => true, 'id' => (int)$vorhanden];
        }

        $pdo->prepare('INSERT INTO mediathek_ordner (name) VALUES (:n)')->execute([':n' => $name]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }

    /** @return array{ok:bool, error?:string} */
    public static function rename(int $id, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Ordnername darf nicht leer sein.'];
        }
        if (self::find($id) === null) {
            return ['ok' => false, 'error' => 'Ordner nicht gefunden.'];
        }
        $pdo = get_pdo();
        // Namenskollision mit einem ANDEREN Ordner verhindern
        $stmt = $pdo->prepare('SELECT id FROM mediathek_ordner WHERE name = :n AND id <> :id');
        $stmt->execute([':n' => $name, ':id' => $id]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'Es gibt bereits einen Ordner mit diesem Namen.'];
        }
        $pdo->prepare('UPDATE mediathek_ordner SET name = :n WHERE id = :id')
            ->execute([':n' => mb_substr($name, 0, 150), ':id' => $id]);
        return ['ok' => true];
    }

    /**
     * Löscht einen Ordner. Die enthaltenen Bilder bleiben erhalten und landen
     * in "Ohne Ordner" (FK ON DELETE SET NULL).
     *
     * @return array{ok:bool, error?:string}
     */
    public static function delete(int $id): array
    {
        if (self::find($id) === null) {
            return ['ok' => false, 'error' => 'Ordner nicht gefunden.'];
        }
        get_pdo()->prepare('DELETE FROM mediathek_ordner WHERE id = :id')->execute([':id' => $id]);
        return ['ok' => true];
    }
}
