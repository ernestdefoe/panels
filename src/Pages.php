<?php

namespace ErnestDefoe\Panels;

use Carbon\Carbon;
use ErnestDefoe\Panels\Media\Archive;
use ErnestDefoe\Panels\Media\Pipeline;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Import;
use ErnestDefoe\Panels\Model\Page;
use ErnestDefoe\Panels\Model\Series;
use Flarum\Foundation\Paths;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;
use ZipArchive;

/**
 * A chapter's pages: adding images, importing archives a few pages at a time, ordering,
 * removing, and keeping the files on the disk their chapter's rules call for.
 */
class Pages
{
    /** How long one import step may run before handing back to the browser. */
    public const STEP_SECONDS = 4.0;

    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly Archive $archive,
        private readonly Store $store,
        private readonly Access $access,
        private readonly Paths $paths,
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Pages a member may add in an hour (the admin's limit, 600 by default). Counted per hour
     * in the cache; staff aren't limited.
     */
    public function throttle(int $userId, int $pages = 1): void
    {
        $limit = (int) ($this->settings->get('ernestdefoe-panels.pages_per_hour') ?: 600);
        $key = 'ernestdefoe-panels.pages.'.$userId.'.'.date('YmdH');
        $this->cache->add($key, 0, 3700);
        if ((int) $this->cache->get($key) + $pages > $limit) {
            throw new Refused('ernestdefoe-panels.api.refused.slow_down_pages', ['limit' => $limit]);
        }
        $this->cache->increment($key, $pages);
    }

    /**
     * One uploaded image (a tall strip may become several rows).
     *
     * @return list<Page>
     */
    public function add(Chapter $chapter, string $bytes, ?string $name): array
    {
        $count = Page::query()->where('chapter_id', $chapter->id)->where('segment', 0)->count();
        if ($count >= $this->archive->maxPages()) {
            throw new Refused('ernestdefoe-panels.api.refused.too_many_pages', ['limit' => $this->archive->maxPages()]);
        }

        // The slow part first, with no lock held.
        $rows = $this->pipeline->page($bytes, (int) $chapter->series_id, (int) $chapter->id, $this->wantsPublic($chapter));

        // 🚨 The editor uploads three images at once: the next position is taken under a lock
        // on the chapter's row, or two pages get the same one and merge into one page.
        $pages = $this->db->transaction(function () use ($chapter, $rows, $name) {
            Chapter::query()->whereKey($chapter->id)->lockForUpdate()->first(['id']);
            $position = (int) Page::query()->where('chapter_id', $chapter->id)->max('position') + 1;

            return $this->save($chapter, $rows, $position, $name);
        });
        $this->recount($chapter);

        return $pages;
    }

    /** Check an uploaded archive and keep it for the steps that follow. */
    public function startImport(Chapter $chapter, UploadedFileInterface $file, User $actor): Import
    {
        if (! class_exists(ZipArchive::class)) {
            throw new Refused('ernestdefoe-panels.api.refused.no_zip');
        }
        if ($file->getError() !== UPLOAD_ERR_OK || (int) $file->getSize() <= 0) {
            throw new Refused('ernestdefoe-panels.api.refused.incomplete');
        }
        if ((int) $file->getSize() > $this->archive->maxArchiveBytes()) {
            throw new Refused('ernestdefoe-panels.api.refused.archive_size', ['mb' => (int) ($this->archive->maxArchiveBytes() / 1048576)]);
        }

        $key = bin2hex(random_bytes(16));
        $path = $this->tempPath($key);
        @mkdir(dirname($path), 0700, true);
        $file->moveTo($path);

        $head = (string) file_get_contents($path, false, null, 0, 4);
        $zip = new ZipArchive();
        if (! Archive::looksLikeZip($head) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            @unlink($path);
            throw new Refused('ernestdefoe-panels.api.refused.not_archive');
        }

        try {
            $existing = Page::query()->where('chapter_id', $chapter->id)->where('segment', 0)->count();
            $found = $this->archive->inspect($zip, $existing);
        } catch (Refused $e) {
            $zip->close();
            @unlink($path);
            throw $e;
        }
        $zip->close();

        $import = new Import();
        $import->user_id = (int) $actor->id;
        $import->chapter_id = (int) $chapter->id;
        $import->temp_key = $key;
        $import->entries = $found['entries'];
        $import->total = count($found['entries']);
        $import->done = 0;
        $import->position_base = (int) Page::query()->where('chapter_id', $chapter->id)->max('position');
        $import->report = ['skipped' => $found['skipped'], 'refused' => [], 'read' => 0];
        $import->state = 'running';
        $import->save();

        return $import;
    }

    /**
     * Turn the next few entries into pages, until the time budget runs out. Safe to call again
     * after a closed tab: it picks up from `done`.
     */
    public function step(Import $import): Import
    {
        if ($import->state !== 'running') {
            return $import;
        }
        $chapter = Chapter::query()->findOrFail($import->chapter_id);
        $path = $this->tempPath($import->temp_key);
        $zip = new ZipArchive();
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            $import->state = 'failed';
            $import->save();

            return $import;
        }

        $started = microtime(true);
        $report = $import->report;
        $public = $this->wantsPublic($chapter);
        $limit = $this->pipeline->maxImageBytes();

        while ($import->done < $import->total && microtime(true) - $started < self::STEP_SECONDS) {
            [$index, $name] = $import->entries[$import->done];
            try {
                $bytes = $this->archive->read($zip, (int) $index, $limit);
                $report['read'] = (int) ($report['read'] ?? 0) + strlen($bytes);
                if ($report['read'] > $this->archive->maxChapterBytes()) {
                    throw new Refused('ernestdefoe-panels.api.refused.archive_size', ['mb' => (int) ($this->archive->maxChapterBytes() / 1048576)]);
                }
                $rows = $this->pipeline->page($bytes, (int) $chapter->series_id, (int) $chapter->id, $public);
                unset($bytes);
                $this->db->transaction(function () use ($chapter, $rows, $name) {
                    Chapter::query()->whereKey($chapter->id)->lockForUpdate()->first(['id']);
                    $this->save($chapter, $rows, (int) Page::query()->where('chapter_id', $chapter->id)->max('position') + 1, basename((string) $name));
                });
            } catch (Refused $e) {
                $report['refused'][] = ['name' => mb_substr(basename((string) $name), 0, 150), 'key' => $e->key, 'params' => $e->params];
                if ($e->key === 'ernestdefoe-panels.api.refused.archive_size') {
                    $import->done = $import->total;
                    break;
                }
            }
            $import->done++;
        }
        $zip->close();

        $import->report = $report;
        if ($import->done >= $import->total) {
            $import->state = 'done';
            @unlink($path);
            $this->renumber($chapter);
        }
        $import->save();
        $this->recount($chapter);

        return $import;
    }

