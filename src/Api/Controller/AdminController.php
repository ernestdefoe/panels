<?php

namespace ErnestDefoe\Panels\Api\Controller;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Genre;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * GET    /api/panels/queue           chapters waiting for a moderator (pending, held)
 * GET    /api/panels/genres          the genre list (anyone who may browse)
 * POST   /api/panels/genres          admin: add {name}
 * PATCH  /api/panels/genres/{id}     admin: {name, position}
 * DELETE /api/panels/genres/{id}     admin
 * POST   /api/panels/watermark       admin: the PNG burnt into pages (when switched on)
 * DELETE /api/panels/watermark       admin
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly Store $store,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $route = (string) $request->getAttribute('routeName');
        $method = $request->getMethod();

        if ($route === 'panels.queue') {
            if (! $this->access->isStaff($actor)) {
                throw new PermissionDeniedException();
            }
            $chapters = Chapter::query()->with('series', 'user')->whereIn('state', ['pending', 'held'])->orderBy('updated_at')->limit(100)->get();

            return $this->json(['data' => $chapters->map(fn (Chapter $c) => $this->presenter->chapter($c, $c->series, $actor) + [
                'seriesTitle' => $c->series->title,
                'seriesSlug' => $c->series->slug,
                'uploader' => $c->user ? $this->presenter->user($c->user) : null,
            ])->values()->all()]);
        }

        if ($route === 'panels.genres' && $method === 'GET') {
            return $this->json(['data' => Genre::query()->orderBy('position')->get()->map(fn (Genre $g) => [
                'id' => (int) $g->id, 'name' => $g->name, 'slug' => $g->slug, 'position' => (int) $g->position, 'seriesCount' => (int) $g->series_count,
            ])->all()]);
        }

        $actor->assertAdmin();
        $data = $this->body($request);

        if (str_starts_with($route, 'panels.watermark')) {
            $disk = $this->store->disk(false);
            if ($method === 'DELETE') {
                $disk->delete('watermark.png');

                return $this->json(['ok' => true]);
            }
            $file = $request->getUploadedFiles()['watermark'] ?? null;
            $bytes = $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK ? (string) $file->getStream()->getContents() : '';
            $info = @getimagesizefromstring($bytes);
            if (! $info || $info[2] !== IMAGETYPE_PNG || strlen($bytes) > 2 * 1048576) {
                throw new Refused('ernestdefoe-panels.api.refused.watermark');
            }
            // Re-encoded, like everything else that's uploaded.
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                throw new Refused('ernestdefoe-panels.api.refused.watermark');
            }
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $disk->put('watermark.png', (string) ob_get_clean());

            return $this->json(['ok' => true]);
        }

        if ($method === 'POST') {
            $genre = new Genre();
            $genre->position = (int) Genre::query()->max('position') + 1;
        } else {
            $genre = Genre::query()->findOrFail((int) $this->param($request, 'id'));
            if ($method === 'DELETE') {
                $genre->delete();

                return $this->json(['deleted' => true]);
            }
        }
        if (array_key_exists('name', $data) || $method === 'POST') {
            $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 60);
            if ($name === '') {
                throw new Refused('ernestdefoe-panels.api.refused.title');
            }
            $genre->name = $name;
            $slug = Str::slug($name) ?: 'genre';
            $base = $slug;
            for ($i = 2; Genre::query()->where('slug', $slug)->when($genre->exists, fn ($q) => $q->where('id', '!=', $genre->id))->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            $genre->slug = $slug;
        }
        if (array_key_exists('position', $data)) {
            $genre->position = max(0, (int) $data['position']);
        }
        $genre->save();

        return $this->json(['data' => ['id' => (int) $genre->id, 'name' => $genre->name, 'slug' => $genre->slug, 'position' => (int) $genre->position]]);
    }
}
