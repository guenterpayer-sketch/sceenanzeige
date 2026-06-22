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
 * gegen Playlists usw.) folgt in Schritt 5 — siehe auch
 * "Notiz_Schritt5_Mediathek.md" für das geplante Mediathek-Konzept
 * (zentrale Bilder-Tabelle mit Hash-Duplikat-Erkennung, Drag&Drop-Upload).
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
        // Hinweis: sobald Playlists existieren (Schritt 6), vor dem Löschen
        // prüfen, ob die Instanz noch in playlist_spalten_inhalte verwendet
        // wird, und ggf. warnen.
        $pdo = get_pdo();
        $pdo->prepare('DELETE FROM modul_instanzen WHERE id = :id')->execute([':id' => $id]);
    }

    /** Pausiert/aktiviert die GESAMTE Instanz ohne sie zu löschen. */
    public static function setAktiv(int $id, bool $aktiv): void
    {
        get_pdo()->prepare('UPDATE modul_instanzen SET aktiv = :a WHERE id = :id')
            ->execute([':a' => $aktiv ? 1 : 0, ':id' => $id]);
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

    public static function addInhalt(int $modulInstanzId, ?string $dateiname, ?string $textInhalt, int $reihenfolge, int $dauerSek, ?string $gueltigBis = null): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO modul_instanz_inhalte (modul_instanz_id, dateiname, text_inhalt, gueltig_bis, reihenfolge, dauer_sek)
             VALUES (:mid, :dateiname, :text, :gueltig, :reihenfolge, :dauer)'
        );
        $stmt->execute([
            ':mid' => $modulInstanzId,
            ':dateiname' => $dateiname,
            ':text' => $textInhalt,
            ':gueltig' => $gueltigBis,
            ':reihenfolge' => $reihenfolge,
            ':dauer' => $dauerSek,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Inhalte einer Instanz. Der Dateiname wird abwärtskompatibel aufgelöst:
     * bevorzugt aus der Mediathek (mediathek_id), sonst der alte direkte
     * dateiname-Wert. Damit funktioniert das bestehende bild/frontend.js
     * (liest i.dateiname) unverändert weiter.
     */
    public static function listInhalte(int $modulInstanzId): array
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'SELECT i.id, i.modul_instanz_id, i.mediathek_id,
                    COALESCE(m.dateiname, i.dateiname) AS dateiname,
                    i.text_inhalt, i.gueltig_bis, i.reihenfolge, i.dauer_sek, i.aktiv
             FROM modul_instanz_inhalte i
             LEFT JOIN mediathek m ON m.id = i.mediathek_id
             WHERE i.modul_instanz_id = :mid
             ORDER BY i.reihenfolge, i.id'
        );
        $stmt->execute([':mid' => $modulInstanzId]);
        return $stmt->fetchAll();
    }

    /**
     * Ersetzt alle Unter-Inhalte einer Instanz durch die übergebene Liste
     * (für den Bibliotheks-Editor: die komplette Eintragsliste wird neu
     * geschrieben). Die Reihenfolge ergibt sich aus der Array-Reihenfolge.
     *
     * @param array<int,array{mediathek_id?:?int,dateiname?:?string,text?:?string,dauer_sek?:int,gueltig_bis?:?string,aktiv?:bool}> $inhalte
     */
    public static function ersetzeInhalte(int $instanzId, array $inhalte): void
    {
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM modul_instanz_inhalte WHERE modul_instanz_id = :id')
                ->execute([':id' => $instanzId]);

            $stmt = $pdo->prepare(
                'INSERT INTO modul_instanz_inhalte
                    (modul_instanz_id, mediathek_id, dateiname, text_inhalt, gueltig_bis, reihenfolge, dauer_sek, aktiv)
                 VALUES (:mid, :media, :datei, :text, :gueltig, :reihenfolge, :dauer, :aktiv)'
            );
            $r = 0;
            foreach ($inhalte as $in) {
                $stmt->execute([
                    ':mid'        => $instanzId,
                    ':media'      => !empty($in['mediathek_id']) ? (int)$in['mediathek_id'] : null,
                    ':datei'      => $in['dateiname'] ?? null,
                    ':text'       => (isset($in['text']) && $in['text'] !== '') ? $in['text'] : null,
                    ':gueltig'    => !empty($in['gueltig_bis']) ? $in['gueltig_bis'] : null,
                    ':reihenfolge'=> $r++,
                    ':dauer'      => (int)($in['dauer_sek'] ?? 10),
                    ':aktiv'      => !empty($in['aktiv']) ? 1 : 0,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function deleteInhalt(int $inhaltId): void
    {
        $pdo = get_pdo();
        $pdo->prepare('DELETE FROM modul_instanz_inhalte WHERE id = :id')->execute([':id' => $inhaltId]);
    }
}
