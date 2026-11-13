<?php

namespace ErnestDefoe\Panels\Media;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Where pages live.
 *
 * Two disks: `ernestdefoe-panels` is public (served as plain static files, so a CDN can cache
 * them for a year) and holds open chapters and covers; `ernestdefoe-panels-private` is outside
 * the web root and holds everything else (Mature, paid, scheduled, drafts, takedowns), served
 * only through short-lived signed URLs.
 *
 * 🚨 A path never contains anything from an upload: `s/{series}/c/{chapter}/{sha1-12}-{width}.webp`.
 * Content-addressed, so a stored file never changes and caches forever.
 */
class Store
{
    public const PUBLIC = 'ernestdefoe-panels';
    public const PRIVATE = 'ernestdefoe-panels-private';

    /** How long a signed URL works. */
    public const TOKEN_TTL = 900;

    public function __construct(
        private readonly Factory $filesystem,
        private readonly UrlGenerator $url,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * 🚨 Explicitly public: left to the default, directories are 0700 and a web server running
     * as another user answers 403 for every page.
     *
     * @return array<string, string>
     */
    public static function publicConfig(Paths $paths, UrlGenerator $url): array
    {
        return [
            'root' => "$paths->public/assets/panels",
            'url' => $url->to('forum')->path('assets/panels'),
            'visibility' => 'public',
            'directory_visibility' => 'public',
        ];
    }

    /** @return array<string, string> */
    public static function privateConfig(Paths $paths): array
    {
        return [
            'root' => "$paths->storage/panels",
        ];
    }

    public function disk(bool $public): Filesystem
    {
        return $this->filesystem->disk($public ? self::PUBLIC : self::PRIVATE);
    }

    public static function chapterDir(int $seriesId, int $chapterId): string
    {
        return "s/$seriesId/c/$chapterId";
    }

    public static function pagePath(int $seriesId, int $chapterId, string $bytes, int $width, string $ext): string
    {
        return self::chapterDir($seriesId, $chapterId).'/'.substr(sha1($bytes), 0, 12)."-$width.$ext";
    }

    public static function coverPath(int $seriesId, string $bytes, string $size, string $ext): string
    {
        return "s/$seriesId/cover-".substr(sha1($bytes), 0, 16)."-$size.$ext";
    }

    public function put(bool $public, string $path, string $bytes): void
    {
        $this->disk($public)->put($path, $bytes);
    }

    public function publicUrl(string $path): string
    {
        $disk = $this->disk(true);

        return $disk instanceof Cloud ? $disk->url($path) : $this->url->to('forum')->path('assets/panels/'.$path);
    }

    /**
     * A short-lived URL for a private file, tied to one member (0 for guests).
     *
     * 🚨 No file extension at the end: nginx serves anything ending in .webp from disk and PHP
     * never sees the request.
     */
    public function signedUrl(string $path, int $userId): string
    {
        $disk = $this->disk(false);
        if ($disk instanceof Cloud && method_exists($disk, 'temporaryUrl') && $this->isRemote($disk)) {
            return $disk->temporaryUrl($path, Carbon::now()->addSeconds(self::TOKEN_TTL));
        }

        $payload = $this->b64(json_encode([$path, $userId, time() + self::TOKEN_TTL], JSON_UNESCAPED_SLASHES) ?: '');

        return $this->url->to('forum')->route('panels.image', ['token' => $payload.'.'.$this->sign($payload)]);
    }

    /**
     * The path a token grants, or null when it's forged, expired or someone else's.
     */
    public function verify(string $token, int $userId): ?string
    {
        [$payload, $mac] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals($this->sign($payload), $mac)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($data) || count($data) !== 3) {
            return null;
        }
        [$path, $owner, $expires] = $data;
        if ((int) $owner !== $userId || (int) $expires < time() || ! is_string($path) || ! preg_match('~^s/\d+/c/\d+/[0-9a-f]{12}-\d+\.(webp|jpg)$~D', $path)) {
            return null;
        }

        return $path;
    }

    public function move(string $path, bool $toPublic): void
    {
        $from = $this->disk(! $toPublic);
        $to = $this->disk($toPublic);
        if ($from->exists($path)) {
            $to->put($path, (string) $from->get($path));
            $from->delete($path);
        }
    }

    public function deleteChapter(int $seriesId, int $chapterId): void
    {
        foreach ([true, false] as $public) {
            $this->disk($public)->deleteDirectory(self::chapterDir($seriesId, $chapterId));
        }
    }

    public function deleteSeries(int $seriesId): void
    {
        foreach ([true, false] as $public) {
            $this->disk($public)->deleteDirectory("s/$seriesId");
        }
    }

    private function isRemote(Filesystem $disk): bool
    {
        // The local driver's temporaryUrl isn't available; anything else (S3, R2) signs its own.
        return ! ($disk instanceof \Illuminate\Filesystem\FilesystemAdapter && $disk->getAdapter() instanceof \League\Flysystem\Local\LocalFilesystemAdapter);
    }

    private function sign(string $payload): string
    {
        return $this->b64(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** A random key made once and kept server-side (never sent to the forum). */
    private function secret(): string
    {
        $secret = (string) $this->settings->get('ernestdefoe-panels.secret');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->settings->set('ernestdefoe-panels.secret', $secret);
        }

        return $secret;
    }
}
