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
     * Normalisiert eine Subdomain-Eingabe: Kleinbuchstaben, nur a–z 0–9 und
     * Bindestrich; eine evtl. mitgetippte Domain (".tcpayer.de") fällt weg.
     */
    public static function normSubdomain(string $raw): string
    {
        $s = strtolower(trim($raw));
        if (($pos = strpos($s, '.')) !== false) {
            $s = substr($s, 0, $pos);
        }
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        return trim((string)$s, '-');
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
