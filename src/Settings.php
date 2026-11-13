<?php

namespace ErnestDefoe\Panels;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The admin's custom series fields: a JSON list of {id, name, type} in one setting. Ids never
 * change, so renaming a field keeps every series' value and removing one only hides it.
 */
class Settings
{
    public const TYPES = ['text', 'link', 'year', 'person', 'select'];

    public const DEFAULT_FIELDS = '[{"id":1,"name":"Author","type":"person"},{"id":2,"name":"Artist","type":"person"},{"id":3,"name":"Country of origin","type":"text"},{"id":4,"name":"Year","type":"year"},{"id":5,"name":"Publisher","type":"text"},{"id":6,"name":"Official site","type":"link"}]';

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    /** @return list<array{id:int, name:string, type:string, options?: list<string>}> */
    public function fields(): array
    {
        $raw = json_decode((string) ($this->settings->get('ernestdefoe-panels.fields') ?: self::DEFAULT_FIELDS), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $field) {
            if (! is_array($field) || ! isset($field['id'], $field['name']) || trim((string) $field['name']) === '') {
                continue;
            }
            $type = in_array($field['type'] ?? 'text', self::TYPES, true) ? (string) $field['type'] : 'text';
            $item = ['id' => (int) $field['id'], 'name' => mb_substr(trim((string) $field['name']), 0, 60), 'type' => $type];
            if ($type === 'select') {
                $item['options'] = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), (array) ($field['options'] ?? []))));
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * A series' values, cleaned against the field definitions.
     *
     * @param array<string|int, mixed> $input
     * @return array<int|string, string>
     */
    public function cleanFields(array $input): array
    {
        $out = [];
        foreach ($this->fields() as $field) {
            $value = trim((string) ($input[(string) $field['id']] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = match ($field['type']) {
                'year' => preg_match('~^\d{4}$~', $value) ? $value : '',
                'link' => preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? mb_substr($value, 0, 300) : '',
                'select' => in_array($value, $field['options'] ?? [], true) ? $value : '',
                default => mb_substr($value, 0, 120),
            };
            if ($value !== '') {
                $out[(string) $field['id']] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string|int, mixed> $values
     * @return list<array{id:int, name:string, type:string, value:string}>
     */
    public function presentFields(array $values): array
    {
        $out = [];
        foreach ($this->fields() as $field) {
            $value = (string) ($values[(string) $field['id']] ?? '');
            if ($value !== '') {
                $out[] = ['id' => $field['id'], 'name' => $field['name'], 'type' => $field['type'], 'value' => $value];
            }
        }

        return $out;
    }
}
