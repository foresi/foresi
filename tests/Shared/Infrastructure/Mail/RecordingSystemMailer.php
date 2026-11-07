<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;

/**
 * A system mailer that keeps what it was given, or throws the given failure.
 */
final class RecordingSystemMailer implements SystemMailer
{
    /** @var list<SystemMail> */
    public array $sent = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function send(SystemMail $mail): void
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        $this->sent[] = $mail;
    }

    public function isConfigured(): bool
    {
        return !$this->failure instanceof \Throwable;
    }
}
