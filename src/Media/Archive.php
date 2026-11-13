<?php

namespace ErnestDefoe\Panels\Media;

use Flarum\Settings\SettingsRepositoryInterface;
use ZipArchive;

/**
 * Reading a .cbz / .zip that a stranger uploaded.
 *
 * 🚨 Never extractTo(). Entries are read one at a time into memory; the entry's name is used
 * only to put pages in order, so a zip-slip name (../../public/index.php, /etc/passwd) can't
 * write anywhere. Symlinks, nested archives, macOS resource forks and anything that isn't an
 * image are skipped and listed back to the uploader.
 *
 * 🚨 Decompression bombs, three ways: the entry count is capped; the sizes the archive claims
 * are summed against the chapter limit and any entry packed tighter than 100:1 refuses the
 * whole archive; and the header isn't trusted, so every stream is read through a counter
 * that stops at the per-image limit (Importer::read).
 */
class Archive
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    public const MAX_RATIO = 100;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function maxPages(): int
    {
        return max(1, (int) ($this->settings->get('ernestdefoe-panels.max_pages') ?: 400));
    }

    public function maxChapterBytes(): int
    {
        return max(1, (int) ($this->settings->get('ernestdefoe-panels.max_chapter_mb') ?: 500)) * 1024 * 1024;
    }

    public function maxArchiveBytes(): int
    {
        return max(1, (int) ($this->settings->get('ernestdefoe-panels.max_archive_mb') ?: 500)) * 1024 * 1024;
    }

    /**
     * Which entries are pages, in reading order, and what was left out.
     *
     * @return array{entries: list<array{0:int, 1:string}>, skipped: list<string>, bytes: int}
     */
    public function inspect(ZipArchive $zip, int $pagesAlready = 0): array
    {
        $room = $this->maxPages() - $pagesAlready;
        if ($zip->numFiles > $this->maxPages() + 50) {
            throw new Refused('ernestdefoe-panels.api.refused.archive_entries', ['limit' => $this->maxPages()]);
        }

        $entries = [];
        $skipped = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new Refused('ernestdefoe-panels.api.refused.damaged_archive');
            }
            $name = (string) $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }

            $base = basename(str_replace('\\', '/', $name));
            if (
                self::unsafeName($name)
                || self::isSymlink($zip, $i)
                || str_starts_with($name, '__MACOSX/') || str_contains($name, '/__MACOSX/')
                || str_starts_with($base, '._') || $base === '.DS_Store' || strcasecmp($base, 'Thumbs.db') === 0
                || ! in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)
            ) {
                $skipped[] = mb_substr($name, 0, 150);
                continue;
            }

            $size = (int) $stat['size'];
            $packed = (int) $stat['comp_size'];
            if ($size > 0 && ($packed <= 0 || $size / $packed > self::MAX_RATIO)) {
                throw new Refused('ernestdefoe-panels.api.refused.archive_ratio');
            }
            $total += $size;
            if ($total > $this->maxChapterBytes()) {
                throw new Refused('ernestdefoe-panels.api.refused.archive_size', ['mb' => (int) ($this->maxChapterBytes() / 1048576)]);
            }
            $entries[] = [$i, $name];
        }

        if (! $entries) {
            throw new Refused('ernestdefoe-panels.api.refused.archive_empty');
        }
        if (count($entries) > $room) {
            throw new Refused('ernestdefoe-panels.api.refused.too_many_pages', ['limit' => $this->maxPages()]);
        }

        // page2 before page10, folders (volumes) in order.
        usort($entries, fn ($a, $b) => strnatcasecmp($a[1], $b[1]));

        return ['entries' => $entries, 'skipped' => $skipped, 'bytes' => $total];
    }

    /**
     * One entry's bytes, through a counter: the size in the archive's header is the
     * uploader's claim, not a fact.
     */
    public function read(ZipArchive $zip, int $index, int $limit): string
    {
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            throw new Refused('ernestdefoe-panels.api.refused.damaged');
        }
        $data = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    break;
                }
                $data .= $chunk;
                if (strlen($data) > $limit) {
                    throw new Refused('ernestdefoe-panels.api.refused.too_big', ['mb' => (int) round($limit / 1048576)]);
                }
            }
        } finally {
            fclose($stream);
        }

        return $data;
    }

    /** Absolute, drive-lettered, or climbing out with "..": never a page, whatever it holds. */
    public static function unsafeName(string $name): bool
    {
        $name = str_replace('\\', '/', $name);

        return str_starts_with($name, '/')
            || preg_match('~^[a-zA-Z]:~', $name) === 1
            || in_array('..', explode('/', $name), true)
            || str_contains($name, "\0");
    }

    public static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0xF000) === 0xA000;
    }

    /** "PK\3\4": a zip by its own bytes, whatever the file was called. */
    public static function looksLikeZip(string $head): bool
    {
        return str_starts_with($head, "PK\x03\x04");
    }
}
