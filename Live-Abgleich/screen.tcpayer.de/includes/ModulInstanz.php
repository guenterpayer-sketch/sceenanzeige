<?php
/**
 * includes/ModulInstanz.php
 *
 * Minimale CRUD-Helfer für modul_instanzen / modul_instanz_inhalte
 * (Tabellen aus 01_schema.sql, Abschnitt 8 der Doku).
 *
 * WICHTIG: Dies ist absichtlich schlank gehalten (nur was für Schritt 3
 * zum Testen der Referenz-Module nötig ist). Die vollständige
 * Bibliotheks-Verwaltung (Liste, Suche, Löschen mit Abhängigkeitsprüfung
 * gegen Playlists usw.) folgt in Schritt 5.
 */

declare(strict_types=1);

final class ModulInstanz
{
    public static function create(string $modulTyp, string $name, array $einstellungen): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO modul_instanzen (modul_typ, name, einstellungen) VALUES (:typ, :name, :einstellungen)'
        );
        $stmt->execute([
            ':typ' => $modulTyp,
            ':name' => $name,
            ':einstellungen' => json_encode($einstellungen, JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, string $name, array $einstellungen): void
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'UPDATE modul_instanzen SET name = :name, einstellungen = :einstellungen WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':einstellungen' => json_encode($einstellungen, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        // ON DELETE CASCADE räumt modul_instanz_inhalte automatisch mit ab.
        // Hinweis für Schritt 5: vor dem Löschen prüfen, ob die Instanz noch
        // in playlist_spalten_inhalte verwendet wird, und ggf. warnen.
        $pdo = get_pdo();
        $pdo->prepare('DELETE FROM modul_instanzen WHERE id = :id')->execute([':id' => $id]);
    }

    public static function find(int $id): ?array
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT * FROM modul_instanzen WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['einstellungen'] = json_decode($row['einstellungen'] ?? '{}', true) ?: [];
        return $row;
    }

    /**
     * @return array<int,array> Alle Instanzen, optional gefiltert nach Modul-Typ
     */
    public static function listAll(?string $modulTyp = null): array
    {
        $pdo = get_pdo();
        if ($modulTyp !== null) {
            $stmt = $pdo->prepare('SELECT * FROM modul_instanzen WHERE modul_typ = :typ ORDER BY name');
            $stmt->execute([':typ' => $modulTyp]);
        } else {
            $stmt = $pdo->query('SELECT * FROM modul_instanzen ORDER BY modul_typ, name');
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['einstellungen'] = json_decode($row['einstellungen'] ?? '{}', true) ?: [];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Unter-Inhalte (z.B. einzelne Bilder einer "bild"-Instanz)
    // ------------------------------------------------------------------

    public static function addInhalt(int $modulInstanzId, ?string $dateiname, ?string $textInhalt, int $reihenfolge, int $dauerSek, ?string $ablaufdatum = null): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO modul_instanz_inhalte (modul_instanz_id, dateiname, text_inhalt, ablaufdatum, reihenfolge, dauer_sek)
             VALUES (:mid, :dateiname, :text, :ablauf, :reihenfolge, :dauer)'
        );
        $stmt->execute([
            ':mid' => $modulInstanzId,
            ':dateiname' => $dateiname,
            ':text' => $textInhalt,
            ':ablauf' => $ablaufdatum,
            ':reihenfolge' => $reihenfolge,
            ':dauer' => $dauerSek,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function listInhalte(int $modulInstanzId): array
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM modul_instanz_inhalte WHERE modul_instanz_id = :mid ORDER BY reihenfolge, id'
        );
        $stmt->execute([':mid' => $modulInstanzId]);
        return $stmt->fetchAll();
    }

    public static function deleteInhalt(int $inhaltId): void
    {
        $pdo = get_pdo();
        $pdo->prepare('DELETE FROM modul_instanz_inhalte WHERE id = :id')->execute([':id' => $inhaltId]);
    }
}
