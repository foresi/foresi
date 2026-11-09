<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Mail;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Domain\Membership;
use App\Shared\Application\Markdown;
use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;
use App\Tenancy\Application\TenantContext;
use App\Work\Application\ManageNotificationPreferences;
use App\Work\Application\NotificationMailer;
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\TaskComment;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The e-mails of notifications as system mail of the installation, in the
 * language of the recipient and with their organisation in the footer: who did
 * what on which task, a short plain excerpt of the comment of a mention, the
 * link to the task, to the settings, and one to stop that kind of e-mail, also
 * as the List-Unsubscribe header with one-click unsubscribing (RFC 8058).
 */
final readonly class SystemNotificationMailer implements NotificationMailer
{
    /** How much of a comment the e-mail of a mention quotes. */
    public const int EXCERPT = 280;

    public function __construct(
        private SystemMailer $mailer,
        private TranslatorInterface $translator,
        private Environment $twig,
        private NotificationLinks $links,
        private Markdown $markdown,
        private MembershipDirectory $directory,
        private TenantContext $context,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    public function immediate(Notification $notification, NotificationKind $kind, Membership $actor, ?TaskComment $comment): void
    {
        $recipient = $notification->recipient();
        $locale = $recipient->user()->locale();
        $task = $notification->task();
        $unsubscribe = $this->links->unsubscribe($recipient, $kind->value);
        $context = [
            'locale' => $locale,
            'event' => $this->translator->trans('work.history.entry', ['who' => $actor->user()->displayName(), 'what' => $this->translator->trans('work.notifications.event.'.$kind->value, [], null, $locale)], null, $locale),
            'task' => $task->title(),
            'project' => $task->project()->name(),
            'excerpt' => $comment instanceof TaskComment ? self::excerpt($this->markdown->toHtml($comment->text())) : null,
            'taskUrl' => $this->links->task($task->id()),
            'settingsUrl' => $this->links->settings(),
            'unsubscribeUrl' => $unsubscribe,
            'unsubscribeLabel' => $this->translator->trans('work.notification_mail.unsubscribe.'.$kind->value, [], null, $locale),
        ];
        $subject = $this->translator->trans('work.notification_mail.subject.'.$kind->value, ['task' => $task->title()], null, $locale);
        $this->send($recipient, $locale, $subject, 'email/work/notification', $context, $unsubscribe);
    }

    public function digest(Membership $recipient, array $notifications): void
    {
        $locale = $recipient->user()->locale();
        $projects = [];
        foreach ($notifications as $notification) {
            $task = $notification->task();
            $event = $this->translator->trans('work.history.entry', ['who' => $notification->actor()->user()->displayName(), 'what' => $this->translator->trans('work.notifications.event.'.$notification->kind()->value, [], null, $locale)], null, $locale);
            if ($notification->events() > 1) {
                $event = $this->translator->trans('work.notifications.more', ['event' => $event, 'count' => $notification->events() - 1], null, $locale);
            }
            $project = $task->project();
            $projects[$project->id()] ??= ['name' => $project->name(), 'rows' => []];
            $projects[$project->id()]['rows'][] = ['task' => $task->title(), 'url' => $this->links->task($task->id()), 'event' => $event];
        }
        $unsubscribe = $this->links->unsubscribe($recipient, ManageNotificationPreferences::DIGEST);
        $context = [
            'locale' => $locale,
            'count' => \count($notifications),
            'projects' => array_values($projects),
            'inboxUrl' => $this->links->inbox(),
            'settingsUrl' => $this->links->settings(),
            'unsubscribeUrl' => $unsubscribe,
            'unsubscribeLabel' => $this->translator->trans('work.notification_mail.unsubscribe.digest', [], null, $locale),
        ];
        $subject = $this->translator->trans('work.notification_mail.subject.digest', ['count' => \count($notifications)], null, $locale);
        $this->send($recipient, $locale, $subject, 'email/work/digest', $context, $unsubscribe);
    }

    /**
     * The text of rendered Markdown, on one line and cut at a word near the limit.
     */
    public static function excerpt(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], ' ', $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) <= self::EXCERPT) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::EXCERPT);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space || $space < self::EXCERPT / 2 ? $cut : mb_substr($cut, 0, $space), ' ,.;:').'…';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(Membership $recipient, string $locale, string $subject, string $template, array $context, string $unsubscribe): void
    {
        $this->mailer->send(new SystemMail(
            $recipient->user()->email(),
            $subject,
            trim($this->twig->render($template.'.txt.twig', $context)),
            $this->twig->render($template.'.html.twig', $context),
            $locale,
            $this->organisation($recipient),
            ['List-Unsubscribe' => '<'.$unsubscribe.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
        ));
    }

    private function organisation(Membership $recipient): ?string
    {
        $tenant = $this->context->current();
        foreach ($this->directory->organisationsOf($recipient->user()->id()) as $organisation) {
            if ($organisation->id->equals($tenant)) {
                return $organisation->name;
            }
        }

        return null;
    }
}
