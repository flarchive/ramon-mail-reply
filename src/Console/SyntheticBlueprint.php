<?php

namespace Ramon\MailReply\Console;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\CommentPost;
use Flarum\User\User;

/**
 * Blueprint sintético usado SOMENTE pelo comando mail-reply:simulate-send
 * para satisfazer o contrato de uma notificação real. Não é registrado no
 * service provider — nunca aparece no fluxo de produção.
 */
class SyntheticBlueprint implements BlueprintInterface, MailableInterface
{
    public function __construct(
        public CommentPost $post,
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->post;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return null;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'synthetic', 'html' => 'synthetic'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return '[simulate-send]';
    }

    public static function getType(): string
    {
        return 'mail-reply.synthetic';
    }

    public static function getSubjectModel(): string
    {
        return CommentPost::class;
    }
}
