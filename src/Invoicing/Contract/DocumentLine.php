<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A line of an issued document as a country writes it into its files: what
 * was supplied, its tax and its stored amounts, in the currency of the
 * document.
 */
interface DocumentLine
{
    /**
     * The tax document or advance invoice this line deducts.
     */
    public function deducts(): ?string;

    public function text(): string;

    public function quantity(): Quantity;

    public function unit(): string;

    /**
     * The price per unit as entered, with VAT or without it as the price type says.
     */
    public function price(): Money;

    public function priceType(): PriceType;

    public function treatment(): TaxTreatment;

    public function supplyCode(): ?string;

    public function rate(): ?Percent;

    public function discount(): Percent;

    public function unitNet(): Money;

    public function net(): Money;

    public function vat(): Money;

    public function gross(): Money;
}
