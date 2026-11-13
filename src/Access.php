<?php

namespace ErnestDefoe\Panels;

use Carbon\Carbon;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Series;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who may see, read and change what. Every list, page, image token and
 * discussion goes through here, so there is one set of rules.
 *
 * Ratings: All ages and Teen are open. Mature is listed to everyone (cover
 * blurred) but read only by members with panels.viewMature who said they're
 * 18+. Explicit doesn't exist for anyone else: not listed, counted or searched,
 * and only when the admin turned it on.
 */
class Access
{
    /**
     * Per member object, so a request asks once however many places build an Access, and a
     * long-running worker never keeps another request's answer.
     *
     * @var \WeakMap<User, array<string, list<int>>>|null
     */
    private static ?\WeakMap $memo = null;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function isStaff(User $actor): bool
    {
        return $actor->hasPermission('panels.moderate');
    }

    public function explicitEnabled(): bool
    {
        return (bool) $this->settings->get('ernestdefoe-panels.explicit');
    }

    public function isAdult(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->getAttribute('panels_adult_at') !== null;
    }

    public function canReadMature(User $actor): bool
    {
        return $actor->hasPermission('panels.viewMature') && $this->isAdult($actor) && $this->oldEnough($actor);
    }

    public function canSeeExplicit(User $actor): bool
    {
        return $this->explicitEnabled() && $actor->hasPermission('panels.viewExplicit') && $this->isAdult($actor) && $this->oldEnough($actor);
    }

    /** The admin's minimum account age and post count for Mature and Explicit. */
    private function oldEnough(User $actor): bool
    {
        $days = (int) $this->settings->get('ernestdefoe-panels.mature_min_days');
        $posts = (int) $this->settings->get('ernestdefoe-panels.mature_min_posts');

        return ($days <= 0 || ($actor->joined_at && $actor->joined_at->diffInDays(Carbon::now(), true) >= $days))
            && ($posts <= 0 || (int) $actor->comment_count >= $posts);
    }

    /** @return list<string> ratings whose series this member can see listed */
    public function listedRatings(User $actor): array
    {
        return $this->canSeeExplicit($actor) ? Series::RATINGS : ['all', 'teen', 'mature'];
    }

    /** @return list<string> ratings this member can open */
    public function readableRatings(User $actor): array
    {
        return array_values(array_filter(
            Series::RATINGS,
            fn (string $r) => match ($r) {
                'mature' => $this->canReadMature($actor),
                'explicit' => $this->canSeeExplicit($actor),
                default => true,
            }
        ));
    }

    /** @return list<int> */
    public function editedSeriesIds(User $actor): array
    {
        if ($actor->isGuest()) {
            return [];
        }

        return $this->memo($actor, 'edits', fn () => $this->db->table('panel_series_editors')
            ->where('user_id', $actor->id)->pluck('series_id')->map(fn ($id) => (int) $id)->all());
    }

    public function canEdit(Series $series, User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $this->isStaff($actor)
            || ((int) $series->user_id === (int) $actor->id && $this->canCreate($actor))
            || (in_array((int) $series->id, $this->editedSeriesIds($actor), true) && ! $this->isBlocked($actor));
    }

    public function canCreate(User $actor): bool
    {
        return $actor->hasPermission('panels.create') && ! $this->isBlocked($actor);
    }

    /** Upheld copyright strikes reached the admin's limit: no more uploads. */
    public function isBlocked(User $actor): bool
    {
        if ($actor->isGuest() || $this->isStaff($actor)) {
            return false;
        }
        $limit = (int) ($this->settings->get('ernestdefoe-panels.strike_limit') ?: 3);

        return $this->db->table('panel_strikes')->where('user_id', $actor->id)->count() >= $limit;
    }

    /**
     * Series this member may see in lists and open.
     *
     * @param Builder<Series> $query
     */
    public function scopeSeries(Builder $query, User $actor): void
    {
        if (! $actor->hasPermission('panels.view')) {
            $query->whereRaw('1 = 0');

            return;
        }
        if ($this->isStaff($actor)) {
            return;
        }

        $mine = $this->editedSeriesIds($actor);
        $query->whereNull('panel_series.removed_at')
            ->where(function ($q) use ($actor, $mine) {
                $q->where('panel_series.is_hidden', false);
                if (! $actor->isGuest()) {
                    $q->orWhere('panel_series.user_id', $actor->id);
                }
                if ($mine) {
                    $q->orWhereIn('panel_series.id', $mine);
                }
            })
            ->whereIn('panel_series.content_rating', $this->listedRatings($actor));
    }

