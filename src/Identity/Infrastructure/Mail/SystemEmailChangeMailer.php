<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Mail;

use Foresi\Identity\Application\EmailChangeMail;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Controller\EmailController;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\SystemMail;
use Foresi\Shared\Application\SystemMailer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mails of an e-mail change, in the person's language: the link to the new
 * address, how long it works and that it can be ignored; and the notice to the
 * old address, when and to which address it changed, that the other devices
 * were signed out, and whom to turn to if it was not them: the firm's owners by
 * name, or without others the administrator of the installation.
 */
final readonly class SystemEmailChangeMailer implements EmailChangeMail
{
    public function __construct(
        private SystemMailer $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
        #[Autowire(param: 'app.product_name')]
        private string $product,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    public function sendConfirmation(User $user, string $newEmail, #[\SensitiveParameter] string $token, \DateTimeImmutable $expiresAt): void
    {
        $locale = $user->locale();
        $arguments = ['product' => $this->product, 'name' => $user->displayName(), 'email' => $user->email(), 'new' => $newEmail, 'hours' => User::EMAIL_CHANGE_VALID_HOURS];
        $subject = $this->translator->trans('email.mail.confirm.subject', $arguments, null, $locale);
        $intro = $this->translator->trans('email.mail.confirm.intro', $arguments, null, $locale);
        $action = $this->translator->trans('email.mail.confirm.action', [], null, $locale);
        $validity = $this->translator->trans('email.mail.confirm.validity', $arguments, null, $locale);
        $ignore = $this->translator->trans('email.mail.confirm.ignore', $arguments, null, $locale);
        $link = $this->urls->generate(EmailController::CONFIRM_ROUTE, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $escape = self::escape(...);
        $html = \sprintf(
            '<p style="margin:0 0 16px;">%s</p><p style="margin:0 0 16px;"><a href="%s" style="color:#2f4a3a;font-weight:bold;">%s</a></p><p style="margin:0 0 16px;">%s</p><p style="margin:0;color:#616e7c;">%s</p>',
            $escape($intro),
            $escape($link),
            $escape($action),
            $escape($validity),
            $escape($ignore),
        );

        $this->mailer->send(new SystemMail($newEmail, $subject, implode("\n\n", [$intro, $action.":\n".$link, $validity, $ignore]), $html, $locale));
    }

    public function sendNotice(User $user, string $oldEmail, \DateTimeImmutable $changedAt, array $owners): void
    {
        $locale = $user->locale();
        $zone = $this->clock->timezone();
        $time = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, $zone)->format($changedAt);
        $arguments = ['product' => $this->product, 'old' => $oldEmail, 'new' => $user->email(), 'time' => \sprintf('%s (%s)', $time, $zone->getName()), 'owners' => implode(', ', $owners)];
        $subject = $this->translator->trans('email.mail.notice.subject', $arguments, null, $locale);
        $paragraphs = array_map(fn (string $key): string => $this->translator->trans('email.mail.notice.'.$key, $arguments, null, $locale), ['changed', 'signed_out', [] === $owners ? 'not_you_admin' : 'not_you']);
        $html = implode('', array_map(static fn (string $paragraph, int $index): string => \sprintf('<p style="margin:0%s;">%s</p>', 2 === $index ? '' : ' 0 16px', self::escape($paragraph)), $paragraphs, array_keys($paragraphs)));

        $this->mailer->send(new SystemMail($oldEmail, $subject, implode("\n\n", $paragraphs), $html, $locale));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
