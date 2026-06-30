<?php
/**
 * includes/Videothek.php
 *
 * Verwaltung eigener Videodateien (Tabelle `video_dateien`), analog zur
 * Mediathek für Bilder, aber bewusst eine eigene Klasse/Tabelle, weil
 * Mediathek.php fest auf Bild-Semantik (getimagesize, breite/hoehe)
 * zugeschnitten ist.
 *
 *   - Upload mit SHA-256-Duplikat-Erkennung (gleicher Inhalt -> vorhandenen
 *     Eintrag wiederverwenden, kein doppelter Speicherplatz)
 *   - Liste, Einzelabruf
 *   - Löschen nur, wenn das Video von keiner Modul-Instanz mehr verwendet wird
 *
 * Die eigentliche Videodatei liegt in uploads/ (UPLOADS_DIR), ausgeliefert
 * über UPLOADS_URL. Verweise aus modul_instanz_inhalte.video_datei_id zeigen
 * auf video_dateien.id.
 */

declare(strict_types=1);

final class Videothek
{
    /** Erlaubte Video-MIME-Typen => Dateiendung */
    private const ERLAUBTE_TYPEN = [
        'video/mp4'  => 'mp4',
        'video/webm' => 'webm',
    ];

    /**
     * Verarbeitet einen einzelnen $_FILES-Eintrag.
     *
     * @param array    $file     z.B. $_FILES['datei'] (name, tmp_name, error, size)
     * @param int|null $dauerSek Im Browser ermittelte Laufzeit (Schätzwert), optional
     * @return array{ok:bool, error?:string, eintrag?:array, duplikat?:bool}
     */
    public static function speichereUpload(array $file, ?int $dauerSek = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload fehlgeschlagen (Fehlercode ' . (int)($file['error'] ?? -1) . ').'];
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Keine gültige Upload-Datei.'];
        }

        // Videotyp serverseitig prüfen (verlässt sich NICHT auf die Dateiendung)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($tmp);
        if (!isset(self::ERLAUBTE_TYPEN[$mime])) {
            return ['ok' => false, 'error' => 'Nicht unterstützter Videotyp (erlaubt: MP4, WebM).'];
        }
        $ext = self::ERLAUBTE_TYPEN[$mime];

        $hash = hash_file('sha256', $tmp);

        $pdo = get_pdo();

        // Duplikat? -> vorhandenen Eintrag wiederverwenden, Datei nicht erneut speichern
        $stmt = $pdo->prepare('SELECT * FROM video_dateien WHERE datei_hash = :h');
        $stmt->execute([':h' => $hash]);
        $vorhanden = $stmt->fetch();
        if ($vorhanden) {
            return ['ok' => true, 'eintrag' => $vorhanden, 'duplikat' => true];
        }

        // Neue Datei eindeutig benennen und speichern
        $dateiname = 'video_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $ziel = rtrim(UPLOADS_DIR, '/') . '/' . $dateiname;
        if (!move_uploaded_file($tmp, $ziel)) {
            return ['ok' => false, 'error' => 'Datei konnte nicht in uploads/ gespeichert werden.'];
        }

        $originalName = mb_substr((string)($file['name'] ?? ''), 0, 255);

        $stmt = $pdo->prepare(
            'INSERT INTO video_dateien (dateiname, original_name, datei_hash, dauer_sek)
             VALUES (:dn, :on, :h, :d)'
        );
        $stmt->execute([
            ':dn' => $dateiname,
            ':on' => $originalName !== '' ? $originalName : null,
            ':h'  => $hash,
            ':d'  => $dauerSek !== null && $dauerSek > 0 ? $dauerSek : null,
        ]);

        $eintrag = self::find((int)$pdo->lastInsertId());
        return ['ok' => true, 'eintrag' => $eintrag, 'duplikat' => false];
    }

    /** Videos (neueste zuerst). */
    public static function listAll(): array
    {
        $stmt = get_pdo()->query('SELECT * FROM video_dateien ORDER BY hochgeladen_am DESC, id DESC');
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = get_pdo()->prepare('SELECT * FROM video_dateien WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Wie oft wird dieses Video aktuell von Modul-Instanzen verwendet? */
    public static function anzahlVerwendungen(int $id): int
    {
        $stmt = get_pdo()->prepare('SELECT COUNT(*) FROM modul_instanz_inhalte WHERE video_datei_id = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    /** Aktualisiert Anzeigename und optional Laufzeit eines Videos. */
    public static function update(int $id, string $originalName, ?int $dauerSek): void
    {
        $pdo = get_pdo();
        $pdo->prepare('UPDATE video_dateien SET original_name = :on, dauer_sek = :d WHERE id = :id')
            ->execute([
                ':on' => mb_substr(trim($originalName), 0, 255),
                ':d'  => ($dauerSek !== null && $dauerSek > 0) ? $dauerSek : null,
                ':id' => $id,
            ]);
    }

    /**
     * Löscht ein Video (DB-Eintrag + Datei), aber nur wenn es nicht mehr
     * verwendet wird.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function delete(int $id): array
    {
        $eintrag = self::find($id);
        if ($eintrag === null) {
            return ['ok' => false, 'error' => 'Video nicht gefunden.'];
        }
        $verwendungen = self::anzahlVerwendungen($id);
        if ($verwendungen > 0) {
            return ['ok' => false, 'error' => "Video wird noch von $verwendungen Modul-Eintrag/Einträgen verwendet und kann nicht gelöscht werden."];
        }

        get_pdo()->prepare('DELETE FROM video_dateien WHERE id = :id')->execute([':id' => $id]);

        $pfad = rtrim(UPLOADS_DIR, '/') . '/' . $eintrag['dateiname'];
        if (is_file($pfad)) {
            @unlink($pfad);
        }
        return ['ok' => true];
    }
}
