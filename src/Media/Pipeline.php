<?php

namespace ErnestDefoe\Panels\Media;

use Flarum\Settings\SettingsRepositoryInterface;
use GdImage;

/**
 * One image, from untrusted bytes to stored WebP copies.
 *
 * 🚨 Nothing the upload says is believed. The type comes from the file's own bytes, the size
 * is checked in pixels before anything is decoded (a 2 MB PNG can be 30,000 × 30,000 and take
 * the PHP worker with it), and every page is decoded and re-encoded: that strips EXIF, GPS,
 * XMP and comments, and turns a polyglot (an image that is also a script or an archive) into
 * plain pixels. The original bytes are never stored or served.
 *
 * Plain GD, like Atrium: no image library a forum might already hold at another version.
 */
class Pipeline
{
    /** The widths stored, never upscaled. */
    public const WIDTHS = [720, 1080, 1600];

    /** A strip taller than this (at the stored width) is cut into segments… */
    public const SEGMENT_OVER = 5000;

    /** …of at most this height. iOS Safari drops or blurs images much taller than this. */
    public const SEGMENT_MAX = 4000;

    private const COVER_WIDTHS = ['240' => 240, '480' => 480, '960' => 960];

    private ?GdImage $mark = null;
    private bool $markLoaded = false;

    public function __construct(
        private readonly Store $store,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function maxImageBytes(): int
    {
        return max(1, (int) ($this->settings->get('ernestdefoe-panels.max_image_mb') ?: 20)) * 1024 * 1024;
    }

    public function maxPixels(): int
    {
        return max(1, (int) ($this->settings->get('ernestdefoe-panels.max_megapixels') ?: 60)) * 1_000_000;
    }

    public static function ext(): string
    {
        return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false) ? 'webp' : 'jpg';
    }

    /**
     * Store one page (or several, for a tall strip) and return the rows' attributes.
     *
     * @return list<array{segment:int, width:int, height:int, bytes:int, color:string, phash:int, variants:array<string,string>}>
     */
    public function page(string $bytes, int $seriesId, int $chapterId, bool $public): array
    {
        $source = $this->decode($bytes);
        $width = imagesx($source);
        $height = imagesy($source);

        $stored = min($width, max(self::WIDTHS));
        $scaledHeight = (int) round($height * $stored / $width);
        $segments = $scaledHeight > self::SEGMENT_OVER ? (int) ceil($scaledHeight / self::SEGMENT_MAX) : 1;
        $slice = (int) ceil($height / $segments);

        $pages = [];
        for ($i = 0; $i < $segments; $i++) {
            $y = $i * $slice;
            $h = min($slice, $height - $y);
            if ($h <= 0) {
                break;
            }
            $piece = $segments === 1 ? $source : imagecrop($source, ['x' => 0, 'y' => $y, 'width' => $width, 'height' => $h]);
            if (! $piece) {
                throw new Refused('ernestdefoe-panels.api.refused.damaged');
            }
            $pages[] = ['segment' => $i] + $this->storePiece($piece, $seriesId, $chapterId, $public);
            if ($piece !== $source) {
                unset($piece);
            }
        }
        unset($source);

        return $pages;
    }

