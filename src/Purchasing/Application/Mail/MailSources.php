<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Purchasing\Domain\ReceivedDocument;

/**
 * Where a document that came by mail came from: the sender, the subject and
 * the date of the stored message. Nothing for a document that did not come by
 * mail, or whose message Mail no longer holds.
 */
final readonly class MailSources
{
    public function __construct(private Messages $messages, private AddressIssuer $addresses)
    {
    }

    public function of(ReceivedDocument $document): ?MailSource
    {
        $id = $document->sourceMailId();
        $message = null === $id ? null : $this->messages->find($id);
        if (!$message instanceof MailMessageView) {
            return null;
        }

        return new MailSource(
            $this->addresses->masked($message->from ?? ''),
            $this->addresses->masked($message->subject),
            $message->sentAt,
            $message->verdict,
        );
    }
}
