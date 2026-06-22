<?php
/**
 * includes/FretGeraet.php
 *
 * Verwaltung der FRET-Geräte-Whitelist (Tabelle `fret_geraete`). FRET liefert
 * alle Computer mit aktiver API; hier wird festgelegt, welche davon im
 * fret-Modul-Editor auswählbar (freigegeben) sind, plus ein Anzeigename.
 */

declare(strict_types=1);

final class FretGeraet
{
    /** @return array<int,array> alle bekannten Geräte (freigegebene zuerst) */
    public static function listAll(): array
    {
        return get_pdo()->query(
            'SELECT * FROM fret_geraete
             ORDER BY freigegeben DESC, COALESCE(NULLIF(anzeige_name, ""), fret_name, uuid)'
        )->fetchAll();
    }

    /** @return array<int,array> nur freigegebene Geräte */
    public static function freigegebene(): array
    {
        return get_pdo()->query(
            'SELECT * FROM fret_geraete WHERE freigegeben = 1
             ORDER BY COALESCE(NULLIF(anzeige_name, ""), fret_name, uuid)'
        )->fetchAll();
    }

    /**
     * Holt die aktuelle Computerliste von FRET und legt neue Geräte an bzw.
     * frischt FRET-Name + gesehen_am vorhandener auf. Freigabe/Anzeigename
     * bleiben unangetastet.
     *
     * @return array{neu:int, gesamt:int}
     * @throws RuntimeException (von FretApi) bei API-/Konfig-Fehlern
     */
    public static function syncVonFret(): array
    {
        $computers = FretApi::listComputers();
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO fret_geraete (uuid, fret_name, gesehen_am)
             VALUES (:uuid, :name, NOW())
             ON DUPLICATE KEY UPDATE fret_name = VALUES(fret_name), gesehen_am = NOW()'
        );
        $neu = 0;
        foreach ($computers as $c) {
            if (($c['id'] ?? '') === '') { continue; }
            $stmt->execute([':uuid' => $c['id'], ':name' => $c['name']]);
            // rowCount(): 1 = INSERT (neu), 2 = UPDATE (vorhanden) bei MySQL/MariaDB
            if ($stmt->rowCount() === 1) { $neu++; }
        }
        return ['neu' => $neu, 'gesamt' => count($computers)];
    }

    /** @return array<string,string> uuid => Anzeigename (Fallback: FRET-Name/uuid) */
    public static function alleAlsMap(): array
    {
        $map = [];
        foreach (get_pdo()->query('SELECT uuid, fret_name, anzeige_name FROM fret_geraete')->fetchAll() as $g) {
            $name = ($g['anzeige_name'] !== null && $g['anzeige_name'] !== '')
                ? $g['anzeige_name']
                : (($g['fret_name'] !== null && $g['fret_name'] !== '') ? $g['fret_name'] : $g['uuid']);
            $map[$g['uuid']] = $name;
        }
        return $map;
    }

    /**
     * Speichert Anzeigename + Freigabe für mehrere Geräte aus dem Admin-Formular.
     *
     * @param array<int,array{anzeige_name?:string}> $werte  geraet-id => [...]
     * @param int[] $freigegebenIds  ids der angehakten (freigegebenen) Geräte
     */
    public static function speichereEinstellungen(array $werte, array $freigegebenIds): void
    {
        $pdo = get_pdo();
        $freigabe = array_fill_keys(array_map('intval', $freigegebenIds), true);
        $stmt = $pdo->prepare(
            'UPDATE fret_geraete SET anzeige_name = :name, freigegeben = :frei WHERE id = :id'
        );
        foreach ($werte as $id => $row) {
            $id = (int)$id;
            $name = trim((string)($row['anzeige_name'] ?? ''));
            $stmt->execute([
                ':name' => $name !== '' ? mb_substr($name, 0, 190) : null,
                ':frei' => isset($freigabe[$id]) ? 1 : 0,
                ':id'   => $id,
            ]);
        }
    }
}