    /**
     * A series cover: 240/480/960 wide, plus a blurred stand-in for readers who may not see a
     * Mature cover. Returns the 480 path; the others follow from it.
     *
     * @return array{key:string, color:string}
     */
    public function cover(string $bytes, int $seriesId): array
    {
        $source = $this->decode($bytes);
        $ext = self::ext();
        $key = '';
        foreach (self::COVER_WIDTHS as $name => $w) {
            // PHP turns the key '480' into the integer 480: compare as strings.
            $name = (string) $name;
            $copy = $this->resize($source, min($w, imagesx($source)));
            $out = $this->encode($copy, 82);
            $path = Store::coverPath($seriesId, $bytes, $name, $ext);
            $this->store->put(true, $path, $out);
            if ($name === '480') {
                $key = $path;
            }
            unset($copy);
        }

        // The stand-in: shrunk to almost nothing, then blown back up and blurred.
        $tiny = $this->resize($source, 12);
        $blur = $this->resize($tiny, 240);
        for ($i = 0; $i < 12; $i++) {
            imagefilter($blur, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $this->store->put(true, Store::coverPath($seriesId, $bytes, 'blur', $ext), $this->encode($blur, 60));
        $color = $this->color($source);
        unset($tiny);
        unset($blur);
        unset($source);

        return ['key' => $key, 'color' => $color];
    }

    /** Sniffed, size-checked, decoded and flattened onto white. */
    private function decode(string $bytes): GdImage
    {
        if (strlen($bytes) > $this->maxImageBytes()) {
            throw new Refused('ernestdefoe-panels.api.refused.too_big', ['mb' => (int) round($this->maxImageBytes() / 1048576)]);
        }

        $info = @getimagesizefromstring($bytes);
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (defined('IMAGETYPE_AVIF') && function_exists('imagecreatefromavif')) {
            $allowed[] = IMAGETYPE_AVIF;
        }
        if (! $info || ! in_array($info[2], $allowed, true) || $info[0] < 1 || $info[1] < 1) {
            throw new Refused('ernestdefoe-panels.api.refused.not_image');
        }

        [$width, $height] = $info;
        if ($width * $height > $this->maxPixels()) {
            throw new Refused('ernestdefoe-panels.api.refused.too_many_pixels', [
                'width' => $width, 'height' => $height, 'limit' => (int) ($this->maxPixels() / 1_000_000),
            ]);
        }
        $this->ensureMemory($width * $height);

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new Refused('ernestdefoe-panels.api.refused.damaged');
        }

        // Transparency goes onto white (a page is read on paper), and palettes become true colour.
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
        unset($image);

        return $flat;
    }

    /**
     * @return array{width:int, height:int, bytes:int, color:string, phash:int, variants:array<string,string>}
     */
    private function storePiece(GdImage $piece, int $seriesId, int $chapterId, bool $public): array
    {
        $width = imagesx($piece);
        $height = imagesy($piece);
        $ext = self::ext();
        $quality = (int) ($this->settings->get('ernestdefoe-panels.quality') ?: 80);

        $widths = array_filter(self::WIDTHS, fn (int $w) => $w < $width);
        $widths[] = min($width, max(self::WIDTHS));
        $widths = array_values(array_unique($widths));
        sort($widths);

        $variants = [];
        $bytes = 0;
        foreach ($widths as $w) {
            $copy = $this->resize($piece, $w);
            $this->watermark($copy);
            $out = $this->encode($copy, $quality);
            unset($copy);
            $path = Store::pagePath($seriesId, $chapterId, $out, $w, $ext);
            $this->store->put($public, $path, $out);
            $variants[(string) $w] = $path;
            $bytes = strlen($out);
        }

        return [
            'width' => min($width, max(self::WIDTHS)),
            'height' => (int) round($height * min($width, max(self::WIDTHS)) / $width),
            'bytes' => $bytes,
            'color' => $this->color($piece),
            'phash' => self::dhash($piece),
            'variants' => $variants,
        ];
    }

    /** Always a new image, so the caller can mark or destroy it freely. */
    private function resize(GdImage $image, int $width): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $height = max(1, (int) round($h * $width / $w));
        $copy = imagecreatetruecolor($width, $height);
        imagecopyresampled($copy, $image, 0, 0, 0, 0, $width, $height, $w, $h);

        return $copy;
    }

