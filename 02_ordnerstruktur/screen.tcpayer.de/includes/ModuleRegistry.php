<?php
/**
 * includes/ModuleRegistry.php
 *
 * Liest modules/registry.php und die jeweiligen module.json-Dateien und
 * generiert daraus automatisch:
 *   - eine Liste aller verfügbaren Module mit Metadaten
 *   - das Backend-Einstellungsformular (HTML) für eine Modul-Instanz
 *   - die Auswertung eines abgeschickten Formulars (POST -> typisiertes Array)
 *
 * Das ist die zentrale Umsetzung von Abschnitt 4 der Projektdokumentation:
 * "Neue Moduleinstellung -> nur module.json anpassen".
 */

declare(strict_types=1);

final class ModuleRegistry
{
    private const MODULES_DIR = __DIR__ . '/../modules';

    /** @var array<string,array>|null Cache der geladenen module.json-Inhalte */
    private static ?array $cache = null;

    /**
     * @return string[] IDs aller registrierten Module, in Registry-Reihenfolge
     */
    public static function getRegisteredIds(): array
    {
        $ids = require self::MODULES_DIR . '/registry.php';
        if (!is_array($ids)) {
            throw new RuntimeException('modules/registry.php muss ein Array zurückgeben.');
        }
        return $ids;
    }

    /**
     * @return array<string,array> id => decodierte module.json
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
        $path = self::MODULES_DIR . '/' . $id . '/module.json';
        if (!is_file($path)) {
            throw new RuntimeException("module.json für Modul '$id' nicht gefunden ($path).");
        }
        $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $json['id'] = $json['id'] ?? $id;
        $json['settings'] = $json['settings'] ?? [];
        $json['has_proxy'] = (bool)($json['has_proxy'] ?? false);
        $json['has_inhalte'] = (bool)($json['has_inhalte'] ?? false);
        return $json;
    }

    public static function exists(string $id): bool
    {
        return in_array($id, self::getRegisteredIds(), true);
    }

    /**
     * Generiert das Einstellungsformular (nur die <div class="field">…-Teile,
     * kein eigenes <form>-Tag, damit die aufrufende Seite den Rahmen bestimmt).
     *
     * @param array<string,mixed> $values Aktuell gespeicherte Werte (überschreiben defaults)
     * @param array<string,array> $dynamicOptions Pro Feld-Key zur Laufzeit gelieferte
     *        Select-Optionen ([['value'=>..,'label'=>..], ...]); ersetzt die Optionen
     *        des Feldes und erzwingt Darstellung als Dropdown (z.B. FRET-Geräteliste).
     */
    public static function renderSettingsForm(string $id, array $values = [], array $dynamicOptions = []): string
    {
        $module = self::load($id);
        $html = '';
        foreach ($module['settings'] as $field) {
            $key = $field['key'];
            if (isset($dynamicOptions[$key])) {
                $field['type'] = 'select';
                $field['options'] = $dynamicOptions[$key];
            }
            $html .= self::renderField($field, $values[$key] ?? $field['default'] ?? null);
        }
        return $html;
    }

    private static function renderField(array $field, mixed $value): string
    {
        $key = htmlspecialchars($field['key']);
        $name = 'einstellungen[' . $key . ']';
        $label = htmlspecialchars($field['label'] ?? $field['key']);
        $type = $field['type'] ?? 'text';

        $out = '<div class="field field-' . htmlspecialchars($type) . '">';
        $out .= '<label for="f_' . $key . '">' . $label . '</label>';

        switch ($type) {
            case 'bool':
                $checked = $value ? ' checked' : '';
                $out .= '<input type="checkbox" id="f_' . $key . '" name="' . $name . '" value="1"' . $checked . '>';
                break;

            case 'number':
                $val = htmlspecialchars((string)($value ?? ''));
                $out .= '<input type="number" id="f_' . $key . '" name="' . $name . '" value="' . $val . '">';
                break;

            case 'select':
                $out .= '<select id="f_' . $key . '" name="' . $name . '">';
                foreach (($field['options'] ?? []) as $opt) {
                    $optVal = htmlspecialchars((string)$opt['value']);
                    $optLabel = htmlspecialchars((string)$opt['label']);
                    $sel = ((string)$value === (string)$opt['value']) ? ' selected' : '';
                    $out .= '<option value="' . $optVal . '"' . $sel . '>' . $optLabel . '</option>';
                }
                $out .= '</select>';
                break;

            case 'location_picker':
                // Platzhalter-Div; JS (in instanz.php) füllt Checkboxen ein.
                // Wert ist JSON-String "[1,3]" oder "" (= alle Standorte).
                $jsonVal = htmlspecialchars((string)($value ?? ''));
                $out .= '<div id="f_' . $key . '" class="adm-location-picker"><span class="adm-leer">Lade Standorte…</span></div>';
                $out .= '<input type="hidden" name="' . $name . '" id="f_' . $key . '_hidden" value="' . $jsonVal . '">';
                break;

            case 'room_picker':
                // Platzhalter-Select; JS (in instanz.php) füllt Optgruppen ein.
                // Wert ist int (0 = alle Säle, sonst room_id).
                $selVal = (int)($value ?? 0);
                $out .= '<select id="f_' . $key . '" name="' . $name . '" data-selected="' . $selVal . '">';
                $out .= '<option value="0">— alle Säle (lädt…) —</option>';
                $out .= '</select>';
                break;

            case 'textarea':
                $val = htmlspecialchars((string)($value ?? ''));
                $out .= '<textarea id="f_' . $key . '" name="' . $name . '">' . $val . '</textarea>';
                break;

            default: // text
                $val = htmlspecialchars((string)($value ?? ''));
                $out .= '<input type="text" id="f_' . $key . '" name="' . $name . '" value="' . $val . '">';
        }

        $out .= '</div>';
        return $out;
    }

    /**
     * Wertet $_POST['einstellungen'] anhand der module.json-Felddefinitionen
     * typisiert aus (bool wird zu echtem bool, number zu int/float, etc.).
     *
     * @param array<string,mixed> $postEinstellungen z.B. $_POST['einstellungen'] ?? []
     * @return array<string,mixed>
     */
    public static function collectSettings(string $id, array $postEinstellungen): array
    {
        $module = self::load($id);
        $result = [];
        foreach ($module['settings'] as $field) {
            $key = $field['key'];
            $type = $field['type'] ?? 'text';
            $raw = $postEinstellungen[$key] ?? null;

            switch ($type) {
                case 'bool':
                    $result[$key] = ($raw === '1' || $raw === true);
                    break;
                case 'number':
                    $result[$key] = $raw === null || $raw === '' ? ($field['default'] ?? 0) : (is_numeric($raw) ? (str_contains((string)$raw, '.') ? (float)$raw : (int)$raw) : ($field['default'] ?? 0));
                    break;
                case 'location_picker':
                    // Gespeichert als JSON-String "[1,3]" oder "" (= alle)
                    $result[$key] = is_string($raw) ? $raw : ($field['default'] ?? '');
                    break;
                case 'room_picker':
                    // Gespeichert als int: 0 = alle Säle, sonst room_id
                    $result[$key] = ($raw !== null && $raw !== '' && $raw !== '0') ? (int)$raw : 0;
                    break;
                default:
                    $result[$key] = $raw !== null ? (string)$raw : ($field['default'] ?? '');
            }
        }
        return $result;
    }
}
