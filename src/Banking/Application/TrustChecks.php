<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundMessage;

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
        private InboundFiles $files,
        private MailReader $reader,
        private AuthenticationResultsParser $parser,
        private TrustSettings $settings,
    ) {
    }

    /**
     * @return TrustCheck|null null when no message came to the feed yet
     */
    public function lastOf(string $feedId): ?TrustCheck
    {
        $message = $this->messages->lastOfFeed($feedId);
        if (!$message instanceof InboundMessage) {
            return null;
        }
        $authservId = $this->topmostAuthserv($message);

        return new TrustCheck(
            $message->channel(),
            $authservId,
            null !== $authservId && $this->settings->trustsAuthserv($authservId, $message->channel()),
            $message->trust(),
            $message->trustBasis(),
        );
    }

    private function topmostAuthserv(InboundMessage $message): ?string
    {
        try {
            $mail = $this->reader->read($this->files->read($message->tenantId()->toString(), $message->rawKey(), $message->rawSha256()));
        } catch (\RuntimeException|\InvalidArgumentException) {
            return null;
        }
        $headers = $mail->headers('Authentication-Results');
        // The sender may have written it, when no server added one above: shown as text, and short.
        $authservId = [] === $headers ? '' : mb_substr(TrustSettings::name($this->parser->parse($headers[0])['authserv']), 0, self::MAX_ID);

        return '' === $authservId ? null : $authservId;
    }
}
