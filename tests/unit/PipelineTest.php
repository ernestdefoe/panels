<?php

namespace ErnestDefoe\Panels\Tests\unit;

use ErnestDefoe\Panels\Media\Pipeline;
use ErnestDefoe\Panels\Media\Refused;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Support.php';

/**
 * What reaches the disk: re-encoded pixels only.
 */
class PipelineTest extends TestCase
{
    private CapturingStore $store;

    private function pipeline(array $settings = []): Pipeline
    {
        $this->store = new CapturingStore();

        return new Pipeline($this->store, new MemorySettings($settings));
    }

    private function image(int $w, int $h, string $type = 'png'): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, (int) ($w / 2), $h, (int) imagecolorallocate($img, 200, 40, 30));
        ob_start();
        $type === 'jpeg' ? imagejpeg($img) : imagepng($img);

        return (string) ob_get_clean();
    }

    #[Test]
    public function a_polyglot_comes_out_as_plain_pixels(): void
    {
        // A real PNG with a PHP script and an HTML page appended: a classic polyglot.
        $evil = $this->image(300, 400).'<?php system($_GET["c"]); ?><script>alert(1)</script>PK';

        $pages = $this->pipeline()->page($evil, 1, 2, true);

        $this->assertCount(1, $pages);
        foreach ($this->store->files as $bytes) {
            $this->assertStringNotContainsString('<?php', $bytes);
            $this->assertStringNotContainsString('<script', $bytes);
            $this->assertStringStartsWith('RIFF', $bytes, 'stored as WebP');
        }
    }

    #[Test]
    public function exif_and_location_data_are_stripped(): void
    {
        $jpeg = $this->image(300, 400, 'jpeg');
        // An APP1 Exif segment with a GPS marker, after the SOI marker.
        $payload = "Exif\0\0".'GPSLatitude 51.5074 N, GPSLongitude 0.1278 W, Camera SECRET-SERIAL-1234';
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $jpeg = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
        $this->assertStringContainsString('SECRET-SERIAL', $jpeg);

        $this->pipeline()->page($jpeg, 1, 2, true);

        foreach ($this->store->files as $bytes) {
            $this->assertStringNotContainsString('SECRET-SERIAL', $bytes);
            $this->assertStringNotContainsString('GPSLatitude', $bytes);
            $this->assertStringNotContainsString('Exif', $bytes);
        }
    }

    #[Test]
    public function a_pixel_bomb_is_refused_before_it_is_decoded(): void
    {
        // A tiny PNG whose header claims 40,000 × 40,000 (1.6 gigapixels).
        $png = $this->image(10, 10);
        $ihdr = pack('N', 40000).pack('N', 40000).substr($png, 24, 5);
        $png = substr($png, 0, 16).$ihdr.pack('N', crc32('IHDR'.$ihdr)).substr($png, 33);

        try {
            $this->pipeline()->page($png, 1, 2, true);
            $this->fail('A 40,000 × 40,000 header was decoded.');
        } catch (Refused $e) {
            $this->assertSame('ernestdefoe-panels.api.refused.too_many_pixels', $e->key);
            $this->assertSame([], $this->store->files);
        }
    }

    #[Test]
    public function svg_and_html_are_not_images(): void
    {
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<html><body>hi</body></html>', 'GIF89a<?php ?>'] as $bytes) {
            try {
                $this->pipeline()->page($bytes, 1, 2, true);
                $this->fail('Accepted: '.substr($bytes, 0, 20));
            } catch (Refused $e) {
                $this->assertContains($e->key, ['ernestdefoe-panels.api.refused.not_image', 'ernestdefoe-panels.api.refused.damaged', 'ernestdefoe-panels.api.refused.too_many_pixels']);
            }
        }
    }

    #[Test]
    public function the_file_size_limit_is_checked_first(): void
    {
        $this->expectException(Refused::class);
        $this->pipeline(['ernestdefoe-panels.max_image_mb' => 1])->page(str_repeat('x', 2 * 1024 * 1024), 1, 2, true);
    }

    #[Test]
    public function a_tall_strip_becomes_segments_and_widths_are_never_upscaled(): void
    {
        $pages = $this->pipeline()->page($this->image(800, 12000), 1, 2, true);

        $this->assertCount(3, $pages);
        $this->assertSame([0, 1, 2], array_column($pages, 'segment'));
        foreach ($pages as $page) {
            $this->assertSame(['720', '800'], array_map('strval', array_keys($page['variants'])));
            $this->assertLessThanOrEqual(4000, $page['height']);
        }
    }

    #[Test]
    public function paths_never_carry_the_upload_name(): void
    {
        $pages = $this->pipeline()->page($this->image(2000, 2800), 7, 9, true);

        foreach ($pages[0]['variants'] as $width => $path) {
            $this->assertMatchesRegularExpression('~^s/7/c/9/[0-9a-f]{12}-'.$width.'\.(webp|jpg)$~', $path);
        }
        $this->assertSame(['720', '1080', '1600'], array_map('strval', array_keys($pages[0]['variants'])));
    }

    #[Test]
    public function similar_pages_have_close_hashes(): void
    {
        $a = imagecreatefromstring($this->image(600, 800));
        $b = imagescale($a, 300, 400);
        $c = imagecreatetruecolor(600, 800);
        imagefilledrectangle($c, 300, 0, 600, 800, (int) imagecolorallocate($c, 255, 255, 255));

        $this->assertLessThanOrEqual(6, Pipeline::distance(Pipeline::dhash($a), Pipeline::dhash($b)));
        $this->assertGreaterThan(6, Pipeline::distance(Pipeline::dhash($a), Pipeline::dhash($c)));
    }

    #[Test]
    public function a_cover_is_stored_at_three_widths_plus_a_blurred_stand_in(): void
    {
        $cover = $this->pipeline()->cover($this->image(600, 800), 3);

        $this->assertMatchesRegularExpression('~^s/3/cover-[0-9a-f]{16}-480\.(webp|jpg)$~', $cover['key']);
        foreach (['240', '480', '960', 'blur'] as $size) {
            $this->assertArrayHasKey(str_replace('-480.', "-$size.", $cover['key']), $this->store->files);
        }
    }
}
