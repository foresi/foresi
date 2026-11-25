<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure;

use Foresi\AiReader\Application\AiProvider;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;

/**
 * The provider of an instance that has none: it cannot answer.
 */
final readonly class UnconfiguredProvider implements AiProvider
{
    public function extract(DocumentFile $file, array $schema, string $instructions): array
    {
        throw new ReaderFailed('not_configured');
    }
}
