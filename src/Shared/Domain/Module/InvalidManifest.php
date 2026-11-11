<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * Raised when a module manifest, or the set of installed modules, cannot be
 * read as a valid module graph.
 */
final class InvalidManifest extends \DomainException
{
}
