<?php

namespace ErnestDefoe\Panels\Api;

use ErnestDefoe\Panels\Media\Refused;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shared shape of Panels' API handlers: a Refused becomes a 422 with the sentence in the
 * member's language (and its key, for the browser to tell cases apart).
 */
abstract class Controller implements RequestHandlerInterface
{
    abstract protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->respond($request, RequestUtil::getActor($request));
        } catch (Refused $e) {
            return new JsonResponse(['error' => [
                'key' => $e->key,
                'message' => resolve(TranslatorInterface::class)->trans($e->key, self::params($e->params)),
            ]], 422);
        }
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, string|int>
     */
    public static function params(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out['{'.$k.'}'] = $v;
        }

        return $out;
    }

    protected function param(ServerRequestInterface $request, string $name): string
    {
        return (string) ($request->getAttribute('routeParameters')[$name] ?? '');
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
