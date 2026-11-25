<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\NotAccepted;

/**
 * The mail of a person's capture address. Each address belongs to one person
 * and can be shown again, for the forwarding instructions.
 */
final readonly class ReceivedDocumentsPurpose implements InboundPurpose
{
    public const string KEY = 'received_documents';
    public const string LABEL = 'Received documents';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function ownerRequired(): bool
    {
        return true;
    }

    public function reshowable(): bool
    {
        return true;
    }

    public function accept(string $messageId, string $addressId): void
    {
        // Taking the attachments in comes with the filing of mail (the next task).
        throw new NotAccepted('Received documents take in no mail yet.');
    }
}
