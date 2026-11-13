<?php

namespace ErnestDefoe\Panels\Api\Controller;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Media\Pipeline;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Model\Series;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/** POST /api/panels/series/{id}/cover: a new cover, re-encoded like every page. */
class CoverController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly Pipeline $pipeline,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $series = Series::query()->findOrFail((int) $this->param($request, 'id'));
        if (! $this->access->canEdit($series, $actor)) {
            throw new PermissionDeniedException();
        }

        $file = $request->getUploadedFiles()['cover'] ?? null;
        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new Refused('ernestdefoe-panels.api.refused.incomplete');
        }
        if ((int) $file->getSize() > $this->pipeline->maxImageBytes()) {
            throw new Refused('ernestdefoe-panels.api.refused.too_big', ['mb' => (int) ($this->pipeline->maxImageBytes() / 1048576)]);
        }

        $cover = $this->pipeline->cover((string) $file->getStream()->getContents(), (int) $series->id);
        $series->cover_key = $cover['key'];
        $series->cover_color = $cover['color'];
        $series->save();

        return $this->json(['cover' => $this->presenter->cover($series, $actor), 'coverColor' => $series->cover_color]);
    }
}
