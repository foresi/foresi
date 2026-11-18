<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application;

/**
 * What a use case refuses, in a form every surface answers alike (ADR 0032): a
 * stable reason code such as "duplicate" or "name_required", a kind that
 * decides the status, the field the refusal is about and details a program can
 * act on, such as the ids of the duplicates. The message is for people and
 * agents; it never quotes text written by third parties.
 */
interface Refused extends \Throwable
{
    /**
     * A stable code in snake case, one of the reasons the use case documents.
     */
    public function reason(): string;

    public function kind(): RefusalKind;

    /**
     * The input field the refusal is about, as the use case's input names it
     * (camel case, such as "displayName"); null when it is about no one field.
     */
    public function field(): ?string;

    /**
     * @return array<string, scalar|list<scalar>|null> members a program can act on, such as ['existing_ids' => [...]]
     */
    public function details(): array;
}
