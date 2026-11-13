<?php

namespace ErnestDefoe\Panels\Scope;

use ErnestDefoe\Panels\Access;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A chapter's discussion is visible to whoever may open the chapter: not before its release,
 * not after a takedown, not past the member's ratings. A paid chapter's discussion stays
 * visible (its readers talk; others see the teaser and the offer).
 *
 * One NOT EXISTS on discussion lists; moderators and Panels staff skip it.
 */
class ChapterDiscussions
{
    public function __construct(private readonly Access $access)
    {
    }

    /** @param Builder<\Flarum\Discussion\Discussion> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if ($this->access->isStaff($actor)) {
            return;
        }
        $ratings = $this->access->readableRatings($actor);
        $mine = $this->access->editedSeriesIds($actor);

        $query->whereNotExists(function ($q) use ($actor, $ratings, $mine) {
            $q->selectRaw('1')->from('panel_chapters')
                ->join('panel_series', 'panel_series.id', '=', 'panel_chapters.series_id')
                ->whereColumn('panel_chapters.discussion_id', 'discussions.id')
                ->when(! $actor->isGuest(), fn ($w) => $w->where(fn ($o) => $o->whereNull('panel_series.user_id')->orWhere('panel_series.user_id', '!=', $actor->id)))
                ->when($mine, fn ($w) => $w->whereNotIn('panel_series.id', $mine))
                ->where(function ($w) use ($ratings) {
                    $w->where('panel_chapters.state', '!=', 'published')
                        ->orWhereNotNull('panel_chapters.removed_at')
                        ->orWhereNotNull('panel_series.removed_at')
                        ->orWhere('panel_series.is_hidden', true)
                        ->orWhere('panel_chapters.published_at', '>', now())
                        ->orWhereNotIn('panel_series.content_rating', $ratings)
                        ->orWhere(fn ($r) => $r->whereNotNull('panel_chapters.content_rating')->whereNotIn('panel_chapters.content_rating', $ratings));
                });
        });
    }
}
