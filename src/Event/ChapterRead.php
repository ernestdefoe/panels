<?php

namespace ErnestDefoe\Panels\Event;

use ErnestDefoe\Panels\Model\Chapter;
use Flarum\User\User;

/**
 * A member finished a chapter for the first time (its last page was on screen). Fired once
 * per member and chapter, never for the chapter's own uploader.
 */
class ChapterRead
{
    public function __construct(public readonly Chapter $chapter, public readonly User $user)
    {
    }
}
