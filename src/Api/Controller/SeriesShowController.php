<?php

namespace ErnestDefoe\Panels\Api\Controller;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Series;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/panels/series/{slug}: the series page. Chapters 100 at a time (?page=2), newest
 * first unless ?order=asc. A series the member may not see doesn't exist for them (404).
 */
class SeriesShowController extends Controller
{
    public const CHAPTERS = 100;

    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $series = self::find($this->param($request, 'slug'), $actor, $this->access);
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $asc = ($params['order'] ?? '') === 'asc';

        $query = Chapter::query()->where('series_id', $series->id);
        $this->access->scopeChapters($query, $series, $actor);
        $chapters = $query->orderBy('number', $asc ? 'asc' : 'desc')->orderBy('id', $asc ? 'asc' : 'desc')
            ->offset(($page - 1) * self::CHAPTERS)->limit(self::CHAPTERS + 1)->get();
        $more = $chapters->count() > self::CHAPTERS;

        return $this->json(['data' => $this->presenter->series($series, $chapters->take(self::CHAPTERS)->values(), $actor), 'hasMoreChapters' => $more]);
    }

    /** By slug (or id), or a 404 for anyone who may not see it. */
    public static function find(string $key, User $actor, Access $access): Series
    {
        $series = ctype_digit($key) ? Series::query()->find((int) $key) : Series::query()->where('slug', $key)->first();
        if (! $series || ! $access->canSeeSeries($series, $actor)) {
            throw new ModelNotFoundException();
        }

        return $series;
    }
}
