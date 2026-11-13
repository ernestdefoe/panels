<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Genre;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Pages;
use ErnestDefoe\Panels\Publisher;
use ErnestDefoe\Panels\Settings;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/panels/series (start one) and PATCH /api/panels/series/{id} (change it).
 *
 * The owner and co-editors change the details; only the owner and moderators change the
 * co-editors; only moderators hide, feature, remove and restore.
 */
class SeriesSaveController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly Settings $settings,
        private readonly Publisher $publisher,
        private readonly Pages $pages,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $data = $this->body($request);
        $id = $this->param($request, 'id');

        if ($id === '') {
            if (! $this->access->canCreate($actor)) {
                throw new PermissionDeniedException();
            }
            $series = new Series();
            $series->user_id = (int) $actor->id;
            $series->format = 'manga';
            $series->reading_mode = 'rtl';
        } else {
            $series = Series::query()->findOrFail((int) $id);
            if (! $this->access->canEdit($series, $actor)) {
                throw new PermissionDeniedException();
            }
        }

        $before = [$series->content_rating, $series->is_hidden, $series->removed_at];
        $this->details($series, $data, $id === '');
        $this->staff($series, $data, $actor);
        $series->save();

        if (array_key_exists('genres', $data)) {
            $this->genres($series, (array) $data['genres']);
        }
        if (array_key_exists('editors', $data) && ($this->access->isStaff($actor) || (int) $series->user_id === (int) $actor->id)) {
            $this->editors($series, (array) $data['editors']);
        }

        // Rating, hidden or removed changed: chapters may move between the public and private disks.
        if ($before !== [$series->content_rating, $series->is_hidden, $series->removed_at]) {
            Chapter::query()->where('series_id', $series->id)->get()->each(fn (Chapter $c) => $this->pages->relocate($c, $series));
        }
        $this->publisher->counters($series);

        $fresh = Series::query()->with(['genres', 'latestChapter'])->findOrFail($series->id);
        $this->presenter->prime(collect([$fresh]), $actor);

        return $this->json(['data' => $this->presenter->series($fresh, collect(), $actor)], $id === '' ? 201 : 200);
    }

    /** @param array<string, mixed> $data */
    private function details(Series $series, array $data, bool $creating): void
    {
        if ($creating || array_key_exists('title', $data)) {
            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 150) {
                throw new Refused('ernestdefoe-panels.api.refused.title');
            }
            $series->title = $title;
            if ($creating) {
                $series->slug = $this->slug($title);
            }
        }
        if (array_key_exists('altTitles', $data)) {
            $lines = array_slice(array_filter(array_map(fn ($l) => mb_substr(trim($l), 0, 150), explode("\n", (string) $data['altTitles']))), 0, 10);
            $series->alt_titles = $lines ? implode("\n", $lines) : null;
        }
        if (array_key_exists('description', $data)) {
            $series->description = mb_substr(trim((string) $data['description']), 0, 5000) ?: null;
        }
        if (in_array($data['format'] ?? null, Series::FORMATS, true)) {
            $series->format = (string) $data['format'];
            if (! array_key_exists('readingMode', $data) && $creating) {
                $series->reading_mode = Series::modeFor($series->format);
            }
        }
        if (in_array($data['readingMode'] ?? null, Series::MODES, true)) {
            $series->reading_mode = (string) $data['readingMode'];
        }
        if (in_array($data['status'] ?? null, Series::STATUSES, true)) {
            $series->status = (string) $data['status'];
        }
        if (in_array($data['contentRating'] ?? null, Series::RATINGS, true)) {
            if ($data['contentRating'] === 'explicit' && ! $this->access->explicitEnabled()) {
                throw new Refused('ernestdefoe-panels.api.refused.explicit_off');
            }
            $series->content_rating = (string) $data['contentRating'];
        }
        if (array_key_exists('fields', $data)) {
            $series->fields = $this->settings->cleanFields((array) $data['fields']) ?: null;
        }
        if (array_key_exists('tagId', $data)) {
            $tag = (int) $data['tagId'];
            $series->tag_id = $tag > 0 && $this->db->getSchemaBuilder()->hasTable('tags') && $this->db->table('tags')->where('id', $tag)->exists() ? $tag : null;
        }
    }

    /** @param array<string, mixed> $data */
    private function staff(Series $series, array $data, User $actor): void
    {
        if (! $this->access->isStaff($actor)) {
            return;
        }
        if (array_key_exists('isHidden', $data)) {
            $series->is_hidden = (bool) $data['isHidden'];
        }
        if (array_key_exists('isFeatured', $data)) {
            $series->is_featured = (bool) $data['isFeatured'];
        }
        if (array_key_exists('isRemoved', $data)) {
            $series->removed_at = $data['isRemoved'] ? ($series->removed_at ?? Carbon::now()) : null;
        }
    }

    /** @param list<mixed> $ids */
    private function genres(Series $series, array $ids): void
    {
        $ids = Genre::query()->whereIn('id', array_map('intval', array_slice($ids, 0, 8)))->pluck('id')->all();
        $old = $series->genres()->pluck('panel_genres.id')->all();
        $series->genres()->sync($ids);
        foreach (array_unique(array_merge($old, $ids)) as $genre) {
            Genre::query()->whereKey($genre)->update(['series_count' => $this->db->table('panel_series_genre')->where('genre_id', $genre)->count()]);
        }
    }

    /** @param list<mixed> $usernames */
    private function editors(Series $series, array $usernames): void
    {
        $names = array_slice(array_filter(array_map(fn ($n) => trim((string) $n), $usernames)), 0, 10);
        $ids = User::query()->whereIn('username', $names)->where('id', '!=', $series->user_id)->pluck('id')->all();
        $series->editors()->sync($ids);
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'series';
        $base = mb_substr($base, 0, 100);
        if (in_array($base, Series::RESERVED, true) || ctype_digit($base)) {
            $base .= '-series';
        }
        $slug = $base;
        for ($i = 2; Series::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
