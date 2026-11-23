<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * A source whose flows fall only in some categories, such as the VAT periods
 * in vat or the tax estimate in taxes and insurance. A read that leaves out
 * every one of them never calls the source, so a module that reads the
 * forecast from inside its own source, as the VAT of the plans does, is not
 * asked again by another such source.
 */
interface CategorisedSource extends CashFlowSource
{
    /**
     * @return list<FlowCategory> every category its flows may fall in
     */
    public function categories(): array;
}
