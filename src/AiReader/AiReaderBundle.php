<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Reads received documents with an AI model. The reader is a fallback of the
 * Purchasing readers: it adds nothing to an instance until a key is set.
 */
final class AiReaderBundle extends ModuleBundle
{
}
