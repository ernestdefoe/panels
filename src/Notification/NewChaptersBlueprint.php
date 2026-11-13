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
 * "3 new chapters of Ink & Ember": one notification per release, however many chapters an
 * uploader published together. The subject is the series (it has an API resource: an
 * unregistered subject 500s every member's notification list).
 */
class NewChaptersBlueprint implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(
        public Series $series,
        public ?User $uploader,
        public int $count,
        public int $chapterId,
        public string $number,
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->series;
    }

    public function getFromUser(): ?User
    {
        return $this->uploader;
    }

    public function getData(): mixed
    {
        return ['count' => $this->count, 'chapterId' => $this->chapterId, 'number' => $this->number];
    }

    public static function getType(): string
    {
        return 'panelsNewChapters';
    }

    public static function getSubjectModel(): string
    {
        return Series::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-panels::emails.plain.newChapters',
            'html' => 'ernestdefoe-panels::emails.html.newChapters',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-panels.email.new_chapters.subject', [
            '{series}' => $this->series->title,
            '{count}' => $this->count,
            '{number}' => $this->number,
        ]);
    }
}
