<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What the Documents use cases refuse (ADR 0032).
 */
final class DocumentsRefused extends \DomainException implements Refused
{
    /**
     * @param array<string, scalar|list<scalar>|null> $details
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
     * @param array<string, scalar|list<scalar>|null> $details
     */
    public static function invalid(string $reason, string $field, string $message, array $details = []): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message, $details);
    }

    public static function conflict(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Conflict, null, $message);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', RefusalKind::NotFound, null, $message);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation work with its documents.');
    }

    /**
     * A template uses variables that have no value for this document.
     *
     * @param list<string> $names such as "deal.title"
     */
    public static function missingVariables(array $names): self
    {
        return self::invalid('template_variables_missing', 'body', \sprintf('The template uses variables that have no value for this document: %s. Link the record they come from, or change the template.', implode(', ', $names)), ['missing' => $names]);
    }

    /**
     * A template uses something the sandbox does not allow, or is not a valid template.
     */
    public static function blockedTemplate(string $construct, string $message): self
    {
        return self::invalid('template_not_allowed', 'body', $message, ['construct' => $construct]);
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
}
