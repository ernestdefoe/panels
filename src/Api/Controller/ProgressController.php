<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Event\ChapterRead;
use ErnestDefoe\Panels\Model\Chapter;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/panels/chapters/{id}/progress  {page, pages, finished}  the reader, every few seconds
 * POST /api/panels/chapters/{id}/read      {read: bool, upTo: bool}  the series page's marks
 *
 * A chapter counts as read once (one row), which is when Renown and Spoiler Shield hear of it
 * and the day's reader count goes up.
 */
class ProgressController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $events,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $chapter = Chapter::query()->findOrFail((int) $this->param($request, 'id'));
        $series = $chapter->series;
        if ($this->access->refusal($chapter, $series, $actor) !== null) {
            throw new PermissionDeniedException();
        }
        $data = $this->body($request);

        if ($request->getAttribute('routeName') === 'panels.read') {
            $ids = ! empty($data['upTo'])
                ? (function () use ($chapter, $series, $actor) {
                    $q = Chapter::query()->where('series_id', $series->id)->where('number', '<=', $chapter->number);
                    $this->access->scopeChapters($q, $series, $actor);

                    return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
                })()
                : [(int) $chapter->id];

            if (! empty($data['read'])) {
                foreach (Chapter::query()->whereIn('id', $ids)->get() as $c) {
                    $this->markRead($c, $actor);
                }
            } else {
                $this->db->table('panel_reads')->where('user_id', $actor->id)->whereIn('chapter_id', $ids)->delete();
            }

            return $this->json(['ok' => true]);
        }

        $pages = max(0, min(5000, (int) ($data['pages'] ?? $chapter->page_count)));
        $page = max(1, min(max(1, $pages), (int) ($data['page'] ?? 1)));
        $this->db->table('panel_progress')->upsert(
            [['user_id' => $actor->id, 'series_id' => $series->id, 'chapter_id' => $chapter->id, 'page' => $page, 'pages' => $pages, 'updated_at' => Carbon::now()]],
            ['user_id', 'series_id'],
            ['chapter_id', 'page', 'pages', 'updated_at']
        );

        $first = ! empty($data['finished']) && $this->markRead($chapter, $actor);

        return $this->json(['ok' => true, 'firstRead' => $first]);
    }

    /** True the first time only. */
    private function markRead(Chapter $chapter, User $actor): bool
    {
        $added = $this->db->table('panel_reads')->insertOrIgnore([
            'user_id' => $actor->id, 'chapter_id' => $chapter->id, 'read_at' => Carbon::now(),
        ]) > 0;
        if (! $added) {
            return false;
        }

        $day = Carbon::now()->toDateString();
        $this->db->table('panel_chapter_stats')->insertOrIgnore(['chapter_id' => $chapter->id, 'series_id' => $chapter->series_id, 'day' => $day, 'readers' => 0]);
        $this->db->table('panel_chapter_stats')->where('chapter_id', $chapter->id)->where('day', $day)->increment('readers');
        $this->db->table('panel_series')->where('id', $chapter->series_id)->increment('read_count');

        if ((int) $chapter->user_id !== (int) $actor->id) {
            $this->events->dispatch(new ChapterRead($chapter, $actor));
        }

        return true;
    }
}