    /** @param list<int> $ids page ids (first segments) in the new order */
    public function reorder(Chapter $chapter, array $ids): void
    {
        $pages = Page::query()->where('chapter_id', $chapter->id)->orderBy('position')->orderBy('segment')->get()->groupBy('position');
        $byFirst = [];
        foreach ($pages as $group) {
            $byFirst[(int) $group->first()->id] = $group;
        }
        $order = array_values(array_unique(array_merge(array_values(array_filter($ids, fn ($id) => isset($byFirst[(int) $id]))), array_keys($byFirst))));

        $this->db->transaction(function () use ($order, $byFirst) {
            foreach ($order as $i => $id) {
                foreach ($byFirst[(int) $id] as $page) {
                    Page::query()->whereKey($page->id)->update(['position' => $i + 1]);
                }
            }
        });
    }

    /** A page and the rest of its strip. */
    public function remove(Page $page): void
    {
        $chapter = $page->chapter;
        $group = Page::query()->where('chapter_id', $page->chapter_id)->where('position', $page->position)->get();
        foreach ($group as $row) {
            foreach ($row->variants as $path) {
                $this->store->disk($chapter->storage === 'public')->delete($path);
            }
            $row->delete();
        }
        $this->renumber($chapter);
        $this->recount($chapter);
    }

