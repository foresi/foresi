<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Field;

use Foresi\Party\Application\PartyRow;
use Foresi\Party\Contract\Contacts;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Party;
use Foresi\Party\Infrastructure\Column\ContactSource;
use Foresi\Shared\Application\Field\FieldRecord;

/**
 * Contacts take custom fields (ADR 0045) through ContactExtension.
 */
final readonly class ContactFieldRecord implements FieldRecord
{
    public function record(): string
    {
        return ContactExtension::RECORD;
    }

    public function scope(): string
    {
        return 'contacts:read';
    }

    public function entity(): string
    {
        return Party::class;
    }

    public function extension(): string
    {
        return ContactExtension::class;
    }

    public function lists(): array
    {
        return [ContactSource::LIST => Contacts::ALIAS];
    }

    public function sorted(): array
    {
        return [ContactSource::LIST];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof PartyRow);

        return $row->id;
    }
}
