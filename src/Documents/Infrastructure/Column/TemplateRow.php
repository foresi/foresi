<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Column;

use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\TemplateVersion;

/**
 * A template as a row of Settings → Documents: the template, the name of its type and its
 * latest version.
 */
final readonly class TemplateRow
{
    public function __construct(public DocumentTemplate $template, public string $type, public ?TemplateVersion $version)
    {
    }
}
