<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Page;
use Flarum\Foundation\Paths;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ZipArchive;

/**
 * POST /api/panels/chapters/{id}/download (a form post: a GET would let any <img> spend the
 * member's daily allowance): the chapter as a .cbz, built from the largest stored
 * copies (watermarked when the forum marks pages). Off unless a group has panels.download, and
 * then limited per day by the admin's per-group allowance.
 */
class DownloadController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Store $store,
        private readonly Paths $paths,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $chapter = Chapter::query()->findOrFail((int) $this->param($request, 'id'));
        $series = $chapter->series;
        if ($chapter->source !== 'upload' || $this->access->refusal($chapter, $series, $actor) !== null) {
            throw new PermissionDeniedException();
        }

        $limit = $this->access->downloadLimit($actor);
        $used = $this->db->table('panel_downloads')->where('user_id', $actor->id)->where('created_at', '>=', Carbon::now()->subDay())->count();
        if ($limit <= 0) {
            throw new PermissionDeniedException();
        }
        if ($used >= $limit) {
            throw new Refused('ernestdefoe-panels.api.refused.download_limit', ['limit' => $limit]);
        }
        if (! class_exists(ZipArchive::class)) {
            throw new Refused('ernestdefoe-panels.api.refused.no_zip');
        }

        $dir = $this->paths->storage.'/tmp/panels';
        @mkdir($dir, 0700, true);
        $path = $dir.'/dl-'.bin2hex(random_bytes(12)).'.cbz';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $disk = $this->store->disk($chapter->storage === 'public');
        $i = 0;
        foreach (Page::query()->where('chapter_id', $chapter->id)->orderBy('position')->orderBy('segment')->get() as $page) {
            $variants = $page->variants;
            ksort($variants, SORT_NUMERIC);
            $file = (string) end($variants);
            if ($disk->exists($file)) {
                $zip->addFromString(sprintf('%04d.%s', ++$i, pathinfo($file, PATHINFO_EXTENSION)), (string) $disk->get($file));
            }
        }
        $zip->close();

        $this->db->table('panel_downloads')->insert(['user_id' => $actor->id, 'chapter_id' => $chapter->id, 'created_at' => Carbon::now()]);

        $name = preg_replace('~[^\p{L}\p{N} ._-]+~u', '', $series->title.' - '.$chapter->label()) ?: 'chapter';
        $stream = fopen($path, 'rb');
        register_shutdown_function(fn () => @unlink($path));

        return new Response(new Stream($stream ?: 'php://memory'), 200, [
            'Content-Type' => 'application/vnd.comicbook+zip',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'.cbz"',
            'Content-Length' => (string) filesize($path),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
