<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\InvitationMailer;
use App\Identity\Domain\Invitation;
use App\Identity\Infrastructure\Controller\InvitationController;
use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invitation as system mail of the installation, in the language chosen for
 * the person: who invites them where, the link and how long it works.
 */
final readonly class SystemInvitationMailer implements InvitationMailer
{
    public function __construct(
        private SystemMailer $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        #[Autowire(param: 'app.product_name')]
        private string $product,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    public function send(Invitation $invitation, string $token, string $inviter, string $organisation): void
    {
        $locale = $invitation->locale();
        $arguments = ['inviter' => '' === $inviter ? $organisation : $inviter, 'organisation' => $organisation, 'days' => Invitation::VALID_DAYS, 'product' => $this->product];
        $subject = $this->translator->trans('people.mail.subject', $arguments, null, $locale);
        $intro = $this->translator->trans('people.mail.intro', $arguments, null, $locale);
        $action = $this->translator->trans('people.mail.action', [], null, $locale);
        $validity = $this->translator->trans('people.mail.validity', $arguments, null, $locale);
        $ignore = $this->translator->trans('people.mail.ignore', [], null, $locale);
        $link = $this->link($token);
        $text = implode("\n\n", [$intro, $action.":\n".$link, $validity, $ignore]);
        $escape = static fn (string $value): string => htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $html = sprintf(
            '<p style="margin:0 0 16px;">%s</p><p style="margin:0 0 16px;"><a href="%s" style="color:#2f4a3a;font-weight:bold;">%s</a></p><p style="margin:0 0 16px;">%s</p><p style="margin:0;color:#616e7c;">%s</p>',
            $escape($intro),
            $escape($link),
            $escape($action),
            $escape($validity),
            $escape($ignore),
        );

        $this->mailer->send(new SystemMail($invitation->email(), $subject, $text, $html, $locale, $organisation));
    }

    public function link(string $token): string
    {
        return $this->urls->generate(InvitationController::ROUTE, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
