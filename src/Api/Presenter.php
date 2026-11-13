<?php

namespace ErnestDefoe\Panels\Api;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Settings;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * The JSON shapes. Lists prime what they need for a whole page in one query each (genres,
 * latest chapters, the member's progress and unread counts), so 1 series or 48 cost the same.
 */
class Presenter
{
    /** @var array<int, array{chapter_id:int, page:int, pages:int, number:string|null}> */
    private array $progress = [];

    /** @var array<int, int> unread live chapters per series, for the member */
    private array $unread = [];

    public function __construct(
        private readonly Access $access,
        private readonly Store $store,
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * Load, for a page of series, everything their cards show.
     *
     * @param Collection<int, Series> $series
     */
    public function prime(Collection $series, User $actor): void
    {
        $series->loadMissing(['genres', 'latestChapter']);
        $ids = $series->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->progress = [];
        $this->unread = [];
        if ($actor->isGuest() || ! $ids) {
            return;
        }

        $this->db->table('panel_progress')
            ->join('panel_chapters', 'panel_chapters.id', '=', 'panel_progress.chapter_id')
            ->where('panel_progress.user_id', $actor->id)->whereIn('panel_progress.series_id', $ids)
            ->get(['panel_progress.series_id', 'panel_progress.chapter_id', 'panel_progress.page', 'panel_progress.pages', 'panel_chapters.number'])
            ->each(function ($row) {
                $this->progress[(int) $row->series_id] = ['chapter_id' => (int) $row->chapter_id, 'page' => (int) $row->page, 'pages' => (int) $row->pages, 'number' => Chapter::formatNumber((string) $row->number)];
            });

        // Unread live chapters per series, for series the member has in their library.
        $this->db->table('panel_chapters')
            ->join('panel_library', function ($join) use ($actor) {
                $join->on('panel_library.series_id', '=', 'panel_chapters.series_id')->where('panel_library.user_id', '=', $actor->id);
            })
            ->leftJoin('panel_reads', function ($join) use ($actor) {
                $join->on('panel_reads.chapter_id', '=', 'panel_chapters.id')->where('panel_reads.user_id', '=', $actor->id);
            })
            ->whereIn('panel_chapters.series_id', $ids)
            ->where('panel_chapters.state', 'published')->whereNull('panel_chapters.removed_at')
            ->where('panel_chapters.published_at', '<=', now())
            ->whereNull('panel_reads.chapter_id')
            ->groupBy('panel_chapters.series_id')
            ->selectRaw($this->db->getQueryGrammar()->wrap('panel_chapters.series_id').' as series_id, COUNT(*) as n')
            ->get()
            ->each(function ($row) {
                $this->unread[(int) $row->series_id] = (int) $row->n;
            });
    }

    /** @return array<string, mixed> */
    public function card(Series $series, User $actor): array
    {
        $latest = $series->latestChapter;

        return [
            'id' => (int) $series->id,
            'slug' => $series->slug,
            'title' => $series->title,
            'format' => $series->format,
            'status' => $series->status,
            'contentRating' => $series->content_rating,
            'cover' => $this->cover($series, $actor),
            'coverColor' => $series->cover_color,
            'genres' => $series->genres->map(fn ($g) => ['id' => (int) $g->id, 'name' => $g->name, 'slug' => $g->slug])->all(),
            'latest' => $latest && $latest->isLive() ? ['id' => (int) $latest->id, 'number' => $latest->label(), 'publishedAt' => $latest->published_at?->toIso8601String()] : null,
            'latestAt' => $series->latest_chapter_at?->toIso8601String(),
            'chapterCount' => (int) $series->chapter_count,
            'followerCount' => (int) $series->follower_count,
            'rating' => $series->averageRating(),
            'ratingCount' => (int) $series->rating_count,
            'isHidden' => (bool) $series->is_hidden,
            'isFeatured' => (bool) $series->is_featured,
            'isRemoved' => $series->isRemoved(),
            'progress' => $this->progress[(int) $series->id] ?? null,
            'unread' => $this->unread[(int) $series->id] ?? 0,
        ];
    }

    /**
     * Cover URLs. A Mature cover goes to people who may read Mature; everyone else gets the
     * blurred stand-in (the real file's address is never sent to them).
     *
     * @return array{small:string, medium:string, large:string, blurred:bool}|null
     */
    public function cover(Series $series, User $actor): ?array
    {
        if (! $series->cover_key) {
            return null;
        }
        $blurred = in_array($series->content_rating, ['mature', 'explicit'], true)
            && ! in_array($series->content_rating, $this->access->readableRatings($actor), true);
        $at = fn (string $size) => $this->store->publicUrl(str_replace('-480.', '-'.$size.'.', $series->cover_key));

        return $blurred
            ? ['small' => $at('blur'), 'medium' => $at('blur'), 'large' => $at('blur'), 'blurred' => true]
            : ['small' => $at('240'), 'medium' => $at('480'), 'large' => $at('960'), 'blurred' => false];
    }

    /**
     * The series page: the card, its details, the member's place, and the chapters they may see.
     *
     * @param Collection<int, Chapter> $chapters
     * @return array<string, mixed>
     */
    public function series(Series $series, Collection $chapters, User $actor): array
    {
        $series->loadMissing(['user', 'editors']);
        $card = $this->card($series, $actor);
        $chapterIds = $chapters->pluck('id')->all();

        $read = $actor->isGuest() || ! $chapterIds ? [] : $this->db->table('panel_reads')
            ->where('user_id', $actor->id)->whereIn('chapter_id', $chapterIds)->pluck('chapter_id')->map(fn ($id) => (int) $id)->flip()->all();
        $replies = $this->db->table('discussions')->whereIn('id', $chapters->pluck('discussion_id')->filter()->all())
            ->whereNull('hidden_at')->pluck('comment_count', 'id')->all();
        $library = $actor->isGuest() ? null : $this->db->table('panel_library')->where('user_id', $actor->id)->where('series_id', $series->id)->first(['shelf', 'notify']);
        $mine = $actor->isGuest() ? null : $this->db->table('panel_ratings')->where('user_id', $actor->id)->where('series_id', $series->id)->value('rating');
        $canEdit = $this->access->canEdit($series, $actor);

        return $card + [
            'altTitles' => $series->altTitles(),
            'description' => $series->description,
            'readingMode' => $series->reading_mode,
            'fields' => $this->settings->presentFields($series->fields ?? []),
            'rawFields' => $canEdit ? ($series->fields ?? (object) []) : null,
            'tagId' => $series->tag_id,
            'isSold' => (bool) $series->is_sold,
            'owner' => $series->user ? $this->user($series->user) : null,
            'editors' => $series->editors->map(fn (User $u) => $this->user($u))->all(),
            'library' => $library ? ['shelf' => $library->shelf, 'notify' => (bool) $library->notify] : null,
            'myRating' => $mine !== null ? (int) $mine : null,
            'canEdit' => $canEdit,
            'canModerate' => $this->access->isStaff($actor),
            'canRate' => $actor->hasPermission('panels.rate'),
            'canDownload' => $this->access->downloadLimit($actor) > 0,
            'chapters' => $chapters->map(fn (Chapter $c) => $this->chapter($c, $series, $actor, isset($read[(int) $c->id]), (int) ($replies[$c->discussion_id] ?? 0)))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function chapter(Chapter $chapter, Series $series, User $actor, bool $read = false, int $comments = 0): array
    {
        $locked = $chapter->isPaywalled() && ! $this->access->holds($actor, $chapter) && ! $this->access->canEdit($series, $actor);

        return [
            'id' => (int) $chapter->id,
            'number' => $chapter->label(),
            'volume' => $chapter->volume,
            'title' => $chapter->title,
            'state' => $chapter->state,
            'scheduled' => $chapter->isScheduled(),
            'publishedAt' => $chapter->published_at?->toIso8601String(),
            'pageCount' => (int) $chapter->page_count,
            'source' => $chapter->source,
            'externalUrl' => $chapter->source === 'external' ? $chapter->external_url : null,
            'access' => $chapter->access,
            'earlyAccessUntil' => $chapter->early_access_until?->toIso8601String(),
            'locked' => $locked,
            'contentRating' => $chapter->content_rating,
            'discussionId' => $chapter->discussion_id,
            'replies' => max(0, $comments - 1),
            'read' => $read,
            'heldFor' => $this->access->isStaff($actor) ? $chapter->held_for : null,
        ];
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
        ];
    }
}
