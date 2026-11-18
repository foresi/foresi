<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure;

use Foresi\Party\Application\ContactImports;
use Foresi\Party\Application\ImportedBy;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The provider that knows the import answers. With none, such as without the
 * Import module, no import exists, and a list narrowed to one shows nothing.
 */
final readonly class CollectedContactImports implements ContactImports
{
    /**
     * @param iterable<ContactImports> $providers
     */
    public function __construct(
        #[AutowireIterator(ContactImports::TAG)]
        private iterable $providers,
    ) {
    }

    public function fileName(string $importId): ?string
    {
        foreach ($this->providers as $provider) {
            $name = $provider->fileName($importId);
            if (null !== $name) {
                return $name;
            }
        }

        return null;
    }

    public function summaryUrl(string $importId): string
    {
        return $this->owner($importId)?->summaryUrl($importId) ?? throw new \LogicException(\sprintf('No installed module knows the import %s.', $importId));
    }

    public function condition(ImportedBy $import, string $partyId): array
    {
        return $this->owner($import->importId)?->condition($import, $partyId) ?? ['1 = 0', []];
    }

    private function owner(string $importId): ?ContactImports
    {
        foreach ($this->providers as $provider) {
            if (null !== $provider->fileName($importId)) {
                return $provider;
            }
        }

        return null;
    }
}
