<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Support;

use Foresi\DocumentTemplates\Contract\DocumentVariableSource;
use Foresi\DocumentTemplates\Contract\VariableDefinition;

final class FakeFirmVariables implements DocumentVariableSource
{
    public string $name = 'Example Firm s.r.o.';

    public function prefix(): string
    {
        return 'firm';
    }

    public function definitions(): array
    {
        return [new VariableDefinition('name', 'Firm name', 'Example Firm s.r.o.')];
    }

    public function values(array $links): array
    {
        return ['name' => $this->name];
    }
}
