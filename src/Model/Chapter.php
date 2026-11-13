<?php

namespace ErnestDefoe\Panels\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $series_id
 * @property string $number
 * @property int|null $volume
 * @property string|null $title
 * @property string $state
 * @property Carbon|null $published_at
 * @property string|null $content_rating
 * @property string $source
 * @property string|null $external_url
 * @property string $access
 * @property Carbon|null $early_access_until
 * @property string $storage
 * @property int $page_count
 * @property int|null $discussion_id
 * @property int|null $user_id
 * @property string|null $note
 * @property string|null $transcript
 * @property Carbon|null $attested_at
 * @property Carbon|null $notified_at
 * @property int|null $held_for
 * @property Carbon|null $removed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Series $series
 * @property Discussion|null $discussion
 * @property User|null $user
 */
class Chapter extends AbstractModel
{
    public const STATES = ['draft', 'pending', 'held', 'published', 'removed'];
    public const ACCESS = ['free', 'paid', 'early'];

    protected $table = 'panel_chapters';

    public $timestamps = true;

    protected $casts = [
        'published_at' => 'datetime',
        'early_access_until' => 'datetime',
        'attested_at' => 'datetime',
        'notified_at' => 'datetime',
        'removed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Series, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_id');
    }

    /** @return HasMany<Page, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'chapter_id')->orderBy('position')->orderBy('segment');
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Out: published, and its release time has come. */
    public function isLive(): bool
    {
        return $this->state === 'published' && $this->published_at !== null && $this->published_at->lessThanOrEqualTo(Carbon::now());
    }

    public function isScheduled(): bool
    {
        return $this->state === 'published' && $this->published_at !== null && $this->published_at->isFuture();
    }

    /** "12", "12.5": the number without trailing zeros. */
    public function label(): string
    {
        return self::formatNumber((string) $this->number);
    }

    public static function formatNumber(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** The rating that applies: the series' or this chapter's, whichever is higher. */
    public function effectiveRating(Series $series): string
    {
        $order = array_flip(Series::RATINGS);
        $own = $this->content_rating;

        return $own !== null && ($order[$own] ?? 0) > ($order[$series->content_rating] ?? 0) ? $own : $series->content_rating;
    }

    /** Paid now: paid, or early access that hasn't ended. */
    public function isPaywalled(): bool
    {
        return $this->access === 'paid'
            || ($this->access === 'early' && $this->early_access_until !== null && $this->early_access_until->isFuture());
    }
}
