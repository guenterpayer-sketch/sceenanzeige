<?php
/**
 * includes/Monitor.php
 *
 * CRUD-Helfer für die Monitore (Tabelle `monitore`, früher „Säle") sowie deren
 * Zeitplan (`monitor_zeitplan`). Monitor-zentrisches Modell: nicht die Playlist
 * trägt Zeitregeln/Zuweisung, sondern jeder Monitor hat einen Zeitplan
 * „Playlist X läuft an diesen Wochentagen/Uhrzeiten (Priorität N)".
 *
 * Ein Monitor hat Name + Subdomain (z.B. "saal1"); die UNIQUE-Subdomain ordnet
 * das Monitor-Frontend zu. NC-Key/FRET liegen schulweit in config.php.
 */

declare(strict_types=1);

final class Monitor
{
    /**
     * Normalisiert eine vollständige Domain-Eingabe: Kleinbuchstaben, nur
     * a–z, 0–9, Bindestrich und Punkt. Muss mindestens einen Punkt enthalten.
     * Gibt einen leeren String zurück wenn die Eingabe ungültig ist.
     */
    public static function normDomain(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9.\-]/', '', $s);
        $s = trim($s, '.');
        if (strpos($s, '.') === false) { return ''; }
        return $s;
    }

    /** @deprecated Verwende normDomain(). */
    public static function normSubdomain(string $raw): string
    {
        return self::normDomain($raw);
    }

    /**
     * Alle Monitore inkl. Anzahl der Zeitplan-Einträge (Playlist + Ticker) für
     * die Übersicht.
     * @return array<int,array>
     */
    public static function listAll(): array
    {
        $sql = 'SELECT m.id, m.name, m.subdomain, m.erstellt_am,
                       (SELECT COUNT(*) FROM monitor_zeitplan z WHERE z.monitor_id = m.id) AS anzahl_zeitplan,
                       (SELECT COUNT(*) FROM ticker_zeitplan tz WHERE tz.monitor_id = m.id) AS anzahl_ticker
                FROM monitore m
                ORDER BY m.subdomain';
        return get_pdo()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM monitore WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $subdomain, string $headerText = ''): int
    {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO monitore (name, subdomain, header_text) VALUES (:name, :sub, :ht)'
        );
        $stmt->execute([
            ':name' => trim($name),
            ':sub'  => $subdomain,
            ':ht'   => trim($headerText) !== '' ? trim($headerText) : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(int $id, string $name, string $subdomain, string $headerText = ''): void
    {
        get_pdo()->prepare(
            'UPDATE monitore SET name = :name, subdomain = :sub, header_text = :ht WHERE id = :id'
        )->execute([
            ':name' => trim($name),
            ':sub'  => $subdomain,
            ':ht'   => trim($headerText) !== '' ? trim($headerText) : null,
            ':id'   => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        // ON DELETE CASCADE räumt monitor_zeitplan, einstellungen und
        // ticker_zeitplan dieses Monitors automatisch mit ab.
        get_pdo()->prepare('DELETE FROM monitore WHERE id = :id')->execute([':id' => $id]);
    }

    /** Setzt reload_at = NOW() für alle Monitore → Frontend lädt beim nächsten Poll neu. */
    public static function triggerReloadAlle(): void
    {
        get_pdo()->exec('UPDATE monitore SET reload_at = NOW()');
    }

    /** Prüft, ob die (normalisierte) Subdomain bereits vergeben ist. */
    public static function subdomainExistiert(string $subdomain, ?int $exceptId = null): bool
    {
        $pdo = get_pdo();
        $sql = 'SELECT COUNT(*) FROM monitore WHERE subdomain = :sub';
        $params = [':sub' => $subdomain];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * IDs aller Monitore, in deren Zeitplan die Playlist vorkommt
     * (für das Badge-Highlight „auf N Monitoren" → monitore.php).
     * @return int[]
     */
    public static function idsMitPlaylist(int $playlistId): array
    {
        $stmt = get_pdo()->prepare(
            'SELECT DISTINCT monitor_id FROM monitor_zeitplan WHERE playlist_id = :id'
        );
        $stmt->execute([':id' => $playlistId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * IDs aller Monitore, in deren Ticker-Zeitplan der Ticker vorkommt.
     * @return int[]
     */
    public static function idsMitTicker(int $tickerPlaylistId): array
    {
        $stmt = get_pdo()->prepare(
            'SELECT DISTINCT monitor_id FROM ticker_zeitplan WHERE ticker_playlist_id = :id'
        );
        $stmt->execute([':id' => $tickerPlaylistId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------
    // Zeitplan-Auswahl „was gilt JETZT" — EINZIGE Wahrheitsquelle.
    //
    // ⚠️ Diese Methoden werden vom Monitor-Proxy (proxies/monitor.php,
    // versorgt die echten Saal-Bildschirme!) UND vom Admin-Dashboard
    // benutzt. Es gibt bewusst KEINE zweite Kopie dieser Regeln — wer die
    // Semantik ändert, ändert sie für Monitore und Dashboard zugleich.
    // Regeln (CLAUDE.md Abschnitt 10/12, Schritt 29):
    //   - Zwei Ebenen: Kalender-Termine (monitor_termine, konkretes Datum)
    //     stechen den Regelbetrieb (monitor_zeitplan, Wochenmuster) aus —
    //     gibt es JETZT mindestens einen passenden Termin, wird der
    //     Regelbetrieb komplett ignoriert.
    //   - Wochentag ISO 1=Mo…7=So via FIND_IN_SET (Spalte wochentage="1,2,5")
    //   - Uhrzeit im Fenster ODER von/bis beide NULL (= Fallback/dauerhaft/
    //     ganztags), gilt auf beiden Ebenen gleich
    //   - Playlist: nur aktive; höchste Priorität gewinnt, bei Gleichstand
    //     bleiben alle (das Frontend rotiert durch sie)
    //   - Ticker: nur aktive; ALLE passenden, KEINE Priorität (Mischung)
    // ------------------------------------------------------------------

    /**
     * Liefert die JETZT gültigen Playlist-Einträge des Monitors — Kalender-
     * Termine vor Regelbetrieb, jeweils nur die mit der höchsten Priorität
     * (leer, wenn nichts passt).
     *
     * @return array<int,array{playlist_id:int|string,name:string,prioritaet:int|string,dauer_sek:int|string,von_uhrzeit:?string,bis_uhrzeit:?string}>
     */
    public static function aktuellePlaylistFuer(int $monitorId, ?DateTimeImmutable $zeit = null): array
    {
        $zeit ??= new DateTimeImmutable('now');

        // Ebene 1: Kalender-Termine (konkretes Datum) — stechen den Regelbetrieb aus
        $termine = self::termineJetzt($monitorId, $zeit);
        if (!empty($termine)) {
            return self::nurHoechstePrioritaet($termine);
        }

        // Ebene 2: Regelbetrieb (Wochenmuster)
        // Hinweis EMULATE_PREPARES=false: jeder benannte Platzhalter darf im
        // Statement nur einmal vorkommen (daher :zeit_von/:zeit_bis).
        $stmt = get_pdo()->prepare(
            'SELECT z.playlist_id, z.prioritaet, z.dauer_sek, z.von_uhrzeit, z.bis_uhrzeit,
                    p.name
             FROM monitor_zeitplan z
             JOIN playlists p ON p.id = z.playlist_id AND p.aktiv = 1
             WHERE z.monitor_id = :mid
               AND FIND_IN_SET(:tag, z.wochentage) > 0
               AND (
                     (z.von_uhrzeit IS NULL AND z.bis_uhrzeit IS NULL)
                  OR (:zeit_von >= z.von_uhrzeit AND :zeit_bis <= z.bis_uhrzeit)
               )
             ORDER BY z.prioritaet DESC'
        );
        $stmt->execute([
            ':mid'      => $monitorId,
            ':tag'      => $zeit->format('N'),
            ':zeit_von' => $zeit->format('H:i:s'),
            ':zeit_bis' => $zeit->format('H:i:s'),
        ]);
        return self::nurHoechstePrioritaet($stmt->fetchAll());
    }

    /**
     * JETZT passende Kalender-Termine des Monitors (Datum im Bereich,
     * Uhrzeit im Fenster oder ganztags, nur aktive Playlists). Gleiche
     * Zeilenform wie der Regelbetrieb (playlist_id, prioritaet, dauer_sek,
     * von_uhrzeit, bis_uhrzeit, name).
     */
    private static function termineJetzt(int $monitorId, DateTimeImmutable $zeit): array
    {
        try {
            $stmt = get_pdo()->prepare(
                'SELECT t.playlist_id, t.prioritaet, t.dauer_sek, t.von_uhrzeit, t.bis_uhrzeit,
                        p.name
                 FROM monitor_termine t
                 JOIN playlists p ON p.id = t.playlist_id AND p.aktiv = 1
                 WHERE t.monitor_id = :mid
                   AND :datum BETWEEN t.datum_von AND t.datum_bis
                   AND (
                         (t.von_uhrzeit IS NULL AND t.bis_uhrzeit IS NULL)
                      OR (:zeit_von >= t.von_uhrzeit AND :zeit_bis <= t.bis_uhrzeit)
                   )
                 ORDER BY t.prioritaet DESC'
            );
            $stmt->execute([
                ':mid'      => $monitorId,
                ':datum'    => $zeit->format('Y-m-d'),
                ':zeit_von' => $zeit->format('H:i:s'),
                ':zeit_bis' => $zeit->format('H:i:s'),
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Migration 14 noch nicht eingespielt (Tabelle fehlt, SQLSTATE
            // 42S02) → wie „keine Termine": Monitore laufen im Regelbetrieb
            // weiter, statt mit einem 500er auszufallen.
            if ($e->getCode() === '42S02') { return []; }
            throw $e;
        }
    }

    /**
     * Alle Kalender-Termine (aller Monitore), die einen Datumsbereich
     * überlappen — für die Kalender-Ansicht (wochenplan.php). Inklusive
     * pausierter Playlists (werden dort als „pausiert" markiert).
     * @return array<int,array>
     */
    public static function termineImZeitraum(string $vonDatum, string $bisDatum): array
    {
        try {
            $stmt = get_pdo()->prepare(
                'SELECT t.id, t.monitor_id, t.playlist_id, t.datum_von, t.datum_bis,
                        t.von_uhrzeit, t.bis_uhrzeit, t.prioritaet, t.dauer_sek,
                        p.name AS playlist_name, p.aktiv AS playlist_aktiv
                 FROM monitor_termine t
                 JOIN playlists p ON p.id = t.playlist_id
                 WHERE t.datum_von <= :bis AND t.datum_bis >= :von
                 ORDER BY t.datum_von, t.von_uhrzeit, t.id'
            );
            $stmt->execute([':von' => $vonDatum, ':bis' => $bisDatum]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // Migration 14 noch nicht eingespielt → keine Termine
            if ($e->getCode() === '42S02') { return []; }
            throw $e;
        }
    }

    /** Nur die Einträge mit der höchsten Priorität behalten (leer bleibt leer). */
    private static function nurHoechstePrioritaet(array $rows): array
    {
        $maxPrio = null;
        foreach ($rows as $row) {
            $p = (int)$row['prioritaet'];
            if ($maxPrio === null || $p > $maxPrio) { $maxPrio = $p; }
        }
        return ($maxPrio !== null)
            ? array_values(array_filter($rows, static fn($r) => (int)$r['prioritaet'] === $maxPrio))
            : [];
    }

    /**
     * Liefert die JETZT aktiven Ticker des Monitors (alle passenden, ohne
     * Priorität). DISTINCT: derselbe Ticker zählt nur einmal, auch wenn
     * mehrere Zeitplan-Zeilen gleichzeitig passen.
     *
     * @return array<int,array{ticker_playlist_id:int|string,name:string}>
     */
    public static function aktiveTickerFuer(int $monitorId, ?DateTimeImmutable $zeit = null): array
    {
        $zeit ??= new DateTimeImmutable('now');
        $stmt = get_pdo()->prepare(
            'SELECT DISTINCT z.ticker_playlist_id, tp.name
             FROM ticker_zeitplan z
             JOIN ticker_playlists tp ON tp.id = z.ticker_playlist_id AND tp.aktiv = 1
             WHERE z.monitor_id = :mid
               AND FIND_IN_SET(:tag, z.wochentage) > 0
               AND (
                     (z.von_uhrzeit IS NULL AND z.bis_uhrzeit IS NULL)
                  OR (:t_von >= z.von_uhrzeit AND :t_bis <= z.bis_uhrzeit)
               )
             ORDER BY tp.name'
        );
        $stmt->execute([
            ':mid'   => $monitorId,
            ':tag'   => $zeit->format('N'),
            ':t_von' => $zeit->format('H:i:s'),
            ':t_bis' => $zeit->format('H:i:s'),
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Nächste Uhrzeit HEUTE (Format "HH:MM"), zu der sich die
     * Playlist-Auswahl dieses Monitors ändern kann: kleinstes
     * von_uhrzeit/bis_uhrzeit aller heutigen Einträge (aktive Playlists,
     * Regelbetrieb UND Kalender-Termine), das noch in der Zukunft liegt.
     * NULL wenn keins.
     */
    public static function naechsterWechselFuer(int $monitorId, ?DateTimeImmutable $zeit = null): ?string
    {
        $zeit ??= new DateTimeImmutable('now');
        $stmt = get_pdo()->prepare(
            'SELECT MIN(x.t) FROM (
                SELECT z.von_uhrzeit AS t
                 FROM monitor_zeitplan z
                 JOIN playlists p ON p.id = z.playlist_id AND p.aktiv = 1
                 WHERE z.monitor_id = :mid1 AND FIND_IN_SET(:tag1, z.wochentage) > 0
                   AND z.von_uhrzeit IS NOT NULL
                UNION ALL
                SELECT z.bis_uhrzeit
                 FROM monitor_zeitplan z
                 JOIN playlists p ON p.id = z.playlist_id AND p.aktiv = 1
                 WHERE z.monitor_id = :mid2 AND FIND_IN_SET(:tag2, z.wochentage) > 0
                   AND z.bis_uhrzeit IS NOT NULL
             ) x
             WHERE x.t > :uhr'
        );
        $stmt->execute([
            ':mid1' => $monitorId,
            ':mid2' => $monitorId,
            ':tag1' => $zeit->format('N'),
            ':tag2' => $zeit->format('N'),
            ':uhr'  => $zeit->format('H:i:s'),
        ]);
        $t = $stmt->fetchColumn();
        $kandidat = ($t !== false && $t !== null) ? (string)$t : null;

        // Kalender-Termine, die heute gelten: deren Fenstergrenzen zählen mit.
        try {
            $stmt = get_pdo()->prepare(
                'SELECT MIN(x.t) FROM (
                    SELECT t1.von_uhrzeit AS t
                     FROM monitor_termine t1
                     JOIN playlists p ON p.id = t1.playlist_id AND p.aktiv = 1
                     WHERE t1.monitor_id = :mid1 AND :dat1 BETWEEN t1.datum_von AND t1.datum_bis
                       AND t1.von_uhrzeit IS NOT NULL
                    UNION ALL
                    SELECT t2.bis_uhrzeit
                     FROM monitor_termine t2
                     JOIN playlists p2 ON p2.id = t2.playlist_id AND p2.aktiv = 1
                     WHERE t2.monitor_id = :mid2 AND :dat2 BETWEEN t2.datum_von AND t2.datum_bis
                       AND t2.bis_uhrzeit IS NOT NULL
                 ) x
                 WHERE x.t > :uhr'
            );
            $stmt->execute([
                ':mid1' => $monitorId,
                ':mid2' => $monitorId,
                ':dat1' => $zeit->format('Y-m-d'),
                ':dat2' => $zeit->format('Y-m-d'),
                ':uhr'  => $zeit->format('H:i:s'),
            ]);
            $tt = $stmt->fetchColumn();
            if ($tt !== false && $tt !== null) {
                $kandidat = ($kandidat === null || (string)$tt < $kandidat) ? (string)$tt : $kandidat;
            }
        } catch (PDOException $e) {
            // Migration 14 noch nicht eingespielt → nur Regelbetrieb-Grenzen
            if ($e->getCode() !== '42S02') { throw $e; }
        }

        return $kandidat !== null ? substr($kandidat, 0, 5) : null;
    }

    // ------------------------------------------------------------------
    // Zeitplan (monitor_zeitplan)
    // ------------------------------------------------------------------

    /**
     * Zeitplan-Einträge eines Monitors inkl. Playlist-Name/-Aktiv. Einträge mit
     * Uhrzeit-Fenster zuerst (nach Priorität), zeitlose („dauerhaft") danach.
     * @return array<int,array>
     */
    public static function ladeZeitplan(int $monitorId): array
    {
        $stmt = get_pdo()->prepare(
            'SELECT z.id, z.playlist_id, z.wochentage, z.von_uhrzeit, z.bis_uhrzeit, z.prioritaet, z.dauer_sek,
                    p.name AS playlist_name, p.aktiv AS playlist_aktiv
             FROM monitor_zeitplan z
             JOIN playlists p ON p.id = z.playlist_id
             WHERE z.monitor_id = :id
             ORDER BY (z.von_uhrzeit IS NULL), z.prioritaet DESC, z.von_uhrzeit, z.id'
        );
        $stmt->execute([':id' => $monitorId]);
        return $stmt->fetchAll();
    }

    /**
     * Ersetzt den kompletten Zeitplan eines Monitors durch die übergebene Liste.
     * von/bis sind optional: leer = NULL (Eintrag läuft dauerhaft an den
     * gewählten Wochentagen, gilt als Fallback ggü. Einträgen mit Uhrzeit).
     *
     * @param array<int,array{playlist_id:int,wochentage:string,von?:string,bis?:string,prioritaet?:int}> $eintraege
     */
    public static function ersetzeZeitplan(int $monitorId, array $eintraege): void
    {
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM monitor_zeitplan WHERE monitor_id = :id')
                ->execute([':id' => $monitorId]);

            $stmt = $pdo->prepare(
                'INSERT INTO monitor_zeitplan
                    (monitor_id, playlist_id, wochentage, von_uhrzeit, bis_uhrzeit, prioritaet, dauer_sek)
                 VALUES (:mid, :pid, :tage, :von, :bis, :prio, :dauer)'
            );
            foreach ($eintraege as $e) {
                $pid  = (int)($e['playlist_id'] ?? 0);
                $tage = trim((string)($e['wochentage'] ?? ''));
                $von  = trim((string)($e['von'] ?? ''));
                $bis  = trim((string)($e['bis'] ?? ''));
                if ($pid <= 0 || $tage === '') {
                    continue;
                }
                $stmt->execute([
                    ':mid'   => $monitorId,
                    ':pid'   => $pid,
                    ':tage'  => $tage,
                    ':von'   => $von !== '' ? $von : null,
                    ':bis'   => $bis !== '' ? $bis : null,
                    ':prio'  => (int)($e['prioritaet'] ?? 0),
                    ':dauer' => max(10, (int)($e['dauer_sek'] ?? 300)),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Ticker-Zeitplan (ticker_zeitplan) — monitor-zentrisch, OHNE Priorität.
    // Mehrere gleichzeitig passende Ticker werden am Monitor gemischt
    // (siehe CLAUDE.md Abschnitt 7), daher kein Prioritätsfeld.
    // ------------------------------------------------------------------

    /**
     * Ticker-Zeitplan-Einträge eines Monitors inkl. Ticker-Name/-Aktiv.
     * Einträge mit Uhrzeit-Fenster zuerst, zeitlose („dauerhaft") danach.
     * @return array<int,array>
     */
    public static function ladeTickerZeitplan(int $monitorId): array
    {
        $stmt = get_pdo()->prepare(
            'SELECT z.id, z.ticker_playlist_id, z.wochentage, z.von_uhrzeit, z.bis_uhrzeit,
                    t.name AS ticker_name, t.aktiv AS ticker_aktiv
             FROM ticker_zeitplan z
             JOIN ticker_playlists t ON t.id = z.ticker_playlist_id
             WHERE z.monitor_id = :id
             ORDER BY (z.von_uhrzeit IS NULL), z.von_uhrzeit, z.id'
        );
        $stmt->execute([':id' => $monitorId]);
        return $stmt->fetchAll();
    }

    /**
     * Ersetzt den kompletten Ticker-Zeitplan eines Monitors durch die
     * übergebene Liste. von/bis sind optional: leer = NULL (Ticker läuft
     * dauerhaft an den gewählten Wochentagen).
     *
     * @param array<int,array{ticker_id:int,wochentage:string,von?:string,bis?:string}> $eintraege
     */
    public static function ersetzeTickerZeitplan(int $monitorId, array $eintraege): void
    {
        $pdo = get_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM ticker_zeitplan WHERE monitor_id = :id')
                ->execute([':id' => $monitorId]);

            $stmt = $pdo->prepare(
                'INSERT INTO ticker_zeitplan
                    (monitor_id, ticker_playlist_id, wochentage, von_uhrzeit, bis_uhrzeit)
                 VALUES (:mid, :tid, :tage, :von, :bis)'
            );
            foreach ($eintraege as $e) {
                $tid  = (int)($e['ticker_id'] ?? 0);
                $tage = trim((string)($e['wochentage'] ?? ''));
                $von  = trim((string)($e['von'] ?? ''));
                $bis  = trim((string)($e['bis'] ?? ''));
                if ($tid <= 0 || $tage === '') {
                    continue;
                }
                $stmt->execute([
                    ':mid'  => $monitorId,
                    ':tid'  => $tid,
                    ':tage' => $tage,
                    ':von'  => $von !== '' ? $von : null,
                    ':bis'  => $bis !== '' ? $bis : null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
