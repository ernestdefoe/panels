<?php

namespace ErnestDefoe\Panels\Media;

use RuntimeException;

/**
 * A file we won't take, with a translation key and its parameters so the reason reaches the
 * uploader in their language, next to the file's name.
 */
class Refused extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
