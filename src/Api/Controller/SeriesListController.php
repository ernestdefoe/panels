<?php

namespace ErnestDefoe\Panels\Api\Controller;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Publisher;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/panels/series: the library, the widgets and every list of series.
 *
 * Filters: q (title and other names), format, status, genre (slug), featured=1, mine=1 (series
 * the member owns or co-edits), shelf=reading|planned|… (the member's library), ids=1,2,3.
 * Sort: latest (default), new, rating, followers, reads (all time), title, trending (distinct readers, 7 days).
 */
class SeriesListController extends Controller
{
    public const LIMIT = 24;

    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly Publisher $publisher,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertPermission($actor->hasPermission('panels.view'));
        $this->publisher->maybeTick();

        $params = $request->getQueryParams();
        $filter = (array) ($params['filter'] ?? []);
        $limit = max(1, min(48, (int) ($params['limit'] ?? self::LIMIT)));
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = Series::query()->select('panel_series.*');
        $this->access->scopeSeries($query, $actor);
        $this->filter($query, $filter, $actor);
        $this->sort($query, (string) ($params['sort'] ?? 'latest'));

        $rows = $query->offset(($page - 1) * $limit)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $this->presenter->prime($rows, $actor);

        return $this->json([
            'data' => $rows->map(fn (Series $s) => $this->presenter->card($s, $actor))->all(),
            'hasMore' => $more,
        ]);
    }

    /**
     * @param Builder<Series> $query
     * @param array<string, mixed> $filter
     */
    private function filter(Builder $query, array $filter, User $actor): void
    {
        $mine = ! empty($filter['mine']) && ! $actor->isGuest();
        $shelf = (string) ($filter['shelf'] ?? '');

        if ($mine) {
            $edited = $this->access->editedSeriesIds($actor);
            $query->where(fn ($q) => $q->where('panel_series.user_id', $actor->id)->orWhereIn('panel_series.id', $edited ?: [0]));
        } elseif ($shelf !== '' && ! $actor->isGuest()) {
            $query->join('panel_library', 'panel_library.series_id', '=', 'panel_series.id')
                ->where('panel_library.user_id', $actor->id)
                ->when($shelf === 'following', fn ($q) => $q->where('panel_library.notify', true), fn ($q) => $q->where('panel_library.shelf', $shelf));
        } elseif (! $this->access->isStaff($actor)) {
            // A series with nothing out yet isn't in the library (its owner sees it under "mine").
            $query->where('panel_series.chapter_count', '>', 0);
        }

        if (($q = trim((string) ($filter['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes(mb_substr($q, 0, 80), '%_\\').'%';
            $query->where(fn ($w) => $w->where('panel_series.title', 'like', $like)->orWhere('panel_series.alt_titles', 'like', $like));
        }
        if (in_array($filter['format'] ?? null, Series::FORMATS, true)) {
            $query->where('panel_series.format', $filter['format']);
        }
        if (in_array($filter['status'] ?? null, Series::STATUSES, true)) {
            $query->where('panel_series.status', $filter['status']);
        }
        if (($genre = (string) ($filter['genre'] ?? '')) !== '') {
            $query->whereExists(fn ($q) => $q->from('panel_series_genre')
                ->join('panel_genres', 'panel_genres.id', '=', 'panel_series_genre.genre_id')
                ->whereColumn('panel_series_genre.series_id', 'panel_series.id')
                ->where('panel_genres.slug', $genre));
        }
        if (! empty($filter['featured'])) {
            $query->where('panel_series.is_featured', true);
        }
        if (! empty($filter['ids'])) {
            $query->whereIn('panel_series.id', array_map('intval', explode(',', (string) $filter['ids'])));
        }
    }

    /** @param Builder<Series> $query */
    private function sort(Builder $query, string $sort): void
    {
        $wrap = fn (string $col) => $this->db->getQueryGrammar()->wrap($col);
        match ($sort) {
            'new' => $query->orderByDesc('panel_series.created_at'),
            'rating' => $query->orderByRaw('CASE WHEN '.$wrap('panel_series.rating_count').' = 0 THEN 0 ELSE '.$wrap('panel_series.rating_sum').' * 1.0 / '.$wrap('panel_series.rating_count').' END DESC'),
            'followers' => $query->orderByDesc('panel_series.follower_count'),
            'reads' => $query->orderByDesc('panel_series.read_count'),
            'title' => $query->orderBy('panel_series.title'),
            'trending' => $query
                ->leftJoinSub(
                    $this->db->table('panel_chapter_stats')->where('day', '>=', now()->subDays(7)->toDateString())
                        ->groupBy('series_id')->selectRaw($wrap('series_id').' as trend_series, SUM('.$wrap('readers').') as trend_readers'),
                    'trend',
                    'trend.trend_series',
                    '=',
                    'panel_series.id'
                )
                ->orderByRaw('COALESCE('.$wrap('trend.trend_readers').', 0) DESC'),
            default => $query->orderByRaw($wrap('panel_series.latest_chapter_at').' IS NULL')->orderByDesc('panel_series.latest_chapter_at'),
        };
        $query->orderByDesc('panel_series.id');
    }
}
