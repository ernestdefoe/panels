<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Page;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Pages;
use ErnestDefoe\Panels\Publisher;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The chapter editor's API.
 *
 * GET    /api/panels/chapters/{id}   the chapter with all its pages, for its editors
 * POST   /api/panels/chapters        a new draft: {seriesId, number, volume, title, …}
 * PATCH  /api/panels/chapters/{id}   details, and actions: publish {at}, unpublish, approve (staff),
 *                                    remove / restore (staff)
 * DELETE /api/panels/chapters/{id}   a draft (its editors) or any chapter (staff)
 */
class ChapterController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly Pages $pages,
        private readonly Publisher $publisher,
        private readonly Store $store,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $method = $request->getMethod();
        $id = $this->param($request, 'id');

        if ($method === 'POST') {
            $data = $this->body($request);
            $series = Series::query()->findOrFail((int) ($data['seriesId'] ?? 0));
            $this->assertEditor($series, $actor);
            $chapter = new Chapter();
            $chapter->series_id = (int) $series->id;
            $chapter->user_id = (int) $actor->id;
            $chapter->state = 'draft';
            $chapter->storage = 'private';
            $chapter->access = $series->is_sold ? 'paid' : 'free';
            $this->details($chapter, $series, $data, $actor, true);
            $chapter->save();

            return $this->json(['data' => $this->editor($chapter, $series, $actor)], 201);
        }

        $chapter = Chapter::query()->findOrFail((int) $id);
        $series = $chapter->series;
        $this->assertEditor($series, $actor);

        if ($method === 'DELETE') {
            if ($chapter->state !== 'draft' && ! $this->access->isStaff($actor)) {
                throw new PermissionDeniedException();
            }
            $this->store->deleteChapter((int) $series->id, (int) $chapter->id);
            $chapter->delete();
            $this->publisher->counters($series);

            return $this->json(['deleted' => true]);
        }

        if ($method === 'PATCH') {
            $data = $this->body($request);
            $this->details($chapter, $series, $data, $actor, false);
            $chapter->save();
            $this->actions($chapter, $data, $actor);
            $chapter->refresh();
            // Rating or access may have changed: the files may need to move.
            $this->pages->relocate($chapter, $series);
        }

        return $this->json(['data' => $this->editor($chapter, $series, $actor)]);
    }

    /** @param array<string, mixed> $data */
    private function details(Chapter $chapter, Series $series, array $data, User $actor, bool $creating): void
    {
        if ($creating || array_key_exists('number', $data)) {
            $number = str_replace(',', '.', trim((string) ($data['number'] ?? '')));
            if (! preg_match('~^\d{1,6}(\.\d{1,2})?$~', $number)) {
                throw new Refused('ernestdefoe-panels.api.refused.number');
            }
            $taken = Chapter::query()->where('series_id', $series->id)->where('number', $number)
                ->where('state', '!=', 'removed')->when($chapter->exists, fn ($q) => $q->where('id', '!=', $chapter->id))->exists();
            if ($taken) {
                throw new Refused('ernestdefoe-panels.api.refused.number_taken', ['number' => Chapter::formatNumber($number)]);
            }
            $chapter->number = $number;
        }
        if (array_key_exists('volume', $data)) {
            $volume = (int) $data['volume'];
            $chapter->volume = $volume > 0 ? min($volume, 9999) : null;
        }
        if (array_key_exists('title', $data)) {
            $chapter->title = mb_substr(trim((string) $data['title']), 0, 150) ?: null;
        }
        if (array_key_exists('note', $data)) {
            $chapter->note = mb_substr(trim((string) $data['note']), 0, 3000) ?: null;
        }
        if (array_key_exists('transcript', $data)) {
            $chapter->transcript = mb_substr(trim((string) $data['transcript']), 0, 60000) ?: null;
        }
        if (array_key_exists('contentRating', $data)) {
            $rating = $data['contentRating'];
            if ($rating === 'explicit' && ! $this->access->explicitEnabled()) {
                throw new Refused('ernestdefoe-panels.api.refused.explicit_off');
            }
            $chapter->content_rating = in_array($rating, Series::RATINGS, true) ? (string) $rating : null;
        }
        if (array_key_exists('source', $data)) {
            $chapter->source = $data['source'] === 'external' ? 'external' : 'upload';
        }
        if (array_key_exists('externalUrl', $data)) {
            $url = trim((string) $data['externalUrl']);
            if ($url !== '' && (! preg_match('~^https://~i', $url) || ! filter_var($url, FILTER_VALIDATE_URL))) {
                throw new Refused('ernestdefoe-panels.api.refused.link');
            }
            $chapter->external_url = $url !== '' ? mb_substr($url, 0, 500) : null;
        }
        if (array_key_exists('access', $data) && in_array($data['access'], Chapter::ACCESS, true)) {
            // Paid and early access only mean something while Kiosk can sell them.
            if ($data['access'] !== 'free' && ! $this->access->kiosk()) {
                throw new Refused('ernestdefoe-panels.api.refused.kiosk_off');
            }
            $chapter->access = (string) $data['access'];
            $until = trim((string) ($data['earlyAccessUntil'] ?? ''));
            $chapter->early_access_until = $chapter->access === 'early' && $until !== '' ? Carbon::parse($until) : null;
            if ($chapter->access === 'early' && ! $chapter->early_access_until) {
                throw new Refused('ernestdefoe-panels.api.refused.early_date');
            }
        }
        if (! empty($data['attest'])) {
            $chapter->attested_at = Carbon::now();
        }
    }

    /** @param array<string, mixed> $data */
    private function actions(Chapter $chapter, array $data, User $actor): void
    {
        $staff = $this->access->isStaff($actor);
        $action = (string) ($data['action'] ?? '');

        match ($action) {
            'publish' => $this->publisher->publish($chapter, $actor, ! empty($data['at']) ? Carbon::parse((string) $data['at']) : null),
            'unpublish' => $chapter->discussion_id === null && in_array($chapter->state, ['published', 'pending', 'held'], true)
                ? $chapter->forceFill(['state' => 'draft'])->save()
                : throw new Refused('ernestdefoe-panels.api.refused.already_out'),
            'approve' => $staff ? $this->publisher->approve($chapter) : throw new PermissionDeniedException(),
            'remove' => $staff ? $this->publisher->remove($chapter, $actor) : throw new PermissionDeniedException(),
            'restore' => $staff ? $this->publisher->restore($chapter, $actor) : throw new PermissionDeniedException(),
            default => null,
        };
    }

    private function assertEditor(Series $series, User $actor): void
    {
        if (! $this->access->canEdit($series, $actor)) {
            throw new PermissionDeniedException();
        }
    }

    /** @return array<string, mixed> */
    private function editor(Chapter $chapter, Series $series, User $actor): array
    {
        $pages = Page::query()->where('chapter_id', $chapter->id)->orderBy('position')->orderBy('segment')->get();

        return $this->presenter->chapter($chapter, $series, $actor) + [
            'seriesId' => (int) $series->id,
            'seriesSlug' => $series->slug,
            'seriesTitle' => $series->title,
            'seriesFormat' => $series->format,
            'note' => $chapter->note,
            'transcript' => $chapter->transcript,
            'attested' => $chapter->attested_at !== null,
            'storage' => $chapter->storage,
            'pages' => array_map(
                fn (array $p, Page $page) => $p + ['name' => $page->original_name],
                $this->pages->present($chapter, $pages, $actor),
                $pages->all()
            ),
        ];
    }
}
