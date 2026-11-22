<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * Why the catalog refused a read or a write, as every surface answers it
 * (ADR 0032): the reason, its kind and the field it concerns.
 */
final class CatalogRefused extends \DomainException implements Refused
{
    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function notFound(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'The catalog has no product with this id. Find it with foresi_search_products.');
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation work with its catalog.');
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
