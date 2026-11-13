<?php

namespace ErnestDefoe\Panels\Widget;

class StatsBlock extends Block
{
    protected function widget(): string
    {
        return 'stats';
    }

    public function icon(): string
    {
        return 'fas fa-chart-simple';
    }

    protected function own(): array
    {
        return [];
    }
}
