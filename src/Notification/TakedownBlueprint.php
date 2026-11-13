<?php

namespace ErnestDefoe\Panels\Notification;

use ErnestDefoe\Panels\Model\Series;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * Told to the uploader: a copyright claim took their chapter (or series) down, or it was
 * restored. The email carries the claim and the counter-notice link.
 */
class TakedownBlueprint implements AlertableInterface, BlueprintInterface, MailableInterface
{
    /** @param 'disabled'|'restored' $what */
    public function __construct(
        public Series $series,
        public string $what,
        public int $takedownId,
        public string $target,
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->series;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return ['what' => $this->what, 'takedownId' => $this->takedownId, 'target' => $this->target];
    }

    public static function getType(): string
    {
        return 'panelsTakedown';
    }

    public static function getSubjectModel(): string
    {
        return Series::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-panels::emails.plain.takedown',
            'html' => 'ernestdefoe-panels::emails.html.takedown',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-panels.email.takedown.'.$this->what.'_subject', ['{target}' => $this->target]);
    }
}