    private function encode(GdImage $image, int $quality): string
    {
        ob_start();
        self::ext() === 'webp' ? imagewebp($image, null, $quality) : imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function color(GdImage $image): string
    {
        $dot = imagecreatetruecolor(1, 1);
        imagecopyresampled($dot, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = imagecolorat($dot, 0, 0);
        unset($dot);

        return sprintf('#%06x', $rgb & 0xFFFFFF);
    }

    /**
     * A 64-bit difference hash: the page shrunk to 9 × 8 greys, one bit per neighbour pair.
     * Re-encoded, resized or lightly edited copies of a page land a few bits apart.
     */
    public static function dhash(GdImage $image): int
    {
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
        $hash = 0;
        $bit = 0;
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $grey = (($rgb >> 16) & 0xFF) * 299 + (($rgb >> 8) & 0xFF) * 587 + ($rgb & 0xFF) * 114;
                if ($prev !== null) {
                    if ($prev > $grey) {
                        $hash |= 1 << $bit;
                    }
                    $bit++;
                }
                $prev = $grey;
            }
        }
        unset($small);

        return $hash;
    }

    public static function distance(int $a, int $b): int
    {
        return substr_count(decbin($a ^ $b), '1');
    }

    /** The admin's mark, burnt into every stored copy when switched on. */
    private function watermark(GdImage $image): void
    {
        if (! $this->settings->get('ernestdefoe-panels.watermark')) {
            return;
        }
        if (! $this->markLoaded) {
            $this->markLoaded = true;
            $disk = $this->store->disk(false);
            $png = $disk->exists('watermark.png') ? (string) $disk->get('watermark.png') : '';
            $mark = $png !== '' ? @imagecreatefromstring($png) : false;
            $this->mark = $mark instanceof GdImage ? $mark : null;
        }
        if (! $this->mark) {
            return;
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $size = max(5, min(50, (int) ($this->settings->get('ernestdefoe-panels.watermark_size') ?: 18)));
        $mw = max(1, (int) round($w * $size / 100));
        $mh = max(1, (int) round(imagesy($this->mark) * $mw / imagesx($this->mark)));
        $scaled = imagecreatetruecolor($mw, $mh);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $this->mark, 0, 0, 0, 0, $mw, $mh, imagesx($this->mark), imagesy($this->mark));

        // Fade it: scale every pixel's alpha by the opacity.
        $opacity = max(5, min(100, (int) ($this->settings->get('ernestdefoe-panels.watermark_opacity') ?: 40))) / 100;
        for ($y = 0; $y < $mh; $y++) {
            for ($x = 0; $x < $mw; $x++) {
                $c = imagecolorat($scaled, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                $alpha = 127 - (int) round((127 - $alpha) * $opacity);
                imagesetpixel($scaled, $x, $y, ($alpha << 24) | ($c & 0xFFFFFF));
            }
        }

        $pad = (int) round($w * 0.02);
        [$x, $y] = match ((string) $this->settings->get('ernestdefoe-panels.watermark_position')) {
            'tl' => [$pad, $pad],
            'tr' => [$w - $mw - $pad, $pad],
            'bl' => [$pad, $h - $mh - $pad],
            'center' => [(int) (($w - $mw) / 2), (int) (($h - $mh) / 2)],
            default => [$w - $mw - $pad, $h - $mh - $pad],
        };
        imagealphablending($image, true);
        imagecopy($image, $scaled, max(0, $x), max(0, $y), 0, 0, $mw, $mh);
        unset($scaled);
    }

    /**
     * GD needs about 5 bytes a pixel while it works. Ask for more memory when the limit is
     * close, and refuse the file (rather than die mid-request) when the server won't give it.
     */
    private function ensureMemory(int $pixels): void
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return;
        }
        $need = memory_get_usage(true) + $pixels * 5 + 48 * 1024 * 1024;
        if ($need <= $limit) {
            return;
        }
        if (@ini_set('memory_limit', (string) $need) === false || self::bytes((string) ini_get('memory_limit')) < $need) {
            throw new Refused('ernestdefoe-panels.api.refused.server_memory');
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
