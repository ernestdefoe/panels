<?php

namespace ErnestDefoe\Panels\Widget;

class TrendingBlock extends Block
{
    protected function widget(): string
    {
        return 'trending';
    }

    public function icon(): string
    {
        return 'fas fa-fire';
    }

    protected function own(): array
    {
        return [...$this->count(5), ...$this->format(), [
            'key' => 'period', 'type' => 'select', 'label' => $this->t('period'), 'default' => 'week',
            'options' => [['value' => 'week', 'label' => $this->t('period_week')], ['value' => 'all', 'label' => $this->t('period_all')]],
        ]];
    }
}
