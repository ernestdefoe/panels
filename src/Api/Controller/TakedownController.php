<?php

namespace ErnestDefoe\Panels\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Media\Refused;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Model\Series;
use ErnestDefoe\Panels\Model\Takedown;
use ErnestDefoe\Panels\Notification\TakedownBlueprint;
use ErnestDefoe\Panels\Publisher;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The copyright flow.
 *
 * POST  /api/panels/takedowns                file a claim (anyone, guests too; 5 an hour per address)
 * GET   /api/panels/takedowns                the queue (moderators), ?state=open|info|disabled|rejected|restored
 * PATCH /api/panels/takedowns/{id}           {action: disable|reject|info|restore, reason}
 * POST  /api/panels/takedowns/{id}/counter   the uploader's counter-notice
 *
 * Disabling takes the chapter (or the whole series) down at once, strikes the uploader, and
 * tells them with a counter-notice link. Restoring is always a moderator's decision.
 */
class TakedownController extends Controller
{
    public const PER_HOUR = 5;

    /** @var array<int, array{0: ?Series, 1: ?Chapter}> */
    private array $targets = [];

    public function __construct(
        private readonly Access $access,
        private readonly Publisher $publisher,
        private readonly NotificationSyncer $notifications,
        private readonly Mailer $mailer,
        private readonly TranslatorInterface $translator,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $route = (string) $request->getAttribute('routeName');
        $data = $this->body($request);

        if ($route === 'panels.takedowns.create') {
            return $this->json(['data' => $this->file($data, $request, $actor)], 201);
        }

        $takedown = $route === 'panels.takedowns' ? null : Takedown::query()->findOrFail((int) $this->param($request, 'id'));

        if ($route === 'panels.takedowns.counter' && $takedown) {
            return $this->json(['data' => $this->counter($takedown, $data, $actor)]);
        }

        if (! $this->access->isStaff($actor)) {
            throw new PermissionDeniedException();
        }

        if ($takedown === null) {
            $state = (string) ($request->getQueryParams()['state'] ?? 'open');
            $rows = Takedown::query()->with('uploader')
                ->when(in_array($state, Takedown::STATES, true), fn ($q) => $q->where('state', $state))
                ->orderByDesc('id')->limit(50)->get();

            return $this->json(['data' => $rows->map(fn (Takedown $t) => $this->present($t, true))->all()]);
        }

        $this->act($takedown, (string) ($data['action'] ?? ''), mb_substr(trim((string) ($data['reason'] ?? '')), 0, 2000), $actor);

        return $this->json(['data' => $this->present($takedown->fresh(['uploader']) ?? $takedown, true)]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function file(array $data, ServerRequestInterface $request, User $actor): array
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($ip !== '' && Takedown::query()->where('ip_address', $ip)->where('created_at', '>=', Carbon::now()->subHour())->count() >= self::PER_HOUR) {
            throw new Refused('ernestdefoe-panels.api.refused.slow_down');
        }

        $type = $data['targetType'] ?? '';
        $target = match ($type) {
            'series' => Series::query()->find((int) ($data['targetId'] ?? 0)),
            'chapter' => Chapter::query()->find((int) ($data['targetId'] ?? 0)),
            default => null,
        };
        if (! $target) {
            throw new Refused('ernestdefoe-panels.api.refused.claim_target');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $work = trim((string) ($data['work'] ?? ''));
        $signature = trim((string) ($data['signature'] ?? ''));
        $urls = array_values(array_filter(array_map('trim', explode("\n", (string) ($data['urls'] ?? '')))));
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($work) < 10 || $signature === '' || ! $urls
            || empty($data['goodFaith']) || empty($data['accurate'])) {
            throw new Refused('ernestdefoe-panels.api.refused.claim_incomplete');
        }

        $series = $target instanceof Chapter ? $target->series : $target;
        $takedown = new Takedown();
        $takedown->target_type = (string) $type;
        $takedown->target_id = (int) $target->id;
        $takedown->uploader_id = $target instanceof Chapter ? ($target->user_id ?? $series->user_id) : $series->user_id;
        $takedown->claimant_name = mb_substr($name, 0, 120);
        $takedown->claimant_email = mb_substr($email, 0, 190);
        $takedown->claimant_org = mb_substr(trim((string) ($data['org'] ?? '')), 0, 150) ?: null;
        $takedown->work = mb_substr($work, 0, 5000);
        $takedown->urls = array_slice(array_map(fn ($u) => mb_substr($u, 0, 500), $urls), 0, 50);
        $takedown->signature = mb_substr($signature, 0, 120);
        $takedown->ip_address = $ip !== '' ? $ip : null;
        $takedown->state = 'open';
        $takedown->save();

        return ['id' => (int) $takedown->id];
    }

    private function act(Takedown $takedown, string $action, string $reason, User $actor): void
    {
        [$series, $chapter] = $this->target($takedown);
        $takedown->handled_by = (int) $actor->id;
        $takedown->reason = $reason !== '' ? $reason : $takedown->reason;

        switch ($action) {
            case 'disable':
                if ($chapter) {
                    $this->publisher->remove($chapter, $actor);
                } elseif ($series) {
                    $series->removed_at = Carbon::now();
                    $series->save();
                    Chapter::query()->where('series_id', $series->id)->where('state', '!=', 'removed')->get()
                        ->each(fn (Chapter $c) => $this->publisher->remove($c, $actor));
                }
                $takedown->state = 'disabled';
                $takedown->save();
                if ($takedown->uploader_id && ! $this->db->table('panel_strikes')->where('takedown_id', $takedown->id)->exists()) {
                    $this->db->table('panel_strikes')->insert(['user_id' => $takedown->uploader_id, 'takedown_id' => $takedown->id, 'created_at' => Carbon::now()]);
                }
                $this->tellUploader($takedown, $series, 'disabled', $chapter);
                $this->mailClaimant($takedown, 'disabled');
                break;

            case 'restore':
                if ($chapter) {
                    $this->publisher->restore($chapter, $actor);
                } elseif ($series) {
                    $series->removed_at = null;
                    $series->save();
                    Chapter::query()->where('series_id', $series->id)->where('state', 'removed')->get()
                        ->each(fn (Chapter $c) => $this->publisher->restore($c, $actor));
                }
                $this->db->table('panel_strikes')->where('takedown_id', $takedown->id)->delete();
                $takedown->state = 'restored';
                $takedown->save();
                $this->tellUploader($takedown, $series, 'restored', $chapter);
                break;

            case 'reject':
            case 'info':
                $takedown->state = $action === 'reject' ? 'rejected' : 'info';
                $takedown->save();
                $this->mailClaimant($takedown, $action);
                break;

            default:
                throw new Refused('ernestdefoe-panels.api.refused.claim_action');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function counter(Takedown $takedown, array $data, User $actor): array
    {
        $actor->assertRegistered();
        if ((int) $takedown->uploader_id !== (int) $actor->id || $takedown->state !== 'disabled') {
            throw new PermissionDeniedException();
        }
        $fields = [];
        foreach (['name' => 120, 'address' => 500, 'statement' => 5000, 'signature' => 120] as $key => $max) {
            $fields[$key] = mb_substr(trim((string) ($data[$key] ?? '')), 0, $max);
            if ($fields[$key] === '') {
                throw new Refused('ernestdefoe-panels.api.refused.claim_incomplete');
            }
        }
        if (empty($data['consent'])) {
            throw new Refused('ernestdefoe-panels.api.refused.claim_incomplete');
        }
        $takedown->counter = $fields + ['at' => Carbon::now()->toIso8601String()];
        // Ten business days, the usual DMCA window: a reminder for moderators, never automatic.
        $takedown->restore_after = Carbon::now()->addWeekdays(10);
        $takedown->save();
        $this->mailClaimant($takedown, 'counter');

        return $this->present($takedown, false);
    }

    /** @return array{0: ?Series, 1: ?Chapter} */
    private function target(Takedown $takedown): array
    {
        return $this->targets[(int) $takedown->id] ??= $takedown->target_type === 'chapter'
            ? (function () use ($takedown) {
                $chapter = Chapter::query()->with('series')->find($takedown->target_id);

                return [$chapter?->series, $chapter];
            })()
            : [Series::query()->find($takedown->target_id), null];
    }

    private function label(Takedown $takedown): string
    {
        [$series, $chapter] = $this->target($takedown);
        $title = $series->title ?? '#'.$takedown->target_id;

        return $chapter ? $title.' · '.$this->translator->trans('ernestdefoe-panels.lib.chapter_number', ['{number}' => $chapter->label()]) : $title;
    }

    private function tellUploader(Takedown $takedown, ?Series $series, string $what, ?Chapter $chapter): void
    {
        $uploader = $takedown->uploader_id ? User::query()->find($takedown->uploader_id) : null;
        if ($uploader && $series) {
            $this->notifications->sync(new TakedownBlueprint($series, $what, (int) $takedown->id, $this->label($takedown)), [$uploader]);
        }
    }

    private function mailClaimant(Takedown $takedown, string $what): void
    {
        $forum = (string) $this->settings->get('forum_title');
        $body = $this->translator->trans('ernestdefoe-panels.email.claimant.'.$what, [
            '{name}' => $takedown->claimant_name,
            '{forum}' => $forum,
            '{target}' => $this->label($takedown),
            '{reason}' => (string) $takedown->reason,
            '{counter}' => $what === 'counter' ? json_encode($takedown->counter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ]);
        try {
            $this->mailer->raw($body, function ($message) use ($takedown, $forum) {
                $message->to($takedown->claimant_email, $takedown->claimant_name)
                    ->subject($this->translator->trans('ernestdefoe-panels.email.claimant.subject', ['{forum}' => $forum, '{id}' => $takedown->id]));
            });
        } catch (\Throwable) {
            // A mail server that's down never blocks the takedown itself.
        }
    }

    /** @return array<string, mixed> */
    private function present(Takedown $t, bool $staff): array
    {
        [$series, $chapter] = $this->target($t);

        return [
            'id' => (int) $t->id,
            'targetType' => $t->target_type,
            'targetId' => (int) $t->target_id,
            'label' => $this->label($t),
            'seriesSlug' => $series?->slug,
            'chapterNumber' => $chapter?->label(),
            'attestedAt' => $chapter?->attested_at?->toIso8601String(),
            'state' => $t->state,
            'reason' => $t->reason,
            'createdAt' => $t->created_at->toIso8601String(),
            'restoreAfter' => $t->restore_after?->toIso8601String(),
            'counter' => $t->counter,
        ] + ($staff ? [
            'claimant' => ['name' => $t->claimant_name, 'email' => $t->claimant_email, 'org' => $t->claimant_org],
            'work' => $t->work,
            'urls' => $t->urls,
            'signature' => $t->signature,
            'uploader' => $t->uploader ? ['id' => (int) $t->uploader->id, 'username' => $t->uploader->username, 'strikes' => $this->db->table('panel_strikes')->where('user_id', $t->uploader->id)->count()] : null,
        ] : []);
    }
}
