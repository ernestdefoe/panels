<?php

namespace ErnestDefoe\Panels\Api\Controller;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Page;
use ErnestDefoe\Panels\Pages;
use ErnestDefoe\Panels\Publisher;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/panels/series/{slug}/chapters/{number}: everything the reader draws, in one request.
 *
 * The chapter, every page (sizes, colour, the URL of each stored width), the chapters before
 * and after, and the member's place. When the member can't read it, no page or URL is sent at
 * all: `refusal` says why (signin, age, rating, paid, unreleased, removed) and the reader
 * explains. For a paid chapter, the Kiosk offer's type and id come along.
 */
class ReaderController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly Pages $pages,
        private readonly Publisher $publisher,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $this->publisher->maybeTick();
        $series = SeriesShowController::find($this->param($request, 'slug'), $actor, $this->access);
        $number = str_replace(',', '.', $this->param($request, 'number'));

        $chapter = preg_match('~^\d{1,6}(\.\d{1,2})?$~', $number)
            ? Chapter::query()->where('series_id', $series->id)->where('number', $number)->where('state', '!=', 'draft')->orderByDesc('id')->first()
            : null;
        if (! $chapter && $this->access->canEdit($series, $actor)) {
            $chapter = Chapter::query()->where('series_id', $series->id)->where('number', $number)->orderByDesc('id')->first();
        }
        if (! $chapter) {
            throw new ModelNotFoundException();
        }

        $refusal = $this->access->refusal($chapter, $series, $actor);
        if ($refusal === 'hidden') {
            throw new ModelNotFoundException();
        }

        // Neighbours among what this member can see.
        $listed = Chapter::query()->where('series_id', $series->id);
        $this->access->scopeChapters($listed, $series, $actor);
        $prev = (clone $listed)->where('number', '<', $chapter->number)->orderByDesc('number')->first(['id', 'number', 'title']);
        $next = (clone $listed)->where('number', '>', $chapter->number)->orderBy('number')->first(['id', 'number', 'title']);

        $me = $this->presenter->memberState($actor, (int) $series->id, (int) $chapter->id, $chapter->discussion_id ? (int) $chapter->discussion_id : null);
        $pages = $refusal === null && $chapter->source === 'upload'
            ? $this->pages->present($chapter, Page::query()->where('chapter_id', $chapter->id)->orderBy('position')->orderBy('segment')->get(), $actor)
            : [];

        $neighbour = fn (?Chapter $c) => $c ? ['id' => (int) $c->id, 'number' => $c->label(), 'title' => $c->title] : null;

        return $this->json([
            // The reader shows little of the series: no genres, counts or the library's extras.
            'series' => [
                'id' => (int) $series->id,
                'slug' => $series->slug,
                'title' => $series->title,
                'format' => $series->format,
                'contentRating' => $series->content_rating,
                'cover' => $this->presenter->cover($series, $actor),
                'readingMode' => $series->reading_mode,
                'canEdit' => $this->access->canEdit($series, $actor),
                'canRate' => $actor->hasPermission('panels.rate'),
                'canDownload' => $refusal === null && $this->access->downloadLimit($actor) > 0,
                'library' => $me['shelf'] !== null || $me['notify'] ? ['shelf' => $me['shelf'], 'notify' => $me['notify']] : null,
                'myRating' => $me['rating'],
            ],
            'chapter' => $this->presenter->chapter($chapter, $series, $actor, $me['read'], $me['comments']) + [
                'note' => $refusal === null ? $chapter->note : null,
                'transcript' => $refusal === null ? $chapter->transcript : null,
            ],
            'refusal' => $refusal,
            'offer' => $refusal === 'paid' ? ['chapter' => (int) $chapter->id, 'series' => (int) $series->id] : null,
            'pages' => $pages,
            'prev' => $neighbour($prev),
            'next' => $neighbour($next),
            'place' => $me['placeChapter'] === (int) $chapter->id ? $me['placePage'] : null,
        ]);
    }
}
