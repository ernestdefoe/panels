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

        $read = ! $actor->isGuest() && $this->db->table('panel_reads')->where('user_id', $actor->id)->where('chapter_id', $chapter->id)->exists();
        $place = $actor->isGuest() ? null : $this->db->table('panel_progress')->where('user_id', $actor->id)->where('series_id', $series->id)->first(['chapter_id', 'page']);
        $comments = $chapter->discussion_id ? (int) $this->db->table('discussions')->where('id', $chapter->discussion_id)->whereNull('hidden_at')->value('comment_count') : 0;

        $this->presenter->prime(collect([$series]), $actor);
        $pages = $refusal === null && $chapter->source === 'upload'
            ? $this->pages->present($chapter, Page::query()->where('chapter_id', $chapter->id)->orderBy('position')->orderBy('segment')->get(), $actor)
            : [];

        $neighbour = fn (?Chapter $c) => $c ? ['id' => (int) $c->id, 'number' => $c->label(), 'title' => $c->title] : null;

        return $this->json([
            'series' => $this->presenter->card($series, $actor) + [
                'readingMode' => $series->reading_mode,
                'canEdit' => $this->access->canEdit($series, $actor),
                'canRate' => $actor->hasPermission('panels.rate'),
                'canDownload' => $refusal === null && $this->access->downloadLimit($actor) > 0,
                'library' => $actor->isGuest() ? null : $this->db->table('panel_library')->where('user_id', $actor->id)->where('series_id', $series->id)->first(['shelf', 'notify']),
                'myRating' => $actor->isGuest() ? null : $this->db->table('panel_ratings')->where('user_id', $actor->id)->where('series_id', $series->id)->value('rating'),
            ],
            'chapter' => $this->presenter->chapter($chapter, $series, $actor, $read, $comments) + [
                'note' => $refusal === null ? $chapter->note : null,
                'transcript' => $refusal === null ? $chapter->transcript : null,
            ],
            'refusal' => $refusal,
            'offer' => $refusal === 'paid' ? ['chapter' => (int) $chapter->id, 'series' => (int) $series->id] : null,
            'pages' => $pages,
            'prev' => $neighbour($prev),
            'next' => $neighbour($next),
            'place' => $place && (int) $place->chapter_id === (int) $chapter->id ? (int) $place->page : null,
        ]);
    }
}
