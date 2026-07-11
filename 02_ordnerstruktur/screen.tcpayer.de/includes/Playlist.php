<?php
/**
 * includes/Playlist.php
 *
 * CRUD-Helfer für die Playlist-Hauptfläche (Schritt 6, Playlist-Editor):
 *   - playlists                (Name, aktiv)
 *   - playlist_layout          (1:1, Spaltenanzahl + Breiten + Header/Footer)
 *   - playlist_spalten_inhalte (Modul-Instanzen je Spalte, Reihenfolge)
 *
 * Zeitregeln (playlist_zeitregeln) und Saal-Zuweisung (playlist_saele) gehören
 * zu Schritt 7 und werden hier bewusst NICHT angefasst.
 *
 * Hinweis MariaDB / EMULATE_PREPARES=false: kein benannter Platzhalter mehrfach
 * im selben Statement (deshalb UPSERT über ON DUPLICATE KEY UPDATE + VALUES()).
 *
 * Das Schema speichert keine Layout-ID als String, sondern nur spalten_anzahl +
 * Breiten. Die Zuordnung zu einem Layout aus der LayoutRegistry wird daher aus
 * diesen Werten abgeleitet (layoutIdAus()).
 */

declare(strict_types=1);

final class Playlist
{
    // ------------------------------------------------------------------
    // Playlist-Stammsatz
    // ------------------------------------------------------------------

