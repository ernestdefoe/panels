<?php

namespace ErnestDefoe\Panels\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * An archive being turned into pages, a few at a time.
 *
 * @property int $id
 * @property int $user_id
 * @property int $chapter_id
 * @property string $temp_key
 * @property list<array{0:int, 1:string}> $entries
 * @property int $total
 * @property int $done
 * @property int $position_base
 * @property array{skipped?: list<string>, refused?: list<array{name:string, reason:string}>, read?: int} $report
 * @property string $state
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Import extends AbstractModel
{
    protected $table = 'panel_imports';

    public $timestamps = true;

    protected $casts = [
        'entries' => 'array',
        'report' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
