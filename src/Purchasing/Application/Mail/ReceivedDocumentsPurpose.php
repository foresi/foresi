<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Mail\Contract\AddressOwners;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\NotAccepted;
use Foresi\Purchasing\Domain\MailIntake;
use Psr\Clock\ClockInterface;

/**
 * The mail of a person's capture address. Mail keeps the addresses: each
 * belongs to one person, who manages it on the settings page (OwnAddresses) or
 * over Mail's REST and MCP calls, and it can be shown again, for the forwarding
 * instructions.
 *
 * Mail hands each stored message over in the transaction that stored it. It
 * only becomes a row of the intake log, queued for the job that files its
 * attachments (MailAttachmentFiling), so Mail's transaction stays short and a
 * failed draft never loses the stored mail. An address that is unknown, revoked
 * or of a person who left the organisation takes in nothing. When Mail last
 * received mail for the address is Mail's to say, not kept here.
 */
final readonly class ReceivedDocumentsPurpose implements InboundPurpose
{
    public const string KEY = 'received_documents';
    public const string LABEL = 'Received documents';

    public function __construct(
        private AddressOwners $owners,
        private StaffCheck $staff,
        private Messages $messages,
        private MailIntakes $intakes,
        private MailFilingQueue $queue,
        private ClockInterface $clock,
    ) {
    }

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
        $owner = $this->owners->ownerOf($addressId);
        if (null === $owner || !$this->staff->isStaffMember($owner)) {
            throw new NotAccepted('No capture address of a person at work has this address.');
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->intakes->add(new MailIntake($messageId, $owner, $addressId, $this->messages->find($messageId)->verdict ?? 'unknown', $now));
        // Queued on the database queue inside Mail's transaction, as Banking does: it goes with the row or not at all.
        $this->queue->queue(new FileMailAttachments($messageId));
    }
}
