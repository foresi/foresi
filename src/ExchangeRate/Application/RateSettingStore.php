<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\ExchangeRate\Domain\RateSourceSetting;

/**
 * The firm's choice of a rate source, one row at most.
 */
interface RateSettingStore
{
    public function setting(): ?RateSourceSetting;

    public function add(RateSourceSetting $setting): void;
}
