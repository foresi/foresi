<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Purchasing\Domain\CaptureAddressRefused;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What the capture addresses refuse over the API and the screens (ADR 0032):
 * the person has an active address already, a name is unusable, or this server
 * cannot issue addresses (no template or no key, with Mail's reason).
 */
final class CaptureAddressRefusal extends \DomainException implements Refused
{
    /** The person has an active capture address; replace or revoke it. */
    public const string EXISTS = 'capture_address_exists';

    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function exists(): self
    {
        return new self(self::EXISTS, RefusalKind::Conflict, null, 'You have a capture address already; replace it for a new one, or revoke it first.');
    }

    /**
     * The reason is the one of Mail: not_configured or no_key.
     */
    public static function unavailable(AddressNotIssued $unavailable): self
    {
        return new self($unavailable->reason->value, RefusalKind::Unavailable, null, $unavailable->getMessage(), $unavailable);
    }

    public static function name(CaptureAddressRefused $refused): self
    {
        return new self($refused->reason, RefusalKind::Invalid, 'label', $refused->getMessage(), $refused);
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
        return [];
    }
}