    /** Positions 1…n with no gaps, keeping the order. */
    public function renumber(Chapter $chapter): void
    {
        $positions = Page::query()->where('chapter_id', $chapter->id)->orderBy('position')->distinct()->pluck('position')->all();
        foreach ($positions as $i => $position) {
            if ((int) $position !== $i + 1) {
                Page::query()->where('chapter_id', $chapter->id)->where('position', $position)->update(['position' => $i + 1]);
            }
        }
    }

    public function recount(Chapter $chapter): void
    {
        $chapter->page_count = Page::query()->where('chapter_id', $chapter->id)->where('segment', 0)->count();
        $chapter->save();
    }

    /**
     * Put the chapter's files where its rules say: public static files for open chapters, the
     * private disk (signed URLs) for everything else. Returns whether anything moved.
     */
    public function relocate(Chapter $chapter, ?Series $series = null): bool
    {
        $series ??= $chapter->series;
        $want = $this->access->isOpen($chapter, $series) ? 'public' : 'private';
        if ($chapter->storage === $want) {
            return false;
        }

        Page::query()->where('chapter_id', $chapter->id)->get(['id', 'variants'])->each(function (Page $page) use ($want) {
            foreach ($page->variants as $path) {
                $this->store->move($path, $want === 'public');
            }
        });
        $chapter->storage = $want;
        $chapter->save();

        return true;
    }

    /**
     * The URL of every stored copy of these pages, for this reader. Built without a query: a
     * public chapter's files are static, a private chapter's get a signed token each.
     *
     * @param iterable<Page> $pages
     * @return list<array<string, mixed>>
     */
    public function present(Chapter $chapter, iterable $pages, User $actor): array
    {
        $public = $chapter->storage === 'public';
        $out = [];
        foreach ($pages as $page) {
            $sources = [];
            foreach ($page->variants as $width => $path) {
                $sources[(string) $width] = $public ? $this->store->publicUrl($path) : $this->store->signedUrl($path, (int) $actor->id);
            }
            $out[] = [
                'id' => (int) $page->id,
                'position' => (int) $page->position,
                'segment' => (int) $page->segment,
                'width' => (int) $page->width,
                'height' => (int) $page->height,
                'color' => $page->color,
                'alt' => $page->alt,
                'sources' => $sources,
            ];
        }

        return $out;
    }

    /** New pages go where the chapter's files are now; relocate() moves them all later. */
    private function wantsPublic(Chapter $chapter): bool
    {
        return $chapter->storage === 'public';
    }

    /**
     * @param list<array{segment:int, width:int, height:int, bytes:int, color:string, phash:int, variants:array<string,string>}> $rows
     * @return list<Page>
     */
    private function save(Chapter $chapter, array $rows, int $position, ?string $name): array
    {
        $saved = [];
        foreach ($rows as $row) {
            $page = new Page();
            $page->chapter_id = (int) $chapter->id;
            $page->position = $position;
            $page->segment = $row['segment'];
            $page->width = $row['width'];
            $page->height = $row['height'];
            $page->bytes = $row['bytes'];
            $page->color = $row['color'];
            $page->phash = $row['phash'];
            $page->variants = $row['variants'];
            $page->original_name = $name !== null ? mb_substr(preg_replace('~[^\p{L}\p{N} ._()-]+~u', '', basename($name)) ?? '', 0, 150) : null;
            $page->save();
            $saved[] = $page;
        }

        return $saved;
    }

    private function tempPath(string $key): string
    {
        return $this->paths->storage.'/tmp/panels/'.preg_replace('~[^0-9a-f]~', '', $key).'.zip';
    }

    /** Archives left behind by closed tabs. */
    public function purgeStaleImports(): void
    {
        Import::query()->where('updated_at', '<', Carbon::now()->subDay())->get()->each(function (Import $import) {
            @unlink($this->tempPath($import->temp_key));
            $import->delete();
        });
        Import::query()->whereIn('state', ['done', 'failed'])->where('updated_at', '<', Carbon::now()->subHour())->delete();
    }
}
