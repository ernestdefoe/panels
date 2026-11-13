<?php

namespace ErnestDefoe\Panels\Kiosk;

use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Pages;
use Ernestdefoe\Kiosk\Unlock\UnlockType;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/** One chapter sold through Kiosk. Selling a free chapter makes it paid (and its files private). */
class ChapterUnlock implements UnlockType
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly Pages $pages,
    ) {
    }

    public function label(): string
    {
        return 'ernestdefoe-panels.lib.kiosk.chapter';
    }

    public function icon(): string
    {
        return 'fas fa-file-image';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return Chapter::query()->with('series:id,title')->whereNull('removed_at')
            ->when($query !== '', fn ($q) => $q->whereHas('series', fn ($s) => $s->where('title', 'like', '%'.addcslashes($query, '%_\\').'%')))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Chapter $c) => ['id' => (int) $c->id, 'label' => $this->name($c)])->all();
    }

    public function describe(array $ids): array
    {
        return Chapter::query()->with('series:id,title,slug')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Chapter $c) => [(int) $c->id => [
                'label' => $this->name($c),
                'url' => $this->url->to('forum')->route('panels.reader', ['slug' => $c->series->slug, 'chapter' => $c->label()]),
            ]])->all();
    }

    public function grant(User $user, int $targetId): void
    {
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    public function prepare(int $targetId): void
    {
        $chapter = Chapter::query()->find($targetId);
        if ($chapter && $chapter->access === 'free') {
            $chapter->access = 'paid';
            $chapter->save();
            $this->pages->relocate($chapter);
        }
    }

    private function name(Chapter $c): string
    {
        return $c->series->title.' · '.$this->translator->trans('ernestdefoe-panels.lib.chapter_number', ['{number}' => $c->label()]);
    }
}
