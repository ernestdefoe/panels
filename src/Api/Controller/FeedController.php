<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Publisher;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/panels/feed?kind=… : what the widgets show.
 *
 * new       the latest released chapters ({chapter, series card}), limit 3–12, format=…
 * continue  the member's series in progress, most recent first (empty for guests)
 * stats     series, chapters, pages, readers this week (cached 10 minutes)
 *
 * Trending and Spotlight read GET /api/panels/series (sort=trending, featured=1).
 */
class FeedController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly Publisher $publisher,
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertPermission($actor->hasPermission('panels.view'));
        $this->publisher->maybeTick();
        $params = $request->getQueryParams();
        $limit = max(1, min(12, (int) ($params['limit'] ?? 6)));

        return match ((string) ($params['kind'] ?? 'new')) {
            'continue' => $this->json(['data' => $this->continuing($actor, $limit)]),
            'stats' => $this->json(['data' => $this->stats($actor)]),
            default => $this->json(['data' => $this->latest($actor, $limit, (string) ($params['format'] ?? ''))]),
        };
    }

    /** @return list<array<string, mixed>> */
    private function latest(User $actor, int $limit, string $format): array
    {
        $visible = Series::query()->select('panel_series.id');
        $this->access->scopeSeries($visible, $actor);
        if (in_array($format, Series::FORMATS, true)) {
            $visible->where('panel_series.format', $format);
        }

        $chapters = Chapter::query()
            ->whereIn('series_id', $visible)
            ->where('state', 'published')->whereNull('removed_at')
            ->where('published_at', '<=', Carbon::now())
            ->whereIn($this->db->raw('COALESCE('.$this->db->getQueryGrammar()->wrap('content_rating').", 'all')"), $this->access->listedRatings($actor))
            ->orderByDesc('published_at')->limit($limit)->get();
        $series = Series::query()->whereIn('id', $chapters->pluck('series_id')->unique())->get()->keyBy('id');
        $this->presenter->prime($series->values(), $actor);
        $read = $actor->isGuest() ? [] : $this->db->table('panel_reads')->where('user_id', $actor->id)
            ->whereIn('chapter_id', $chapters->pluck('id'))->pluck('chapter_id')->flip()->all();

        return $chapters->map(fn (Chapter $c) => [
            'chapter' => ['id' => (int) $c->id, 'number' => $c->label(), 'title' => $c->title, 'publishedAt' => $c->published_at?->toIso8601String(), 'read' => isset($read[$c->id]), 'locked' => $c->isPaywalled() && ! $this->access->holds($actor, $c)],
            'series' => $this->presenter->card($series[$c->series_id], $actor),
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function continuing(User $actor, int $limit): array
    {
        if ($actor->isGuest()) {
            return [];
        }
        $ids = $this->db->table('panel_progress')->where('user_id', $actor->id)->orderByDesc('updated_at')->limit($limit * 2)->pluck('series_id')->all();
        if (! $ids) {
            return [];
        }
        $query = Series::query()->select('panel_series.*')->whereIn('panel_series.id', $ids);
        $this->access->scopeSeries($query, $actor);
        $series = $query->get()->sortBy(fn (Series $s) => array_search($s->id, $ids))->take($limit)->values();
        $this->presenter->prime($series, $actor);

        return $series->map(fn (Series $s) => $this->presenter->card($s, $actor))->all();
    }

    /** @return array<string, int> */
    private function stats(User $actor): array
    {
        $explicit = $this->access->canSeeExplicit($actor);

        return $this->cache->remember('ernestdefoe-panels.stats.'.($explicit ? 'x' : 'm'), 600, function () use ($explicit) {
            $series = $this->db->table('panel_series')->whereNull('removed_at')->where('is_hidden', false)->where('chapter_count', '>', 0)
                ->when(! $explicit, fn ($q) => $q->where('content_rating', '!=', 'explicit'));
            $ids = (clone $series)->pluck('id');
            $chapters = $this->db->table('panel_chapters')->whereIn('series_id', $ids)->where('state', 'published')
                ->whereNull('removed_at')->where('published_at', '<=', Carbon::now());

            return [
                'series' => $ids->count(),
                'chapters' => (clone $chapters)->count(),
                'pages' => (int) (clone $chapters)->sum('page_count'),
                'readers' => (int) $this->db->table('panel_chapter_stats')->whereIn('series_id', $ids)
                    ->where('day', '>=', Carbon::now()->subDays(7)->toDateString())->sum('readers'),
            ];
        });
    }
}
