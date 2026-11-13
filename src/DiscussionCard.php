<?php

namespace ErnestDefoe\Panels;

use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Model\Chapter;
use Flarum\Discussion\Discussion;
use Flarum\User\User;

/**
 * The chapter card shown above a chapter discussion's first post: which series and chapter,
 * its cover, whether the member has read it, and the chapters either side. Only on the
 * discussion page (one query there), never on lists.
 */
class DiscussionCard
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function for(Discussion $discussion, User $actor): ?array
    {
        $chapter = Chapter::query()->with('series')->where('discussion_id', $discussion->id)->first();
        if (! $chapter || ! $chapter->series || ! $this->access->canSeeSeries($chapter->series, $actor)) {
            return null;
        }
        $series = $chapter->series;

        $listed = Chapter::query()->where('series_id', $series->id);
        $this->access->scopeChapters($listed, $series, $actor);
        $next = (clone $listed)->where('number', '>', $chapter->number)->orderBy('number')->first(['id', 'number', 'published_at', 'state']);
        $prev = (clone $listed)->where('number', '<', $chapter->number)->orderByDesc('number')->first(['id', 'number']);
        $read = ! $actor->isGuest() && resolve(\Illuminate\Database\ConnectionInterface::class)->table('panel_reads')
            ->where('user_id', $actor->id)->where('chapter_id', $chapter->id)->exists();

        return [
            'series' => ['id' => (int) $series->id, 'slug' => $series->slug, 'title' => $series->title, 'format' => $series->format, 'readingMode' => $series->reading_mode, 'cover' => $this->presenter->cover($series, $actor)],
            'chapter' => $this->presenter->chapter($chapter, $series, $actor, $read),
            'prev' => $prev ? ['number' => $prev->label()] : null,
            'next' => $next ? ['number' => $next->label()] : null,
            'refusal' => $this->access->refusal($chapter, $series, $actor),
        ];
    }
}
