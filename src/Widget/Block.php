<?php

namespace ErnestDefoe\Panels\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A Panels widget as a Page Builder block. resolve() hands Page Builder nothing: the client
 * fetches /api/panels/… like every other host. Registered from extend.php only when Page
 * Builder is installed. Setting keys are the same as the widget's (js/src/forum/widgets).
 */
abstract class Block implements BlockInterface
{
    public function __construct(protected readonly TranslatorInterface $translator)
    {
    }

    abstract protected function widget(): string;

    /** @return list<array<string, mixed>> */
    protected function own(): array
    {
        return [];
    }

    protected function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-panels.lib.widget.'.$key);
    }

    public function type(): string
    {
        return 'panels-'.$this->widget();
    }

    public function name(): string
    {
        return $this->t($this->widget());
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaultSettings(): array
    {
        $out = [];
        foreach ($this->settingsSchema() as $field) {
            $out[$field['key']] = $field['default'];
        }

        return $out;
    }

    public function settingsSchema(): array
    {
        return $this->own();
    }

    /** @return list<array<string, mixed>> */
    protected function count(int $default): array
    {
        return [['key' => 'count', 'type' => 'number', 'label' => $this->t('count'), 'default' => $default]];
    }

    /** @return list<array<string, mixed>> */
    protected function format(): array
    {
        $options = [['value' => '', 'label' => $this->t('format_any')]];
        foreach (['manga', 'manhwa', 'manhua', 'webtoon', 'comic'] as $f) {
            $options[] = ['value' => $f, 'label' => $this->translator->trans('ernestdefoe-panels.lib.format.'.$f)];
        }

        return [['key' => 'format', 'type' => 'select', 'label' => $this->t('format'), 'default' => '', 'options' => $options]];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
