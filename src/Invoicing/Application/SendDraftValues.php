<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\DocumentLanguage;

/**
 * What the send dialog opens with. The subject and body are filled in, in the
 * language of the document, and keep {link} where the link goes.
 */
final readonly class SendDraftValues
{
    /**
     * @param list<SendRecipient> $recipients
     * @param bool                $fromDocument the contact has no invoicing address, so the one the document copied is offered
     * @param string|null         $copyAddress  where "copy to me" goes; null when nobody has an address
     */
    public function __construct(
        public bool $hasServer,
        public ?string $fromName,
        public ?string $fromAddress,
        public array $recipients,
        public bool $fromDocument,
        public ?string $copyAddress,
        public string $subject,
        public string $body,
        public DocumentLanguage $language,
        public ?\DateTimeImmutable $lastSentAt,
    ) {
    }
}
