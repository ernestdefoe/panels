<?php

namespace ErnestDefoe\Panels;

use Carbon\Carbon;
use ErnestDefoe\Panels\Event\ChapterPublished;
use ErnestDefoe\Panels\Event\ChapterRemoved;
use ErnestDefoe\Panels\Media\Pipeline;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Page;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Notification\NewChaptersBlueprint;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Started;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * A chapter's life: published (or held for a moderator), going live at its release time with
 * a real discussion, followers told once per release, taken down and restored.
 *
 * Time-based work (scheduled releases, early access ending, the batched notifications,
 * quarantine purges) runs from tick(): every minute on the scheduler, and, for forums without
 * one, at most once a minute when someone browses the library.
 */
class Publisher
{
    /** Followers hear about a release this long after its last chapter went live. */
    public const NOTIFY_QUIET_MINUTES = 5;

    /** A new chapter whose pages match this share of one removed chapter's waits for a moderator. */
    public const MATCH_SHARE = 0.8;

    public function __construct(
        private readonly Pages $pages,
        private readonly Access $access,
        private readonly Store $store,
        private readonly SettingsRepositoryInterface $settings,
        private readonly NotificationSyncer $notifications,
        private readonly Dispatcher $events,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
        private readonly TranslatorInterface $translator,
        private readonly Cache $cache,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Publish now or at $at. Members without panels.publishWithoutApproval wait in the queue;
     * a chapter that looks like a removed one waits for a moderator whoever posted it.
     */
    public function publish(Chapter $chapter, User $actor, ?Carbon $at = null): void
    {
        if ($chapter->source === 'upload' && $chapter->page_count < 1) {
            throw new Refused('ernestdefoe-panels.api.refused.no_pages');
        }
        if ($chapter->source === 'external' && ! $chapter->external_url) {
            throw new Refused('ernestdefoe-panels.api.refused.no_link');
        }
        if ($chapter->attested_at === null) {
            throw new Refused('ernestdefoe-panels.api.refused.attest');
        }

        $held = $this->looksLikeRemoved($chapter);
        $approval = (bool) ($this->settings->get('ernestdefoe-panels.approve_members') ?? true)
            && ! $actor->hasPermission('panels.publishWithoutApproval');

        $chapter->published_at = $at ?? Carbon::now();
        $chapter->held_for = $held;
        $chapter->state = $held ? 'held' : ($approval ? 'pending' : 'published');
        $chapter->save();

        $this->launch($chapter);
    }

    /** A moderator lets a pending or held chapter out. */
    public function approve(Chapter $chapter): void
    {
        $chapter->state = 'published';
        $chapter->held_for = null;
        if ($chapter->published_at === null) {
            $chapter->published_at = Carbon::now();
        }
        $chapter->save();
        $this->launch($chapter);
    }

    /**
     * Out now: its discussion, its files where everyone can fetch them, the series' counters,
     * and the event Renown and Spoiler Shield listen for. Safe to call again.
     */
    public function launch(Chapter $chapter): void
    {
        $series = $chapter->series;
        if (! $chapter->isLive()) {
            $this->pages->relocate($chapter, $series);

            return;
        }

        $first = $chapter->discussion_id === null;
        if ($first) {
            $this->startDiscussion($chapter, $series);
        }
        $this->pages->relocate($chapter, $series);
        $this->counters($series);

        if ($first) {
            $this->events->dispatch(new ChapterPublished($chapter));
        }
    }

    /** Taken down: files private (the public URL stops working), discussion hidden. */
    public function remove(Chapter $chapter, ?User $actor = null): void
    {
        $chapter->state = 'removed';
        $chapter->removed_at = Carbon::now();
        $chapter->save();
        $this->pages->relocate($chapter);

        if ($chapter->discussion_id && ($discussion = Discussion::query()->find($chapter->discussion_id)) && ! $discussion->hidden_at) {
            $discussion->hide($actor);
            $discussion->save();
        }
        $this->counters($chapter->series);
        $this->events->dispatch(new ChapterRemoved($chapter));
    }

    public function restore(Chapter $chapter, ?User $actor = null): void
    {
        $chapter->state = 'published';
        $chapter->removed_at = null;
        $chapter->save();

        if ($chapter->discussion_id && ($discussion = Discussion::query()->find($chapter->discussion_id)) && $discussion->hidden_at) {
            $discussion->restore();
            $discussion->save();
        }
        $this->launch($chapter);
        if ($chapter->isLive()) {
            $this->events->dispatch(new ChapterPublished($chapter));
        }
    }

    /** The series' cached numbers, recounted (never incremented, so they can't drift). */
    public function counters(Series $series): void
    {
        $live = Chapter::query()->where('series_id', $series->id)
            ->where('state', 'published')->whereNull('removed_at')
            ->where('published_at', '<=', Carbon::now());

        $latest = (clone $live)->orderByDesc('published_at')->orderByDesc('number')->first(['id', 'published_at']);
        $series->chapter_count = (clone $live)->count();
        $series->latest_chapter_id = $latest?->id;
        $series->latest_chapter_at = $latest?->published_at;
        $series->follower_count = $this->db->table('panel_library')->where('series_id', $series->id)->where('notify', true)->count();
        $ratings = $this->db->table('panel_ratings')->where('series_id', $series->id)->selectRaw('COUNT(*) as n, COALESCE(SUM(rating), 0) as total')->first();
        $series->rating_count = (int) ($ratings->n ?? 0);
        $series->rating_sum = (int) ($ratings->total ?? 0);
        $series->save();
    }

    /** At most once a minute, from a library or reader request, for forums without a scheduler. */
    public function maybeTick(): void
    {
        if ($this->cache->add('ernestdefoe-panels.tick', 1, 60)) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->log->error('[panels] tick failed: '.$e->getMessage());
            }
        }
    }

    public function tick(): void
    {
        // Scheduled chapters whose time came.
        Chapter::query()->where('state', 'published')->whereNull('discussion_id')->whereNull('removed_at')
            ->where('published_at', '<=', Carbon::now())->orderBy('published_at')->limit(50)->get()
            ->each(fn (Chapter $c) => $this->launch($c));

        // Early access ended: the files can become public.
        Chapter::query()->where('access', 'early')->where('storage', 'private')->where('state', 'published')
            ->where('early_access_until', '<=', Carbon::now())->limit(50)->get()
            ->each(fn (Chapter $c) => $this->pages->relocate($c));

        $this->notifyReleases();
        $this->purgeQuarantine();
        $this->pages->purgeStaleImports();
    }

    /** One notification per series per release, once its last chapter has been out for a few minutes. */
    private function notifyReleases(): void
    {
        $pending = Chapter::query()->where('state', 'published')->whereNull('notified_at')->whereNull('removed_at')
            ->whereNotNull('discussion_id')->where('published_at', '<=', Carbon::now())
            ->orderBy('published_at')->get()->groupBy('series_id');

        foreach ($pending as $seriesId => $chapters) {
            if ($chapters->max('published_at')->greaterThan(Carbon::now()->subMinutes(self::NOTIFY_QUIET_MINUTES))) {
                continue;
            }
            $series = Series::query()->find($seriesId);
            Chapter::query()->whereIn('id', $chapters->pluck('id'))->update(['notified_at' => Carbon::now()]);
            if (! $series || $series->isRemoved() || $series->is_hidden) {
                continue;
            }

            $first = $chapters->sortBy(fn (Chapter $c) => (float) $c->number)->first();
            $uploader = $first->user_id ? User::query()->find($first->user_id) : null;
            $followers = User::query()
                ->whereIn('id', $this->db->table('panel_library')->where('series_id', $seriesId)->where('notify', true)->select('user_id'))
                ->when($uploader, fn ($q) => $q->where('id', '!=', $uploader->id))
                ->get()
                ->filter(fn (User $user) => ! in_array($this->access->refusal($first, $series, $user), ['hidden', 'removed', 'unreleased', 'rating', 'age', 'signin'], true))
                ->values()->all();

            if ($followers) {
                $this->notifications->sync(new NewChaptersBlueprint($series, $uploader, $chapters->count(), (int) $first->id, $first->label()), $followers);
            }
        }
    }

    /** Taken-down files are kept privately for a counter-notice, then deleted. */
    private function purgeQuarantine(): void
    {
        $days = max(1, (int) ($this->settings->get('ernestdefoe-panels.quarantine_days') ?: 30));
        Chapter::query()->where('state', 'removed')->where('removed_at', '<', Carbon::now()->subDays($days))
            ->where('page_count', '>', 0)->limit(20)->get()
            ->each(function (Chapter $chapter) {
                $this->store->deleteChapter((int) $chapter->series_id, (int) $chapter->id);
                Page::query()->where('chapter_id', $chapter->id)->delete();
                $chapter->page_count = 0;
                $chapter->save();
            });
    }

    /**
     * The takedown id when most of this chapter's pages match one removed chapter's pages
     * (a re-upload of something a claim took down), else null.
     */
    public function looksLikeRemoved(Chapter $chapter): ?int
    {
        $mine = Page::query()->where('chapter_id', $chapter->id)->whereNotNull('phash')->pluck('phash')->map(fn ($h) => (int) $h)->all();
        if (count($mine) < 3) {
            return null;
        }

        $removed = $this->db->table('panel_takedowns')->where('target_type', 'chapter')->whereIn('state', ['disabled'])->pluck('id', 'target_id');
        $seriesClaims = $this->db->table('panel_takedowns')->where('target_type', 'series')->whereIn('state', ['disabled'])->pluck('id', 'target_id');
        $chapterIds = $removed->keys()->all();
        if ($seriesClaims->isNotEmpty()) {
            foreach (Chapter::query()->whereIn('series_id', $seriesClaims->keys())->pluck('series_id', 'id') as $id => $seriesId) {
                $chapterIds[] = $id;
                $removed[$id] = $seriesClaims[$seriesId];
            }
        }
        if (! $chapterIds) {
            return null;
        }

        $theirs = Page::query()->whereIn('chapter_id', $chapterIds)->where('chapter_id', '!=', $chapter->id)
            ->whereNotNull('phash')->get(['chapter_id', 'phash'])->groupBy('chapter_id');
        foreach ($theirs as $chapterId => $pages) {
            $hashes = $pages->pluck('phash')->map(fn ($h) => (int) $h)->all();
            $hits = 0;
            foreach ($mine as $hash) {
                foreach ($hashes as $other) {
                    if (Pipeline::distance($hash, $other) <= 6) {
                        $hits++;
                        break;
                    }
                }
            }
            if ($hits / count($mine) >= self::MATCH_SHARE) {
                return (int) $removed[$chapterId];
            }
        }

        return null;
    }

    private function startDiscussion(Chapter $chapter, Series $series): void
    {
        $author = User::query()->find($chapter->user_id ?? $series->user_id)
            ?? User::query()->whereHas('groups', fn ($q) => $q->where('id', Group::ADMINISTRATOR_ID))->orderBy('id')->first();
        if (! $author) {
            return;
        }

        $label = $this->translator->trans('ernestdefoe-panels.lib.chapter_number', ['{number}' => $chapter->label()]);
        $title = mb_substr($series->title.' · '.$label.($chapter->title ? ': '.$chapter->title : ''), 0, 200);
        $body = trim((string) $chapter->note) !== ''
            ? (string) $chapter->note
            : $this->translator->trans('ernestdefoe-panels.lib.discussion_body', ['{chapter}' => $label]);

        $discussion = Discussion::start($title, $author);
        $discussion->save();

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->type = CommentPost::$type;
        $post->created_at = Carbon::now();
        $post->setContentAttribute($body, $author);
        $post->save();

        $discussion->setFirstPost($post);
        $discussion->setLastPost($post);
        $discussion->refreshCommentCount();
        $discussion->refreshParticipantCount();
        $discussion->save();

        $chapter->discussion_id = (int) $discussion->id;
        $chapter->save();

        $this->tag($discussion, $series);

        $this->events->dispatch(new Started($discussion, $author));
        $this->events->dispatch(new Posted($post, $author));
    }

    private function tag(Discussion $discussion, Series $series): void
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return;
        }
        $ids = array_values(array_unique(array_filter([
            (int) $this->settings->get('ernestdefoe-panels.tag_id'),
            (int) $series->tag_id,
        ])));
        $ids = $this->db->table('tags')->whereIn('id', $ids)->pluck('id')->all();
        if (! $ids) {
            return;
        }
        $discussion->tags()->sync($ids);
        foreach (\Flarum\Tags\Tag::query()->whereIn('id', $ids)->get() as $tag) {
            $tag->discussion_count = $this->db->table('discussion_tag')->where('tag_id', $tag->id)->count();
            $tag->setLastPostedDiscussion($discussion);
            $tag->save();
        }
    }
}
