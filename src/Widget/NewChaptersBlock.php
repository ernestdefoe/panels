<?php

namespace ErnestDefoe\Panels\Widget;

class NewChaptersBlock extends Block
{
    protected function widget(): string
    {
        return 'new';
    }

    public function icon(): string
    {
        return 'fas fa-bolt';
    }

    protected function own(): array
    {
        return [...$this->count(6), ...$this->format(), [
            'key' => 'layout', 'type' => 'select', 'label' => $this->t('layout'), 'default' => 'list',
            'options' => [['value' => 'list', 'label' => $this->t('layout_list')], ['value' => 'covers', 'label' => $this->t('layout_covers')]],
        ]];
    }
}
