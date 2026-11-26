<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Mail;

use Foresi\Identity\Application\PasswordChangedNotice;
use Foresi\Identity\Domain\User;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\SystemMail;
use Foresi\Shared\Application\SystemMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The notice of a changed password, in the person's language: when it was
 * changed, that the other devices were signed out, and whom to turn to if it
 * was not them. Without system mail there is nothing to send; the change
 * stands and the log says so.
 */
final readonly class SystemPasswordChangedMailer implements PasswordChangedNotice
{
    public function __construct(
        private SystemMailer $mailer,
        private TranslatorInterface $translator,
        private InstanceClock $clock,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.product_name')]
        private string $product,
    ) {
    }

    public function send(User $user, \DateTimeImmutable $changedAt): void
    {
        if (!$this->mailer->isConfigured()) {
            $this->logger->notice('No notice of the changed password was sent: system mail is not configured.', ['user' => $user->id()]);

            return;
        }
        $locale = $user->locale();
        $zone = $this->clock->timezone();
        $time = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, $zone)->format($changedAt);
        $arguments = ['product' => $this->product, 'time' => \sprintf('%s (%s)', $time, $zone->getName()), 'email' => $user->email()];
        $subject = $this->translator->trans('password.mail.subject', $arguments, null, $locale);
        $paragraphs = array_map(fn (string $key): string => $this->translator->trans('password.mail.'.$key, $arguments, null, $locale), ['changed', 'signed_out', 'not_you']);
        $escape = static fn (string $value): string => htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $html = implode('', array_map(static fn (string $paragraph, int $index): string => \sprintf('<p style="margin:0%s;">%s</p>', 2 === $index ? '' : ' 0 16px', $escape($paragraph)), $paragraphs, array_keys($paragraphs)));

        $this->mailer->send(new SystemMail($user->email(), $subject, implode("\n\n", $paragraphs), $html, $locale));
    }
}
