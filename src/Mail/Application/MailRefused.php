<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What a person's inbound addresses refuse, answered by the shared refusal mapping on the web, REST and MCP.
 * A message never quotes what the person sent.
 */
final class MailRefused extends \DomainException implements Refused
{
    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function conflict(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Conflict, null, $message);
    }

    public static function unavailable(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Unavailable, null, $message);
    }

    public static function notFound(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'You have no inbound address with this id. Find yours with foresi_list_mail_addresses.');
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation have inbound addresses.');
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