    public function canSeeSeries(Series $series, User $actor): bool
    {
        if (! $actor->hasPermission('panels.view')) {
            return false;
        }
        if ($this->canEdit($series, $actor)) {
            return true;
        }

        return ! $series->isRemoved()
            && ! $series->is_hidden
            && in_array($series->content_rating, $this->listedRatings($actor), true);
    }

    /**
     * Chapters this member sees listed in a series they can see: out, or theirs.
     *
     * @param Builder<Chapter>|QueryBuilder $query
     */
    public function scopeChapters($query, Series $series, User $actor): void
    {
        if ($this->canEdit($series, $actor)) {
            return;
        }

        $query->where('panel_chapters.state', 'published')
            ->where('panel_chapters.published_at', '<=', Carbon::now())
            ->whereNull('panel_chapters.removed_at')
            ->whereIn($this->db->raw('COALESCE('.$this->db->getQueryGrammar()->wrap('panel_chapters.content_rating').", 'all')"), $this->listedRatings($actor));
    }

    /**
     * Why this member can't read the chapter, or null when they can.
     *
     * signin | age | rating | paid | unreleased | removed | hidden
     */
    public function refusal(Chapter $chapter, Series $series, User $actor): ?string
    {
        if (! $this->canSeeSeries($series, $actor)) {
            return 'hidden';
        }
        if ($this->canEdit($series, $actor)) {
            return null;
        }
        if ($series->isRemoved() || $chapter->removed_at !== null || $chapter->state === 'removed') {
            return 'removed';
        }
        if (! $chapter->isLive()) {
            return 'unreleased';
        }

        $rating = $chapter->effectiveRating($series);
        if (! in_array($rating, $this->readableRatings($actor), true)) {
            if ($rating === 'explicit' && ! $this->canSeeExplicit($actor)) {
                return 'hidden';
            }

            return $actor->isGuest() ? 'signin' : ($this->isAdult($actor) ? 'rating' : 'age');
        }

        if ($chapter->isPaywalled() && ! $this->holds($actor, $chapter)) {
            return 'paid';
        }

        return null;
    }

    /** Bought the chapter or its whole series through Kiosk. Fails closed without Kiosk. */
    public function holds(User $actor, Chapter $chapter): bool
    {
        return in_array((int) $chapter->id, $this->bought($actor, 'panels-chapter'), true)
            || in_array((int) $chapter->series_id, $this->bought($actor, 'panels-series'), true);
    }

    /** @return list<int> */
    public function bought(User $actor, string $type): array
    {
        if ($actor->isGuest() || ! $this->kiosk()) {
            return [];
        }

        return $this->memo($actor, 'kiosk:'.$type, fn () => array_map('intval', resolve(\Ernestdefoe\Kiosk\Service\Access::class)->ids($actor, $type)));
    }

    /**
     * @param callable(): list<int> $load
     * @return list<int>
     */
    private function memo(User $actor, string $key, callable $load): array
    {
        self::$memo ??= new \WeakMap();
        $mine = self::$memo[$actor] ?? [];
        if (! array_key_exists($key, $mine)) {
            $mine[$key] = $load();
            self::$memo[$actor] = $mine;
        }

        return $mine[$key];
    }

    public function kiosk(): bool
    {
        return class_exists(\Ernestdefoe\Kiosk\Service\Access::class) && $this->extensions->isEnabled('ernestdefoe-kiosk');
    }

    /**
     * Files anyone may fetch as plain static files: out, All ages or Teen, free, and in a
     * series that's listed. Everything else is behind signed URLs.
     */
    public function isOpen(Chapter $chapter, Series $series): bool
    {
        return $chapter->isLive()
            && $chapter->removed_at === null
            && ! $series->isRemoved()
            && ! $series->is_hidden
            && in_array($chapter->effectiveRating($series), ['all', 'teen'], true)
            && ! $chapter->isPaywalled();
    }

    /** The member's daily .cbz allowance: the largest of their groups' limits. */
    public function downloadLimit(User $actor): int
    {
        if ($actor->isGuest() || ! $actor->hasPermission('panels.download')) {
            return 0;
        }
        $limits = json_decode((string) $this->settings->get('ernestdefoe-panels.download_limits'), true) ?: [];
        $max = 0;
        foreach ($actor->groups as $group) {
            $max = max($max, (int) ($limits[(string) $group->id] ?? 0));
        }

        return $this->isStaff($actor) ? max($max, 100) : $max;
    }
}