    /** Legt eine Playlist samt Default-Layout (1-spaltig) an, liefert die neue ID. */
    public static function create(string $name): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('INSERT INTO playlists (name, aktiv) VALUES (:name, 1)');
        $stmt->execute([':name' => trim($name)]);
        $id = (int)$pdo->lastInsertId();
        // Invariante: jede Playlist hat genau eine playlist_layout-Zeile.
        self::speichereLayout($id, 1, [100], true, true);
        return $id;
    }

    public static function update(int $id, string $name): void
    {
        get_pdo()->prepare('UPDATE playlists SET name = :name WHERE id = :id')
            ->execute([':name' => trim($name), ':id' => $id]);
    }

    /** Pausiert/aktiviert die GESAMTE Playlist ohne sie zu löschen. */
    public static function setAktiv(int $id, bool $aktiv): void
    {
        get_pdo()->prepare('UPDATE playlists SET aktiv = :a WHERE id = :id')
            ->execute([':a' => $aktiv ? 1 : 0, ':id' => $id]);
    }

    public static function delete(int $id): void
    {
        // ON DELETE CASCADE räumt playlist_layout, playlist_spalten_inhalte,
        // playlist_zeitregeln und playlist_saele automatisch mit ab.
        get_pdo()->prepare('DELETE FROM playlists WHERE id = :id')->execute([':id' => $id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM playlists WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Prüft, ob bereits eine Playlist diesen Namen trägt (case-insensitiv).
     * $exceptId schließt die eigene Playlist beim Bearbeiten aus.
     */
    public static function nameExistiert(string $name, ?int $exceptId = null): bool
    {
        $pdo = get_pdo();
        $sql = 'SELECT COUNT(*) FROM playlists WHERE LOWER(name) = LOWER(:name)';
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
     * Alle Playlists inkl. Layout-Kurzinfo und Modul-Anzahl (für die Übersicht).
     * @return array<int,array>
     */
    public static function listAll(): array
    {
        $sql = 'SELECT p.id, p.name, p.aktiv, p.erstellt_am,
                       l.spalten_anzahl, l.spalte1_breite, l.spalte2_breite, l.spalte3_breite,
                       l.header_sichtbar, l.footer_ticker,
                       (SELECT COUNT(*) FROM playlist_spalten_inhalte s WHERE s.playlist_id = p.id) AS anzahl_module,
                       (SELECT COUNT(DISTINCT z.monitor_id) FROM monitor_zeitplan z WHERE z.playlist_id = p.id) AS anzahl_monitore,
                       (SELECT GROUP_CONCAT(m.name ORDER BY m.name SEPARATOR ', ')
                        FROM monitor_zeitplan z2 JOIN monitore m ON m.id = z2.monitor_id
                        WHERE z2.playlist_id = p.id) AS monitor_namen
                FROM playlists p
                LEFT JOIN playlist_layout l ON l.playlist_id = p.id
                ORDER BY p.name';
        return get_pdo()->query($sql)->fetchAll();
    }

    // ------------------------------------------------------------------
    // Layout (playlist_layout, 1:1)
    // ------------------------------------------------------------------

    public static function ladeLayout(int $playlistId): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM playlist_layout WHERE playlist_id = :id');
        $stmt->execute([':id' => $playlistId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Speichert/aktualisiert die Layout-Zeile (UPSERT über die UNIQUE-Spalte
     * playlist_id). $breiten ist ein Array mit 1–3 Prozentwerten; fehlende
     * Spalten werden auf NULL gesetzt.
     *
     * @param int[] $breiten
     */
    public static function speichereLayout(
        int $playlistId,
        int $spaltenAnzahl,
        array $breiten,
        bool $headerSichtbar,
        bool $footerTicker
    ): void {
        $spaltenAnzahl = max(1, min(3, $spaltenAnzahl));
        $b1 = isset($breiten[0]) ? (int)$breiten[0] : null;
        $b2 = ($spaltenAnzahl >= 2 && isset($breiten[1])) ? (int)$breiten[1] : null;
        $b3 = ($spaltenAnzahl >= 3 && isset($breiten[2])) ? (int)$breiten[2] : null;

        $sql = 'INSERT INTO playlist_layout
                    (playlist_id, spalten_anzahl, spalte1_breite, spalte2_breite, spalte3_breite, header_sichtbar, footer_ticker)
                VALUES (:pid, :sa, :b1, :b2, :b3, :hs, :ft)
                ON DUPLICATE KEY UPDATE
                    spalten_anzahl  = VALUES(spalten_anzahl),
                    spalte1_breite  = VALUES(spalte1_breite),
                    spalte2_breite  = VALUES(spalte2_breite),
                    spalte3_breite  = VALUES(spalte3_breite),
                    header_sichtbar = VALUES(header_sichtbar),
                    footer_ticker   = VALUES(footer_ticker)';
        get_pdo()->prepare($sql)->execute([
            ':pid' => $playlistId,
            ':sa'  => $spaltenAnzahl,
            ':b1'  => $b1,
            ':b2'  => $b2,
            ':b3'  => $b3,
            ':hs'  => $headerSichtbar ? 1 : 0,
            ':ft'  => $footerTicker ? 1 : 0,
        ]);
    }

    /**
     * Leitet aus einer Layout-Zeile (spalten_anzahl + Breiten) die passende
     * Layout-ID aus der LayoutRegistry ab: bevorzugt exakte Breiten-
     * Übereinstimmung, sonst das erste Layout mit gleicher Spaltenanzahl.
     * Liefert null, wenn keins passt.
     */
    public static function layoutIdAus(?array $layoutRow): ?string
    {
        if (!$layoutRow) {
            return null;
        }
        $spalten = (int)($layoutRow['spalten_anzahl'] ?? 1);
        $breiten = array_values(array_filter([
            $layoutRow['spalte1_breite'] ?? null,
            $layoutRow['spalte2_breite'] ?? null,
            $layoutRow['spalte3_breite'] ?? null,
        ], static fn($v) => $v !== null));
        $breiten = array_map('intval', $breiten);

        $fallback = null;
        foreach (LayoutRegistry::getAll() as $id => $meta) {
            if ((int)$meta['spalten'] !== $spalten) {
                continue;
            }
            if ($fallback === null) {
                $fallback = $id;
            }
            if ($meta['default_breiten'] === $breiten) {
                return $id; // exakte Übereinstimmung
            }
        }
        return $fallback;
    }

    // ------------------------------------------------------------------
    // Spalten-Inhalte (playlist_spalten_inhalte)
    // ------------------------------------------------------------------

    /**
     * Alle Spalten-Inhalte einer Playlist inkl. Instanz-Name/-Typ/-Aktiv,
     * sortiert nach Spalte und Reihenfolge.
     * @return array<int,array>
     */
    public static function listSpaltenInhalte(int $playlistId): array
    {
        $sql = 'SELECT s.id, s.spalte, s.reihenfolge, s.modul_instanz_id, s.layout_override,
                       m.name AS instanz_name, m.modul_typ, m.aktiv AS instanz_aktiv
                FROM playlist_spalten_inhalte s
                JOIN modul_instanzen m ON m.id = s.modul_instanz_id
                WHERE s.playlist_id = :id
                ORDER BY s.spalte, s.reihenfolge, s.id';
        $stmt = get_pdo()->prepare($sql);
        $stmt->execute([':id' => $playlistId]);
        return $stmt->fetchAll();
    }

    /**
     * Ersetzt alle Spalten-Inhalte einer Playlist durch die übergebene Liste.
     * Die Reihenfolge je Spalte ergibt sich aus der Array-Reihenfolge.
     *
     * @param array<int,array{spalte:int,modul_instanz_id:int,layout_override?:?string}> $inhalte
     */
    public static function ersetzeSpaltenInhalte(int $playlistId, array $inhalte): void
    {
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM playlist_spalten_inhalte WHERE playlist_id = :id')
                ->execute([':id' => $playlistId]);

            $stmt = $pdo->prepare(
                'INSERT INTO playlist_spalten_inhalte
                    (playlist_id, spalte, reihenfolge, modul_instanz_id, layout_override)
                 VALUES (:pid, :spalte, :reihenfolge, :mid, :override)'
            );
            $reihenProSpalte = [1 => 0, 2 => 0, 3 => 0];
            foreach ($inhalte as $in) {
                $spalte = max(1, min(3, (int)($in['spalte'] ?? 1)));
                $mid    = (int)($in['modul_instanz_id'] ?? 0);
                if ($mid <= 0) {
                    continue;
                }
                $stmt->execute([
                    ':pid'         => $playlistId,
                    ':spalte'      => $spalte,
                    ':reihenfolge' => $reihenProSpalte[$spalte]++,
                    ':mid'         => $mid,
                    ':override'    => !empty($in['layout_override']) ? (string)$in['layout_override'] : null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Hinweis: Zeitplanung + Monitor-Zuordnung sind monitor-zentrisch und
    // liegen jetzt in Monitor::ladeZeitplan/ersetzeZeitplan (Tabelle
    // monitor_zeitplan), nicht mehr an der Playlist.
}
