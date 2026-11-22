<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\View;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\VatStatus;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A VAT status of the firm as the screens name it, "Plátce DPH, měsíčně": the
 * status with the period length of a payer.
 */
final readonly class VatStretchLabel
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(VatStretch $stretch): string
    {
        return $this->status(VatStatus::ofRegistration($stretch->status), $stretch->periodLength);
    }

    public function status(VatStatus $status, ?VatPeriodLength $length): string
    {
        $name = $this->translator->trans('firm.vat_status.'.$status->value);

        return $length instanceof VatPeriodLength ? $this->translator->trans('firm.vat.with_length', ['status' => $name, 'length' => $this->translator->trans('firm.vat.length.'.$length->value)]) : $name;
    }
}
