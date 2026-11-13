<?php

namespace ErnestDefoe\Panels\Api\Controller;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Import;
use ErnestDefoe\Panels\Model\Page;
use ErnestDefoe\Panels\Pages;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST   /api/panels/chapters/{id}/pages    images (pages[]), each accepted or refused on its own
 * PATCH  /api/panels/chapters/{id}/pages    {order: [pageId, …]}
 * DELETE /api/panels/pages/{id}             a page (and the rest of its strip)
 * POST   /api/panels/chapters/{id}/import   one .cbz / .zip (archive): checked, then kept for…
 * POST   /api/panels/imports/{id}/step      …the browser to call until it's done (4 s a step)
 */
class PagesController extends Controller
{
    /** Images per request; the browser sends a big drop in batches. */
    private const PER_REQUEST = 10;

    public function __construct(
        private readonly Access $access,
        private readonly Pages $pages,
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'panels.imports.step') {
            $import = Import::query()->findOrFail((int) $this->param($request, 'id'));
            if ((int) $import->user_id !== (int) $actor->id) {
                throw new PermissionDeniedException();
            }
            $this->editable(Chapter::query()->findOrFail($import->chapter_id), $actor);

            return $this->json(['import' => $this->import($this->pages->step($import))]);
        }

        if ($route === 'panels.pages.delete') {
            $page = Page::query()->findOrFail((int) $this->param($request, 'id'));
            $this->editable($page->chapter, $actor);
            $this->pages->remove($page);

            return $this->json(['deleted' => true]);
        }

        $chapter = Chapter::query()->findOrFail((int) $this->param($request, 'id'));
        $this->editable($chapter, $actor);

        if ($route === 'panels.pages.order') {
            $this->pages->reorder($chapter, array_map('intval', (array) ($this->body($request)['order'] ?? [])));

            return $this->json(['ok' => true]);
        }

        if ($route === 'panels.import') {
            $file = $request->getUploadedFiles()['archive'] ?? null;
            if (! $file instanceof UploadedFileInterface) {
                throw new Refused('ernestdefoe-panels.api.refused.incomplete');
            }

            return $this->json(['import' => $this->import($this->pages->startImport($chapter, $file, $actor))], 201);
        }

        $saved = [];
        $refused = [];
        $files = [];
        $uploaded = $request->getUploadedFiles();
        array_walk_recursive($uploaded, function ($f) use (&$files) {
            if ($f instanceof UploadedFileInterface) {
                $files[] = $f;
            }
        });
        foreach (array_slice($files, 0, self::PER_REQUEST) as $file) {
            $name = mb_substr(basename((string) $file->getClientFilename()), 0, 150);
            try {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new Refused('ernestdefoe-panels.api.refused.incomplete');
                }
                foreach ($this->pages->add($chapter, (string) $file->getStream()->getContents(), $name) as $page) {
                    $saved[] = ['id' => (int) $page->id, 'position' => (int) $page->position, 'segment' => (int) $page->segment, 'name' => $name];
                }
            } catch (Refused $e) {
                $refused[] = ['name' => $name, 'message' => $this->translator->trans($e->key, self::params($e->params))];
            }
        }

        return $this->json(['pages' => $saved, 'refused' => $refused, 'pageCount' => (int) $chapter->fresh()?->page_count], $saved ? 201 : 422);
    }

    private function editable(Chapter $chapter, User $actor): void
    {
        if (! $this->access->canEdit($chapter->series, $actor)) {
            throw new PermissionDeniedException();
        }
        // Pages of a chapter that's out stay put: take it back to a draft first.
        if ($chapter->discussion_id !== null && ! $this->access->isStaff($actor)) {
            throw new Refused('ernestdefoe-panels.api.refused.already_out');
        }
    }

    /** @return array<string, mixed> */
    private function import(Import $import): array
    {
        $report = $import->report;

        return [
            'id' => (int) $import->id,
            'state' => $import->state,
            'total' => (int) $import->total,
            'done' => (int) $import->done,
            'current' => $import->state === 'running' && isset($import->entries[$import->done]) ? basename((string) $import->entries[$import->done][1]) : null,
            'skipped' => $report['skipped'] ?? [],
            'refused' => array_map(fn ($r) => ['name' => $r['name'], 'message' => $this->translator->trans($r['key'], self::params($r['params'] ?? []))], $report['refused'] ?? []),
        ];
    }
}
