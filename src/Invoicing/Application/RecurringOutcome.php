<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What became of an invoice a recurring template issued: queued for sending,
 * held for review, or refused for sending, so it waits for review too and the
 * template records why.
 */
final readonly class RecurringOutcome
{
    public const string SENT = 'sent';
    public const string REVIEW = 'review';
    public const string REFUSED = 'refused';

    /**
     * @param string|null $code for a refusal, why: "send." and the reason of SendRefused
     */
    private function __construct(public string $kind, public ?string $code)
    {
    }

    public static function sent(): self
    {
        return new self(self::SENT, null);
    }

    public static function review(): self
    {
        return new self(self::REVIEW, null);
    }

    public static function refused(string $code): self
    {
        return new self(self::REFUSED, $code);
    }

    public function isRefused(): bool
    {
        return self::REFUSED === $this->kind;
    }
}
