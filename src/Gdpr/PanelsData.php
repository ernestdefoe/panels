<?php

namespace ErnestDefoe\Panels\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's library, places, read marks, ratings and downloads in their export, erased with
 * them. Series they uploaded stay (like their posts), owner cleared by the foreign key.
 */
class PanelsData extends Type
{
    private const TABLES = ['panel_library', 'panel_progress', 'panel_reads', 'panel_ratings', 'panel_downloads'];

    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-panels.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-panels.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $db = resolve(ConnectionInterface::class);
        $out = [];
        foreach (self::TABLES as $table) {
            $out[] = ["panels/$table.json" => $this->encodeForExport($db->table($table)->where('user_id', $this->user->id)->get()->map(fn ($r) => (array) $r)->all())];
        }

        return $out;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $db = resolve(ConnectionInterface::class);
        foreach (self::TABLES as $table) {
            $db->table($table)->where('user_id', $this->user->id)->delete();
        }
    }
}
