<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What a person's own profile refuses, answered by the shared refusal mapping on the web, REST and MCP.
 */
final class ProfileRefused extends \DomainException implements Refused
{
    public const string NAME_REQUIRED = 'name_required';
    public const string NAME_TOO_LONG = 'name_too_long';
    public const string UNSUPPORTED_LANGUAGE = 'unsupported_language';
    public const string PHOTO_TYPE = 'photo_type';
    public const string PHOTO_TOO_LARGE = 'photo_too_large';
    public const string PHOTO_DIMENSIONS = 'photo_dimensions';

    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function photo(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, 'photo', $message);
    }

    public static function unknownUser(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'The person does not exist.');
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
