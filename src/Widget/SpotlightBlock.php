<?php

namespace ErnestDefoe\Panels\Widget;

class SpotlightBlock extends Block
{
    protected function widget(): string
    {
        return 'spotlight';
    }

    public function icon(): string
    {
        return 'fas fa-star';
    }

    protected function own(): array
    {
        return [];
    }
}
