<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Where a document stands with the accountant, as iDoklad marks it: never exported,
 * exported, or edited after its export, so the accountant needs it again.
 */
enum ExportState: string
{
    case NotExported = 'not_exported';
    case Exported = 'exported';
    case Changed = 'changed';

    /**
     * @param \DateTimeImmutable|null $changedAfterExportAt set only while exportedAt is
     */
    public static function of(?\DateTimeImmutable $exportedAt, ?\DateTimeImmutable $changedAfterExportAt): self
    {
        return match (true) {
            $changedAfterExportAt instanceof \DateTimeImmutable => self::Changed,
            $exportedAt instanceof \DateTimeImmutable => self::Exported,
            default => self::NotExported,
        };
    }
}
