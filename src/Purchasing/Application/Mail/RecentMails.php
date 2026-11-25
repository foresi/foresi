<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Domain\MailIntake;

/**
 * The newest mails of the signed-in person's capture address with what came
 * of each: the message log of the purpose, as Mail has no screen of its own.
 */
final readonly class RecentMails
{
    public const int LIMIT = 20;

    public function __construct(
        private PurchasingAccess $access,
        private MailIntakes $intakes,
        private Messages $messages,
        private AddressIssuer $addresses,
    ) {
    }

    /**
     * @return list<RecentMail> newest first
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function mine(): array
    {
        $intakes = $this->intakes->latestOf($this->access->denyUnlessStaff(), self::LIMIT);
        $drafts = $this->intakes->firstDraftsOf(array_map(static fn (MailIntake $intake): string => $intake->id(), $intakes));

        return array_map(function (MailIntake $intake) use ($drafts): RecentMail {
            $message = $this->messages->find($intake->id());
            $held = $message instanceof MailMessageView;

            return new RecentMail(
                $intake->id(),
                $intake->createdAt(),
                $held && null !== $message->from ? $this->addresses->masked($message->from) : null,
                $held ? $this->addresses->masked($message->subject) : null,
                $intake->outcome(),
                $intake->drafts(),
                $intake->skipped(),
                $intake->skippedCount(),
                $drafts[$intake->id()] ?? null,
            );
        }, $intakes);
    }
}
