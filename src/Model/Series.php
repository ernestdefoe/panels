<?php

namespace ErnestDefoe\Panels\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A comic, manga or webtoon: the cover, its details and its chapters.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $alt_titles
 * @property string|null $description
 * @property string|null $cover_key
 * @property string|null $cover_color
 * @property string $format
 * @property string $reading_mode
 * @property string $status
 * @property string $content_rating
 * @property array<string, mixed>|null $fields
 * @property int|null $user_id
 * @property int|null $tag_id
 * @property bool $is_hidden
 * @property bool $is_featured
 * @property bool $is_sold
 * @property int|null $latest_chapter_id
 * @property Carbon|null $latest_chapter_at
 * @property int $chapter_count
 * @property int $follower_count
 * @property int $rating_sum
 * @property int $rating_count
 * @property int $read_count
 * @property Carbon|null $removed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User|null $user
 */
class Series extends AbstractModel
{
    public const FORMATS = ['manga', 'manhwa', 'manhua', 'webtoon', 'comic', 'other'];
    public const MODES = ['vertical', 'ltr', 'rtl'];
    public const STATUSES = ['ongoing', 'completed', 'hiatus', 'cancelled'];
    public const RATINGS = ['all', 'teen', 'mature', 'explicit'];

    /** Words /read/{slug} can't take: they are pages of their own. */
    public const RESERVED = ['library', 'copyright', 'moderation', 'new', 'queue', 'img', 'genre', 'genres'];

    protected $table = 'panel_series';

    public $timestamps = true;

    protected $casts = [
        'fields' => 'array',
        'is_hidden' => 'boolean',
        'is_featured' => 'boolean',
        'is_sold' => 'boolean',
        'latest_chapter_at' => 'datetime',
        'removed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The reading mode a format starts with. */
    public static function modeFor(string $format): string
    {
        return match ($format) {
            'manga' => 'rtl',
            'webtoon', 'manhwa', 'manhua' => 'vertical',
            default => 'ltr',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'series_id');
    }

    /** @return BelongsToMany<Genre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'panel_series_genre', 'series_id', 'genre_id')->orderBy('position');
    }

    /** @return BelongsToMany<User, $this> */
    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'panel_series_editors', 'series_id', 'user_id');
    }

    /** @return BelongsTo<Chapter, $this> */
    public function latestChapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class, 'latest_chapter_id');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function averageRating(): ?float
    {
        return $this->rating_count > 0 ? round($this->rating_sum / $this->rating_count, 1) : null;
    }

    /** @return list<string> */
    public function altTitles(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->alt_titles))));
    }
}
