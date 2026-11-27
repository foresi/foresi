<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Infrastructure\Column;

use Foresi\DocumentTemplates\Domain\DocumentTemplate;
use Foresi\DocumentTemplates\Domain\TemplateVersion;

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
