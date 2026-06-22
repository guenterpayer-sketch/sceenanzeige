<?php
/**
 * includes/Mediathek.php
 *
 * Zentrale Bild-Verwaltung (Abschnitt 5 der Doku, "Mediathek statt
 * Pro-Instanz-Upload"). Verwaltet die Tabelle `mediathek`:
 *   - Upload mit SHA-256-Duplikat-Erkennung (gleicher Inhalt -> vorhandenen
 *     Eintrag wiederverwenden, kein doppelter Speicherplatz)
 *   - Galerie-Liste, Einzelabruf
 *   - Löschen nur, wenn das Bild von keiner Modul-Instanz mehr verwendet wird
 *
 * Die eigentliche Bilddatei liegt in uploads/ (UPLOADS_DIR), ausgeliefert
 * über UPLOADS_URL. Verweise aus modul_instanz_inhalte.mediathek_id zeigen
 * auf mediathek.id.
 */

declare(strict_types=1);

final class Mediathek
{
    /** Erlaubte Bildtypen: IMAGETYPE-Konstante => Dateiendung */
    private const ERLAUBTE_TYPEN = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Verarbeitet einen einzelnen $_FILES-Eintrag.
     *
     * @param array $file z.B. $_FILES['datei'] (name, tmp_name, error, size)
     * @return array{ok:bool, error?:string, eintrag?:array, duplikat?:bool}
     */
    public static function speichereUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload fehlgeschlagen (Fehlercode ' . (int)($file['error'] ?? -1) . ').'];
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Keine gültige Upload-Datei.'];
        }

        // Bildtyp serverseitig prüfen (verlässt sich NICHT auf die Dateiendung)
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::ERLAUBTE_TYPEN[$info[2]])) {
            return ['ok' => false, 'error' => 'Nicht unterstützter Bildtyp (erlaubt: JPG, PNG, GIF, WEBP).'];
        }
        $ext    = self::ERLAUBTE_TYPEN[$info[2]];
        $breite = (int)$info[0];
        $hoehe  = (int)$info[1];

        $hash = hash_file('sha256', $tmp);

        $pdo = get_pdo();

        // Duplikat? -> vorhandenen Eintrag wiederverwenden, Datei nicht erneut speichern
        $stmt = $pdo->prepare('SELECT * FROM mediathek WHERE datei_hash = :h');
        $stmt->execute([':h' => $hash]);
        $vorhanden = $stmt->fetch();
        if ($vorhanden) {
            return ['ok' => true, 'eintrag' => $vorhanden, 'duplikat' => true];
        }

        // Neue Datei eindeutig benennen und speichern
        $dateiname = 'media_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $ziel = rtrim(UPLOADS_DIR, '/') . '/' . $dateiname;
        if (!move_uploaded_file($tmp, $ziel)) {
            return ['ok' => false, 'error' => 'Datei konnte nicht in uploads/ gespeichert werden.'];
        }

        $originalName = mb_substr((string)($file['name'] ?? ''), 0, 255);

        $stmt = $pdo->prepare(
            'INSERT INTO mediathek (dateiname, original_name, datei_hash, breite, hoehe)
             VALUES (:dn, :on, :h, :b, :hh)'
        );
        $stmt->execute([
            ':dn' => $dateiname,
            ':on' => $originalName !== '' ? $originalName : null,
            ':h'  => $hash,
            ':b'  => $breite,
            ':hh' => $hoehe,
        ]);

        $eintrag = self::find((int)$pdo->lastInsertId());
        return ['ok' => true, 'eintrag' => $eintrag, 'duplikat' => false];
    }

    /** @return array<int,array> Alle Bilder, neueste zuerst */
    public static function listAll(): array
    {
        return get_pdo()->query('SELECT * FROM mediathek ORDER BY hochgeladen_am DESC, id DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM mediathek WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Wie oft wird dieses Bild aktuell von Modul-Instanzen verwendet? */
    public static function anzahlVerwendungen(int $id): int
    {
        $stmt = get_pdo()->prepare('SELECT COUNT(*) FROM modul_instanz_inhalte WHERE mediathek_id = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Löscht ein Bild (DB-Eintrag + Datei), aber nur wenn es nicht mehr
     * verwendet wird.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function delete(int $id): array
    {
        $eintrag = self::find($id);
        if ($eintrag === null) {
            return ['ok' => false, 'error' => 'Bild nicht gefunden.'];
        }
        $verwendungen = self::anzahlVerwendungen($id);
        if ($verwendungen > 0) {
            return ['ok' => false, 'error' => "Bild wird noch von $verwendungen Modul-Eintrag/Einträgen verwendet und kann nicht gelöscht werden."];
        }

        get_pdo()->prepare('DELETE FROM mediathek WHERE id = :id')->execute([':id' => $id]);

        $pfad = rtrim(UPLOADS_DIR, '/') . '/' . $eintrag['dateiname'];
        if (is_file($pfad)) {
            @unlink($pfad);
        }
        return ['ok' => true];
    }
}
