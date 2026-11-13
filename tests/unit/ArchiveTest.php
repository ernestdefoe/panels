<?php

namespace ErnestDefoe\Panels\Tests\unit;

use ErnestDefoe\Panels\Media\Archive;
use ErnestDefoe\Panels\Media\Refused;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once __DIR__.'/Support.php';

/**
 * The archive checks, against hand-made hostile archives.
 */
class ArchiveTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
    }

    /** @param callable(ZipArchive): void $fill */
    private function zip(callable $fill): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'panels-test').'.zip';
        $this->temp[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $fill($zip);
        $zip->close();
        $read = new ZipArchive();
        $read->open($path, ZipArchive::RDONLY);

        return $read;
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(20, 30);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function archive(array $settings = []): Archive
    {
        return new Archive(new MemorySettings($settings));
    }

    #[Test]
    public function zip_slip_names_are_never_pages(): void
    {
        $zip = $this->zip(function (ZipArchive $z) {
            $z->addFromString('page01.png', $this->png());
            $z->addFromString('../../public/evil.png', $this->png());
            $z->addFromString('/etc/evil.png', $this->png());
            $z->addFromString('ok/../../../evil2.png', $this->png());
            $z->addFromString('C:/Windows/evil3.png', $this->png());
        });

        $found = $this->archive()->inspect($zip);

        $this->assertSame(['page01.png'], array_column($found['entries'], 1));
        $this->assertCount(4, $found['skipped']);
    }

    #[Test]
    public function symlinks_are_skipped(): void
    {
        $zip = $this->zip(function (ZipArchive $z) {
            $z->addFromString('page01.png', $this->png());
            $z->addFromString('link.png', '/etc/passwd');
            $z->setExternalAttributesName('link.png', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });

        $found = $this->archive()->inspect($zip);

        $this->assertSame(['page01.png'], array_column($found['entries'], 1));
        $this->assertSame(['link.png'], $found['skipped']);
    }

    #[Test]
    public function a_decompression_bomb_refuses_the_whole_archive(): void
    {
        $zip = $this->zip(function (ZipArchive $z) {
            $z->addFromString('page01.png', $this->png());
            $z->addFromString('page02.png', str_repeat("\0", 30 * 1024 * 1024));
        });

        try {
            $this->archive()->inspect($zip);
            $this->fail('A 30 MB entry packed into a few kilobytes was accepted.');
        } catch (Refused $e) {
            $this->assertSame('ernestdefoe-panels.api.refused.archive_ratio', $e->key);
        }
    }

    #[Test]
    public function the_unpacked_total_is_capped(): void
    {
        $zip = $this->zip(function (ZipArchive $z) {
            for ($i = 1; $i <= 3; $i++) {
                $z->addFromString("page$i.png", random_bytes(600 * 1024));
                $z->setCompressionName("page$i.png", ZipArchive::CM_STORE);
            }
        });

        $this->expectException(Refused::class);
        $this->archive(['ernestdefoe-panels.max_chapter_mb' => 1])->inspect($zip);
    }

    #[Test]
    public function too_many_entries_are_refused_before_reading_any(): void
    {
        $zip = $this->zip(function (ZipArchive $z) {
            for ($i = 0; $i < 70; $i++) {
                $z->addFromString("p$i.png", 'x');
            }
        });

        try {
            $this->archive(['ernestdefoe-panels.max_pages' => 10])->inspect($zip);
            $this->fail('70 entries passed a 10-page limit.');
        } catch (Refused $e) {
            $this->assertSame('ernestdefoe-panels.api.refused.archive_entries', $e->key);
        }
    }

    #[Test]
    public function junk_is_skipped_and_pages_come_in_natural_order(): void
    {
        $zip = $this->zip(function (ZipArchive $z) {
            foreach (['page10.png', 'page2.png', 'page1.png', '__MACOSX/._page1.png', '._page2.png', 'Thumbs.db', 'credits.txt', 'inner.cbz', '.DS_Store'] as $name) {
                $z->addFromString($name, $this->png());
            }
        });

        $found = $this->archive()->inspect($zip);

        $this->assertSame(['page1.png', 'page2.png', 'page10.png'], array_column($found['entries'], 1));
        $this->assertCount(6, $found['skipped']);
    }

    #[Test]
    public function reading_an_entry_stops_at_the_limit_whatever_the_header_says(): void
    {
        $zip = $this->zip(fn (ZipArchive $z) => $z->addFromString('page1.png', random_bytes(300 * 1024)));

        $this->expectException(Refused::class);
        $this->archive()->read($zip, 0, 100 * 1024);
    }

    #[Test]
    public function a_file_is_a_zip_by_its_bytes_not_its_name(): void
    {
        $this->assertTrue(Archive::looksLikeZip("PK\x03\x04rest"));
        $this->assertFalse(Archive::looksLikeZip('Rar!'));
        $this->assertFalse(Archive::looksLikeZip('<?php'));
    }
}
