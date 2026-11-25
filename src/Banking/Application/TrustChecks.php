<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\InboundMessage;
use Foresi\Mail\Contract\AuthenticationResult;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;

/**
 * The trust check of the last message of a feed, for the owner's Pairing tab:
 * which receiving server wrote the topmost Authentication-Results, as the raw
 * message has it, and what Foresi made of the message. Only the authserv-id is
 * read out of the mail; its sender, subject and text never leave here.
 */
final readonly class TrustChecks
{
    /** A host name is at most 253 characters. */
    private const int MAX_ID = 253;

    public function __construct(
        private InboundMessages $messages,
        private Messages $mail,
    ) {
    }

    /**
     * @return TrustCheck|null null when no message came to the feed yet
     */
    public function lastOf(string $feedId): ?TrustCheck
    {
        $message = $this->messages->lastOfFeed($feedId);
        $view = $message instanceof InboundMessage ? $this->mail->find($message->mailMessageId()) : null;
        if (!$message instanceof InboundMessage || !$view instanceof MailMessageView) {
            return null;
        }
        $topmost = $this->mail->authenticationResults($message->mailMessageId())[0] ?? null;
        // The sender may have written it, when no server added one above: shown as text, and short.
        $authservId = $topmost instanceof AuthenticationResult ? mb_substr($topmost->authservId, 0, self::MAX_ID) : '';

        return new TrustCheck(
            $view->channel,
            '' === $authservId ? null : $authservId,
            '' !== $authservId && $topmost->trusted,
            $message->trust(),
            $message->trustBasis(),
        );
    }
}
