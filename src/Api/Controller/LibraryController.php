<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Publisher;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /api/panels/series/{id}/library  {shelf, notify}  put on a shelf, follow / unfollow
 * DELETE /api/panels/series/{id}/library                   out of the library
 * POST   /api/panels/series/{id}/rating   {rating: 1–5 | null}
 * POST   /api/panels/age                                   "I am 18 or older"
 * POST   /api/panels/progress/merge       {places: [{seriesId, chapterId, page, pages}]} a guest's places, after sign-in
 */
class LibraryController extends Controller
{
    public const SHELVES = ['reading', 'planned', 'hold', 'completed', 'dropped'];

    public function __construct(
        private readonly Access $access,
        private readonly Publisher $publisher,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $route = (string) $request->getAttribute('routeName');
        $data = $this->body($request);

        if ($route === 'panels.age') {
            if ($actor->getAttribute('panels_adult_at') === null) {
                $this->db->table('users')->where('id', $actor->id)->update(['panels_adult_at' => Carbon::now()]);
            }

            return $this->json(['ok' => true]);
        }

        if ($route === 'panels.progress.merge') {
            return $this->json(['merged' => $this->merge((array) ($data['places'] ?? []), $actor)]);
        }

        $series = SeriesShowController::find($this->param($request, 'id'), $actor, $this->access);

        if ($route === 'panels.rating') {
            $actor->assertPermission($actor->hasPermission('panels.rate'));
            $rating = $data['rating'] ?? null;
            if ($rating === null) {
                $this->db->table('panel_ratings')->where('user_id', $actor->id)->where('series_id', $series->id)->delete();
            } else {
                $rating = (int) $rating;
                if ($rating < 1 || $rating > 5) {
                    throw new Refused('ernestdefoe-panels.api.refused.rating');
                }
                $this->db->table('panel_ratings')->upsert(
                    [['user_id' => $actor->id, 'series_id' => $series->id, 'rating' => $rating, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]],
                    ['user_id', 'series_id'],
                    ['rating', 'updated_at']
                );
            }
        } elseif ($request->getMethod() === 'DELETE') {
            $this->db->table('panel_library')->where('user_id', $actor->id)->where('series_id', $series->id)->delete();
        } else {
            $current = $this->db->table('panel_library')->where('user_id', $actor->id)->where('series_id', $series->id)->first();
            $shelf = array_key_exists('shelf', $data)
                ? (in_array($data['shelf'], self::SHELVES, true) ? $data['shelf'] : null)
                : ($current->shelf ?? null);
            // Putting it on "Reading" follows it, unless the member said otherwise.
            $notify = array_key_exists('notify', $data)
                ? (bool) $data['notify']
                : ($current ? (bool) $current->notify : $shelf === 'reading');
            if ($shelf === null && ! $notify) {
                $this->db->table('panel_library')->where('user_id', $actor->id)->where('series_id', $series->id)->delete();
            } else {
                $this->db->table('panel_library')->upsert(
                    [['user_id' => $actor->id, 'series_id' => $series->id, 'shelf' => $shelf, 'notify' => $notify, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]],
                    ['user_id', 'series_id'],
                    ['shelf', 'notify', 'updated_at']
                );
            }
        }

        $this->publisher->counters($series);
        $series->refresh();
        $entry = $this->db->table('panel_library')->where('user_id', $actor->id)->where('series_id', $series->id)->first(['shelf', 'notify']);

        return $this->json([
            'library' => $entry ? ['shelf' => $entry->shelf, 'notify' => (bool) $entry->notify] : null,
            'myRating' => $this->db->table('panel_ratings')->where('user_id', $actor->id)->where('series_id', $series->id)->value('rating'),
            'rating' => $series->averageRating(),
            'ratingCount' => (int) $series->rating_count,
            'followerCount' => (int) $series->follower_count,
        ]);
    }

    /** @param list<mixed> $places */
    private function merge(array $places, User $actor): int
    {
        $merged = 0;
        foreach (array_slice($places, 0, 50) as $place) {
            $chapter = \ErnestDefoe\Panels\Model\Chapter::query()->find((int) ($place['chapterId'] ?? 0));
            if (! $chapter || $this->access->refusal($chapter, $chapter->series, $actor) !== null) {
                continue;
            }
            $exists = $this->db->table('panel_progress')->where('user_id', $actor->id)->where('series_id', $chapter->series_id)->exists();
            if ($exists) {
                continue;
            }
            $pages = max(0, (int) ($place['pages'] ?? 0));
            $this->db->table('panel_progress')->insert([
                'user_id' => $actor->id, 'series_id' => $chapter->series_id, 'chapter_id' => $chapter->id,
                'page' => max(1, min(max(1, $pages), (int) ($place['page'] ?? 1))), 'pages' => $pages, 'updated_at' => Carbon::now(),
            ]);
            $merged++;
        }

        return $merged;
    }
}
