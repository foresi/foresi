<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Support;

use Foresi\ExchangeRate\Application\RateSettingStore;
use Foresi\ExchangeRate\Domain\RateSourceSetting;

final class InMemoryRateSettings implements RateSettingStore
{
    public function __construct(public ?RateSourceSetting $stored = null)
    {
    }

    public function setting(): ?RateSourceSetting
    {
        return $this->stored;
    }

    public function add(RateSourceSetting $setting): void
    {
        $this->stored = $setting;
    }
}
