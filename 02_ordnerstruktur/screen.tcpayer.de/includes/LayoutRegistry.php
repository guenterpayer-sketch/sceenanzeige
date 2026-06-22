<?php
/**
 * includes/LayoutRegistry.php
 *
 * Liest layouts/registry.php und die jeweiligen layout.json-Dateien und stellt
 * die verfügbaren Playlist-Layouts bereit (Spaltenanzahl, Default-Breiten,
 * Label). Analog zu includes/ModuleRegistry.php — siehe Abschnitt 4 und 6 der
 * Projektdokumentation ("Neues Layout -> Ordner in /layouts/ + Eintrag in
 * registry.php").
 *
 * Die zugehörige template.html (CSS-Grid) wird erst vom Monitor-Frontend
 * (Schritt 9) gerendert; hier nur per templateHtml() bereitgestellt.
 */

declare(strict_types=1);

final class LayoutRegistry
{
    private const LAYOUTS_DIR = __DIR__ . '/../layouts';

    /** @var array<string,array>|null Cache der geladenen layout.json-Inhalte */
    private static ?array $cache = null;

    /**
     * @return string[] IDs aller registrierten Layouts, in Registry-Reihenfolge
     */
    public static function getRegisteredIds(): array
    {
        $ids = require self::LAYOUTS_DIR . '/registry.php';
        if (!is_array($ids)) {
            throw new RuntimeException('layouts/registry.php muss ein Array zurückgeben.');
        }
        return $ids;
    }

    /**
     * @return array<string,array> id => decodierte layout.json (+ normalisierte Felder)
     */
    public static function getAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $result = [];
        foreach (self::getRegisteredIds() as $id) {
            $result[$id] = self::load($id);
        }
        return self::$cache = $result;
    }

    public static function load(string $id): array
    {
        $path = self::LAYOUTS_DIR . '/' . $id . '/layout.json';
        if (!is_file($path)) {
            throw new RuntimeException("layout.json für Layout '$id' nicht gefunden ($path).");
        }
        $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $json['id']      = $json['id'] ?? $id;
        $json['spalten'] = max(1, min(3, (int)($json['spalten'] ?? 1)));
        $json['label']   = $json['label'] ?? $id;
        // Default-Breiten auf Spaltenanzahl normalisieren (Summe ~100).
        $breiten = $json['default_breiten'] ?? [];
        if (count($breiten) !== $json['spalten']) {
            $breiten = self::gleichBreiten($json['spalten']);
        }
        $json['default_breiten'] = array_map('intval', $breiten);
        $json['breiten_frei']    = (bool)($json['breiten_frei'] ?? false);
        return $json;
    }

    public static function exists(string $id): bool
    {
        return in_array($id, self::getRegisteredIds(), true);
    }

    /**
     * Rohes template.html eines Layouts (CSS-Grid-Gerüst) oder null.
     * Wird erst in Schritt 9 (Monitor-Rendering) ausgewertet.
     */
    public static function templateHtml(string $id): ?string
    {
        $path = self::LAYOUTS_DIR . '/' . $id . '/template.html';
        return is_file($path) ? (string)file_get_contents($path) : null;
    }

    /**
     * Gleichmäßige Breiten für n Spalten, Summe exakt 100 (Rest auf Spalte 1).
     * @return int[]
     */
    public static function gleichBreiten(int $spalten): array
    {
        $spalten = max(1, min(3, $spalten));
        $basis = intdiv(100, $spalten);
        $breiten = array_fill(0, $spalten, $basis);
        $breiten[0] += 100 - $basis * $spalten;
        return $breiten;
    }
}
