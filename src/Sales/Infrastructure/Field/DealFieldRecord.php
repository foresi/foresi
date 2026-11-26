<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Field;

use Foresi\Sales\Application\View\DealLists;
use Foresi\Sales\Application\View\DealRow;
use Foresi\Sales\Contract\Field\DealExtension;
use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Application\Field\FieldRecord;

/**
 * Deals take custom fields (ADR 0045) through DealExtension.
 */
final readonly class DealFieldRecord implements FieldRecord
{
    public function record(): string
    {
        return DealExtension::RECORD;
    }

    public function entity(): string
    {
        return Deal::class;
    }

    public function extension(): string
    {
        return DealExtension::class;
    }

    public function lists(): array
    {
        return [DealLists::KEY => 'd'];
    }

    public function sorted(): array
    {
        return [];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof DealRow);

        return $row->deal->id();
    }
}
