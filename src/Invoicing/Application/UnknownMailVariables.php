<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A template names a variable that does not exist, or the link in its subject.
 */
final class UnknownMailVariables extends \DomainException
{
    /**
     * @param list<string> $inSubject
     * @param list<string> $inBody
     */
    public function __construct(
        public readonly array $inSubject,
        public readonly array $inBody,
    ) {
        parent::__construct(\sprintf('Unknown mail variables: %s.', implode(', ', array_unique([...$inSubject, ...$inBody]))));
    }
}
