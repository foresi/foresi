<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\MessageRetention;

final readonly class StoredRetention implements MessageRetention
{
    public function __construct(private MailMessages $messages)
    {
    }

    public function release(string $messageId, \DateTimeImmutable $after): void
    {
        $this->messages->schedulePurge($messageId, $after);
    }

    public function keep(string $messageId): void
    {
        $this->messages->schedulePurge($messageId, null);
    }
}
