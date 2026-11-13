<?php

namespace ErnestDefoe\Panels\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image of a chapter, or one segment of a tall strip.
 *
 * @property int $id
 * @property int $chapter_id
 * @property int $position
 * @property int $segment
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string|null $color
 * @property int|null $phash
 * @property array<int|string, string> $variants
 * @property string|null $alt
 * @property string|null $original_name
 * @property Chapter $chapter
 */
class Page extends AbstractModel
{
    protected $table = 'panel_pages';

    public $timestamps = false;

    protected $casts = [
        'variants' => 'array',
        'phash' => 'integer',
    ];

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
