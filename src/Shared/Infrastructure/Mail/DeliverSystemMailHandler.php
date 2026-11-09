<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends a queued system mail through the installation's own mailer, in the
 * layout of system mail (templates/email). Nothing of the mail is logged: a
 * failure goes back to the queue, which retries it and logs only its class.
 */
#[AsMessageHandler]
final readonly class DeliverSystemMailHandler
{
    public function __construct(private SystemMailSettings $settings, private Environment $twig)
    {
    }

    public function __invoke(SystemMail $mail): void
    {
        $context = ['mail' => $mail, 'locale' => $mail->locale];
        $email = new Email()
            ->from($this->settings->from())
            ->to($mail->to)
            ->subject($mail->subject)
            ->text($this->twig->render('email/system.txt.twig', $context));
        foreach ($mail->headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }
        if (null !== $mail->html) {
            $email->html($this->twig->render('email/system.html.twig', $context));
        }
        $this->settings->transport()->send($email);
    }
}
