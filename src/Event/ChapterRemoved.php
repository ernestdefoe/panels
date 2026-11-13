<?php

namespace ErnestDefoe\Panels\Event;

use ErnestDefoe\Panels\Model\Chapter;

/**
 * A chapter was taken down (removed, or disabled by a copyright claim). Renown takes back what publishing it earned.
 */
class ChapterRemoved
{
    public function __construct(public readonly Chapter $chapter)
    {
    }
}
