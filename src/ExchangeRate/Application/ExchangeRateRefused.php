<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What the use cases of the exchange rates refuse (ADR 0032), with a stable
 * reason, the input field it is about and the kind that decides the status.
 */
final class ExchangeRateRefused extends \DomainException implements Refused
{
    /**
     * @param array<string, list<string>> $details
     */
    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * No installed source has the name.
     *
     * @param list<string> $allowed
     */
    public static function unknownSource(string $name, array $allowed): self
    {
        return new self('source_unknown', RefusalKind::Invalid, 'source', \sprintf('No exchange-rate source "%s" is installed. The firm may choose: %s.', $name, self::listed($allowed)), ['allowed' => $allowed]);
    }

    /**
     * The source is installed, but the rules of the firm's country do not
     * let the firm convert at it.
     *
     * @param list<string> $allowed
     */
    public static function notAllowed(string $name, string $country, array $allowed): self
    {
        return new self('source_not_allowed', RefusalKind::Invalid, 'source', \sprintf('The law of %s does not let the firm convert at the rates of "%s". The firm may choose: %s.', $country, $name, self::listed($allowed)), ['allowed' => $allowed]);
    }

    /**
     * The exchange rates are the firm's: its owners and members work with
     * them, and a guest or anyone signed out is refused.
     */
    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and the members of the organisation choose the source of its exchange rates.');
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return $this->details;
    }

    /**
     * @param list<string> $names
     */
    private static function listed(array $names): string
    {
        return [] === $names ? 'none, no source is installed' : implode(', ', $names);
    }
}
