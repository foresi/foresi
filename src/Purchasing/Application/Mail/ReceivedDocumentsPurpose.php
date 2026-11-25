<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\NotAccepted;
use Foresi\Purchasing\Domain\CaptureAddress;
use Foresi\Purchasing\Domain\MailIntake;
use Psr\Clock\ClockInterface;

/**
 * The mail of a person's capture address. Each address belongs to one person
 * and can be shown again, for the forwarding instructions.
 *
 * Mail hands each stored message over in the transaction that stored it. It
 * only becomes a row of the intake log, queued for the job that files its
 * attachments (MailAttachmentFiling), so Mail's transaction stays short and a
 * failed draft never loses the stored mail. An address that is unknown, revoked
 * or of a person who left the organisation takes in nothing.
 */
final readonly class ReceivedDocumentsPurpose implements InboundPurpose
{
    public const string KEY = 'received_documents';
    public const string LABEL = 'Received documents';

    public function __construct(
        private CaptureAddressBook $book,
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
        $address = $this->book->ofMailAddress($addressId);
        if (!$address instanceof CaptureAddress || $address->isRevoked() || !$this->staff->isStaffMember($address->ownerUserId())) {
            throw new NotAccepted('No capture address of a person at work has this address.');
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->intakes->add(new MailIntake($messageId, $address->ownerUserId(), $address->id(), $this->messages->find($messageId)->verdict ?? 'unknown', $now));
        $address->received($now);
        // Queued on the database queue inside Mail's transaction, as Banking does: it goes with the row or not at all.
        $this->queue->queue(new FileMailAttachments($messageId));
    }
}
