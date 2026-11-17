<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Fixtures\ModuleIntegration\Alpha\Integration\Beta;

/**
 * Integration code of the fixture module Alpha for its optional dependency Beta.
 */
final readonly class BetaGreeting
{
    public function text(): string
    {
        return 'Alpha greets Beta.';
    }
}
