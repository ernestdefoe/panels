<?php

namespace ErnestDefoe\Panels\Widget;

class ContinueReadingBlock extends Block
{
    protected function widget(): string
    {
        return 'continue';
    }

    public function icon(): string
    {
        return 'fas fa-book-reader';
    }

    protected function own(): array
    {
        return $this->count(4);
    }
}
