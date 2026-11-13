<?php

namespace ErnestDefoe\Panels\Event;

use ErnestDefoe\Panels\Model\Chapter;

/**
 * A chapter went live (published, and its release time came). Fired once per chapter; Renown and Spoiler Shield listen.
 */
class ChapterPublished
{
    public function __construct(public readonly Chapter $chapter)
    {
    }
}
