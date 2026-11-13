<?php

namespace ErnestDefoe\Panels\Kiosk;

use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Series;
use Ernestdefoe\Kiosk\Unlock\UnlockType;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;

/**
 * A whole series sold through Kiosk: every paid and early-access chapter, now and later.
 * Nothing is written on purchase: Access::holds() asks Kiosk, so access starts and stops with it.
 */
class SeriesUnlock implements UnlockType
{
    public function __construct(private readonly UrlGenerator $url)
    {
    }

    public function label(): string
    {
        return 'ernestdefoe-panels.lib.kiosk.series';
    }

    public function icon(): string
    {
        return 'fas fa-book-open';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return Series::query()->whereNull('removed_at')
            ->when($query !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('title')->limit($limit)->get(['id', 'title'])
            ->map(fn (Series $s) => ['id' => (int) $s->id, 'label' => (string) $s->title])->all();
    }

    public function describe(array $ids): array
    {
        return Series::query()->whereIn('id', $ids)->get(['id', 'title', 'slug'])
            ->mapWithKeys(fn (Series $s) => [(int) $s->id => [
                'label' => (string) $s->title,
                'url' => $this->url->to('forum')->route('panels.series', ['slug' => $s->slug]),
            ]])->all();
    }

    public function grant(User $user, int $targetId): void
    {
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    /** Selling a series: its free chapters stay free, new chapters start as paid. */
    public function prepare(int $targetId): void
    {
        Series::query()->whereKey($targetId)->update(['is_sold' => true]);
    }
}
