<?php

namespace ErnestDefoe\Panels\Http;

use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Model\Chapter;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /panels/img/{token}: one private page, for the member the token was made for.
 *
 * The access check happened when the reader asked for the chapter; the token (HMAC, 15
 * minutes, tied to the member) carries that decision. One query here still refuses a chapter
 * taken down in the meantime.
 *
 * With `x_sendfile` set, the web server sends the file (nginx X-Accel-Redirect to an internal
 * location, or X-Sendfile for Apache) and PHP doesn't hold the connection open.
 */
class ImageController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Store $store,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $token = (string) ($request->getAttribute('routeParameters')['token'] ?? '');
        $path = $this->store->verify($token, (int) $actor->id);
        if ($path === null) {
            return new EmptyResponse(403);
        }

        preg_match('~^s/\d+/c/(\d+)/~', $path, $m);
        $chapter = Chapter::query()->find((int) ($m[1] ?? 0), ['id', 'state']);
        if (! $chapter || ($chapter->state === 'removed' && ! $actor->hasPermission('panels.moderate'))) {
            return new EmptyResponse(410);
        }

        $disk = $this->store->disk(false);
        if (! $disk->exists($path)) {
            return new EmptyResponse(404);
        }

        $headers = [
            'Content-Type' => str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=600',
            'Content-Security-Policy' => "default-src 'none'",
            'Referrer-Policy' => 'same-origin',
        ];

        $mode = (string) $this->settings->get('ernestdefoe-panels.x_sendfile');
        if ($mode === 'nginx') {
            $prefix = rtrim((string) ($this->settings->get('ernestdefoe-panels.x_accel_prefix') ?: '/panels-private'), '/');

            return new EmptyResponse(200, $headers + ['X-Accel-Redirect' => $prefix.'/'.$path]);
        }
        if ($mode === 'apache') {
            return new EmptyResponse(200, $headers + ['X-Sendfile' => $disk->path($path)]);
        }

        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return new EmptyResponse(404);
        }

        return new Response(new Stream($stream), 200, $headers);
    }
}
